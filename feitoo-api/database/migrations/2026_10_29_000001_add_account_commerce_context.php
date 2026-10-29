<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_credit_orders', function (Blueprint $table) {
            $table->string('return_destination', 16)->default('editor');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('contract_amount')->nullable();
            $table->string('contract_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotation_credit_orders', fn (Blueprint $table) => $table->dropColumn('return_destination'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['contract_amount', 'contract_currency']));
    }
};
