<?php

namespace App\Http\Middleware;

use App\Services\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockOnboardingWrites
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->onboarding_mode) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This action is disabled during guided onboarding.',
            'redirect_to' => $this->onboarding->pathForStep((int) $user->onboarding_step, $request),
            'onboarding_step' => (int) $user->onboarding_step,
        ], 409);
    }
}
