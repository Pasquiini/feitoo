<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_file_results', function (Blueprint $table) {
            $table->json('products_items')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('processing_file_results', fn (Blueprint $table) => $table->dropColumn('products_items'));
    }
};
