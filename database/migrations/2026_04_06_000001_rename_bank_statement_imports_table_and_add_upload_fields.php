<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_statement_imports') && ! Schema::hasTable('bank_statement_uploads')) {
            Schema::rename('bank_statement_imports', 'bank_statement_uploads');
        }

        Schema::table('bank_statement_uploads', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_statement_uploads', 'file_path')) {
                $table->string('file_path')->nullable()->after('file_name');
            }

            if (! Schema::hasColumn('bank_statement_uploads', 'status')) {
                $table->string('status', 20)->default('pending')->after('file_format');
            }

            if (! Schema::hasColumn('bank_statement_uploads', 'ai_fallback_used')) {
                $table->boolean('ai_fallback_used')->default(false)->after('confidence_score');
            }

            if (! Schema::hasColumn('bank_statement_uploads', 'detected_transactions')) {
                $table->unsignedInteger('detected_transactions')->default(0)->after('total_rows');
            }
        });

        if (Schema::hasColumn('bank_statement_uploads', 'processing_status') && Schema::hasColumn('bank_statement_uploads', 'status')) {
            DB::table('bank_statement_uploads')
                ->whereNull('status')
                ->update(['status' => DB::raw('processing_status')]);

            DB::table('bank_statement_uploads')
                ->where('status', '')
                ->update(['status' => DB::raw('processing_status')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_statement_uploads')) {
            Schema::table('bank_statement_uploads', function (Blueprint $table) {
                $drops = [];

                foreach (['file_path', 'status', 'ai_fallback_used', 'detected_transactions'] as $column) {
                    if (Schema::hasColumn('bank_statement_uploads', $column)) {
                        $drops[] = $column;
                    }
                }

                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }

        if (Schema::hasTable('bank_statement_uploads') && ! Schema::hasTable('bank_statement_imports')) {
            Schema::rename('bank_statement_uploads', 'bank_statement_imports');
        }
    }
};
