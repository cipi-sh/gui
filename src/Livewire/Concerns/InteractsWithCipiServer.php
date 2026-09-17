<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;

trait InteractsWithCipiServer
{
    public ?int $serverId = null;

    public ?string $error = null;

    protected function currentServer(): ?CipiServer
    {
        $id = $this->serverId ?? session('cipi_gui_server_id');

        if (! $id) {
            return null;
        }

        return CipiServer::where('is_active', true)->find($id);
    }

    protected function client(): CipiApiClient
    {
        $server = $this->currentServer();

        if (! $server) {
            throw new CipiApiException('No server selected. Add a server first.', 400);
        }

        return CipiApiClient::for($server);
    }

    protected function handleApiError(CipiApiException $e): void
    {
        $message = $e->getMessage();
        if ($message === 'Server Error' || str_starts_with($message, 'API request failed with status 500')) {
            $message = 'Panel API error (HTTP 500). On the managed server run: cipi self-update && cipi api update';
        }
        $this->error = $message;
        $this->dispatch('notify', type: 'error', message: $message);
    }

    protected function appFlagIsTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['true', '1', 1], true);
    }

    /** @param  array<string, mixed>  $app */
    protected function normalizeApp(array $app): array
    {
        $app['custom'] = $this->appFlagIsTrue($app['custom'] ?? false);

        if (array_key_exists('basic_auth', $app)) {
            $app['basic_auth'] = $this->appFlagIsTrue($app['basic_auth']);
        }

        if (array_key_exists('force_https', $app)) {
            $app['force_https'] = $this->appFlagIsTrue($app['force_https']);
        }

        if (array_key_exists('suspended', $app)) {
            $app['suspended'] = $this->appFlagIsTrue($app['suspended']);
        }

        $engine = $app['engine'] ?? null;
        $app['engine'] = is_string($engine) && $engine !== '' ? $engine : null;

        $octane = $app['octane'] ?? null;
        if (is_string($octane) && $octane !== '') {
            $app['octane'] = $octane;
        } elseif ($this->appFlagIsTrue($octane)) {
            // Create accepts boolean true; list/show usually return "frankenphp".
            $app['octane'] = 'frankenphp';
        } else {
            $app['octane'] = null;
        }

        $octanePort = $app['octane_port'] ?? null;
        if ($octanePort !== null && $octanePort !== '' && is_numeric($octanePort)) {
            $app['octane_port'] = (int) $octanePort;
        } else {
            $app['octane_port'] = null;
        }

        $wwwRedirect = $app['www_redirect'] ?? null;
        $app['www_redirect'] = is_string($wwwRedirect) && $wwwRedirect !== '' ? $wwwRedirect : null;

        // Node apps (API 1.31+ / Cipi ≥ 5.4.0)
        $app['node'] = $this->appFlagIsTrue($app['node'] ?? false);
        $nodeMode = $app['node_mode'] ?? null;
        $app['node_mode'] = is_string($nodeMode) && $nodeMode !== '' ? $nodeMode : null;
        $nodeVersion = $app['node_version'] ?? null;
        $app['node_version'] = is_string($nodeVersion) && $nodeVersion !== '' ? $nodeVersion : null;

        // Whole-app redirect + routing rules (API 1.31+ / Cipi ≥ 5.3.1)
        $app['redirect'] = is_array($app['redirect'] ?? null) ? $app['redirect'] : null;
        $app['redirects'] = is_array($app['redirects'] ?? null) ? array_values($app['redirects']) : [];
        $app['proxies'] = is_array($app['proxies'] ?? null) ? array_values($app['proxies']) : [];

        return $app;
    }

    protected function nodeModeLabel(?string $mode): string
    {
        return match ($mode) {
            'spa' => 'SPA',
            'static' => 'Static',
            'ssr' => 'SSR',
            default => $mode ?: '—',
        };
    }

    /** @param  array<string, mixed>  $app */
    protected function isNodeApp(array $app): bool
    {
        return $this->appFlagIsTrue($app['node'] ?? false);
    }

    /** @param  array<string, mixed>  $app */
    protected function isLaravelApp(array $app): bool
    {
        return ! $this->appFlagIsTrue($app['custom'] ?? false) && ! $this->isNodeApp($app);
    }

    /** @param  array<string, mixed>  $app */
    protected function appKindLabel(array $app): string
    {
        if ($this->isNodeApp($app)) {
            return 'Node '.$this->nodeModeLabel($app['node_mode'] ?? null);
        }

        if ($this->appFlagIsTrue($app['custom'] ?? false)) {
            return 'Custom';
        }

        return 'Laravel';
    }

    protected function engineLabel(?string $engine): string
    {
        return match ($engine) {
            'pgsql' => 'PostgreSQL',
            'mariadb' => 'MariaDB',
            default => $engine ?: '—',
        };
    }

    protected function runtimeLabel(?string $octane, ?int $octanePort = null): string
    {
        if ($octane) {
            $label = 'Octane ('.$octane.')';
            if ($octanePort) {
                $label .= ' :'.$octanePort;
            }

            return $label;
        }

        return 'PHP-FPM';
    }

    protected function wwwRedirectLabel(?string $mode): string
    {
        return match ($mode) {
            'to-root' => 'www → apex',
            'from-root' => 'apex → www',
            default => 'None',
        };
    }

    /** @param  array<string, mixed>  $patch */
    protected function rememberAppPatch(string $appName, array $patch): void
    {
        $patches = session('cipi_gui_app_patches', []);
        $patches[$appName] = array_merge($patches[$appName] ?? [], $patch);
        session(['cipi_gui_app_patches' => $patches]);

        $this->dispatch('app-changed', name: $appName, patch: $patch);
    }

    /** @param  array<int, array<string, mixed>>  $apps */
    protected function applySessionAppPatches(array $apps): array
    {
        $patches = session('cipi_gui_app_patches', []);
        if ($patches === []) {
            return $apps;
        }

        $remaining = [];

        foreach ($apps as $i => $app) {
            $name = $app['app'] ?? '';
            if ($name === '' || ! isset($patches[$name])) {
                continue;
            }

            $patch = $patches[$name];
            $stillNeeded = false;

            foreach ($patch as $key => $value) {
                if (($app[$key] ?? null) !== $value) {
                    $stillNeeded = true;

                    break;
                }
            }

            if ($stillNeeded) {
                $apps[$i] = array_merge($app, $patch);
                $remaining[$name] = $patch;
            }
        }

        session(['cipi_gui_app_patches' => $remaining]);

        return $apps;
    }

    protected function ensureServerSelected(): void
    {
        if ($this->serverId) {
            return;
        }

        $fromSession = session('cipi_gui_server_id');
        if ($fromSession && CipiServer::where('is_active', true)->where('id', $fromSession)->exists()) {
            $this->serverId = (int) $fromSession;

            return;
        }

        $first = CipiServer::where('is_active', true)->orderBy('name')->first();
        if ($first) {
            $this->serverId = $first->id;
            session(['cipi_gui_server_id' => $first->id]);
        }
    }
}
