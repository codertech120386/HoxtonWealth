<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LedgerDirection;
use App\Enums\TransferStatus;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Verify double-entry invariants across the ledger. Exits non-zero on drift.';

    public function handle(): int
    {
        $this->info('Reconciling ledger…');
        $this->newLine();

        $this->printStats();
        $this->newLine();

        $checks = [
            'Per-transfer double-entry' => $this->checkPerTransferDoubleEntry(),
            'Global zero-sum' => $this->checkGlobalZeroSum(),
            'User account balances non-negative' => $this->checkNoNegativeUserBalances(),
        ];

        $hasViolation = false;
        foreach ($checks as $name => $violations) {
            if ($violations->isEmpty()) {
                $this->line("  <fg=green>✓</> {$name}: OK");

                continue;
            }
            $hasViolation = true;
            $this->line("  <fg=red>✗</> {$name}:");
            foreach ($violations as $v) {
                $this->line("      <fg=red>•</> {$v}");
            }
        }

        $this->newLine();

        if ($hasViolation) {
            $this->error('Ledger drift detected.');

            return self::FAILURE;
        }

        $this->info('Ledger is consistent.');

        return self::SUCCESS;
    }

    private function printStats(): void
    {
        $byStatus = Transfer::query()
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $totalTransfers = array_sum($byStatus);
        $totalLedger = LedgerEntry::count();

        $globalSum = (int) DB::table('ledger_entries')
            ->selectRaw("COALESCE(SUM(amount * CASE direction WHEN 'CREDIT' THEN 1 ELSE -1 END), 0) AS s")
            ->value('s');

        $this->line(sprintf('  Transfers:      %d (%s)', $totalTransfers, collect($byStatus)
            ->map(fn ($n, $s) => "{$s}={$n}")
            ->join(', ') ?: 'none'));
        $this->line("  Ledger entries: {$totalLedger}");
        $this->line("  Global signed sum: {$globalSum}");
    }

    private function checkPerTransferDoubleEntry(): Collection
    {
        $violations = collect();
        $byTransfer = LedgerEntry::all()->groupBy('transfer_id');

        foreach (Transfer::all() as $transfer) {
            /** @var Collection<int, LedgerEntry> $entries */
            $entries = $byTransfer->get($transfer->id, collect());

            if ($transfer->status === TransferStatus::Completed) {
                if ($entries->count() !== 2) {
                    $violations->push(sprintf(
                        'transfer %s (COMPLETED) has %d ledger rows (expected 2)',
                        $transfer->id,
                        $entries->count(),
                    ));

                    continue;
                }

                $debits = $entries->where('direction', LedgerDirection::Debit);
                $credits = $entries->where('direction', LedgerDirection::Credit);

                if ($debits->count() !== 1 || $credits->count() !== 1) {
                    $violations->push(sprintf(
                        'transfer %s has unbalanced direction split (debits=%d, credits=%d)',
                        $transfer->id,
                        $debits->count(),
                        $credits->count(),
                    ));

                    continue;
                }

                $debitAmount = $debits->first()->amount;
                $creditAmount = $credits->first()->amount;
                if ($debitAmount !== $creditAmount) {
                    $violations->push(sprintf(
                        'transfer %s debit (%d) ≠ credit (%d)',
                        $transfer->id,
                        $debitAmount,
                        $creditAmount,
                    ));
                }
            } else {
                if ($entries->count() !== 0) {
                    $violations->push(sprintf(
                        'transfer %s (status=%s) has %d ledger rows (expected 0)',
                        $transfer->id,
                        $transfer->status->value,
                        $entries->count(),
                    ));
                }
            }
        }

        return $violations;
    }

    private function checkGlobalZeroSum(): Collection
    {
        $sum = (int) DB::table('ledger_entries')
            ->selectRaw("COALESCE(SUM(amount * CASE direction WHEN 'CREDIT' THEN 1 ELSE -1 END), 0) AS s")
            ->value('s');

        if ($sum === 0) {
            return collect();
        }

        return collect(["global signed sum is {$sum} (expected 0)"]);
    }

    private function checkNoNegativeUserBalances(): Collection
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.name,
                   COALESCE(SUM(l.amount * CASE l.direction WHEN 'CREDIT' THEN 1 ELSE -1 END), 0) AS balance
            FROM accounts a
            LEFT JOIN ledger_entries l ON l.account_id = a.id
            WHERE a.is_system = false
            GROUP BY a.id, a.name
            HAVING COALESCE(SUM(l.amount * CASE l.direction WHEN 'CREDIT' THEN 1 ELSE -1 END), 0) < 0
        SQL);

        return collect($rows)->map(fn ($r): string => sprintf(
            'user account %s (%s) has negative balance: %d',
            $r->id,
            $r->name,
            $r->balance,
        ));
    }
}
