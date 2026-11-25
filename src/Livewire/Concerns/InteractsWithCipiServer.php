<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\AppEnv;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;

trait InteractsWithCipiServer
{
    /**
     * The server this component talks to. Set by the component itself — the
     * route, the session, a row action that passes its server through
     * useServer() — and locked: the browser cannot point an open page at
     * another server (4.2.0).
     */
    #[Locked]
    public ?int $serverId = null;

    public ?string $error = null;

    /** @var array<int, string> server id => "mysql"|"mariadb", for this request */
    protected array $mysqlServerMemo = [];

    protected function currentServer(): ?CipiServer
    {
        $id = $this->serverId ?? session('cipi_gui_server_id');

        if (! $id || ! $this->serverVisible((int) $id)) {
            return null;
        }

        return CipiServer::where('is_active', true)->find($id);
    }

    protected function panelUser(): ?object
    {
        return auth()->user();
    }

    /** The signed-in user may open this server (a level on it, or an app on it). */
    protected function serverVisible(int $id): bool
    {
        $ids = Access::visibleServerIds($this->panelUser());

        return $ids === null || in_array($id, $ids, true);
    }

    /** Every active server the signed-in user may open, by name. */
    protected function activeServers(): \Illuminate\Database\Eloquent\Collection
    {
        return Access::visibleServers($this->panelUser());
    }

    /** Active servers where the user may create apps and databases and manage the server. */
    protected function manageableServers(): \Illuminate\Database\Eloquent\Collection
    {
        $ids = Access::manageableServerIds($this->panelUser());

        return $ids === null ? $this->activeServers() : $this->activeServers()->whereIn('id', $ids)->values();
    }

    /** Ability on an app for the signed-in user; a refusal is reported in the page. */
    protected function appAllowed(string $ability, int $serverId, string $app): bool
    {
        if (Access::can($this->panelUser(), $ability, $serverId, $app)) {
            return true;
        }

        $this->denied();

        return false;
    }

    protected function serverAllowed(string $level, int $serverId): bool
    {
        $ok = $level === Access::MANAGE
            ? Access::canManageServer($this->panelUser(), $serverId)
            : Access::canViewServer($this->panelUser(), $serverId);

        if (! $ok) {
            $this->denied();
        }

        return $ok;
    }

    protected function denied(): void
    {
        $this->error = 'Your access does not include this action. Ask an admin of the panel.';
        $this->dispatch('notify', type: 'error', message: $this->error);
    }

