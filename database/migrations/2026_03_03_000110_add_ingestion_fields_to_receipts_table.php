<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('completed')->after('scanned_at');
            $table->text('processing_error')->nullable()->after('processing_status');
            $table->longText('extracted_data')->nullable()->after('processing_error');
            $table->decimal('confidence_score', 5, 2)->nullable()->after('extracted_data');
            $table->boolean('flagged')->default(false)->after('confidence_score');
            $table->string('category_suggestion')->nullable()->after('flagged');
            $table->decimal('category_confidence', 5, 2)->nullable()->after('category_suggestion');
            $table->timestamp('processing_started_at')->nullable()->after('category_confidence');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
            $table->timestamp('reviewed_at')->nullable()->after('processing_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'processing_error',
                'extracted_data',
                'confidence_score',
                'flagged',
                'category_suggestion',
                'category_confidence',
                'processing_started_at',
                'processing_completed_at',
                'reviewed_at',
            ]);
        });
    }
};
