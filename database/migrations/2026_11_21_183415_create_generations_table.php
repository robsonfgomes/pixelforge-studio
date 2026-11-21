<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Deleting an account keeps its generations, so the admin report still matches Pixelforge's usage.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');
            $table->jsonb('estimate_price_table');
            $table->bigInteger('estimated_charge');
            $table->bigInteger('estimated_max_charge');
            $table->string('pixelforge_id')->nullable()->unique();
            $table->jsonb('rates')->nullable();
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost')->nullable();
            $table->bigInteger('charge')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('submission_uncertain_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
