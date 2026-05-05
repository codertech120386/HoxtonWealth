<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'transfer_id',
        'direction',
        'amount',
        'created_at',
    ];

    protected $casts = [
        'direction' => LedgerDirection::class,
        'amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
