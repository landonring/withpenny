<?php

use App\Services\AnalyticsService;

if (! function_exists('analytics_track')) {
    function analytics_track(string $eventName, array $data = []): void
    {
        try {
            app(AnalyticsService::class)->track($eventName, $data);
        } catch (\Throwable $e) {
            // ignore analytics failures
        }
    }
}
