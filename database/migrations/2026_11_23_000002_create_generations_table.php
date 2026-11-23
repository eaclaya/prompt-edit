<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Financial history outlives the account so costs keep reconciling with /v1/usage.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider_id')->nullable()->unique(); // gen_…
            $table->string('idempotency_key')->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images')->default('[]');

            $table->string('status')->default('pending');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Rates fixed by Pixelforge at creation (USD per 1M tokens, decimal strings).
            $table->jsonb('rates')->nullable();

            // Estimated user price (after markup), in nanodollars.
            $table->bigInteger('est_min_nanos');
            $table->bigInteger('est_expected_nanos');
            $table->bigInteger('est_max_nanos');

            $table->jsonb('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']); // reconcile poller
            $table->index('completed_at'); // admin report, grouped by UTC day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
