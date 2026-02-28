<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AnalyticsService
{
    private const ALLOWED_EVENTS = [
        'user_registered',
        'user_logged_in',
        'plan_upgraded',
        'plan_downgraded',
        'plan_cancelled',
        'receipt_uploaded',
        'statement_uploaded',
        'reflection_generated',
        'chat_message_sent',
        'life_phase_selected',
    ];

    private const BLOCKED_KEYS = [
        'amount',
        'account',
        'routing',
        'card',
        'bank',
        'receipt',
        'image',
        'transaction',
        'balance',
        'income',
        'spending',
        'number',
    ];

    public function track(string $eventName, array $data = []): void
    {
        if (! in_array($eventName, self::ALLOWED_EVENTS, true)) {
            return;
        }

        $user = Auth::user();
        if (
            $user
            && $user->onboarding_mode
            && in_array($eventName, ['receipt_uploaded', 'statement_uploaded', 'reflection_generated', 'chat_message_sent'], true)
        ) {
            return;
        }

        $payload = [
            'user_id' => Auth::id(),
            'event_name' => $eventName,
            'event_data' => $this->encodeData($data),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            AnalyticsEvent::query()->insert($payload);
        } catch (\Throwable $e) {
            // Analytics should never block the main app flow.
        }
    }

    private function encodeData(array $data): ?string
    {
        $safe = $this->sanitize($data);
        if (empty($safe)) {
            return null;
        }
        return json_encode($safe);
    }

    private function sanitize(array $data): array
    {
        $safe = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $lower = Str::lower($key);
            foreach (self::BLOCKED_KEYS as $blocked) {
                if (Str::contains($lower, $blocked)) {
                    continue 2;
                }
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
