<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_credit_orders', function (Blueprint $table) {
            // The previous gateway only accepted TEST. Preserve those orders and grants as TEST.
            $table->string('stripe_mode', 8)->default('test');
            $table->index(['user_id', 'stripe_mode', 'created_at'], 'quotation_orders_owner_mode_created');
        });
        // Preserve any historical LIVE issuance identified by its persisted provider Session.
        DB::table('quotation_credit_orders')->whereRaw('SUBSTR(session_id, 1, 8) = ?', ['cs_live_'])->update(['stripe_mode' => 'live']);
    }

    public function down(): void
    {
        Schema::table('quotation_credit_orders', function (Blueprint $table) {
            $table->dropIndex('quotation_orders_owner_mode_created');
            $table->dropColumn('stripe_mode');
        });
    }
};
