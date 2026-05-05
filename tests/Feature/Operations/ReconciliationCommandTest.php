<?php

declare(strict_types=1);

use App\Enums\LedgerDirection;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Services\TransferProcessor;
use App\Services\TransferService;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);

    // Build a small healthy state: deposit 5000 to alice, transfer 1000 alice→bob.
    app(TransferService::class)->deposit(
        userAccount: $this->alice,
        amount: 5_000,
        idempotencyKey: 'rec-fund',
        correlationId: (string) Str::uuid7(),
    );

    $this->transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'rec-t-1',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 1_000,
        'status' => TransferStatus::Pending,
    ]);

    (new ProcessTransferJob($this->transfer->id, (string) Str::uuid7()))
        ->handle(app(TransferProcessor::class));
});

it('exits 0 with an OK report on a healthy ledger', function (): void {
    $exit = Artisan::call('ledger:reconcile');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('Ledger is consistent.');
    expect($output)->toContain('Per-transfer double-entry: OK');
    expect($output)->toContain('Global zero-sum: OK');
    expect($output)->toContain('User account balances non-negative: OK');
});

it('flags drift and exits 1 when a ledger row is deleted (per-transfer + global zero-sum)', function (): void {
    // Delete one half of the transfer's double-entry pair.
    LedgerEntry::where('transfer_id', $this->transfer->id)
        ->where('direction', LedgerDirection::Credit)
        ->delete();

    $exit = Artisan::call('ledger:reconcile');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('Ledger drift detected');
    expect($output)->toContain($this->transfer->id);
    expect($output)->toContain('global signed sum');
});

it('flags drift when a non-COMPLETED transfer has ledger rows', function (): void {
    $stray = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'rec-stray',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 50,
        'status' => TransferStatus::Pending,
    ]);

    // Inject a single ledger row for a PENDING transfer (should be 0 entries).
    LedgerEntry::create([
        'account_id' => $this->alice->id,
        'transfer_id' => $stray->id,
        'direction' => LedgerDirection::Debit,
        'amount' => 50,
        'created_at' => now(),
    ]);

    $exit = Artisan::call('ledger:reconcile');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain($stray->id);
    expect($output)->toContain('expected 0');
});

it('flags drift when a user account is forced into a negative balance', function (): void {
    // Inject a stray DEBIT against bob with no matching transfer pair, large
    // enough to push his balance below zero.
    $orphanTransfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'rec-orphan',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 10_000,
        'status' => TransferStatus::Completed,
    ]);

    // Only one half of a "completed" transfer (debiting bob) — this also
    // breaks per-transfer invariant. Verifies multiple checks fire together.
    LedgerEntry::create([
        'account_id' => $this->bob->id,
        'transfer_id' => $orphanTransfer->id,
        'direction' => LedgerDirection::Debit,
        'amount' => 10_000,
        'created_at' => now(),
    ]);

    $exit = Artisan::call('ledger:reconcile');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('negative balance');
    expect($output)->toContain($this->bob->id);
});

it('reports zero violations even when the system account has a large negative balance', function (): void {
    // System balance is -5000 (it counterparties the deposit). That's expected
    // — system is allowed to be negative. Reconciliation should still pass.
    $exit = Artisan::call('ledger:reconcile');

    expect($exit)->toBe(0);
    expect(Account::where('is_system', true)->first()->getBalance())->toBe(-5_000);
});
