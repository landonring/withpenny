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
        $type = $this->normalizeType((string) ($payload['type'] ?? 'behavioral'));
        $subtype = trim((string) ($payload['subtype'] ?? 'tip')) ?: 'tip';
        $deepLink = (string) ($payload['deep_link'] ?? Arr::get($payload, 'route', '/app'));

        $notification = InAppNotification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'subtype' => $subtype,
            'title' => (string) ($payload['title'] ?? 'Penny'),
            'body' => (string) ($payload['body'] ?? ''),
            'deep_link' => $deepLink,
            'version' => Arr::get($payload, 'version'),
            'priority' => (int) ($payload['priority'] ?? 50),
            'push_status' => 'pending',
            'data_json' => $payload['data'] ?? null,
            'sent_at' => now(),
        ]);

        $sendPush = (bool) ($payload['send_push'] ?? true);
        if (! $sendPush || ! $user->notifications_enabled) {
            $notification->forceFill(['push_status' => 'skipped'])->save();
            $this->trackSentEvent($notification, $user);
            return $notification->refresh();
        }

        $clickUrl = $this->normalizePath($deepLink);
        $trackUrl = URL::temporarySignedRoute(
            'api.notifications.track-click',
            now()->addMonths(6),
            ['notification' => $notification->id]
        );

        $pushPayload = [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'subtype' => $notification->subtype,
            'title' => $notification->title,
            'body' => $notification->body,
            'icon' => url('/icons/penny-192.png'),
            'badge' => url('/icons/penny-192.png'),
            'tag' => $this->pushTag($notification),
            'renotify' => false,
            'click_url' => $clickUrl,
            'track_url' => $trackUrl,
            'data' => $notification->data_json ?? [],
        ];

        $result = $this->sendWebPushToUser($user, $pushPayload);

        $notification->forceFill([
            'push_status' => $result['status'],
            'push_sent_at' => $result['status'] === 'sent' ? now() : null,
            'push_failed_at' => $result['status'] === 'failed' ? now() : null,
            'push_error' => $result['reason'],
        ])->save();

        if ($result['status'] === 'failed') {
            $this->analytics->track('notification_push_failed', [
                'type' => $notification->type,
                'subtype' => $notification->subtype,
            ], $user);
        }

        $this->trackSentEvent($notification, $user);

        return $notification->refresh();
    }

    /**
     * @return array{status:string,sent:int,failed:int,reason:?string}
     */
    private function sendWebPushToUser(User $user, array $payload): array
    {
        $subscriptions = $user->pushSubscriptions()
            ->where('active', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return ['status' => 'skipped', 'sent' => 0, 'failed' => 0, 'reason' => 'No active push subscription.'];
        }

        if (! class_exists(WebPush::class) || ! class_exists(Subscription::class)) {
            Log::warning('Push notification skipped because web-push package is unavailable.');
            return ['status' => 'failed', 'sent' => 0, 'failed' => $subscriptions->count(), 'reason' => 'Web push package unavailable.'];
        }

        $publicKey = (string) config('services.webpush.public_key');
        $privateKey = (string) config('services.webpush.private_key');
        $subject = (string) config('services.webpush.subject');

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            Log::warning('Push notification skipped because VAPID keys are missing.');
            return ['status' => 'failed', 'sent' => 0, 'failed' => $subscriptions->count(), 'reason' => 'Missing VAPID credentials.'];
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
            return ['status' => 'failed', 'sent' => 0, 'failed' => $subscriptions->count(), 'reason' => 'Unable to initialize push client.'];
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

        $sent = 0;
        $failed = 0;

        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            /** @var PushSubscription|null $subscription */
            $subscription = $subscriptions->firstWhere('endpoint', $endpoint);
            if (! $subscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill([
                    'active' => true,
                    'last_used_at' => now(),
                ])->save();
                $sent++;
                continue;
            }

            $failed++;

            if ($report->isSubscriptionExpired()) {
                $subscription->forceFill(['active' => false])->save();
                continue;
            }

            Log::warning('Push send failed for endpoint '.$endpoint.': '.$report->getReason());
        }

        if ($sent > 0) {
            return ['status' => 'sent', 'sent' => $sent, 'failed' => $failed, 'reason' => null];
        }

        return ['status' => 'failed', 'sent' => 0, 'failed' => max($failed, $subscriptions->count()), 'reason' => 'Push delivery failed.'];
    }

    private function trackSentEvent(InAppNotification $notification, User $user): void
    {
        $this->analytics->track('notification_sent', [
            'type' => $notification->type,
            'subtype' => $notification->subtype,
        ], $user);

        if ($notification->type === 'behavioral') {
            $this->analytics->track('behavioral_notification_sent', [
                'subtype' => $notification->subtype,
            ], $user);
        }

        if ($notification->subtype === 'tip') {
            $this->analytics->track('tip_notification_sent', [], $user);
        }

        if (str_starts_with($notification->subtype ?? '', 'new_user_')) {
            $this->analytics->track('lifecycle_notification_sent', [
                'subtype' => $notification->subtype,
            ], $user);
        }

        if ($notification->subtype === 'inactivity_nudge') {
            $this->analytics->track('inactivity_notification_sent', [], $user);
        }

        if ($notification->subtype === 'update') {
            $this->analytics->track('update_notification_sent', [
                'version' => $notification->version,
            ], $user);
        }
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $clean = '/'.ltrim($path, '/');
        return rtrim((string) config('app.url'), '/').$clean;
    }

    private function normalizeType(string $type): string
    {
        return in_array($type, ['behavioral', 'system'], true) ? $type : 'behavioral';
    }

    private function pushTag(InAppNotification $notification): string
    {
        if ($notification->subtype === 'update' && $notification->version) {
            return 'penny-update-'.$notification->version;
        }

        return 'penny-'.$notification->type.'-'.$notification->subtype;
    }
}
