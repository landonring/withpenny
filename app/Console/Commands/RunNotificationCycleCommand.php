<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUserNotificationCycleJob;
use App\Models\User;
use Illuminate\Console\Command;

class RunNotificationCycleCommand extends Command
{
    protected $signature = 'notifications:run {--sync : Process notifications synchronously in this process}';

    protected $description = 'Evaluate and send scheduled Penny notifications for eligible users.';

    public function handle(): int
    {
        $query = User::query()
            ->where('notifications_enabled', true)
            ->whereNotNull('notifications_enabled_at');

        $total = 0;
        $sync = (bool) $this->option('sync');

        $query->chunkById(200, function ($users) use (&$total, $sync): void {
            foreach ($users as $user) {
                if ($sync) {
                    ProcessUserNotificationCycleJob::dispatchSync($user->id);
                } else {
                    ProcessUserNotificationCycleJob::dispatch($user->id);
                }
                $total++;
            }
        });

        $this->info("Notification cycle queued for {$total} users.");

        return self::SUCCESS;
    }
}

