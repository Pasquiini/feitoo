<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('password')->nullable()->change();
        });
        // Legacy anonymous records stay ownerless and purgeable.
        Schema::table('processings', function (Blueprint $table) {
            $table->dropColumn('anonymous_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('processings', function (Blueprint $table) {
            $table->char('anonymous_token_hash', 64)->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
        // Keep nullable passwords: never fabricate passwords for Google-only users on rollback.
    }
};
