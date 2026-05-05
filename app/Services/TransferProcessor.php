<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\InsufficientBalanceException;
use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class TransferProcessor
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Settle a single PENDING transfer atomically.
     *
     * The whole sequence (status updates, account locking, balance check,
     * ledger writes, audit logs) lives inside one DB::transaction. On any
     * exception the entire transaction rolls back, so partial work is
     * never observable. Caller orchestrates retry / fail policy.
     *
     * @throws InsufficientBalanceException when sender balance < transfer amount
     */
    public function process(Transfer $transfer, string $correlationId): void
    {
        // Phase 1: mark PROCESSING in its own committed transaction so the
        // lifecycle stays visible even if settlement crashes or fails.
        DB::transaction(function () use ($transfer, $correlationId): void {
            $transfer->update(['status' => TransferStatus::Processing]);

            AuditLog::create([
                'event_type' => AuditEventType::TransferProcessing,
                'transfer_id' => $transfer->id,
                'correlation_id' => $correlationId,
                'payload' => ['amount' => $transfer->amount],
                'created_at' => now(),
            ]);
        });

        // Phase 2: settle atomically. Lock both accounts in deterministic
        // UUIDv7-asc order to avoid deadlocks under concurrent processing.
        // Any exception thrown here rolls Phase 2 back; PROCESSING from
        // Phase 1 remains the visible state for the caller to transition
        // to FAILED (business error) or for retry (transient error).
        DB::transaction(function () use ($transfer, $correlationId): void {
            $orderedIds = collect([$transfer->from_account_id, $transfer->to_account_id])
                ->sort()
                ->values()
                ->all();

            $accounts = Account::whereIn('id', $orderedIds)
                ->orderByRaw('id ASC')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $accounts[$transfer->from_account_id];
            $to = $accounts[$transfer->to_account_id];

            $balance = $from->getBalance();
            if ($balance < $transfer->amount) {
                throw new InsufficientBalanceException(
                    accountId: $from->id,
                    balance: $balance,
                    attempted: $transfer->amount,
                );
            }

            $this->ledger->postDoubleEntry(
                debitAccountId: $from->id,
                creditAccountId: $to->id,
                transferId: $transfer->id,
                amount: $transfer->amount,
            );

            $transfer->update(['status' => TransferStatus::Completed]);

            AuditLog::create([
                'event_type' => AuditEventType::TransferCompleted,
                'transfer_id' => $transfer->id,
                'account_id' => $from->id,
                'correlation_id' => $correlationId,
                'payload' => [
                    'amount' => $transfer->amount,
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
