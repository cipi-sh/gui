<?php

namespace CipiGui;

use CipiGui\Console\Commands\GuiVersion;
use CipiGui\Console\Commands\RefreshTheme;
use CipiGui\Console\Commands\SeedGuiUser;
use CipiGui\Http\Controllers\SiteIconController;
use CipiGui\Http\Middleware\EnsureAdmin;
use CipiGui\Http\Middleware\EnsureTwoFactorVerified;
use CipiGui\Http\Middleware\PreventSearchIndexing;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Theme;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
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
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'cipi');

        Livewire::component('cipi-gui.dashboard', \CipiGui\Livewire\Dashboard::class);
        Livewire::component('cipi-gui.servers', \CipiGui\Livewire\Servers::class);
        Livewire::component('cipi-gui.server-manage', \CipiGui\Livewire\ServerManage::class);
        Livewire::component('cipi-gui.apps', \CipiGui\Livewire\Apps::class);
        Livewire::component('cipi-gui.app-detail', \CipiGui\Livewire\AppDetail::class);
        Livewire::component('cipi-gui.databases', \CipiGui\Livewire\Databases::class);
        Livewire::component('cipi-gui.log-viewer', \CipiGui\Livewire\LogViewer::class);
        Livewire::component('cipi-gui.metrics', \CipiGui\Livewire\Metrics::class);
        Livewire::component('cipi-gui.notifications', \CipiGui\Livewire\Notifications::class);
        Livewire::component('cipi-gui.firewall', \CipiGui\Livewire\Firewall::class);
        Livewire::component('cipi-gui.settings', \CipiGui\Livewire\Settings::class);
        Livewire::component('cipi-gui.team', \CipiGui\Livewire\Team::class);
        Livewire::component('cipi-gui.infrastructure', \CipiGui\Livewire\Infrastructure::class);
        Livewire::component('cipi-gui.connector-accounts', \CipiGui\Livewire\ConnectorAccounts::class);
        Livewire::component('cipi-gui.passkey-list', \CipiGui\Livewire\PasskeyList::class);
        // Parts of the layout that follow the data they show (see the classes).
        Livewire::component('cipi-gui.header', \CipiGui\Livewire\Header::class);
        Livewire::component('cipi-gui.nav', \CipiGui\Livewire\Nav::class);

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
        $this->registerPersistentMiddleware();
        Route::aliasMiddleware('cipi-gui.admin', EnsureAdmin::class);
        Route::aliasMiddleware('cipi-gui.noindex', PreventSearchIndexing::class);
    }

    /**
     * Livewire runs a component's actions on its own endpoint, without the
     * middleware of the page it is on — except those listed here. The 2FA
     * check is one of them (4.6.0): a page left open since before 2FA was
     * required, or before its code was given, cannot go on acting.
     */
    private function registerPersistentMiddleware(): void
    {
        $livewire = Livewire::getFacadeRoot();

        if (is_object($livewire) && method_exists($livewire, 'addPersistentMiddleware')) {
            $livewire->addPersistentMiddleware([EnsureTwoFactorVerified::class]);
        }
    }

    private function registerRoutes(): void
    {
        $prefix = config('cipi-gui.route_prefix', '');

        Route::group([
            'prefix' => $prefix,
            'middleware' => ['web', 'cipi-gui.noindex'],
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        // Outside `web`: a list asks for hundreds of icons, none needs the session (nor may
        // rewrite it under a Livewire request); the signature from the apps list is the check.
        Route::group([
            'prefix' => $prefix,
            'middleware' => ['signed:relative', 'cipi-gui.noindex'],
        ], function () {
            Route::get('/site-icon', SiteIconController::class)->name('cipi-gui.site-icon');
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
