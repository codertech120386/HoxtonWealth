<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessTransferJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetryFailedTransferCommand extends Command
{
    protected $signature = 'transfers:retry-failed {transferId? : Transfer UUID to re-queue. Omit to list all failed transfer jobs.}';

    protected $description = 'List or replay ProcessTransferJob entries that landed in the failed_jobs DLQ table.';

    public function handle(): int
    {
        $entries = $this->loadFailedTransferJobs();
        $targetId = $this->argument('transferId');

        if ($targetId === null) {
            return $this->renderList($entries);
        }

        $match = $entries->firstWhere('transfer_id', $targetId);
        if ($match === null) {
            $this->error("No failed transfer job found for transfer_id={$targetId}.");

            return self::FAILURE;
        }

        $this->info("Re-queuing failed job {$match['failed_jobs_uuid']} for transfer {$targetId}");
        Artisan::call('queue:retry', ['id' => [$match['failed_jobs_uuid']]]);
        $this->line(rtrim(Artisan::output()));

        return self::SUCCESS;
    }

    /**
     * Read failed_jobs and pull out only ProcessTransferJob rows, decoding
     * the parent transfer_id from the payload for human-friendly listing.
     */
    private function loadFailedTransferJobs(): \Illuminate\Support\Collection
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->get()
            ->map(function ($row): ?array {
                $payload = json_decode((string) $row->payload, true);
                if (($payload['displayName'] ?? null) !== ProcessTransferJob::class) {
                    return null;
                }

                try {
                    /** @var ProcessTransferJob $job */
                    $job = unserialize($payload['data']['command']);
                } catch (\Throwable) {
                    return null;
                }

                return [
                    'failed_jobs_id' => (int) $row->id,
                    'failed_jobs_uuid' => (string) $row->uuid,
                    'transfer_id' => $job->transferId,
                    'failed_at' => (string) $row->failed_at,
                    'exception' => Str::limit((string) strtok((string) $row->exception, "\n"), 100),
                ];
            })
            ->filter()
            ->values();
    }

    private function renderList(\Illuminate\Support\Collection $entries): int
    {
        if ($entries->isEmpty()) {
            $this->info('No failed transfer jobs.');

            return self::SUCCESS;
        }

        $this->table(
            ['failed_jobs.id', 'transfer_id', 'failed_at', 'exception'],
            $entries->map(fn (array $e): array => [
                $e['failed_jobs_id'],
                $e['transfer_id'],
                $e['failed_at'],
                $e['exception'],
            ])->all(),
        );

        $this->newLine();
        $this->line('Run <info>php artisan transfers:retry-failed {transferId}</info> to re-queue.');

        return self::SUCCESS;
    }
}
