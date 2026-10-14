<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('kind', 16);
            $table->string('original_name')->nullable();
            $table->string('stored_name', 64);
            $table->string('storage_disk', 64);
            $table->string('storage_path');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_files');
    }
};
