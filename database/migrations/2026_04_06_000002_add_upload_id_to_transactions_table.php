<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'upload_id')) {
                $table->foreignId('upload_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('bank_statement_uploads')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'upload_id')) {
                $table->dropConstrainedForeignId('upload_id');
            }
        });
    }
};
