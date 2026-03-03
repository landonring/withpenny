<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('confidence_score', 5, 2)->nullable()->after('type');
            $table->boolean('flagged')->default(false)->after('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'confidence_score',
                'flagged',
            ]);
        });
    }
};
