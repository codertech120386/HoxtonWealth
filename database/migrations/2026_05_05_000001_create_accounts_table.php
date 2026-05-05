<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // DB-enforced "exactly one system account" invariant.
        DB::statement(
            'CREATE UNIQUE INDEX accounts_one_system ON accounts (is_system) WHERE is_system = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
