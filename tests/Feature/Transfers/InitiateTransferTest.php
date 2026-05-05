<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transfer;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    Queue::fake();
    $this->seed(SystemAccountSeeder::class);
    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);
});

function initiate(array $body): \Illuminate\Testing\TestResponse
{
    return test()->withHeader('X-Api-Key', 'test-key')
        ->postJson('/api/v1/transfers', $body);
}

it('returns 202 with transfer_id and status PENDING on first request', function (): void {
    $response = initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 5_000,
        'idempotency_key' => 't-1',
    ])->assertStatus(202);

    expect(Str::isUuid($response->json('transfer_id')))->toBeTrue();
    expect($response->json('status'))->toBe(TransferStatus::Pending->value);
});

it('persists a transfer row with type=TRANSFER and status=PENDING', function (): void {
    $response = initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 1_000,
        'idempotency_key' => 't-2',
    ])->assertStatus(202);

    $transfer = Transfer::find($response->json('transfer_id'));
    expect($transfer)->not->toBeNull();
    expect($transfer->type)->toBe(TransferType::Transfer);
    expect($transfer->status)->toBe(TransferStatus::Pending);
    expect($transfer->amount)->toBe(1_000);
    expect($transfer->from_account_id)->toBe($this->alice->id);
    expect($transfer->to_account_id)->toBe($this->bob->id);
});

it('writes a TransferRequested audit log carrying the request correlation id', function (): void {
    $cid = (string) Str::uuid7();

    $response = test()->withHeader('X-Api-Key', 'test-key')
        ->withHeader('X-Correlation-Id', $cid)
        ->postJson('/api/v1/transfers', [
            'from_account_id' => $this->alice->id,
            'to_account_id' => $this->bob->id,
            'amount' => 750,
            'idempotency_key' => 't-3',
        ])->assertStatus(202);

    $log = AuditLog::where('event_type', AuditEventType::TransferRequested->value)->first();
    expect($log)->not->toBeNull();
    expect($log->transfer_id)->toBe($response->json('transfer_id'));
    expect($log->correlation_id)->toBe($cid);
    expect($log->payload['from_account_id'])->toBe($this->alice->id);
    expect($log->payload['to_account_id'])->toBe($this->bob->id);
    expect($log->payload['amount'])->toBe(750);
});

it('enqueues a ProcessTransferJob carrying the transfer id and correlation id', function (): void {
    $cid = (string) Str::uuid7();

    $response = test()->withHeader('X-Api-Key', 'test-key')
        ->withHeader('X-Correlation-Id', $cid)
        ->postJson('/api/v1/transfers', [
            'from_account_id' => $this->alice->id,
            'to_account_id' => $this->bob->id,
            'amount' => 900,
            'idempotency_key' => 't-4',
        ])->assertStatus(202);

    Queue::assertPushed(
        ProcessTransferJob::class,
        fn (ProcessTransferJob $job): bool => $job->transferId === $response->json('transfer_id')
            && $job->correlationId === $cid,
    );
});

it('is idempotent: same idempotency_key returns 200 with same transfer_id and does not enqueue twice', function (): void {
    $first = initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 1_000,
        'idempotency_key' => 't-replay',
    ])->assertStatus(202);

    $second = initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 1_000,
        'idempotency_key' => 't-replay',
    ])->assertStatus(200);

    expect($second->json('transfer_id'))->toBe($first->json('transfer_id'));
    expect(Transfer::count())->toBe(1);
    expect(AuditLog::where('event_type', AuditEventType::TransferRequested->value)->count())->toBe(1);
    Queue::assertPushed(ProcessTransferJob::class, 1);
});

it('returns 422 when from_account_id equals to_account_id', function (): void {
    initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->alice->id,
        'amount' => 100,
        'idempotency_key' => 't-self',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['from_account_id']);
});

it('returns 422 when amount is zero or negative', function (): void {
    initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 0,
        'idempotency_key' => 't-zero',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('returns 422 when required fields are missing', function (): void {
    initiate([])->assertStatus(422)
        ->assertJsonValidationErrors(['from_account_id', 'to_account_id', 'amount', 'idempotency_key']);
});

it('returns 422 when account ids are not valid UUIDs', function (): void {
    initiate([
        'from_account_id' => 'not-a-uuid',
        'to_account_id' => $this->bob->id,
        'amount' => 100,
        'idempotency_key' => 't-bad-uuid',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['from_account_id']);
});

it('returns 404 when from_account does not exist', function (): void {
    initiate([
        'from_account_id' => (string) Str::uuid7(),
        'to_account_id' => $this->bob->id,
        'amount' => 100,
        'idempotency_key' => 't-no-from',
    ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 404 when to_account does not exist', function (): void {
    initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => (string) Str::uuid7(),
        'amount' => 100,
        'idempotency_key' => 't-no-to',
    ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
});

it('rejects transfers where from_account is the system account', function (): void {
    $system = Account::where('is_system', true)->first();

    initiate([
        'from_account_id' => $system->id,
        'to_account_id' => $this->bob->id,
        'amount' => 100,
        'idempotency_key' => 't-from-sys',
    ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects transfers where to_account is the system account', function (): void {
    $system = Account::where('is_system', true)->first();

    initiate([
        'from_account_id' => $this->alice->id,
        'to_account_id' => $system->id,
        'amount' => 100,
        'idempotency_key' => 't-to-sys',
    ])->assertStatus(404);

    expect(Transfer::count())->toBe(0);
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->alice->id,
        'to_account_id' => $this->bob->id,
        'amount' => 100,
        'idempotency_key' => 't-401',
    ])->assertStatus(401);

    expect(Transfer::count())->toBe(0);
    Queue::assertNothingPushed();
});
