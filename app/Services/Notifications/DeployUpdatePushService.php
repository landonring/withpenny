<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\User;

class DeployUpdatePushService
{
    public function __construct(private readonly NotificationDeliveryService $delivery)
    {
    }

    /**
     * @return array{users:int,eligible:int,sent:int,push_sent:int,push_failed:int,skipped_version:int,skipped_rate:int}
     */
    public function dispatch(string $version, bool $dryRun = false): array
    {
        $stats = [
            'users' => 0,
            'eligible' => 0,
            'sent' => 0,
            'push_sent' => 0,
            'push_failed' => 0,
            'skipped_version' => 0,
            'skipped_rate' => 0,
        ];

        User::query()
            ->where('notifications_enabled', true)
            ->chunkById(200, function ($users) use (&$stats, $version, $dryRun): void {
                foreach ($users as $user) {
                    $stats['users']++;

                    $alreadyVersion = InAppNotification::query()
                        ->where('user_id', $user->id)
                        ->where('type', 'system')
                        ->where('subtype', 'update')
                        ->where('version', $version)
                        ->exists();

                    if ($alreadyVersion) {
                        $stats['skipped_version']++;
                        continue;
                    }

                    $recentUpdate = InAppNotification::query()
                        ->where('user_id', $user->id)
                        ->where('type', 'system')
                        ->where('subtype', 'update')
                        ->where('sent_at', '>=', now()->subHours((int) config('notifications.system.update_cooldown_hours', 24)))
                        ->exists();

                    if ($recentUpdate) {
                        $stats['skipped_rate']++;
                        continue;
                    }

                    $stats['eligible']++;

                    if ($dryRun) {
                        continue;
                    }

                    $notification = $this->delivery->deliver($user, [
                        'type' => 'system',
                        'subtype' => 'update',
                        'title' => 'Penny Updated',
                        'body' => 'New improvements are available.',
                        'deep_link' => '/app',
                        'version' => $version,
                        'priority' => (int) config('notifications.system.priorities.update', 98),
                        'data' => [
                            'version' => $version,
                        ],
                    ]);

                    $stats['sent']++;
                    if ($notification->push_status === 'sent') {
                        $stats['push_sent']++;
                    }
                    if ($notification->push_status === 'failed') {
                        $stats['push_failed']++;
                    }
                }
            });

        return $stats;
    }
}
