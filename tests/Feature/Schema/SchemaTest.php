<?php

declare(strict_types=1);

use App\Enums\LedgerDirection;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds exactly one system account', function (): void {
    $this->seed(SystemAccountSeeder::class);

    $count = Account::where('is_system', true)->count();
    expect($count)->toBe(1);

    $system = Account::where('is_system', true)->first();
    expect($system->name)->toBe('system');
    expect($system->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('rejects a second is_system=true row via the partial unique index', function (): void {
    Account::create(['name' => 'sys1', 'is_system' => true]);

    expect(fn () => Account::create(['name' => 'sys2', 'is_system' => true]))
        ->toThrow(QueryException::class);
});

it('allows many is_system=false rows', function (): void {
    Account::create(['name' => 'alice']);
    Account::create(['name' => 'bob']);
    Account::create(['name' => 'carol']);

    expect(Account::count())->toBe(3);
});

it('computes balance from ledger entries (signed: credit + / debit -)', function (): void {
    $alice = Account::create(['name' => 'alice']);
    $bob = Account::create(['name' => 'bob']);

    expect($alice->getBalance())->toBe(0);

    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'test-key-1',
        'from_account_id' => $alice->id,
        'to_account_id' => $bob->id,
        'amount' => 1000,
        'status' => TransferStatus::Completed,
    ]);

    LedgerEntry::create([
        'account_id' => $alice->id,
        'transfer_id' => $transfer->id,
        'direction' => LedgerDirection::Debit,
        'amount' => 1000,
    ]);
    LedgerEntry::create([
        'account_id' => $bob->id,
        'transfer_id' => $transfer->id,
        'direction' => LedgerDirection::Credit,
        'amount' => 1000,
    ]);

    expect($alice->getBalance())->toBe(-1000);
    expect($bob->getBalance())->toBe(1000);
});

it('casts type, status, and direction columns to enum instances on read', function (): void {
    $alice = Account::create(['name' => 'alice']);
    $bob = Account::create(['name' => 'bob']);

    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'test-key-2',
        'from_account_id' => $alice->id,
        'to_account_id' => $bob->id,
        'amount' => 500,
        'status' => TransferStatus::Pending,
    ]);

    $entry = LedgerEntry::create([
        'account_id' => $alice->id,
        'transfer_id' => $transfer->id,
        'direction' => LedgerDirection::Debit,
        'amount' => 500,
    ]);

    expect($transfer->fresh()->type)->toBe(TransferType::Transfer);
    expect($transfer->fresh()->status)->toBe(TransferStatus::Pending);
    expect($entry->fresh()->direction)->toBe(LedgerDirection::Debit);
});

it('rejects a transfer with non-positive amount via CHECK constraint', function (): void {
    $alice = Account::create(['name' => 'alice']);
    $bob = Account::create(['name' => 'bob']);

    expect(fn () => Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'test-key-3',
        'from_account_id' => $alice->id,
        'to_account_id' => $bob->id,
        'amount' => 0,
        'status' => TransferStatus::Pending,
    ]))->toThrow(QueryException::class);
});
