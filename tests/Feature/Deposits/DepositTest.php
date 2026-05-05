<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\LedgerDirection;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->user = Account::create(['name' => 'alice']);
});

it('returns 201 with transfer_id, status COMPLETED, and updated balance', function (): void {
    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 10_000,
            'idempotency_key' => 'dep-1',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['transfer_id', 'status', 'balance']);

    expect($response->json('status'))->toBe(TransferStatus::Completed->value);
    expect($response->json('balance'))->toBe(10_000);
    expect(Str::isUuid($response->json('transfer_id')))->toBeTrue();
});

it('writes exactly one transfer, two ledger entries, and one audit log', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 5_000,
            'idempotency_key' => 'dep-2',
        ])
        ->assertStatus(201);

    expect(Transfer::count())->toBe(1);
    expect(LedgerEntry::count())->toBe(2);
    expect(AuditLog::where('event_type', AuditEventType::DepositMade->value)->count())->toBe(1);

    $transfer = Transfer::first();
    expect($transfer->type)->toBe(TransferType::Deposit);
    expect($transfer->status)->toBe(TransferStatus::Completed);
});

it('debits the system account by the deposited amount and credits the user', function (): void {
    $system = Account::where('is_system', true)->first();

    expect($system->getBalance())->toBe(0);
    expect($this->user->getBalance())->toBe(0);

    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 12_345,
            'idempotency_key' => 'dep-3',
        ])->assertStatus(201);

    expect($system->getBalance())->toBe(-12_345);
    expect($this->user->getBalance())->toBe(12_345);

    $entries = LedgerEntry::all();
    expect($entries->where('account_id', $system->id)->first()->direction)->toBe(LedgerDirection::Debit);
    expect($entries->where('account_id', $this->user->id)->first()->direction)->toBe(LedgerDirection::Credit);
});

it('is idempotent: same idempotency_key returns 200 and the same transfer_id with no new rows', function (): void {
    $first = $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 7_777,
            'idempotency_key' => 'dep-replay',
        ])->assertStatus(201);

    $second = $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 7_777,
            'idempotency_key' => 'dep-replay',
        ])->assertStatus(200);

    expect($second->json('transfer_id'))->toBe($first->json('transfer_id'));
    expect($second->json('balance'))->toBe(7_777);

    expect(Transfer::count())->toBe(1);
    expect(LedgerEntry::count())->toBe(2);
    expect(AuditLog::where('event_type', AuditEventType::DepositMade->value)->count())->toBe(1);
});

it('audit log payload includes amount and new_balance', function (): void {
    $cid = (string) Str::uuid7();

    $this->withHeader('X-Api-Key', 'test-key')
        ->withHeader('X-Correlation-Id', $cid)
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 4_200,
            'idempotency_key' => 'dep-audit',
        ])->assertStatus(201);

    $log = AuditLog::where('event_type', AuditEventType::DepositMade->value)->first();
    expect($log->payload)->toBe(['amount' => 4_200, 'new_balance' => 4_200]);
    expect($log->correlation_id)->toBe($cid);
    expect($log->account_id)->toBe($this->user->id);
    expect($log->transfer_id)->not->toBeNull();
});

it('returns 422 when amount is zero or negative', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => 0,
            'idempotency_key' => 'dep-zero',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
            'amount' => -100,
            'idempotency_key' => 'dep-neg',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('returns 422 when required fields are missing', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$this->user->id}/deposits", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'idempotency_key']);
});

it('returns 404 for an unknown account id', function (): void {
    $unknown = (string) Str::uuid7();

    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$unknown}/deposits", [
            'amount' => 100,
            'idempotency_key' => 'dep-404',
        ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
});

it('refuses to deposit into the system account', function (): void {
    $system = Account::where('is_system', true)->first();

    $this->withHeader('X-Api-Key', 'test-key')
        ->postJson("/api/v1/accounts/{$system->id}/deposits", [
            'amount' => 100,
            'idempotency_key' => 'dep-sys',
        ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->postJson("/api/v1/accounts/{$this->user->id}/deposits", [
        'amount' => 100,
        'idempotency_key' => 'dep-401',
    ])->assertStatus(401);

    expect(Transfer::count())->toBe(0);
});
