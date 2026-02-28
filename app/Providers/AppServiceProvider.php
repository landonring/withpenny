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

        $requestHost = strtolower((string) request()->getHost());
        $loopbackHosts = ['127.0.0.1', 'localhost', '::1', '0.0.0.0'];
        $isRequestLocal = in_array($requestHost, $loopbackHosts, true) || str_ends_with($requestHost, '.localhost');

        $assetUrl = (string) config('app.asset_url', '');
        if ($assetUrl !== '') {
            $assetHost = strtolower((string) parse_url($assetUrl, PHP_URL_HOST));
            if ($assetHost !== '' && in_array($assetHost, $loopbackHosts, true) && ! $isRequestLocal) {
                config(['app.asset_url' => null]);
            }
        }

        $hotFile = public_path('hot');
        if (! is_file($hotFile)) {
            return;
        }

        $hotUrl = trim((string) file_get_contents($hotFile));
        $hotHost = strtolower((string) parse_url($hotUrl, PHP_URL_HOST));

        $isHotLocal = in_array($hotHost, $loopbackHosts, true);

        if ($isHotLocal && ! $isRequestLocal) {
            Vite::useHotFile(storage_path('framework/vite.hot.disabled'));
        } else {
            Vite::useHotFile($hotFile);
        }

        // Always generate root-relative build asset paths in non-hot mode.
        // This avoids bad absolute hosts caused by proxy/CDN host mismatches.
        if (! Vite::isRunningHot()) {
            Vite::createAssetPathsUsing(static fn (string $path, $secure = null): string => '/'.ltrim($path, '/'));
        }
    }
}
