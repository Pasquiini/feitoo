<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_file_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('document_type', 16);
            $table->string('model', 8);
            $table->char('access_key', 44);
            $table->string('number', 44);
            $table->string('series', 44);
            // Preserve the parser's offset / date-only precision, without DB timezone conversion.
            $table->string('issued_at', 40);
            $table->boolean('issued_at_has_time');
            $table->string('issuer_document', 32)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('recipient_document', 32)->nullable();
            $table->string('recipient_name')->nullable();
            $table->unsignedInteger('items_count');
            $table->string('total_value', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_file_results');
    }
};
