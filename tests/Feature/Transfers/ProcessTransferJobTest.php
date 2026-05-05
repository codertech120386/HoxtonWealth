<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\LedgerService;
use App\Services\TransferProcessor;
use App\Services\TransferService;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);

    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);

    $this->processor = app(TransferProcessor::class);
});

function fund(Account $account, int $amount): void
{
    app(TransferService::class)->deposit(
        userAccount: $account,
        amount: $amount,
        idempotencyKey: 'fund-'.Str::uuid7(),
        correlationId: (string) Str::uuid7(),
    );
}

function makePending(Account $from, Account $to, int $amount): Transfer
{
    return Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'k-'.Str::uuid7(),
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => $amount,
        'status' => TransferStatus::Pending,
    ]);
}

function runJob(Transfer $transfer, ?string $cid = null): void
{
    $job = new ProcessTransferJob($transfer->id, $cid ?? (string) Str::uuid7());
    $job->handle(test()->processor);
}

it('happy path: settles a transfer with double-entry, balances correct, audit chain present', function (): void {
    fund($this->alice, 10_000);

    expect($this->alice->getBalance())->toBe(10_000);
    expect($this->bob->getBalance())->toBe(0);

    $transfer = makePending($this->alice, $this->bob, 3_000);
    $cid = (string) Str::uuid7();

    runJob($transfer, $cid);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Completed);
    expect($this->alice->getBalance())->toBe(7_000);
    expect($this->bob->getBalance())->toBe(3_000);

    $entries = LedgerEntry::where('transfer_id', $transfer->id)->get();
    expect($entries)->toHaveCount(2);
    expect($entries->where('account_id', $this->alice->id)->first()->direction->value)->toBe('DEBIT');
    expect($entries->where('account_id', $this->bob->id)->first()->direction->value)->toBe('CREDIT');

    $events = AuditLog::where('transfer_id', $transfer->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->map(fn ($e) => $e->value)
        ->all();
    expect($events)->toBe(['TransferProcessing', 'TransferCompleted']);

    expect(AuditLog::where('event_type', AuditEventType::TransferCompleted->value)
        ->where('transfer_id', $transfer->id)
        ->first()->correlation_id)->toBe($cid);
});

it('insufficient balance: marks FAILED, leaves balances untouched, no ledger rows', function (): void {
    fund($this->alice, 500);
    $transfer = makePending($this->alice, $this->bob, 1_000);

    runJob($transfer);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Failed);
    expect($transfer->fresh()->error_reason)->toContain('attempted to debit 1000');
    expect($transfer->fresh()->attempts)->toBeGreaterThan(0);

    expect($this->alice->getBalance())->toBe(500);
    expect($this->bob->getBalance())->toBe(0);

    expect(LedgerEntry::where('transfer_id', $transfer->id)->count())->toBe(0);

    $events = AuditLog::where('transfer_id', $transfer->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->map(fn ($e) => $e->value)
        ->all();
    expect($events)->toBe(['TransferProcessing', 'TransferFailed']);
});

it('idempotent: re-running on a COMPLETED transfer is a no-op', function (): void {
    fund($this->alice, 10_000);
    $transfer = makePending($this->alice, $this->bob, 1_000);

    runJob($transfer);

    expect(LedgerEntry::where('transfer_id', $transfer->id)->count())->toBe(2);
    expect($this->alice->getBalance())->toBe(9_000);

    // Second run should not write any new rows.
    runJob($transfer);

    expect(LedgerEntry::where('transfer_id', $transfer->id)->count())->toBe(2);
    expect($this->alice->getBalance())->toBe(9_000);
});

