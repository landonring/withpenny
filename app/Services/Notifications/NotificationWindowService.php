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
        $hour = (int) $localNow->format('G');

        if ($hour >= 7 && $hour < 11) {
            return 'morning';
        }

        if ($hour >= 11 && $hour < 14) {
            return 'midday';
        }

        if ($hour >= 14 && $hour < 19) {
            return 'afternoon';
        }

        return null;
    }
}

