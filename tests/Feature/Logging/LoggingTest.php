<?php

declare(strict_types=1);

use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Logging\JsonFormatter;
use App\Models\Account;
use App\Models\Transfer;
use App\Services\TransferProcessor;
use App\Services\TransferService;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

/**
 * Swap the Log facade with a Monolog logger that records every event in
 * memory and uses the production JsonFormatter, so tests inspect exactly
 * what would land in storage/logs/laravel.json.log.
 */
function captureLog(): TestHandler
{
    $handler = new TestHandler();
    $handler->setFormatter(new JsonFormatter());

    $monolog = new \Monolog\Logger('testing', [$handler]);
    Log::swap(new \Illuminate\Log\Logger($monolog));

    return $handler;
}

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->logs = captureLog();
});

it('emits one valid JSON object per log line', function (): void {
    Log::info('TestEvent', ['correlation_id' => 'abc', 'foo' => 'bar']);

    $records = $this->logs->getRecords();
    expect($records)->toHaveCount(1);

    $line = $this->logs->getFormatter()->format($records[0]);
    expect($line)->toEndWith("\n");

    $decoded = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveKeys(['datetime', 'level', 'channel', 'message', 'correlation_id']);
    expect($decoded['message'])->toBe('TestEvent');
    expect($decoded['correlation_id'])->toBe('abc');
    expect($decoded['context'])->toBe(['foo' => 'bar']);
});

it('promotes correlation_id, transfer_id, and account_id to top level', function (): void {
    Log::info('Promoted', [
        'correlation_id' => 'cid-1',
        'transfer_id' => 'tid-1',
        'account_id' => 'aid-1',
        'remaining' => 'in-context',
    ]);

    $line = trim($this->logs->getFormatter()->format($this->logs->getRecords()[0]));
    $decoded = json_decode($line, true);

    expect($decoded['correlation_id'])->toBe('cid-1');
    expect($decoded['transfer_id'])->toBe('tid-1');
    expect($decoded['account_id'])->toBe('aid-1');
    expect($decoded['context'])->toBe(['remaining' => 'in-context']);
});

it('a single HTTP request emits log lines that all share the same correlation_id', function (): void {
    $cid = (string) Str::uuid7();

    $this->withHeader('X-Api-Key', 'test-key')
        ->withHeader('X-Correlation-Id', $cid)
        ->postJson('/api/v1/accounts', ['name' => 'Logged Alice'])
        ->assertStatus(201);

    $records = $this->logs->getRecords();
    expect($records)->not->toBeEmpty();

    $accountCreated = collect($records)->first(
        fn ($r) => $r->message === 'AccountCreated',
    );
    expect($accountCreated)->not->toBeNull();
    expect($accountCreated->context['correlation_id'])->toBe($cid);
});

it('propagates the originating correlation_id from HTTP request through to worker logs', function (): void {
    $cid = (string) Str::uuid7();

    app(TransferService::class)->deposit(
        userAccount: $this->alice = Account::create(['name' => 'alice']),
        amount: 5_000,
        idempotencyKey: 'fund-log',
        correlationId: $cid,
    );

    $bob = Account::create(['name' => 'bob']);
    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'log-t-1',
        'from_account_id' => $this->alice->id,
        'to_account_id' => $bob->id,
        'amount' => 1_000,
        'status' => TransferStatus::Pending,
    ]);

    // Reset capture so we only see logs from the worker side.
    $this->logs->clear();

    (new ProcessTransferJob($transfer->id, $cid))
        ->handle(app(TransferProcessor::class));

    $workerLogs = collect($this->logs->getRecords());
    expect($workerLogs)->not->toBeEmpty();

    foreach ($workerLogs as $record) {
        expect($record->context['correlation_id'])->toBe($cid);
        expect($record->context['transfer_id'])->toBe($transfer->id);
    }

    $completedLine = $workerLogs->first(fn ($r) => $r->message === 'Transfer completed');
    expect($completedLine)->not->toBeNull();
});

it('emits TransferFailed at warning level when a transfer is rejected for insufficient balance', function (): void {
    $alice = Account::create(['name' => 'alice']);
    $bob = Account::create(['name' => 'bob']);

    $transfer = Transfer::create([
        'type' => TransferType::Transfer,
        'idempotency_key' => 'log-fail',
        'from_account_id' => $alice->id,
        'to_account_id' => $bob->id,
        'amount' => 500,
        'status' => TransferStatus::Pending,
    ]);

    (new ProcessTransferJob($transfer->id, (string) Str::uuid7()))
        ->handle(app(TransferProcessor::class));

    $failed = collect($this->logs->getRecords())->first(fn ($r) => $r->message === 'TransferFailed');
    expect($failed)->not->toBeNull();
    expect($failed->level->getName())->toBe('WARNING');
});
