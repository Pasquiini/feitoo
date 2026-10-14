<?php

use App\Enums\ProcessingFileStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processings', function (Blueprint $table) {
            $table->unsignedInteger('processed_files_count')->default(0);
            $table->unsignedInteger('successful_files_count')->default(0);
            $table->unsignedInteger('failed_files_count')->default(0);
        });
        Schema::table('processing_files', function (Blueprint $table) {
            $table->string('status', 24)->default(ProcessingFileStatus::Pending->value);
            $table->timestamp('processed_at')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('error_message')->nullable();
            $table->index(['processing_id', 'kind', 'status']);
        });
        DB::table('processing_files')->whereNotNull('removed_at')->update(['status' => ProcessingFileStatus::Removed->value]);
    }

    public function down(): void
    {
        Schema::table('processing_files', function (Blueprint $table) {
            $table->dropIndex(['processing_id', 'kind', 'status']);
            $table->dropColumn(['status', 'processed_at', 'error_code', 'error_message']);
        });
        Schema::table('processings', function (Blueprint $table) {
            $table->dropColumn(['processed_files_count', 'successful_files_count', 'failed_files_count']);
        });
    }
};
