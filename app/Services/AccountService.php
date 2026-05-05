<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditEventType;
use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountService
{
    public function create(string $name, string $correlationId): Account
    {
        $account = DB::transaction(function () use ($name, $correlationId): Account {
            $account = Account::create(['name' => $name]);

            AuditLog::create([
                'event_type' => AuditEventType::AccountCreated,
                'account_id' => $account->id,
                'correlation_id' => $correlationId,
                'payload' => ['name' => $name],
                'created_at' => now(),
            ]);

            return $account;
        });

        Log::info('AccountCreated', [
            'account_id' => $account->id,
            'name' => $name,
        ]);

        return $account;
    }
}
