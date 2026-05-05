<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;

class LedgerService
{
    /**
     * Append a balanced pair of ledger entries: a DEBIT on $debitAccountId
     * and a CREDIT of the same amount on $creditAccountId, both linked to
     * the same parent $transferId. Must be called inside an outer DB transaction
     * so the pair commits atomically.
     */
    public function postDoubleEntry(
        string $debitAccountId,
        string $creditAccountId,
        string $transferId,
        int $amount,
    ): void {
        $now = now();

        LedgerEntry::create([
            'account_id' => $debitAccountId,
            'transfer_id' => $transferId,
            'direction' => LedgerDirection::Debit,
            'amount' => $amount,
            'created_at' => $now,
        ]);

        LedgerEntry::create([
            'account_id' => $creditAccountId,
            'transfer_id' => $transferId,
            'direction' => LedgerDirection::Credit,
            'amount' => $amount,
            'created_at' => $now,
        ]);
    }
}
