<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Exceptions\InsufficientBalanceException;
use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\Transfer;
use App\Services\TransferProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTransferJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $transferId,
        public readonly string $correlationId,
    ) {}

    public function handle(TransferProcessor $processor): void
    {
        Log::withContext([
            'correlation_id' => $this->correlationId,
            'transfer_id' => $this->transferId,
        ]);

        $transfer = Transfer::find($this->transferId);

        if ($transfer === null) {
            Log::warning('ProcessTransferJob: transfer not found, dropping');

            return;
        }

        if ($transfer->status === TransferStatus::Completed
            || $transfer->status === TransferStatus::Failed) {
            Log::info('ProcessTransferJob: transfer is already terminal, skipping');

            return;
        }

        try {
            $processor->process($transfer, $this->correlationId);
            Log::info('Transfer completed');
        } catch (InsufficientBalanceException $e) {
            $this->markFailed($transfer, $e->getMessage());
            $this->fail($e);
        } catch (Throwable $e) {
            // Unexpected failure (DB outage, deadlock, etc.) — bubble out so
            // Laravel applies the retry policy. The inner DB::transaction has
            // already rolled back any partial work.
            Log::warning('Transfer processing exception, will retry', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function markFailed(Transfer $transfer, string $reason): void
    {
        DB::transaction(function () use ($transfer, $reason): void {
            $transfer->refresh();
            $transfer->update([
                'status' => TransferStatus::Failed,
                'error_reason' => $reason,
                'attempts' => max(1, $this->attempts()),
            ]);

            AuditLog::create([
                'event_type' => AuditEventType::TransferFailed,
                'transfer_id' => $transfer->id,
                'correlation_id' => $this->correlationId,
                'payload' => [
                    'reason' => $reason,
                    'attempts' => max(1, $this->attempts()),
                ],
                'created_at' => now(),
            ]);
        });
    }
}
