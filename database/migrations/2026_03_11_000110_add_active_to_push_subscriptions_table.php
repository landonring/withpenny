<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('push_subscriptions', 'active')) {
                $table->boolean('active')->default(true)->after('auth_key')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('push_subscriptions', 'active')) {
                $table->dropColumn('active');
            }
        });
    }
};
