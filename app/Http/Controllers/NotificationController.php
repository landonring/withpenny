<?php

namespace App\Http\Controllers;

use App\Jobs\SendWelcomeNotificationJob;
use App\Models\InAppNotification;
use App\Models\PushSubscription;
use App\Services\AnalyticsService;
use App\Services\Notifications\NotificationOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationOrchestratorService $orchestrator,
        private readonly AnalyticsService $analytics,
    )
    {
    }

    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) $user->notifications_enabled,
            'show_financial_data_in_notifications' => (bool) $user->show_financial_data_in_notifications,
            'timezone' => (string) ($user->timezone ?: config('app.timezone')),
            'vapid_public_key' => (string) config('services.webpush.public_key'),
            'history' => $this->historyPayload($user->inAppNotifications()->latest('sent_at')->limit(30)->get()),
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'show_financial_data_in_notifications' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->orchestrator->enable(
            $request->user(),
            (bool) ($validated['show_financial_data_in_notifications'] ?? false),
            $validated['timezone'] ?? null,
        );

        $this->analytics->track('notification_permission_granted', [], $user);
        SendWelcomeNotificationJob::dispatch($user->id)->delay(now()->addSeconds(60));

        return response()->json([
            'enabled' => (bool) $user->notifications_enabled,
            'show_financial_data_in_notifications' => (bool) $user->show_financial_data_in_notifications,
            'timezone' => (string) ($user->timezone ?: config('app.timezone')),
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->orchestrator->disable($request->user());

        return response()->json([
            'enabled' => (bool) $user->notifications_enabled,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:1024'],
        ]);

        PushSubscription::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint_hash' => hash('sha256', $validated['endpoint']),
            ],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh_key' => $validated['keys']['p256dh'],
                'auth_key' => $validated['keys']['auth'],
                'last_used_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:2048'],
        ]);

        $query = PushSubscription::query()->where('user_id', $request->user()->id);
        if (! empty($validated['endpoint'])) {
            $query->where('endpoint_hash', hash('sha256', $validated['endpoint']));
        }
        $query->delete();

        return response()->json(['ok' => true]);
    }

    public function history(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->integer('limit', 30)));
        $notifications = $request->user()
            ->inAppNotifications()
            ->latest('sent_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'notifications' => $this->historyPayload($notifications),
        ]);
    }

    public function markRead(Request $request, InAppNotification $notification): JsonResponse
    {
        $updated = $this->orchestrator->markRead($request->user(), $notification);

        return response()->json([
            'notification' => $this->notificationPayload($updated),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->inAppNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function trackClick(Request $request, InAppNotification $notification): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $this->orchestrator->markClicked($notification);
        if ($notification->user) {
            $this->analytics->track('notification_clicked', [
                'type' => $notification->type,
            ], $notification->user);
        }

        return response()->json(['ok' => true]);
    }

    private function historyPayload($notifications): array
    {
        return $notifications
            ->map(fn (InAppNotification $notification): array => $this->notificationPayload($notification))
            ->values()
            ->all();
    }

    private function notificationPayload(InAppNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data_json ?? [],
            'sent_at' => optional($notification->sent_at)->toIso8601String(),
            'read_at' => optional($notification->read_at)->toIso8601String(),
        ];
    }
}
