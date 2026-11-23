<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger. users.balance_nanos is a cached sum of it.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // LedgerEntryType
            $table->bigInteger('amount_nanos'); // signed
            $table->bigInteger('balance_after_nanos');
            // Client token for purchases, so a double-click credits once.
            $table->string('idempotency_key')->nullable()->unique();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        // At most one hold / hold_release / charge per generation, enforced by the database.
        DB::statement('CREATE UNIQUE INDEX credit_transactions_generation_id_type_unique ON credit_transactions (generation_id, type) WHERE generation_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
