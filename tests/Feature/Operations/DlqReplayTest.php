<?php

declare(strict_types=1);

use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Jobs\ProcessTransferJob;
use App\Models\Account;
use App\Models\Transfer;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.api_keys', 'test-key');
    $this->seed(SystemAccountSeeder::class);
    $this->alice = Account::create(['name' => 'alice']);
    $this->bob = Account::create(['name' => 'bob']);
});

/**
 * Insert a row into failed_jobs that mirrors what Laravel writes when a
 * ProcessTransferJob exhausts its retries. queue:retry treats this row
 * exactly like the real thing.
 */
function fakeFailedJob(string $transferId, string $exception = 'RuntimeException: simulated failure'): string
{
    $job = new ProcessTransferJob($transferId, (string) Str::uuid7());
    $uuid = (string) Str::uuid();

    $payload = [
        'uuid' => $uuid,
        'displayName' => ProcessTransferJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => $job->tries,
        'data' => [
            'commandName' => ProcessTransferJob::class,
            'command' => serialize($job),
        ],
    ];

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode($payload),
        'exception' => $exception,
        'failed_at' => now(),
    ]);

    return $uuid;
}

function pendingTransfer(Account $from, Account $to, int $amount): Transfer
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

it('lists failed transfer jobs with their transfer_id', function (): void {
    $t1 = pendingTransfer($this->alice, $this->bob, 100);
    $t2 = pendingTransfer($this->alice, $this->bob, 200);

    fakeFailedJob($t1->id);
    fakeFailedJob($t2->id);

    Artisan::call('transfers:retry-failed');
    $output = Artisan::output();

    expect($output)->toContain($t1->id);
    expect($output)->toContain($t2->id);
});

it('reports cleanly when there are no failed transfer jobs', function (): void {
    Artisan::call('transfers:retry-failed');
    expect(Artisan::output())->toContain('No failed transfer jobs.');
});

it('re-queues a specific failed transfer by id', function (): void {
    $transfer = pendingTransfer($this->alice, $this->bob, 100);
    fakeFailedJob($transfer->id);

    expect(DB::table('failed_jobs')->count())->toBe(1);
    expect(DB::table('jobs')->count())->toBe(0);

    $exit = Artisan::call('transfers:retry-failed', ['transferId' => $transfer->id]);

    expect($exit)->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
});

it('returns a non-zero exit code when no failed job matches the supplied transfer id', function (): void {
    $unknownId = (string) Str::uuid7();

    $exit = Artisan::call('transfers:retry-failed', ['transferId' => $unknownId]);

    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain($unknownId);
});

it('end-to-end: a job that exhausts retries lands in DLQ and replay completes the transfer', function (): void {
    // Fund alice so the transfer would actually succeed if processed.
    app(\App\Services\TransferService::class)->deposit(
        userAccount: $this->alice,
        amount: 5_000,
        idempotencyKey: 'fund-dlq',
        correlationId: (string) Str::uuid7(),
    );

    $transfer = pendingTransfer($this->alice, $this->bob, 1_000);

    // Simulate exhausted retries by writing the failed_jobs row directly.
    fakeFailedJob($transfer->id, 'RuntimeException: transient DB error');

    // Replay it.
    Artisan::call('transfers:retry-failed', ['transferId' => $transfer->id]);

    // The job is now back in the queue. Run it via the framework.
    $jobRow = DB::table('jobs')->first();
    expect($jobRow)->not->toBeNull();

    // Pull the queued job off the database queue and execute it.
    $payload = json_decode($jobRow->payload, true);
    /** @var ProcessTransferJob $job */
    $job = unserialize($payload['data']['command']);
    $job->handle(app(\App\Services\TransferProcessor::class));

    expect($transfer->fresh()->status)->toBe(TransferStatus::Completed);
    expect($this->alice->getBalance())->toBe(4_000);
    expect($this->bob->getBalance())->toBe(1_000);
});
