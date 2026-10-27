<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('import_key');
            $table->char('import_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedSmallInteger('schema_version');
            // Encrypted JSON can expand beyond TEXT's 64 KiB on MySQL.
            $table->longText('payload');
            $table->string('logo_path')->nullable();
            $table->char('logo_checksum', 64)->nullable();
            $table->timestamps();
            $table->timestamp('expires_at')->index();
            $table->unique(['user_id', 'import_key']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_drafts');
    }
};
