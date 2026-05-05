<?php

declare(strict_types=1);

use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\TransferProcessor;
use App\Services\TransferService;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);
});

it('returns the full status payload for a COMPLETED async transfer', function (): void {
    app(TransferService::class)->deposit(
        userAccount: $this->alice,
        amount: 5_000,
        idempotencyKey: 'fund-1',
        correlationId: (string) Str::uuid7(),
    );

    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 't-status-1',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 1_500,
        'status' => TransferStatus::Pending,
    ]);

    (new ProcessTransferJob($transfer->id, (string) Str::uuid7()))
        ->handle(app(TransferProcessor::class));

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/transfers/{$transfer->id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'id', 'type', 'status', 'from_account_id', 'to_account_id',
            'amount', 'error_reason', 'attempts', 'created_at', 'updated_at',
        ]);

    expect($response->json('id'))->toBe($transfer->id);
    expect($response->json('type'))->toBe('TRANSFER');
    expect($response->json('status'))->toBe('COMPLETED');
    expect($response->json('amount'))->toBe(1_500);
    expect($response->json('from_account_id'))->toBe($this->alice->id);
    expect($response->json('to_account_id'))->toBe($this->bob->id);
    expect($response->json('error_reason'))->toBeNull();
});

it('returns PENDING for a freshly enqueued transfer (job not yet run)', function (): void {
    Queue::fake();

    app(TransferService::class)->deposit(
        userAccount: $this->alice,
        amount: 5_000,
        idempotencyKey: 'fund-2',
        correlationId: (string) Str::uuid7(),
    );

    $transferId = app(TransferService::class)->initiateTransfer(
        fromAccount: $this->alice,
        toAccount: $this->bob,
        amount: 1_000,
        idempotencyKey: 't-status-pending',
        correlationId: (string) Str::uuid7(),
    )->id;

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/transfers/{$transferId}")
        ->assertStatus(200);

    expect($response->json('status'))->toBe('PENDING');
});

it('returns FAILED with error_reason when the job rejected it for insufficient balance', function (): void {
    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 't-status-fail',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 500,
        'status' => TransferStatus::Pending,
    ]);

    (new ProcessTransferJob($transfer->id, (string) Str::uuid7()))
        ->handle(app(TransferProcessor::class));

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/transfers/{$transfer->id}")
        ->assertStatus(200);

    expect($response->json('status'))->toBe('FAILED');
    expect($response->json('error_reason'))->toContain('attempted to debit 500');
    expect($response->json('attempts'))->toBeGreaterThan(0);
});

it('also returns deposits, marking them type=DEPOSIT and status=COMPLETED immediately', function (): void {
    $deposit = app(TransferService::class)->deposit(
        userAccount: $this->alice,
        amount: 2_000,
        idempotencyKey: 'd-status',
        correlationId: (string) Str::uuid7(),
    );

    $response = $this->withHeader('X-Api-Key', 'test-key')
        ->getJson("/api/v1/transfers/{$deposit->id}")
        ->assertStatus(200);

    expect($response->json('type'))->toBe('DEPOSIT');
    expect($response->json('status'))->toBe('COMPLETED');
    expect($response->json('amount'))->toBe(2_000);
    expect($response->json('to_account_id'))->toBe($this->alice->id);
    expect($response->json('from_account_id'))
        ->toBe(Account::where('is_system', true)->first()->id);
});

it('returns 404 when the transfer id does not exist', function (): void {
    $this->withHeader('X-Api-Key', 'test-key')
        ->getJson('/api/v1/transfers/'.(string) Str::uuid7())
        ->assertStatus(404);
});

it('rejects unauthenticated requests with 401', function (): void {
    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 't-status-401',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 100,
        'status' => TransferStatus::Pending,
    ]);

    $this->getJson("/api/v1/transfers/{$transfer->id}")
        ->assertStatus(401);
});
