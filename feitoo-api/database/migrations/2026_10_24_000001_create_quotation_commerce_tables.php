<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_credit_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->string('package_code', 32);
            $t->unsignedInteger('amount');
            $t->char('currency', 3);
            $t->unsignedInteger('credits');
            $t->string('price_id');
            $t->string('status', 24)->default('pending');
            $t->string('session_id')->nullable()->unique();
            $t->string('payment_intent_id')->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('paid_at')->nullable();
            $t->unsignedInteger('refunded_amount')->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'idempotency_key'], 'quotation_order_request_unique');
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('quotation_credit_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->unique()->constrained('quotation_credit_orders')->restrictOnDelete();
            $t->unsignedInteger('granted');
            $t->unsignedInteger('reserved')->default(0);
            $t->unsignedInteger('consumed')->default(0);
            $t->unsignedInteger('revoked')->default(0);
            $t->dateTime('blocked_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'blocked_at', 'id']);
        });
        Schema::create('quotation_pro_buckets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $t->dateTime('period_start');
            $t->dateTime('period_end');
            $t->unsignedInteger('quota_limit');
            $t->unsignedInteger('reserved')->default(0);
            $t->unsignedInteger('consumed')->default(0);
            $t->timestamps();
            $t->unique(['subscription_id', 'period_start', 'period_end'], 'quotation_pro_period_unique');
        });
        Schema::table('quotation_generations', function (Blueprint $t) {
            $t->string('source', 12)->default('free');
            $t->foreignId('credit_grant_id')->nullable()->constrained('quotation_credit_grants')->restrictOnDelete();
            $t->foreignId('pro_bucket_id')->nullable()->constrained('quotation_pro_buckets')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE quotation_credit_grants ADD CONSTRAINT quotation_credit_balance_check CHECK (reserved + consumed + revoked <= granted)');
            DB::statement('ALTER TABLE quotation_pro_buckets ADD CONSTRAINT quotation_pro_balance_check CHECK (reserved + consumed <= quota_limit)');
            DB::statement("ALTER TABLE quotation_generations ADD CONSTRAINT quotation_source_check CHECK ((source = 'free' AND credit_grant_id IS NULL AND pro_bucket_id IS NULL) OR (source = 'credit' AND credit_grant_id IS NOT NULL AND pro_bucket_id IS NULL) OR (source = 'pro' AND pro_bucket_id IS NOT NULL AND credit_grant_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE quotation_generations DROP CHECK quotation_source_check');
        }
        Schema::table('quotation_generations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('credit_grant_id');
            $t->dropConstrainedForeignId('pro_bucket_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('quotation_pro_buckets');
        Schema::dropIfExists('quotation_credit_grants');
        Schema::dropIfExists('quotation_credit_orders');
    }
};
