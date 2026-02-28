<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('submitted_ip')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
