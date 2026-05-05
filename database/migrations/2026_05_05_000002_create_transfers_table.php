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
        Schema::create('transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('idempotency_key')->unique();
            $table->uuid('from_account_id');
            $table->uuid('to_account_id');
            $table->bigInteger('amount');
            $table->string('status');
            $table->text('error_reason')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();

            $table->foreign('from_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('to_account_id')->references('id')->on('accounts')->restrictOnDelete();

            $table->index('status');
            $table->index('from_account_id');
            $table->index('to_account_id');
            $table->index(['type', 'created_at']);
        });

        DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE transfers ADD CONSTRAINT transfers_type_check CHECK (type IN ('TRANSFER', 'DEPOSIT'))");
        DB::statement("ALTER TABLE transfers ADD CONSTRAINT transfers_status_check CHECK (status IN ('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
