<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class DeployUpdatePushService
{
    /**
     * @return array{subscriptions:int,sent:int,failed:int,expired:int,skipped:bool,reason:?string}
     */
    public function dispatch(string $version, bool $dryRun = false): array
    {
        $stats = [
            'subscriptions' => 0,
            'sent' => 0,
            'failed' => 0,
            'expired' => 0,
            'skipped' => false,
            'reason' => null,
        ];

        try {
            $subscriptions = PushSubscription::query()
                ->whereHas('user', fn ($query) => $query->where('notifications_enabled', true))
                ->select(['id', 'endpoint', 'p256dh_key', 'auth_key'])
                ->get();
        } catch (\Throwable $error) {
            Log::warning('deploy_update_push_query_failed', [
                'message' => $error->getMessage(),
            ]);
            $stats['skipped'] = true;
            $stats['reason'] = 'Unable to query push subscriptions.';
            return $stats;
        }

        $stats['subscriptions'] = $subscriptions->count();

        if ($dryRun || $subscriptions->isEmpty()) {
            return $stats;
        }

        if (! class_exists(WebPush::class) || ! class_exists(Subscription::class)) {
            $stats['skipped'] = true;
            $stats['reason'] = 'Web push package is not installed.';
            return $stats;
        }

        $publicKey = (string) config('services.webpush.public_key');
        $privateKey = (string) config('services.webpush.private_key');
        $subject = (string) config('services.webpush.subject');

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            $stats['skipped'] = true;
            $stats['reason'] = 'VAPID credentials are missing.';
            return $stats;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        } catch (\Throwable $error) {
            $stats['skipped'] = true;
            $stats['reason'] = 'Unable to initialize WebPush: '.$error->getMessage();
            return $stats;
        }

        $payload = json_encode([
            'type' => 'deploy_update',
            'title' => 'Penny Updated',
            'body' => 'New improvements are available.',
            'icon' => url('/icons/penny-192.png'),
            'badge' => url('/icons/penny-192.png'),
            'tag' => 'penny-deploy-update-'.$version,
            'renotify' => false,
            'click_url' => rtrim((string) config('app.url'), '/').'/app',
            'data' => [
                'version' => $version,
            ],
        ]);

        $endpointIndex = [];
        /** @var PushSubscription $subscription */
        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'keys' => [
                            'p256dh' => $subscription->p256dh_key,
                            'auth' => $subscription->auth_key,
                        ],
                    ]),
                    $payload
                );
                $endpointIndex[(string) $subscription->endpoint] = $subscription;
            } catch (\Throwable $error) {
                $stats['failed']++;
                Log::warning('deploy_update_push_queue_failed', [
                    'subscription_id' => $subscription->id,
                    'message' => $error->getMessage(),
                ]);
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            /** @var PushSubscription|null $subscription */
            $subscription = $endpointIndex[$endpoint] ?? null;
            if (! $subscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();
                $stats['sent']++;
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
                $stats['expired']++;
                continue;
            }

            $stats['failed']++;
            Log::warning('deploy_update_push_send_failed', [
                'subscription_id' => $subscription->id,
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        return $stats;
    }
}
