<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_items', 'meter_id')) {
                $table->string('meter_id')->nullable()->after('stripe_price');
            }
            if (! Schema::hasColumn('subscription_items', 'meter_event_name')) {
                $table->string('meter_event_name')->nullable()->after('meter_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_items', 'meter_event_name')) {
                $table->dropColumn('meter_event_name');
            }
            if (Schema::hasColumn('subscription_items', 'meter_id')) {
                $table->dropColumn('meter_id');
            }
        });
    }
};