it('two sequential transfers from the same account never overdraw it', function (): void {
    // Alice has 1000. Two pending transfers of 800 each.
    // Under FOR UPDATE locking + recompute, the first wins, the second sees
    // the post-first balance and fails with insufficient balance.
    fund($this->alice, 1_000);

    $carol = Account::create(['name' => 'carol']);

    $t1 = makePending($this->alice, $this->bob, 800);
    $t2 = makePending($this->alice, $carol, 800);

    runJob($t1);
    runJob($t2);

    expect($t1->fresh()->status)->toBe(TransferStatus::Completed);
    expect($t2->fresh()->status)->toBe(TransferStatus::Failed);

    expect($this->alice->getBalance())->toBe(200);
    expect($this->bob->getBalance())->toBe(800);
    expect($carol->getBalance())->toBe(0);

    expect($this->alice->getBalance())->toBeGreaterThanOrEqual(0);
});

it('crash mid-settlement: Phase-2 rollback leaves no ledger writes; PROCESSING + audit from Phase-1 remain', function (): void {
    fund($this->alice, 10_000);
    $transfer = makePending($this->alice, $this->bob, 1_000);

    // LedgerService that inserts the debit row, then throws — simulating a
    // crash after partial work inside Phase 2's transaction.
    $crashy = new class extends LedgerService
    {
        public function postDoubleEntry(string $debitAccountId, string $creditAccountId, string $transferId, int $amount): void
        {
            \App\Models\LedgerEntry::create([
                'account_id' => $debitAccountId,
                'transfer_id' => $transferId,
                'direction' => \App\Enums\LedgerDirection::Debit,
                'amount' => $amount,
                'created_at' => now(),
            ]);
            throw new \RuntimeException('simulated worker crash');
        }
    };

    $crashyProcessor = new TransferProcessor($crashy);
    $job = new ProcessTransferJob($transfer->id, (string) Str::uuid7());

    expect(fn () => $job->handle($crashyProcessor))->toThrow(RuntimeException::class);

    // Phase 2 rolled back: no ledger rows, balances untouched.
    expect(LedgerEntry::where('transfer_id', $transfer->id)->count())->toBe(0);
    expect($this->alice->getBalance())->toBe(10_000);
    expect($this->bob->getBalance())->toBe(0);

    // Phase 1 stays committed — observable evidence the transfer was attempted.
    expect($transfer->fresh()->status)->toBe(TransferStatus::Processing);

    $events = AuditLog::where('transfer_id', $transfer->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->map(fn ($e) => $e->value)
        ->all();
    expect($events)->toBe(['TransferProcessing']);
});

it('retry-after-crash: re-running with a healthy processor settles the transfer cleanly', function (): void {
    fund($this->alice, 10_000);
    $transfer = makePending($this->alice, $this->bob, 1_000);

    // First attempt: crash mid-settlement.
    $crashy = new class extends LedgerService
    {
        public function postDoubleEntry(string $debitAccountId, string $creditAccountId, string $transferId, int $amount): void
        {
            throw new \RuntimeException('crash');
        }
    };

    try {
        (new ProcessTransferJob($transfer->id, (string) Str::uuid7()))
            ->handle(new TransferProcessor($crashy));
    } catch (RuntimeException) {
        // expected
    }

    // Status is PROCESSING (Phase 1 committed; Phase 2 rolled back).
    expect($transfer->fresh()->status)->toBe(TransferStatus::Processing);

    // Second attempt with the real processor settles it.
    runJob($transfer);

    expect($transfer->fresh()->status)->toBe(TransferStatus::Completed);
    expect(LedgerEntry::where('transfer_id', $transfer->id)->count())->toBe(2);
    expect($this->alice->getBalance())->toBe(9_000);
    expect($this->bob->getBalance())->toBe(1_000);

    // Two TransferProcessing events — one per attempt — show the retry trail.
    $events = AuditLog::where('transfer_id', $transfer->id)
        ->orderBy('id')
        ->pluck('event_type')
        ->map(fn ($e) => $e->value)
        ->all();
    expect($events)->toBe(['TransferProcessing', 'TransferProcessing', 'TransferCompleted']);
});

it('drops gracefully when the transfer row no longer exists', function (): void {
    $job = new ProcessTransferJob((string) Str::uuid7(), (string) Str::uuid7());

    // Should NOT throw; just logs and returns.
    $job->handle($this->processor);

    expect(true)->toBeTrue();
});
