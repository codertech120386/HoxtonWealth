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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_type');
            $table->uuid('account_id')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->uuid('correlation_id');
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('transfer_id')->references('id')->on('transfers')->nullOnDelete();

            $table->index(['transfer_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index('correlation_id');
        });

        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_type_check CHECK (event_type IN (".
            "'AccountCreated', 'DepositMade', ".
            "'TransferRequested', 'TransferProcessing', 'TransferCompleted', 'TransferFailed'".
            "))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
