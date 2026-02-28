<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeatureAccess
{
    public function __construct(private readonly SubscriptionAccessService $subscriptionAccess)
    {
    }

    public function handle(Request $request, Closure $next, string $requiredPlan = 'pro', string $feature = 'this feature'): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->subscriptionAccess->allows($user, $requiredPlan)) {
            return $next($request);
        }

        $label = $this->subscriptionAccess->label($requiredPlan);

        return response()->json([
            'title' => 'A little more clarity.',
            'message' => "This feature is available on {$label}. Penny keeps things simple, but sometimes a little guidance goes a long way.",
            'required_plan' => $requiredPlan,
            'feature' => $feature,
        ], 402);
    }
}
