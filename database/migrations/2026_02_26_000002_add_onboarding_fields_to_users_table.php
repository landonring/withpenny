<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('onboarding_mode')->default(false)->after('last_login_at');
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('onboarding_mode');
            $table->boolean('onboarding_completed')->default(false)->after('onboarding_step');
            $table->timestamp('onboarding_started_at')->nullable()->after('onboarding_completed');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_mode',
                'onboarding_step',
                'onboarding_completed',
                'onboarding_started_at',
            ]);
        });
    }
};
