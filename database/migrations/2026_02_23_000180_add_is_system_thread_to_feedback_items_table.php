<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->boolean('is_system_thread')->default(false)->after('comments_locked');
            $table->index(['is_system_thread', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->dropIndex('feedback_items_is_system_thread_status_index');
            $table->dropColumn('is_system_thread');
        });
    }
};

