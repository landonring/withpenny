<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_votes', function (Blueprint $table) {
            $table->tinyInteger('direction')->default(1)->after('voter_key');
        });
    }

    public function down(): void
    {
        Schema::table('feedback_votes', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};

