<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_daily_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('reservation_date');
            $table->unsignedTinyInteger('reserved')->default(0);
            $table->unsignedTinyInteger('consumed')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'reservation_date']);
        });
        Schema::create('quotation_generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('processing_id')->unique()->constrained()->restrictOnDelete();
            // No draft FK: removing/editing a draft must not change this immutable issuance.
            $table->uuid('draft_uuid');
            $table->unsignedInteger('draft_revision');
            $table->foreignId('quota_id')->constrained('quotation_daily_quotas')->restrictOnDelete();
            $table->date('reservation_date');
            $table->string('consumption_status', 16)->default('reserved');
            $table->string('snapshot_path')->nullable();
            $table->char('snapshot_checksum', 64);
            $table->string('logo_path')->nullable();
            $table->char('logo_checksum', 64)->nullable();
            $table->string('renderer_version', 64);
            $table->unsignedInteger('attempt')->default(0);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('pending_expires_at')->index();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->unique(['draft_uuid', 'draft_revision']);
            $table->index(['user_id', 'consumption_status']);
        });
        Schema::create('quotation_generation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->foreignId('generation_id')->constrained('quotation_generations')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_generation_requests');
        Schema::dropIfExists('quotation_generations');
        Schema::dropIfExists('quotation_daily_quotas');
    }
};
