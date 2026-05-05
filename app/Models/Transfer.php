<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasUuidv7s;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    use HasFactory;
    use HasUuidv7s;

    protected $fillable = [
        'type',
        'idempotency_key',
        'from_account_id',
        'to_account_id',
        'amount',
        'status',
        'error_reason',
        'attempts',
    ];

    protected $casts = [
        'type' => TransferType::class,
        'status' => TransferStatus::class,
        'amount' => 'integer',
        'attempts' => 'integer',
    ];

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
