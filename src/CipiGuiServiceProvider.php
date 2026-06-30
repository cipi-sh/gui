<?php

namespace CipiGui;

use CipiGui\Console\Commands\SeedGuiUser;
use CipiGui\Http\Middleware\EnsureTwoFactorVerified;
use CipiGui\Services\CipiApiException;
use Illuminate\Auth\Middleware\Authenticate;
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
            ]);

            $this->publishes([
                __DIR__.'/../config/cipi-gui.php' => config_path('cipi-gui.php'),
            ], 'cipi-gui-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/cipi-gui'),
            ], 'cipi-gui-views');
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
