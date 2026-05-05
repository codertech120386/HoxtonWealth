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
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('account_id');
            $table->uuid('transfer_id');
            $table->string('direction');
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('transfer_id')->references('id')->on('transfers')->restrictOnDelete();

            $table->index(['account_id', 'created_at']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check CHECK (direction IN ('DEBIT', 'CREDIT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
