<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->string('extraction_confidence')->nullable()->after('source');
            $table->boolean('balance_mismatch')->default(false)->after('extraction_confidence');
            $table->string('extraction_method')->nullable()->after('balance_mismatch');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_confidence',
                'balance_mismatch',
                'extraction_method',
            ]);
        });
    }
};
