<?php

namespace Database\Seeders;

use App\Models\AnalyticsEvent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;

class AnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = config('services.admin.email') ?: 'admin@example.com';
        $admin = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Penny Admin',
                'role' => 'admin',
                'last_login_at' => now()->subDays(1),
                'password' => 'password',
            ]
        );
        $admin->role = 'admin';
        $admin->last_login_at = $admin->last_login_at ?? now()->subDays(1);
        $admin->save();

        $users = User::factory()->count(25)->create()->each(function ($user) {
            $user->last_login_at = now()->subDays(random_int(0, 45));
            $user->save();
        });

        $proMonthly = config('subscriptions.plans.pro.monthly.stripe_price') ?: 'price_pro_monthly';
        $premiumMonthly = config('subscriptions.plans.premium.monthly.stripe_price') ?: 'price_premium_monthly';

        $this->seedSubscriptions($users, $proMonthly, $premiumMonthly);
        $this->seedEvents($users);
    }

    private function seedSubscriptions($users, string $proPrice, string $premiumPrice): void
    {
        $pick = $users->random(min(8, $users->count()));

        foreach ($pick as $index => $user) {
            $plan = $index % 2 === 0 ? $proPrice : $premiumPrice;
            Subscription::create([
                'user_id' => $user->id,
                'type' => 'default',
                'stripe_id' => 'sub_'.Str::random(12),
                'stripe_status' => 'active',
                'stripe_price' => $plan,
                'quantity' => 1,
                'created_at' => now()->subDays(random_int(10, 120)),
                'updated_at' => now()->subDays(random_int(0, 9)),
            ]);
        }
    }

    private function seedEvents($users): void
    {
        $events = [
            'user_logged_in',
            'receipt_uploaded',
            'reflection_generated',
            'life_phase_selected',
            'plan_upgraded',
            'plan_cancelled',
        ];

        foreach ($users as $user) {
            $count = random_int(2, 8);
            for ($i = 0; $i < $count; $i++) {
                AnalyticsEvent::create([
                    'user_id' => $user->id,
                    'event_name' => $events[array_rand($events)],
                    'event_data' => [],
                    'created_at' => Carbon::now()->subDays(random_int(0, 90)),
                    'updated_at' => Carbon::now()->subDays(random_int(0, 90)),
                ]);
            }
        }
    }
}
