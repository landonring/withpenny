<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

require_once __DIR__.'/../app/Support/helpers.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $cashierPath = trim((string) env('CASHIER_PATH', 'stripe'), '/');
        $stripeWebhookPath = ($cashierPath !== '' ? $cashierPath : 'stripe').'/webhook';

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'feature' => \App\Http\Middleware\EnsurePlanFeatureAccess::class,
            'onboarding.step' => \App\Http\Middleware\EnsureOnboardingStep::class,
            'onboarding.activity' => \App\Http\Middleware\OnboardingActivityMiddleware::class,
            'onboarding.readonly' => \App\Http\Middleware\BlockOnboardingWrites::class,
        ]);
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->validateCsrfTokens(except: [
            'api/login',
            'api/register',
            $stripeWebhookPath,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
