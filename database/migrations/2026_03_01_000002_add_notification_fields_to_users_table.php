<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default(config('app.timezone', 'UTC'))->after('password');
            $table->boolean('notifications_enabled')->default(false)->after('timezone');
            $table->timestamp('notifications_enabled_at')->nullable()->after('notifications_enabled');
            $table->timestamp('welcome_notification_sent_at')->nullable()->after('notifications_enabled_at');
            $table->timestamp('last_notification_sent_at')->nullable()->after('welcome_notification_sent_at');
            $table->boolean('show_financial_data_in_notifications')->default(false)->after('last_notification_sent_at');
            $table->unsignedTinyInteger('notifications_sent_today_count')->default(0)->after('show_financial_data_in_notifications');
            $table->string('last_notification_window', 20)->nullable()->after('notifications_sent_today_count');
            $table->date('last_notification_date')->nullable()->after('last_notification_window');
            $table->timestamp('last_notification_opened_at')->nullable()->after('last_notification_date');
            $table->timestamp('last_micro_tip_sent_at')->nullable()->after('last_notification_opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'notifications_enabled',
                'notifications_enabled_at',
                'welcome_notification_sent_at',
                'last_notification_sent_at',
                'show_financial_data_in_notifications',
                'notifications_sent_today_count',
                'last_notification_window',
                'last_notification_date',
                'last_notification_opened_at',
                'last_micro_tip_sent_at',
            ]);
        });
    }
};

