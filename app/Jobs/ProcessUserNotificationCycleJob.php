<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\NotificationOrchestratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessUserNotificationCycleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(NotificationOrchestratorService $orchestrator): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $orchestrator->runScheduledCycle($user);
    }
}

