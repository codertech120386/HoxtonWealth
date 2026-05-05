<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transfer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransferService
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /**
     * Synchronously credit $amount to $userAccount, debiting the singleton
     * system account on the other side. Idempotent on $idempotencyKey:
     * a duplicate request returns the original Transfer without writing again.
     */
    public function deposit(
        Account $userAccount,
        int $amount,
        string $idempotencyKey,
        string $correlationId,
    ): Transfer {
        if ($existing = Transfer::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        try {
            $transfer = DB::transaction(function () use ($userAccount, $amount, $idempotencyKey, $correlationId): Transfer {
                $systemAccount = Account::where('is_system', true)->firstOrFail();

                $transfer = Transfer::create([
                    'type' => TransferType::Deposit,
                    'idempotency_key' => $idempotencyKey,
                    'from_account_id' => $systemAccount->id,
                    'to_account_id' => $userAccount->id,
                    'amount' => $amount,
                    'status' => TransferStatus::Completed,
                ]);

                $this->ledger->postDoubleEntry(
                    debitAccountId: $systemAccount->id,
                    creditAccountId: $userAccount->id,
                    transferId: $transfer->id,
                    amount: $amount,
                );

                AuditLog::create([
                    'event_type' => AuditEventType::DepositMade,
                    'account_id' => $userAccount->id,
                    'transfer_id' => $transfer->id,
                    'correlation_id' => $correlationId,
                    'payload' => [
                        'amount' => $amount,
                        'new_balance' => $userAccount->getBalance(),
                    ],
                    'created_at' => now(),
                ]);

                return $transfer;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the idempotency-key race against a concurrent caller; return
            // their committed row instead of erroring.
            return Transfer::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        Log::info('DepositMade', [
            'transfer_id' => $transfer->id,
            'account_id' => $userAccount->id,
            'amount' => $amount,
        ]);

        return $transfer;
    }

    /**
     * Persist a PENDING transfer row + TransferRequested audit log atomically,
     * then enqueue ProcessTransferJob to settle it asynchronously. Idempotent
     * on $idempotencyKey: a duplicate request returns the original row and does
     * not enqueue a second job.
     */
    public function initiateTransfer(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $idempotencyKey,
        string $correlationId,
    ): Transfer {
        if ($existing = Transfer::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        try {
            $transfer = DB::transaction(function () use ($fromAccount, $toAccount, $amount, $idempotencyKey, $correlationId): Transfer {
                $transfer = Transfer::create([
                    'type' => TransferType::Transfer,
                    'idempotency_key' => $idempotencyKey,
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $amount,
                    'status' => TransferStatus::Pending,
                ]);

                AuditLog::create([
                    'event_type' => AuditEventType::TransferRequested,
                    'transfer_id' => $transfer->id,
                    'correlation_id' => $correlationId,
                    'payload' => [
                        'from_account_id' => $fromAccount->id,
                        'to_account_id' => $toAccount->id,
                        'amount' => $amount,
                    ],
                    'created_at' => now(),
                ]);

                return $transfer;
            });
        } catch (UniqueConstraintViolationException) {
            return Transfer::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        Log::info('TransferRequested', [
            'transfer_id' => $transfer->id,
            'account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $amount,
        ]);

        ProcessTransferJob::dispatch($transfer->id, $correlationId);

        return $transfer;
    }
}
