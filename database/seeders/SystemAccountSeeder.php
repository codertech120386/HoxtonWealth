<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class SystemAccountSeeder extends Seeder
{
    public function run(): void
    {
        Account::firstOrCreate(
            ['is_system' => true],
            ['name' => 'system'],
        );
    }
}
