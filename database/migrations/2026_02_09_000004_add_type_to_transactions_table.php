<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'type')) {
                $table->string('type', 20)->default('spending')->after('source');
                $table->index('type');
            }
        });

        if (Schema::hasColumn('transactions', 'type')) {
            \DB::table('transactions')
                ->whereNull('type')
                ->update(['type' => 'spending']);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'type')) {
                $table->dropIndex(['type']);
                $table->dropColumn('type');
            }
        });
    }
};
