<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our ULID doubles as the Idempotency-Key and metadata.local_id.
            $table->ulid('id')->primary();
            // Kept (user_id nulled) if the account is deleted, so cost records still
            // reconcile with Pixelforge's usage report.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Estimate at submission (nano-dollars). est_price_max_nano is held.
            $table->bigInteger('est_cost_min_nano');
            $table->bigInteger('est_cost_max_nano');
            $table->bigInteger('est_price_min_nano');
            $table->bigInteger('est_price_max_nano');

            // Actuals, filled from the provider's generation object.
            $table->json('rates')->nullable();
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();
            $table->bigInteger('price_nano')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('last_synced_at')->nullable();

            // Provider timestamps (UTC). completed_at decides the billing day.
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
