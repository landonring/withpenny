<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationDeliveryService
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function deliver(User $user, array $payload): InAppNotification
    {
        $notification = InAppNotification::query()->create([
            'user_id' => $user->id,
            'type' => (string) ($payload['type'] ?? 'micro_tip'),
            'title' => (string) ($payload['title'] ?? 'Penny'),
            'body' => (string) ($payload['body'] ?? ''),
            'data_json' => $payload['data'] ?? null,
            'sent_at' => now(),
        ]);

        $clickPath = (string) Arr::get($payload, 'route', '/app');
        $trackUrl = URL::temporarySignedRoute(
            'api.notifications.track-click',
            now()->addMonths(6),
            ['notification' => $notification->id]
        );

        $pushPayload = [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'icon' => url('/icons/penny-192.png'),
            'badge' => url('/icons/penny-192.png'),
            'tag' => 'penny-user-'.$user->id,
            'renotify' => false,
            'click_url' => $this->normalizePath($clickPath),
            'track_url' => $trackUrl,
            'data' => $notification->data_json ?? [],
        ];

        $this->sendWebPushToUser($user, $pushPayload);
        $this->trackSentEvent($notification->type, $user);

        return $notification;
    }

    private function sendWebPushToUser(User $user, array $payload): void
    {
        $subscriptions = $user->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        if (! class_exists(WebPush::class) || ! class_exists(Subscription::class)) {
            Log::warning('Push notification skipped because web-push package is unavailable.');
            return;
        }

        $publicKey = (string) config('services.webpush.public_key');
        $privateKey = (string) config('services.webpush.private_key');
        $subject = (string) config('services.webpush.subject');

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            Log::warning('Push notification skipped because VAPID keys are missing.');
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to initialize WebPush client: '.$e->getMessage());
            return;
        }

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
                    json_encode($payload)
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to queue push notification: '.$e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            /** @var PushSubscription|null $subscription */
            $subscription = $subscriptions->firstWhere('endpoint', $endpoint);
            if (! $subscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
            } else {
                Log::warning('Push send failed for endpoint '.$endpoint.': '.$report->getReason());
            }
        }
    }

    private function trackSentEvent(string $type, User $user): void
    {
        $event = match ($type) {
            'welcome' => 'welcome_notification_sent',
            'monthly_snapshot' => 'monthly_notification_sent',
            'weekly_reflection' => 'weekly_notification_sent',
            'spending_shift' => 'shift_notification_sent',
            'celebration' => 'celebration_notification_sent',
            default => null,
        };

        if (! $event) {
            return;
        }

        $this->analytics->track($event, [
            'type' => $type,
        ], $user);
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = '/'.ltrim($path, '/');
        return rtrim((string) config('app.url'), '/').$clean;
    }
}
