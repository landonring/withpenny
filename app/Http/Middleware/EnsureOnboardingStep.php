<?php

namespace App\Http\Middleware;

use App\Services\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingStep
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function handle(Request $request, Closure $next, string $steps): Response
    {
        $user = $request->user();
        if (! $user || ! $user->onboarding_mode) {
            return $next($request);
        }

        $this->onboarding->maybeExpire($user, $request);
        $user->refresh();

        if (! $user->onboarding_mode) {
            return $next($request);
        }

        $expected = array_values(array_filter(array_map('trim', explode(',', $steps)), 'strlen'));
        $expectedSteps = array_map('intval', $expected);
        if (! $this->onboarding->expectedStepMismatch($user, ...$expectedSteps)) {
            return $next($request);
        }

        $redirectTo = $this->onboarding->pathForStep((int) $user->onboarding_step, $request);
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'This step is locked during onboarding.',
                'redirect_to' => $redirectTo,
                'onboarding_step' => (int) $user->onboarding_step,
            ], 409);
        }

        return redirect($redirectTo);
    }
}