    /**
     * Point this component's API calls at another connected server (a row action
     * in the fleet-wide apps list). 0 keeps the current one. The global "current
     * server" in the session is left alone.
     */
    protected function useServer(int $id): bool
    {
        if ($id === 0) {
            return true;
        }

        if (! $this->serverVisible($id) || ! CipiServer::where('is_active', true)->whereKey($id)->exists()) {
            $this->error = 'That server is no longer connected to the panel.';
            $this->dispatch('notify', type: 'error', message: $this->error);

            return false;
        }

        $this->serverId = $id;

        return true;
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
        $message = self::plainCliText($e->getMessage());

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

        // Horizon instead of queue workers (`cipi worker horizon enable`, or found at
        // a deploy). Left out by a server that does not say: unknown, not "no".
        foreach (['basic_auth', 'force_https', 'suspended', 'horizon'] as $flag) {
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

        // Databases linked to the app with `cipi db link` / `db create --app=` (API 1.39+ / Cipi ≥ 5.9.0).
        $linked = [];
        foreach (is_array($app['databases'] ?? null) ? $app['databases'] : [] as $db) {
            if (is_array($db) && is_string($db['name'] ?? null) && $db['name'] !== '') {
                $linked[] = ['name' => $db['name'], 'engine' => is_string($db['engine'] ?? null) && $db['engine'] !== '' ? $db['engine'] : 'mariadb'];
            }
        }
        $app['databases'] = $linked;

        foreach (['php', 'branch', 'repository', 'domain', 'created_at'] as $key) {
            $app[$key] = is_scalar($app[$key] ?? null) ? (string) $app[$key] : '';
        }

        // The Linux user that owns /home/<app> and signs in over SSH and SFTP. Cipi
        // names it after the app; an answer without `user` means the same. It ends
        // up in a command the page offers to copy: a user name, or nothing.
        $candidates = [$app['user'] ?? null, $app['app'] ?? null];
        $app['user'] = '';
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $candidate)) {
                $app['user'] = $candidate;
                break;
            }
        }

        // Environment label (API 1.36+); servers before it omit it, which means production.
        $app['env'] = AppEnv::normalize($app['env'] ?? null);

        return $app;
    }

    /** Badge class of an environment label: production muted, anything else highlighted. */
    protected function envBadgeClass(string $env): string
    {
        return AppEnv::badgeClass($env);
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

    /**
     * A message that came from the CLI, without its terminal colours: an API
     * that passes the output of a failed command on (the Access tab showed
     * "[1mBasic auth — app[0m ━━━ …" on Cipi 5.11–5.12) never puts escape
     * codes, or what is left of them, in front of the user.
     */
    public static function plainCliText(string $text): string
    {
        $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
        $text = preg_replace('/\[\d{1,3}(?:;\d{1,3})*m/', '', $text) ?? $text;
        $text = preg_replace('/━+/u', '', $text) ?? $text;

        return trim(preg_replace('/[ \t]*\n[ \t\n]*/', "\n", $text) ?? $text);
    }

    /**
     * The state `cipi basicauth status` printed before exiting 1 (Cipi 5.11–5.12,
     * under set -o pipefail), which an API before 1.43 answered as an error: the
     * Access tab reads it from there instead of showing the output as a failure.
     *
     * @return array{enabled: bool, users: list<string>}|null null when it is not that output
     */
    public static function basicAuthStateFromCliText(string $text): ?array
    {
        $plain = self::plainCliText($text);
        if (! preg_match('/Basic auth\s+—\s+\S+\s+Status\s+(enabled|disabled)\b/u', $plain, $m)) {
            return null;
        }
        $users = [];
        if (preg_match('/\bUsers\s+([A-Za-z0-9._-]+(?:,[A-Za-z0-9._-]+)*)/', $plain, $u)) {
            $users = array_values(array_filter(explode(',', $u[1]), fn ($v) => $v !== ''));
        }

        return ['enabled' => $m[1] === 'enabled', 'users' => $users];
    }

    /**
     * Port 3306 runs MariaDB, or MySQL where `cipi db switch mysql` replaced it
     * (Cipi 5.13.0). The engine keeps its id "mariadb"; GET /dbs/engines says
     * which server answers on it, and it is remembered per server for half a
     * day, so every label of the panel can say MySQL there.
     *
     * @param  list<array<string, mixed>>  $engines  the data.engines of GET /dbs/engines
     */
    protected function rememberEngineServers(?int $serverId, array $engines): void
    {
        if ($serverId === null) {
            return;
        }
        foreach ($engines as $engine) {
            if (is_array($engine) && ($engine['engine'] ?? null) === 'mariadb') {
                $server = ($engine['server'] ?? 'mariadb') === 'mysql' ? 'mysql' : 'mariadb';
                Cache::put('cipi-gui.mysql-server.'.$serverId, $server, now()->addHours(12));
                $this->mysqlServerMemo[$serverId] = $server;
            }
        }
    }

    /** "mysql" or "mariadb": what the engine "mariadb" runs on that server, as far as the panel knows. */
    protected function mysqlServerOf(?int $serverId = null): string
    {
        $serverId ??= ($this->serverId ?? (session('cipi_gui_server_id') !== null ? (int) session('cipi_gui_server_id') : null));
        if ($serverId === null) {
            return 'mariadb';
        }

        return $this->mysqlServerMemo[$serverId] ??= (Cache::get('cipi-gui.mysql-server.'.$serverId) === 'mysql' ? 'mysql' : 'mariadb');
    }

    /** Ask the server once, when nothing is remembered for it (the pages that show an app's database). */
    protected function ensureEngineServers(): void
    {
        $server = $this->currentServer();
        if ($server === null || Cache::has('cipi-gui.mysql-server.'.$server->id)) {
            return;
        }
        try {
            $this->rememberEngineServers($server->id, (array) ($this->client()->listDatabaseEngines()['engines'] ?? []));
            if (! Cache::has('cipi-gui.mysql-server.'.$server->id)) {
                // No MySQL engine there: nothing to ask again for half a day.
                Cache::put('cipi-gui.mysql-server.'.$server->id, 'mariadb', now()->addHours(12));
            }
        } catch (CipiApiException) {
            // An older API, a token without the ability, a server that did not
            // answer: MariaDB it stays, asked again in a while.
            Cache::put('cipi-gui.mysql-server.'.$server->id, 'mariadb', now()->addMinutes(30));
        }
    }

    protected function engineLabel(?string $engine, ?int $serverId = null): string
    {
        return match ($engine) {
            'pgsql' => 'PostgreSQL',
            'mariadb' => $this->mysqlServerOf($serverId) === 'mysql' ? 'MySQL' : 'MariaDB',
            'valkey' => 'Valkey',
            'meilisearch' => 'Meilisearch',
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

    /** Session key of an app's pending patch — the same name can exist on two servers. */
    protected function appPatchKey(string $appName, ?int $serverId = null): string
    {
        return ($serverId ?? $this->serverId ?? 0).':'.$appName;
    }

    /** @param  array<string, mixed>  $patch */
    protected function rememberAppPatch(string $appName, array $patch): void
    {
        $key = $this->appPatchKey($appName);
        $patches = session('cipi_gui_app_patches', []);
        $patches[$key] = array_merge($patches[$key] ?? [], $patch);
        session(['cipi_gui_app_patches' => $patches]);

        $this->dispatch('app-changed', name: $appName, patch: $patch, serverId: $this->serverId);
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
            $key = $this->appPatchKey($name, isset($app['server_id']) ? (int) $app['server_id'] : null);
            if ($name === '' || ! isset($patches[$key])) {
                continue;
            }

            $patch = $patches[$key];
            $stillNeeded = false;

            foreach ($patch as $key => $value) {
                if (($app[$key] ?? null) !== $value) {
                    $stillNeeded = true;

                    break;
                }
            }

            if ($stillNeeded) {
                $apps[$i] = array_merge($app, $patch);
                $remaining[$key] = $patch;
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

        if ($requested && $this->serverVisible($requested) && CipiServer::where('is_active', true)->whereKey($requested)->exists()) {
            $this->serverId = $requested;
            session(['cipi_gui_server_id' => $requested]);

            return;
        }

        if ($this->serverId && $this->serverVisible($this->serverId)) {
            return;
        }

        $fromSession = session('cipi_gui_server_id');
        if ($fromSession && $this->serverVisible((int) $fromSession) && CipiServer::where('is_active', true)->whereKey($fromSession)->exists()) {
            $this->serverId = (int) $fromSession;

            return;
        }

        $first = $this->activeServers()->first();
        if ($first) {
            $this->serverId = $first->id;
            session(['cipi_gui_server_id' => $first->id]);
        }
    }
}
