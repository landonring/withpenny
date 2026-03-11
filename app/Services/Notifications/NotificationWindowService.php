<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

class NotificationWindowService
{
    public function userNow(User $user, ?CarbonInterface $reference = null): CarbonImmutable
    {
        $timezone = $this->timezoneForUser($user);
        $source = $reference
            ? CarbonImmutable::instance($reference->toDateTime())
            : CarbonImmutable::now('UTC');

        return $source->setTimezone($timezone);
    }

    public function timezoneForUser(User $user): string
    {
        $candidate = trim((string) ($user->timezone ?? ''));

        if ($candidate === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            new DateTimeZone($candidate);
            return $candidate;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    public function currentWindow(CarbonInterface $localNow): ?string
    {
        $time = $localNow->format('H:i');
        $windows = (array) config('notifications.behavioral.windows', []);

        foreach ($windows as $key => $range) {
            $start = (string) ($range['start'] ?? '');
            $end = (string) ($range['end'] ?? '');
            if ($start === '' || $end === '') {
                continue;
            }

            if ($time >= $start && $time < $end) {
                return (string) $key;
            }
        }

        return null;
    }
}
