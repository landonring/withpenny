<?php

namespace App\Http\Controllers;

use App\Services\PlanUsageService;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function __construct(
        private readonly PlanUsageService $planUsage,
        private readonly AnalyticsService $analytics,
    )
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->planUsage->usageSummary($request->user()));
    }

    public function trackActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activity' => ['required', 'string', 'in:insight_viewed,reflection_completed'],
        ]);

        $event = $validated['activity'] === 'reflection_completed'
            ? 'reflection_generated'
            : 'insight_viewed';

        $this->analytics->track($event, [], $request->user());

        return response()->json(['ok' => true]);
    }
}
