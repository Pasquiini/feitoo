<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. Resume safely if a later CREATE TABLE failed.
        if (! Schema::hasColumn('processings', 'entitlement_source')) {
            Schema::table('processings', function (Blueprint $table) {
                $table->string('entitlement_source', 24)->nullable();
                $table->date('usage_date')->nullable();
                $table->unsignedInteger('usage_files_count')->default(0);
                $table->index(['user_id', 'usage_date', 'tool', 'entitlement_source'], 'processing_daily_usage');
            });
        }
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('processing_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 16)->default('stripe');
            $table->string('provider_checkout_session_id')->nullable()->unique();
            $table->string('provider_payment_intent_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('package_code', 32);
            $table->unsignedInteger('files_limit');
            $table->unsignedInteger('files_count');
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('checkout_closed_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id')->unique();
            $table->string('type', 64);
            $table->dateTime('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('purchases');
        Schema::table('processings', function (Blueprint $table) {
            $table->dropIndex('processing_daily_usage');
            $table->dropColumn(['entitlement_source', 'usage_date', 'usage_files_count']);
        });
    }
};
