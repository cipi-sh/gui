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
        $message = $this->friendlyApiError($e);
        $this->error = $message;
        $this->dispatch('notify', type: 'error', message: $message);
    }

    protected function friendlyApiError(CipiApiException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            $message === 'Server Error' || str_starts_with($message, 'API request failed with status 500') => 'Panel API error (HTTP 500). On the managed server run: cipi self-update && cipi api update',
            $e->getStatusCode() === 401 => 'The server rejected the API token (401). Update it under Connections, or create a new one with cipi api token create.',
            $e->getStatusCode() === 403 && ($message === 'Invalid ability provided.' || str_contains(strtolower($message), 'ability')) => 'This token is missing the ability for that action (403). Recreate it with the full ability list from the docs.',
            $e->getStatusCode() === 403 && str_contains($message, 'IP not allowed') => $message.' — add this panel to the API IP whitelist on the server (cipi api ip-whitelist).',
            default => $message,
        };
    }

    /** True when the endpoint is missing (older API) or the token lacks the ability. */
    protected function isUnsupported(CipiApiException $e): bool
    {
        return in_array($e->getStatusCode(), [403, 404, 405, 501], true);
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
        $engine = $app['engine'] ?? null;
        $app['engine'] = is_string($engine) && $engine !== '' ? $engine : null;

        $app['node'] = $this->appFlagIsTrue($app['node'] ?? false);

        if (array_key_exists('custom', $app)) {
            $app['custom'] = $this->appFlagIsTrue($app['custom']);
        } else {
            // GET /api/apps does not return `custom`. Since Cipi 4.8 every Laravel app
            // has a database engine (migration 4.8.0 backfills it) and Node apps carry
            // `node`, so a non-Node app without an engine is a custom app.
            $app['custom'] = array_key_exists('engine', $app) && $app['engine'] === null && ! $app['node'];
        }

        foreach (['basic_auth', 'force_https', 'suspended'] as $flag) {
            if (array_key_exists($flag, $app)) {
                $app[$flag] = $this->appFlagIsTrue($app[$flag]);
            }
        }

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
        $app['octane_port'] = $octanePort !== null && $octanePort !== '' && is_numeric($octanePort) ? (int) $octanePort : null;

        $wwwRedirect = $app['www_redirect'] ?? null;
        $app['www_redirect'] = is_string($wwwRedirect) && $wwwRedirect !== '' ? $wwwRedirect : null;

        // Node apps (API 1.31+ / Cipi ≥ 5.4.0)
        $nodeMode = $app['node_mode'] ?? null;
        $app['node_mode'] = is_string($nodeMode) && $nodeMode !== '' ? $nodeMode : null;
        $nodeVersion = $app['node_version'] ?? null;
        $app['node_version'] = is_scalar($nodeVersion) && (string) $nodeVersion !== '' ? (string) $nodeVersion : null;

        // Whole-app redirect + routing rules (API 1.31+ / Cipi ≥ 5.3.1)
        $app['redirect'] = is_array($app['redirect'] ?? null) ? $app['redirect'] : null;
        $app['redirects'] = is_array($app['redirects'] ?? null) ? array_values($app['redirects']) : [];
        $app['proxies'] = is_array($app['proxies'] ?? null) ? array_values($app['proxies']) : [];
        $app['aliases'] = is_array($app['aliases'] ?? null) ? array_values($app['aliases']) : [];

        foreach (['php', 'branch', 'repository', 'domain', 'created_at'] as $key) {
            $app[$key] = is_scalar($app[$key] ?? null) ? (string) $app[$key] : '';
        }

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
    protected function isCustomApp(array $app): bool
    {
        return $this->appFlagIsTrue($app['custom'] ?? false) && ! $this->isNodeApp($app);
    }

    /** @param  array<string, mixed>  $app */
    protected function isLaravelApp(array $app): bool
    {
        return ! $this->appFlagIsTrue($app['custom'] ?? false) && ! $this->isNodeApp($app);
    }

    /** laravel | node | custom */
    protected function appKind(array $app): string
    {
        return match (true) {
            $this->isNodeApp($app) => 'node',
            $this->isCustomApp($app) => 'custom',
            default => 'laravel',
        };
    }

    /** @param  array<string, mixed>  $app */
    protected function appKindLabel(array $app): string
    {
        if ($this->isNodeApp($app)) {
            return 'Node '.$this->nodeModeLabel($app['node_mode'] ?? null);
        }

        if ($this->appFlagIsTrue($app['custom'] ?? false)) {
            return 'Custom PHP';
        }

        return 'Laravel';
    }

    /** @param  array<string, mixed>  $app */
    protected function appRuntimeLabel(array $app): string
    {
        if ($this->isNodeApp($app)) {
            return 'Node '.($app['node_version'] ?: 'default');
        }

        $php = ($app['php'] ?? '') !== '' ? 'PHP '.$app['php'] : 'PHP';

        return ($app['octane'] ?? null) ? $php.' · Octane' : $php.' · FPM';
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

    /**
     * Pick the server for this page: an explicit ?server= id (shareable links),
     * then the session, then the first active server.
     */
    protected function ensureServerSelected(?int $requested = null): void
    {
        $requested ??= is_numeric(request()->query('server')) ? (int) request()->query('server') : null;

        if ($requested && CipiServer::where('is_active', true)->whereKey($requested)->exists()) {
            $this->serverId = $requested;
            session(['cipi_gui_server_id' => $requested]);

            return;
        }

        if ($this->serverId) {
            return;
        }

        $fromSession = session('cipi_gui_server_id');
        if ($fromSession && CipiServer::where('is_active', true)->whereKey($fromSession)->exists()) {
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
