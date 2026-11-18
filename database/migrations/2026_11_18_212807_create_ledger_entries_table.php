<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            // Financial history outlives account deletion; generations are never deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            // Signed: credits positive, debits negative. Balance = SUM(amount_nano).
            $table->bigInteger('amount_nano');
            // What Pixelforge charged us; set on charge entries only.
            $table->bigInteger('provider_cost_nano')->nullable();
            // Dedupes grants and purchases (e.g. a double-clicked "Buy" button).
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('description')->nullable();
            // For charges, the provider's completed_at: the day Pixelforge bills.
            $table->timestamp('occurred_at');
            $table->timestamps();

            // The exactly-once guarantee: one hold, one release, one charge per
            // generation. NULL generation_ids (grants, purchases) never collide.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
