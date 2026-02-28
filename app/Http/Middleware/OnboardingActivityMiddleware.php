<?php

namespace App\Http\Middleware;

use App\Services\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnboardingActivityMiddleware
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->onboarding_mode) {
            $this->onboarding->maybeExpire($user, $request);
        }

        return $next($request);
    }
}
