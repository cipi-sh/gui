<?php

namespace CipiGui;

use CipiGui\Console\Commands\GuiVersion;
use CipiGui\Console\Commands\RefreshTheme;
use CipiGui\Console\Commands\SeedGuiUser;
use CipiGui\Http\Middleware\EnsureTwoFactorVerified;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Theme;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class CipiGuiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cipi-gui.php', 'cipi-gui');
    }

    public function boot(): void
    {
        Authenticate::redirectUsing(function ($request) {
            if ($request->expectsJson()) {
                return null;
            }

            return route('cipi-gui.login');
        });

        $this->purgePublishedViews();
        $this->clearViewCacheIfThemeUpdated();
        $this->registerExceptionHandlers();
        $this->registerRoutes();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cipi-gui');

        Livewire::component('cipi-gui.dashboard', \CipiGui\Livewire\Dashboard::class);
        Livewire::component('cipi-gui.servers', \CipiGui\Livewire\Servers::class);
        Livewire::component('cipi-gui.apps', \CipiGui\Livewire\Apps::class);
        Livewire::component('cipi-gui.app-detail', \CipiGui\Livewire\AppDetail::class);
        Livewire::component('cipi-gui.databases', \CipiGui\Livewire\Databases::class);
        Livewire::component('cipi-gui.job-monitor', \CipiGui\Livewire\JobMonitor::class);
        Livewire::component('cipi-gui.log-viewer', \CipiGui\Livewire\LogViewer::class);
        Livewire::component('cipi-gui.settings', \CipiGui\Livewire\Settings::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedGuiUser::class,
                RefreshTheme::class,
                GuiVersion::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/cipi-gui.php' => config_path('cipi-gui.php'),
            ], 'cipi-gui-config');
        }

        Route::aliasMiddleware('cipi-gui.2fa', EnsureTwoFactorVerified::class);
    }

    private function registerRoutes(): void
    {
        $prefix = config('cipi-gui.route_prefix', '');

        Route::group([
            'prefix' => $prefix,
            'middleware' => ['web'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /** Published views always override the package and block theme updates. */
    private function purgePublishedViews(): void
    {
        $dir = resource_path('views/vendor/cipi-gui');

        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    private function clearViewCacheIfThemeUpdated(): void
    {
        $hash = Theme::fingerprint();
        $key = 'cipi-gui.theme-css-hash';

        if ($hash === 'missing' || Cache::get($key) === $hash) {
            return;
        }

        try {
            Artisan::call('view:clear');
        } catch (\Throwable) {
            // Host may not be fully bootstrapped yet.
        }

        Cache::forever($key, $hash);
    }

    private function registerExceptionHandlers(): void
    {
        $this->app->booted(function () {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

            if (method_exists($handler, 'renderable')) {
                $handler->renderable(function (CipiApiException $e, $request) {
                    if ($request->expectsJson() || $request->header('X-Livewire')) {
                        return response()->json([
                            'error' => $e->getMessage(),
                            'status' => $e->getStatusCode(),
                            'details' => $e->getDetails(),
                        ], $e->getStatusCode() >= 400 ? $e->getStatusCode() : 500);
                    }

                    return null;
                });
            }
        });
    }
}
