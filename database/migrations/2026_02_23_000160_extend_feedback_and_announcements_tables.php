<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_items', function (Blueprint $table) {
            $table->boolean('comments_locked')->default(false)->after('status');
            $table->text('admin_response')->nullable()->after('description');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('tags');
        });

        Schema::table('feedback_items', function (Blueprint $table) {
            $table->dropColumn(['comments_locked', 'admin_response']);
        });
    }
};
