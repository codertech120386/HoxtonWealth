<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'account_id',
        'transfer_id',
        'correlation_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'event_type' => AuditEventType::class,
        'payload' => 'array',
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
