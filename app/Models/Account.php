<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidv7s;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Account extends Model
{
    use HasFactory;
    use HasUuidv7s;

    protected $fillable = ['name', 'is_system'];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function getBalance(): int
    {
        return (int) DB::table('ledger_entries')
            ->where('account_id', $this->id)
            ->selectRaw(
                "COALESCE(SUM(amount * CASE direction WHEN 'CREDIT' THEN 1 ELSE -1 END), 0) AS balance"
            )
            ->value('balance');
    }
}
