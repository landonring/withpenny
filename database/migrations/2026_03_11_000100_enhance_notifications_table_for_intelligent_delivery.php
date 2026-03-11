<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'subtype')) {
                $table->string('subtype', 80)->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('notifications', 'deep_link')) {
                $table->string('deep_link', 255)->nullable()->after('body');
            }
            if (! Schema::hasColumn('notifications', 'version')) {
                $table->string('version', 64)->nullable()->after('deep_link')->index();
            }
            if (! Schema::hasColumn('notifications', 'priority')) {
                $table->unsignedTinyInteger('priority')->default(50)->after('version');
            }
            if (! Schema::hasColumn('notifications', 'push_status')) {
                $table->string('push_status', 20)->default('pending')->after('priority')->index();
            }
            if (! Schema::hasColumn('notifications', 'push_sent_at')) {
                $table->timestamp('push_sent_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('notifications', 'push_failed_at')) {
                $table->timestamp('push_failed_at')->nullable()->after('push_sent_at');
            }
            if (! Schema::hasColumn('notifications', 'push_error')) {
                $table->text('push_error')->nullable()->after('push_failed_at');
            }
        });

        DB::table('notifications')
            ->whereNull('subtype')
            ->update([
                'subtype' => DB::raw('type'),
            ]);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            foreach (['subtype', 'deep_link', 'version', 'priority', 'push_status', 'push_sent_at', 'push_failed_at', 'push_error'] as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
