<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('source');
            $table->string('file_format', 16)->nullable()->after('file_name');
            $table->string('processing_status', 20)->default('completed')->after('file_format');
            $table->text('processing_error')->nullable()->after('processing_status');
            $table->longText('raw_extraction_cache')->nullable()->after('processing_error');
            $table->decimal('confidence_score', 5, 2)->nullable()->after('raw_extraction_cache');
            $table->unsignedInteger('flagged_rows')->default(0)->after('confidence_score');
            $table->unsignedInteger('total_rows')->default(0)->after('flagged_rows');
            $table->timestamp('processing_started_at')->nullable()->after('total_rows');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropColumn([
                'file_name',
                'file_format',
                'processing_status',
                'processing_error',
                'raw_extraction_cache',
                'confidence_score',
                'flagged_rows',
                'total_rows',
                'processing_started_at',
                'processing_completed_at',
            ]);
        });
    }
};
