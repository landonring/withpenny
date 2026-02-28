<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $hotFile = public_path('hot');
        if (! is_file($hotFile)) {
            return;
        }

        $requestHost = strtolower((string) request()->getHost());
        $hotUrl = trim((string) file_get_contents($hotFile));
        $hotHost = strtolower((string) parse_url($hotUrl, PHP_URL_HOST));

        $loopbackHosts = ['127.0.0.1', 'localhost', '::1', '0.0.0.0'];
        $isRequestLocal = in_array($requestHost, $loopbackHosts, true) || str_ends_with($requestHost, '.localhost');
        $isHotLocal = in_array($hotHost, $loopbackHosts, true);

        if ($isHotLocal && ! $isRequestLocal) {
            Vite::useHotFile(storage_path('framework/vite.hot.disabled'));

            return;
        }

        Vite::useHotFile($hotFile);
    }
}
