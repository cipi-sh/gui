<?php

namespace CipiGui\Services;

use CipiGui\Models\CipiServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CipiApiClient
{
    /**
     * Answers asked ahead with prefetch(), by "<server id> <path>[?query]" —
     * each is used by the next get() of that path, once.
     *
     * @var array<string, Response|\Throwable>
     */
    protected static array $prefetched = [];

    public function __construct(
        protected CipiServer $server,
    ) {}

    public static function for(CipiServer $server): self
    {
        return new self($server);
    }

    /**
     * GET the same path on many servers concurrently (dashboard, fleet-wide app list).
     *
     * @param  iterable<CipiServer>  $servers
     * @param  bool  $markErrors  false for an optional extra: a server that fails it keeps its connection state
     * @return array<int, array{data: mixed, error: ?string, status: ?int}> keyed by server id —
     *                                                                        `data` is the `data` of the answer, null when the server failed
     */
    public static function getForMany(iterable $servers, string $path, bool $markErrors = true): array
    {
        $servers = collect($servers)->keyBy('id');
        if ($servers->isEmpty()) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => $servers->map(
            fn (CipiServer $server) => self::configure($pool->as((string) $server->id), $server)->get($server->api_url.$path),
        )->all());

        $results = [];
        foreach ($servers as $id => $server) {
            $response = $responses[(string) $id] ?? null;
            $client = new self($server);

            if (! $response instanceof Response) {
                $message = $response instanceof \Throwable ? $response->getMessage() : 'No response';
                if ($markErrors) {
                    $server->markError('Connection failed: '.$message);
                }
                $results[$id] = ['data' => null, 'error' => 'Unable to connect to server. Check the URL and network.', 'status' => 503];

                continue;
            }

            // An optional extra (a column of a list): its failure says nothing
            // about the connection the main request has just established.
            if (! $markErrors && ! $response->successful()) {
                $results[$id] = ['data' => null, 'error' => 'HTTP '.$response->status(), 'status' => $response->status()];

                continue;
            }

            try {
                $results[$id] = ['data' => $client->handle($response)['data'] ?? [], 'error' => null, 'status' => null];
            } catch (CipiApiException $e) {
                $results[$id] = ['data' => null, 'error' => $e->getMessage(), 'status' => $e->getStatusCode()];
            }
        }

        return $results;
    }

    /**
     * GET /api/status on many servers concurrently (dashboard).
     *
     * @param  iterable<CipiServer>  $servers
     * @return array<int, array{status: ?array, error: ?string}> keyed by server id
     */
    public static function statusForMany(iterable $servers): array
    {
        $servers = collect($servers);
        $results = self::getForMany($servers, '/status');
        // The machine's own name comes with the status: kept, so every list can show it.
        foreach ($servers as $server) {
            if (is_array($results[$server->id]['data'] ?? null)) {
                $server->rememberHostname($results[$server->id]['data']);
            }
        }

        return array_map(
            fn (array $result) => ['status' => is_array($result['data']) ? $result['data'] : null, 'error' => $result['error']],
            $results,
        );
    }

    /**
     * GET /api/apps on many servers concurrently (the apps list across the fleet).
     *
     * @param  iterable<CipiServer>  $servers
     * @return array<int, array{apps: ?array, error: ?string, status: ?int}> keyed by server id
     */
    public static function appsForMany(iterable $servers): array
    {
        return array_map(
            fn (array $result) => ['apps' => is_array($result['data']) ? array_values($result['data']) : null, 'error' => $result['error'], 'status' => $result['status']],
            self::getForMany($servers, '/apps'),
        );
    }

    /**
     * Ask several GET paths of this server at once and keep the answers for the
     * calls that follow: the next get() of one of these paths takes its answer
     * from here instead of going out again. A page that needs five answers
     * before it can render then waits for the slowest of them, not for their
     * sum — each is a `sudo cipi …` on the server.
     *
     * Nothing else changes for the caller: an answer is used once, by the same
     * method that would have asked for it, and its errors surface there. Call
     * forgetPrefetched() when the block that needs them is over, so an answer
     * nobody took is not served to a later call.
     *
     * @param  list<string|array{0: string, 1?: array<string, mixed>}>  $paths  a path, or [path, query]
     */
    public function prefetch(array $paths): void
    {
        $requests = [];
        foreach ($paths as $entry) {
            [$path, $query] = is_array($entry) ? [(string) $entry[0], (array) ($entry[1] ?? [])] : [(string) $entry, []];
            $requests[$this->prefetchKey($path, $query)] = [$path, $query];
        }
        if (count($requests) < 2) {
            return; // one request gains nothing from a pool
        }

        $server = $this->server;
        try {
            $responses = Http::pool(function (Pool $pool) use ($requests, $server) {
                $out = [];
                foreach ($requests as $key => [$path, $query]) {
                    $out[] = self::configure($pool->as($key), $server)->get($server->api_url.$path, $query);
                }

                return $out;
            });
        } catch (\Throwable) {
            return; // the calls go out one by one, as they did before
        }

        foreach ($requests as $key => $_) {
            $response = $responses[$key] ?? null;
            if ($response instanceof Response || $response instanceof \Throwable) {
                self::$prefetched[$key] = $response;
            }
        }
    }

    /** Drop what prefetch() fetched and nobody used. */
    public static function forgetPrefetched(): void
    {
        self::$prefetched = [];
    }

    /** @param  array<string, mixed>  $query */
    protected function prefetchKey(string $path, array $query = []): string
    {
        return $this->server->id.' '.$path.($query !== [] ? '?'.http_build_query($query) : '');
    }

    // ── Apps ──────────────────────────────────────────────────────────

    public function listApps(): array
    {
        return $this->get('/apps')['data'] ?? [];
    }

    public function showApp(string $name): array
    {
        return $this->get("/apps/{$name}")['data'] ?? [];
    }

    public function createApp(array $payload): array
    {
        return $this->post('/apps', $payload);
    }

    public function editApp(string $name, array $payload): array
    {
        return $this->put("/apps/{$name}", $payload);
    }

    /**
     * Recreate GitHub/GitLab webhook (API 1.15+ / Cipi CLI ≥ 5.0.6).
     */
    public function recreateWebhook(string $name, bool $rotateSecret = false): array
    {
        return $this->post("/apps/{$name}/webhook/recreate", [
            'rotate_secret' => $rotateSecret,
        ]);
    }

    public function deleteApp(string $name): array
    {
        return $this->delete("/apps/{$name}");
    }

    /**
     * Take an app offline with an HTTP 503 maintenance page (async job).
     */
    public function suspendApp(string $name): array
    {
        return $this->post("/apps/{$name}/suspend");
    }

    /**
     * Bring a suspended app back online (async job).
     */
    public function unsuspendApp(string $name): array
    {
        return $this->post("/apps/{$name}/unsuspend");
    }

    /**
     * Restore the app home permission model (async job — API 1.31+ / Cipi ≥ 5.2.1).
     */
    public function fixPermissions(string $name): array
    {
        return $this->post("/apps/{$name}/fix-permissions");
    }

    public function basicAuthStatus(string $name): array
    {
        return $this->get("/apps/{$name}/basicauth")['data'] ?? [];
    }

    public function basicAuthEnable(string $name, array $payload = []): array
    {
        return $this->post("/apps/{$name}/basicauth/enable", $payload)['data'] ?? [];
    }

    public function basicAuthDisable(string $name): array
    {
        return $this->post("/apps/{$name}/basicauth/disable")['data'] ?? [];
    }

    /** cipi/agent routes left out of basic auth (API 1.41+): {mode, public, prefix}. */
    public function basicAuthAgent(string $name): array
    {
        return $this->get("/apps/{$name}/basicauth/agent")['data'] ?? [];
    }

    public function setBasicAuthAgent(string $name, string $mode): array
    {
        return $this->put("/apps/{$name}/basicauth/agent", ['mode' => $mode])['data'] ?? [];
    }

    public function appLogs(string $name, array $query = []): array
    {
        return $this->get("/apps/{$name}/logs", $query)['data'] ?? [];
    }

    // ── App .env (API 1.14+ / Cipi CLI ≥ 5.0.3) ───────────────────────

    public function showEnv(string $name): array
    {
        return $this->get("/apps/{$name}/env")['data'] ?? [];
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $unset
     * @param  list<string>|null  $order  every key once, applied after set/unset (API 1.43, Cipi 5.13.0)
     */
    public function updateEnv(string $name, array $set = [], array $unset = [], ?array $order = null): array
    {
        $payload = [];
        if ($set !== []) {
            $payload['set'] = $set;
        }
        if ($unset !== []) {
            $payload['unset'] = $unset;
        }
        if ($order !== null && $order !== []) {
            $payload['order'] = array_values($order);
        }

        return $this->put("/apps/{$name}/env", $payload)['data'] ?? [];
    }

    /**
     * The .env as it is — comments, blank lines, spaces (cipi/api 1.49.1,
     * Cipi 5.19.2; 404 or 501 before).
     *
     * @return array{app?: string, text?: string}
     */
    public function showEnvRaw(string $name): array
    {
        return $this->get("/apps/{$name}/env/raw")['data'] ?? [];
    }

    /**
     * Replace the whole .env with $text, written byte for byte.
     *
     * @return array{app?: string, text?: string} the file as it is now
     */
    public function updateEnvRaw(string $name, string $text): array
    {
        return $this->put("/apps/{$name}/env/raw", ['text' => $text])['data'] ?? [];
    }

    // ── Shared auth.json (Composer — not HTTP Basic Auth) ─────────────

    public function showAuthJson(string $name): array
    {
        return $this->get("/apps/{$name}/auth")['data'] ?? [];
    }

    public function createAuthJson(string $name, bool $force = false): array
    {
        $payload = $force ? ['force' => true] : [];

        return $this->post("/apps/{$name}/auth", $payload)['data'] ?? [];
    }

    public function updateAuthJson(string $name, string $rawJson): array
    {
        return $this->putRaw("/apps/{$name}/auth", $rawJson)['data'] ?? [];
    }

    public function deleteAuthJson(string $name): array
    {
        return $this->delete("/apps/{$name}/auth")['data'] ?? [];
    }

    // ── Artisan (async — Laravel apps only) ───────────────────────────

    public function runArtisan(string $name, string $command): array
    {
        return $this->post("/apps/{$name}/artisan", ['command' => $command]);
    }

    // ── Whitelisted app run (composer/npm/…) ──────────────────────────

    public function listRunCommands(): array
    {
        return $this->get('/run-commands')['data'] ?? [];
    }

    public function runAppCommand(string $name, string $command): array
    {
        return $this->post("/apps/{$name}/run", ['command' => $command]);
    }

    // ── Aliases ───────────────────────────────────────────────────────

    public function listAliases(string $app): array
    {
        return $this->get("/apps/{$app}/aliases")['data'] ?? [];
    }

    public function addAlias(string $app, string $alias): array
    {
        return $this->post("/apps/{$app}/aliases/{$alias}");
    }

    public function removeAlias(string $app, string $alias): array
    {
        return $this->delete("/apps/{$app}/aliases/{$alias}");
    }

    // ── Deploy ────────────────────────────────────────────────────────

    public function deploy(string $app): array
    {
        return $this->post("/apps/{$app}/deploy");
    }

    public function deployRollback(string $app): array
    {
        return $this->post("/apps/{$app}/deploy/rollback");
    }

    public function deployUnlock(string $app): array
    {
        return $this->post("/apps/{$app}/deploy/unlock");
    }

    /**
     * Hash-chained deploy ledger records, oldest first (API 1.31+ / Cipi ≥ 5.4.0).
     */
    public function deployAudit(string $app, int $days = 90): array
    {
        return $this->get("/apps/{$app}/deploy/audit", ['days' => $days])['data'] ?? [];
    }

    /**
     * Whether a deploy is running right now — whoever started it: the panel, `cipi
     * deploy`, a Git push through the webhook, a rollback — queued behind the
     * webhook's per-minute cron, or left interrupted; and how the last one ended
     * (`cipi deploy --status --json`, API 1.37+ / Cipi ≥ 5.8.4). $tail > 0 adds
     * the last lines of the deploy log.
     */
    public function deployStatus(string $app, int $tail = 0): array
    {
        return $this->get("/apps/{$app}/deploy/status", $tail > 0 ? ['tail' => $tail] : [])['data'] ?? [];
    }

    /**
     * Past deploys and rollbacks of an app, newest first, with their duration
     * (`cipi deploy --history`, API 1.39+ / Cipi ≥ 5.9.0) — from the deploy log
     * and its rotated files, so a deploy of last week still has one. Null when
     * the server does not have the endpoint or answers something else.
     *
     * @return list<array<string, mixed>>|null
     */
    public function deployHistory(string $app, int $limit = 100): ?array
    {
        $data = $this->get("/apps/{$app}/deploy/history", ['limit' => max(1, min(500, $limit))])['data'] ?? null;
        if (! is_array($data) || ! is_array($data['runs'] ?? null)) {
            return null;
        }

        return array_values(array_filter($data['runs'], 'is_array'));
    }

    /**
     * GET /api/deploys/last on many servers at once (API 1.39+ / Cipi ≥ 5.9.0):
     * every app's last finished deploy, for the apps list.
     *
     * @param  iterable<CipiServer>  $servers
     * @return array<int, array<string, array<string, mixed>|null>|null> server id → app → run (null: never deployed); null for a server without the endpoint
     */
    public static function lastDeploysForMany(iterable $servers): array
    {
        $out = [];
        foreach (self::getForMany($servers, '/deploys/last', markErrors: false) as $id => $result) {
            $out[$id] = is_array($result['data']) && $result['error'] === null ? $result['data'] : null;
        }

        return $out;
    }

    // ── Deploy config (structured deploy.php options — API 1.31+ / Cipi ≥ 5.0.3) ──

    public function showDeployConfig(string $app): array
    {
        return $this->get("/apps/{$app}/deploy-config")['data'] ?? [];
    }

    public function updateDeployConfig(string $app, array $payload): array
    {
        return $this->put("/apps/{$app}/deploy-config", $payload)['data'] ?? [];
    }

    // ── cipi.yml (API 1.35+ / Cipi ≥ 5.6.4) ───────────────────────────

    /**
     * `cipi yml plan <app> --json`: the cipi.yml of the live release, whether it is
     * valid, what `apply` would change, what blocks it, and the auto-apply flag.
     * `file` is null when the release has none; `valid` and `in_sync` are null when
     * there is nothing to compare. `env` (API 1.36+) is the app's environment label:
     * the server reads `cipi-<env>.yml` first and falls back to `cipi.yml`; null on
     * servers that do not report it.
     *
     * @return array{app: string, env: ?string, file: ?string, auto: bool, valid: ?bool, in_sync: ?bool, errors: list<string>, warnings: list<string>, changes: list<array{kind: string, summary: string}>, blockers: list<string>, notes: list<string>}
     */
    public function ymlStatus(string $app): array
    {
        $data = $this->get("/apps/{$app}/yml")['data'] ?? [];
        $data = is_array($data) ? $data : [];

        $lines = fn (mixed $list): array => is_array($list)
            ? array_values(array_map('strval', array_filter($list, fn ($l) => is_scalar($l) && (string) $l !== '')))
            : [];
        $flag = fn (mixed $value): ?bool => is_bool($value) ? $value : null;

        $changes = [];
        foreach (is_array($data['changes'] ?? null) ? $data['changes'] : [] as $change) {
            if (is_array($change) && is_scalar($change['summary'] ?? null) && (string) $change['summary'] !== '') {
                $changes[] = ['kind' => is_scalar($change['kind'] ?? null) ? (string) $change['kind'] : '', 'summary' => (string) $change['summary']];
            }
        }

        return [
            'app' => is_string($data['app'] ?? null) ? $data['app'] : $app,
            'env' => is_string($data['env'] ?? null) && trim($data['env']) !== '' ? trim($data['env']) : null,
            'file' => is_string($data['file'] ?? null) && $data['file'] !== '' ? $data['file'] : null,
            'auto' => ($data['auto'] ?? false) === true,
            'valid' => $flag($data['valid'] ?? null),
            'in_sync' => $flag($data['in_sync'] ?? null),
            'errors' => $lines($data['errors'] ?? null),
            'warnings' => $lines($data['warnings'] ?? null),
            'changes' => $changes,
            'blockers' => $lines($data['blockers'] ?? null),
            'notes' => $lines($data['notes'] ?? null),
        ];
    }

    /**
     * Apply the release's cipi.yml (async job `app-yml-apply`). The finished job's
     * `result` carries `outcome`, `applied`, `failed`, `failures`, `blockers`, `errors`.
     */
    public function ymlApply(string $app): array
    {
        return $this->post("/apps/{$app}/yml/apply");
    }

    /**
     * Apply cipi.yml after every successful deploy — CLI, panel and Git webhook.
     *
     * @return array{app?: string, auto?: bool, sudoers?: bool, file?: ?string}
     */
    public function ymlAutoEnable(string $app): array
    {
        return $this->post("/apps/{$app}/yml/auto/enable")['data'] ?? [];
    }

    /** @return array{app?: string, auto?: bool, sudoers?: bool, file?: ?string} */
    public function ymlAutoDisable(string $app): array
    {
        return $this->post("/apps/{$app}/yml/auto/disable")['data'] ?? [];
    }

    // ── App / path redirects (API 1.31+ / Cipi ≥ 5.3.1, sudoers ≥ 5.4.1) ──

    public function listRedirects(string $app): array
    {
        return $this->get("/apps/{$app}/redirects")['data'] ?? [];
    }

    /**
     * Set (or replace) the whole-app redirect: every hostname → target URL.
     */
    public function setAppRedirect(string $app, string $to, int $code = 301, bool $keepPath = true): array
    {
        return $this->put("/apps/{$app}/redirect", [
            'to' => $to,
            'code' => $code,
            'keep_path' => $keepPath,
        ])['data'] ?? [];
    }

    public function enableAppRedirect(string $app): array
    {
        return $this->post("/apps/{$app}/redirect/enable")['data'] ?? [];
    }

    public function disableAppRedirect(string $app): array
    {
        return $this->post("/apps/{$app}/redirect/disable")['data'] ?? [];
    }

    public function unsetAppRedirect(string $app): array
    {
        return $this->delete("/apps/{$app}/redirect")['data'] ?? [];
    }

    /**
     * Add (or update) a path redirect. A `from` ending in `/` is a prefix match.
     */
    public function addPathRedirect(string $app, string $from, string $to, int $code = 301, bool $keepPath = true): array
    {
        return $this->post("/apps/{$app}/redirects", [
            'from' => $from,
            'to' => $to,
            'code' => $code,
            'keep_path' => $keepPath,
        ])['data'] ?? [];
    }

    public function removePathRedirect(string $app, string $from): array
    {
        return $this->request('delete', "/apps/{$app}/redirects", data: ['from' => $from])['data'] ?? [];
    }

    // ── Prefix reverse proxies (API 1.31+ / Cipi ≥ 5.3.1, sudoers ≥ 5.4.1) ──

    public function listProxies(string $app): array
    {
        return $this->get("/apps/{$app}/proxies")['data'] ?? [];
    }

    public function addProxy(string $app, array $payload): array
    {
        return $this->post("/apps/{$app}/proxies", $payload)['data'] ?? [];
    }

    public function removeProxy(string $app, string $prefix): array
    {
        return $this->request('delete', "/apps/{$app}/proxies", data: ['prefix' => $prefix])['data'] ?? [];
    }

    // An app's own nginx directives (API 1.44+ / Cipi ≥ 5.14.0). Synchronous:
    // nginx is tested and reloaded before the answer; a refused text is a 422
    // whose `reasons` say why, and nothing has changed.

    public function showNginx(string $app): array
    {
        return $this->get("/apps/{$app}/nginx")['data'] ?? [];
    }

    public function updateNginx(string $app, string $config): array
    {
        return $this->put("/apps/{$app}/nginx", ['config' => $config])['data'] ?? [];
    }

    public function resetNginx(string $app): array
    {
        return $this->request('delete', "/apps/{$app}/nginx")['data'] ?? [];
    }

    // The nginx page cache of an app (API 1.47+ / Cipi ≥ 5.17.0): nginx keeps
    // the page PHP made and answers the next visitors without running PHP.
    // Not OPcache, which is always on. Synchronous; a refusal is a 422.

    /** supported, kind, enabled, active, ttl, size, clear_on_deploy, exclude, files, bytes, clear_command. */
    public function pageCache(string $app): array
    {
        return $this->get("/apps/{$app}/cache")['data'] ?? [];
    }

    /** `enabled` and, with it on, `ttl`, `size`, `clear_on_deploy`; what is left out stays. */
    public function setPageCache(string $app, array $payload): array
    {
        return $this->put("/apps/{$app}/cache", $payload)['data'] ?? [];
    }

    /**
     * The whole list of the app's own paths that are never cached (`/cart*`,
     * `/feed`); an empty one for none. With the cache off it waits for it.
     *
     * @param  list<string>  $paths
     */
    public function setPageCacheExclude(string $app, array $paths): array
    {
        return $this->put("/apps/{$app}/cache/exclude", ['exclude' => array_values($paths)])['data'] ?? [];
    }

    /** The cache after it, with `cleared` (pages deleted). */
    public function clearPageCache(string $app): array
    {
        return $this->post("/apps/{$app}/cache/clear")['data'] ?? [];
    }

    /**
     * Some pages: paths (/blog/post/), prefixes (/blog/*) or URLs of the app.
     * `purged` is the cached files deleted.
     *
     * @param  list<string>  $pages
     */
    public function purgePageCache(string $app, array $pages): array
    {
        return $this->post("/apps/{$app}/cache/purge", ['paths' => array_values($pages)])['data'] ?? [];
    }

    // The PHP of an Octane app (API 1.47+ / Cipi ≥ 5.17.0): what answers
    // requests (inside its FrankenPHP build), what runs the rest.

    /** php, octane_php, build, frankenphp, state, restart_pending, available_php, fix. */
    public function octane(string $app): array
    {
        return $this->get("/apps/{$app}/octane")['data'] ?? [];
    }

    /** Async job (`octane-php`): Octane and the server's PHP on one version, by its number. */
    public function octanePhp(string $app, string $php): array
    {
        return $this->put("/apps/{$app}/octane/php", ['php' => $php]);
    }

    /** Async job (`octane-restart`): restarted on its build, and checked. */
    public function octaneRestart(string $app): array
    {
        return $this->post("/apps/{$app}/octane/restart");
    }

    /** Async job (`frankenphp-upgrade`, API 1.40+): the newest build on the app's own PHP. */
    public function frankenphpUpgrade(string $app): array
    {
        return $this->post('/frankenphp/upgrade', ['app' => $app]);
    }

    // ── Node apps + runtimes (API 1.31+ / Cipi ≥ 5.4.0) ───────────────

    /**
     * @return list<array{major: string, version: ?string, default: bool, apps: list<string>}>
     */
    public function listNodeRuntimes(): array
    {
        $data = $this->get('/node')['data'] ?? [];

        if (isset($data['runtimes']) && is_array($data['runtimes'])) {
            $data = $data['runtimes'];
        }

        return is_array($data) ? array_values($data) : [];
    }

    public function nodeStatus(string $app): array
    {
        return $this->get("/apps/{$app}/node")['data'] ?? [];
    }

    /**
     * Blue/green restart of an SSR Node app (async job).
     */
    public function nodeRestart(string $app): array
    {
        return $this->post("/apps/{$app}/node/restart");
    }

    // Node runtimes on the server (API 1.39+ / Cipi ≥ 5.9.0) — async jobs.

    public function nodeInstall(string $major): array
    {
        return $this->post('/node/install', ['major' => $major]);
    }

    /** Latest patch of one major, or of every installed one; $restart moves SSR apps to it blue/green. */
    public function nodeUpgrade(?string $major = null, bool $restart = false): array
    {
        $payload = ['restart' => $restart];
        if ($major !== null && $major !== '') {
            $payload['major'] = $major;
        }

        return $this->post('/node/upgrade', $payload);
    }

    /** @param  string  $major  an installed major, or `system` */
    public function nodeSetDefault(string $major): array
    {
        return $this->put('/node/default', ['major' => $major]);
    }

    public function nodeRemove(string $major): array
    {
        return $this->delete('/node/'.rawurlencode($major));
    }

    // ── Meilisearch / Laravel Scout (API 1.31+ / Cipi ≥ 5.2.2) ────────

    public function searchStatus(): array
    {
        return $this->get('/search')['data'] ?? [];
    }

    public function enableSearch(string $app): array
    {
        return $this->post("/apps/{$app}/search/enable")['data'] ?? [];
    }

    public function disableSearch(string $app): array
    {
        return $this->post("/apps/{$app}/search/disable")['data'] ?? [];
    }

    // ── Host insights (API 1.31+; packages install/remove 1.44+) ──────

    /**
     * @return list<array{id: string, packages: list<string>, description?: string, kind?: string, binaries?: list<string>, missing?: list<string>, installed: bool, partial?: bool, source?: string, version?: ?string, installed_version?: ?string, outdated?: bool}>
     */
    public function listPackages(): array
    {
        $data = $this->get('/packages')['data'] ?? [];

        if (isset($data['packages']) && is_array($data['packages'])) {
            $data = $data['packages'];
        }

        return is_array($data) ? array_values($data) : [];
    }

    /** Install one entry of the server's closed catalog, or update an outdated upstream one — a job (`package-install`; API 1.44+ / Cipi ≥ 5.14.0, `packages-manage`). */
    public function installPackage(string $id): array
    {
        return $this->post('/packages/'.rawurlencode($id));
    }

    /** Remove one catalog entry, and only what nothing else needs — a job (`package-remove`). */
    public function removePackage(string $id): array
    {
        return $this->delete('/packages/'.rawurlencode($id));
    }

    public function monitorStatus(): array
    {
        return $this->get('/monitor')['data'] ?? [];
    }

    // ── Metrics & health history (API 1.41+ / Cipi ≥ 5.11.0) ──────────

    /** Host now, per app latest values + last-hour trend + uptime 24h/7d/30d, recent events, collector state. */
    public function metricsOverview(): array
    {
        return $this->get('/metrics')['data'] ?? [];
    }

    /**
     * ~240 points of [ts, avg, max] per metric. $app null = the host.
     *
     * @param  list<string>  $metrics
     */
    public function metricsSeries(?string $app, array $metrics = [], ?string $range = null): array
    {
        $query = array_filter(['metrics' => implode(',', $metrics), 'range' => $range], fn ($v) => $v !== null && $v !== '');

        return $this->get($app !== null ? "/apps/{$app}/metrics" : '/metrics/series', $query)['data'] ?? [];
    }

    public function metricsUptime(?string $app = null, ?string $range = null): array
    {
        return $this->get('/metrics/uptime', array_filter(['app' => $app, 'range' => $range], fn ($v) => $v !== null))['data'] ?? [];
    }

    /** @return list<array{ts: int, source: string, subject: string, status: string, summary: string}> */
    public function metricsEvents(?string $app = null, ?string $range = null, int $limit = 100, ?string $source = null): array
    {
        $query = array_filter(['app' => $app, 'range' => $range, 'limit' => $limit, 'source' => $source], fn ($v) => $v !== null);

        return $this->get('/metrics/events', $query)['data']['events'] ?? [];
    }

    public function metricsSettings(): array
    {
        return $this->get('/metrics/settings')['data'] ?? [];
    }

    /** @param  array<string, bool|int>  $settings  enabled, probe, raw_days, rollup_days, resolution_minutes, events_days */
    public function updateMetricsSettings(array $settings): array
    {
        return $this->put('/metrics/settings', $settings)['data'] ?? [];
    }

    public function setMetricsHeartbeat(string $url, int $every = 1): array
    {
        return $this->put('/metrics/heartbeat', ['url' => $url, 'every' => $every])['data'] ?? [];
    }

    public function deleteMetricsHeartbeat(): array
    {
        return $this->delete('/metrics/heartbeat')['data'] ?? [];
    }

    public function testMetricsHeartbeat(): array
    {
        return $this->post('/metrics/heartbeat/test')['data'] ?? [];
    }

    /**
     * Zero Trust: Cloudflare Tunnel and the mesh network, with what this
     * panel may do from where it calls. $client is the address of the person
     * at the panel: when the panel runs on the server it manages, the server
     * then also says whether closing 80/443 would close their page.
     */
    public function ztStatus(?string $client = null): array
    {
        return $this->get('/zt', $client !== null && $client !== '' ? ['client' => $client] : [])['data'] ?? [];
    }

    // ── Zero Trust: the mesh network — Tailscale, NetBird (ability zt-manage) ──
    // Synchronous on the server; each answers with Zero Trust after the change.
    // $client is the address of the person at the panel (see ztStatus): the
    // answer is then read for them too, and `lock http` is refused when it
    // would close the page they are on.

    /**
     * Join a network. The server installs the client and signs in: it can
     * take a minute or two, so the call gets a long timeout.
     *
     * @param  array<string, mixed>  $options  provider, key, hostname, control_url, tags, direct, accept_dns, accept_routes, ssh
     * @return array{data: ?array, message: string}
     */
    public function ztMeshJoin(array $options, ?string $client = null): array
    {
        return $this->ztMeshChange('post', '/zt/mesh', $options, $client, max(240, (int) config('cipi-gui.http_timeout', 30)));
    }

    /** Leave the network: what its locks closed is reopened first. */
    public function ztMeshLeave(?string $client = null): array
    {
        return $this->ztMeshChange('delete', '/zt/mesh', [], $client, max(120, (int) config('cipi-gui.http_timeout', 30)));
    }

    /** Close the SSH port (`ssh`) or 80/443 (`http`) to the world: open on the mesh only. */
    public function ztMeshLock(string $port, ?string $client = null): array
    {
        return $this->ztMeshChange('post', '/zt/mesh/lock', ['port' => $port], $client);
    }

    /** Reopen it to the world. */
    public function ztMeshUnlock(string $port, ?string $client = null): array
    {
        return $this->ztMeshChange('delete', '/zt/mesh/lock', ['port' => $port], $client);
    }

    /** Tailscale SSH on or off. */
    public function ztMeshSsh(bool $enabled, ?string $client = null): array
    {
        return $this->ztMeshChange('put', '/zt/mesh/ssh', ['enabled' => $enabled], $client);
    }

    /** @return array{data: ?array, message: string} */
    protected function ztMeshChange(string $method, string $path, array $data, ?string $client = null, ?int $timeout = null): array
    {
        if ($client !== null && $client !== '') {
            $data['client'] = $client;
        }
        $response = $this->request($method, $path, data: $data, timeout: $timeout);

        return [
            'data' => is_array($response['data'] ?? null) ? $response['data'] : null,
            'message' => (string) ($response['message'] ?? ''),
        ];
    }

    // ── Moving apps to another server (cipi sync — ability sync-manage) ──

    /** This server's sync key: what a target must trust before this server can push to it. */
    public function syncKey(): array
    {
        return $this->get('/sync/key')['data'] ?? [];
    }

    /** Does the target accept this server's key, does Cipi answer there. Nothing is sent. */
    public function syncCheck(string $host, int $port = 22): array
    {
        return $this->request('post', '/sync/check', data: ['host' => $host, 'port' => $port], timeout: max(60, (int) config('cipi-gui.http_timeout', 30)))['data'] ?? [];
    }

    /** On the target: let the server this key belongs to push here. `changed`: false when it was trusted already. */
    public function syncTrust(string $key): array
    {
        return $this->request('post', '/sync/trust', data: ['key' => $key])['data'] ?? [];
    }

    /** On the target: take that key back out. */
    public function syncUntrust(string $key): array
    {
        return $this->request('delete', '/sync/trust', data: ['key' => $key])['data'] ?? [];
    }

    /**
     * The transfer, as a job of the source: export, send, import on the target.
     *
     * @param  array{host: string, port?: int, apps?: list<string>, with_db?: bool, with_storage?: bool}  $payload
     */
    public function syncPush(array $payload): array
    {
        return $this->request('post', '/sync/push', data: $payload);
    }

    // ── Disk usage (read-only — API 1.33+ / Cipi ≥ 5.5.2 for the API sudoers) ──

    /**
     * `cipi disk --json`: the filesystem /home is on, then every app largest first
     * (files, database, total, share of the disk, soft limit). Sizes are measured
     * on request, so the call gets its own timeout.
     *
     * @return array{disk?: array, apps?: list<array>, apps_total_gb?: float, apps_percent?: float, other_gb?: float, other_percent?: float}
     */
    public function diskUsage(): array
    {
        $data = $this->get('/disk', timeout: $this->diskTimeout())['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * `cipi disk db --json`: every database of every installed engine, in MB.
     *
     * @return list<array{engine: string, databases: list<array>, on_disk_mb: ?float, memory_mb: ?float, note: ?string}>
     */
    public function diskDatabases(): array
    {
        $data = $this->get('/disk/dbs', timeout: $this->diskTimeout())['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, fn ($e) => is_array($e) && ! empty($e['engine']))) : [];
    }

    protected function diskTimeout(): int
    {
        return max((int) config('cipi-gui.http_disk_timeout', 180), (int) config('cipi-gui.http_timeout', 30));
    }

    // ── API client IP whitelist (API 1.31+ / Cipi ≥ 5.0.8) ────────────

    public function getIpWhitelist(): array
    {
        return $this->get('/ip-whitelist')['data'] ?? [];
    }

    /**
     * @param  list<string>  $entries
     */
    public function setIpWhitelist(array $entries, bool $ensureClientIp = true): array
    {
        return $this->put('/ip-whitelist', [
            'entries' => $entries,
            'ensure_client_ip' => $ensureClientIp,
        ])['data'] ?? [];
    }

    public function addIpWhitelistEntry(string $ip): array
    {
        return $this->post('/ip-whitelist', ['ip' => $ip])['data'] ?? [];
    }

    public function removeIpWhitelistEntry(string $ip): array
    {
        return $this->request('delete', '/ip-whitelist', data: ['ip' => $ip])['data'] ?? [];
    }

    public function allowAllIpWhitelist(): array
    {
        return $this->post('/ip-whitelist/allow-all')['data'] ?? [];
    }

    // ── Firewall: UFW, fail2ban, CrowdSec (API 1.44+ / Cipi ≥ 5.14.0) ─

    /** UFW rules, protected ports, Zero Trust locks, fail2ban, CrowdSec: `cipi firewall status --json`. */
    public function firewall(): array
    {
        return $this->get('/firewall')['data'] ?? [];
    }

    /** @return array{data: ?array, message: string} the firewall after the change, and what the CLI said */
    public function firewallAllow(string $port, ?string $from = null, string $proto = 'tcp'): array
    {
        return $this->firewallChange('post', '/firewall/rules', ['port' => $port, 'from' => $from, 'proto' => $proto]);
    }

    /** The rule `allow` (or, with $action deny, `deny`) made: the same port, source and protocol. */
    public function firewallRemove(string $port, ?string $from = null, string $proto = 'tcp', string $action = 'allow'): array
    {
        return $this->firewallChange('delete', '/firewall/rules', ['port' => $port, 'from' => $from, 'proto' => $proto, 'action' => $action]);
    }

    public function firewallBlock(string $address): array
    {
        return $this->firewallChange('post', '/firewall/blocks', ['address' => $address]);
    }

    public function firewallUnblock(string $address): array
    {
        return $this->firewallChange('delete', '/firewall/blocks', ['address' => $address]);
    }

    /** null: back to the default. */
    public function firewallAttempts(?int $attempts): array
    {
        return $this->firewallChange('put', '/firewall/attempts', ['attempts' => $attempts]);
    }

    /** fail2ban's jails and CrowdSec's decisions alike. */
    public function firewallUnban(string $ip): array
    {
        return $this->firewallChange('delete', '/firewall/bans', ['ip' => $ip]);
    }

    public function crowdsecAllow(string $address): array
    {
        return $this->firewallChange('post', '/firewall/crowdsec/allowlist', ['address' => $address]);
    }

    public function crowdsecUnallow(string $address): array
    {
        return $this->firewallChange('delete', '/firewall/crowdsec/allowlist', ['address' => $address]);
    }

    /** nginx takes the visitor's address from Cloudflare (CF-Connecting-IP); again, the ranges are fetched anew. */
    public function firewallRealIpEnable(): array
    {
        return $this->firewallChange('put', '/firewall/real-ip', []);
    }

    /** Cloudflare's ranges fetched again (the server does it every night too). */
    public function firewallRealIpRefresh(): array
    {
        return $this->firewallChange('post', '/firewall/real-ip/refresh', []);
    }

    /** Back to the address of each connection. */
    public function firewallRealIpDisable(): array
    {
        return $this->firewallChange('delete', '/firewall/real-ip', []);
    }

    /** Async job (`crowdsec-enable`): the panel's address goes on CrowdSec's allowlist. */
    public function crowdsecEnable(): array
    {
        return $this->post('/firewall/crowdsec/enable');
    }

    /** Async job (`crowdsec-disable`); fail2ban stays. */
    public function crowdsecDisable(): array
    {
        return $this->post('/firewall/crowdsec/disable');
    }

    /** @return array{data: ?array, message: string} */
    protected function firewallChange(string $method, string $path, array $data): array
    {
        $data = array_filter($data, fn ($v) => $v !== '' && ! ($v === null && $path !== '/firewall/attempts'));
        $response = $this->request($method, $path, data: $data);

        return [
            'data' => is_array($response['data'] ?? null) ? $response['data'] : null,
            'message' => (string) ($response['message'] ?? ''),
        ];
    }

    // ── WWW redirects (Cipi 4.8+ / API 1.12+) ─────────────────────────

    public function wwwStatus(string $app): array
    {
        return $this->get("/apps/{$app}/www")['data'] ?? [];
    }

    public function wwwAdd(string $app): array
    {
        return $this->post("/apps/{$app}/www/add");
    }

    public function wwwForceToRoot(string $app): array
    {
        return $this->post("/apps/{$app}/www/force-to-root");
    }

    public function wwwForceFromRoot(string $app): array
    {
        return $this->post("/apps/{$app}/www/force-from-root");
    }

    public function wwwClear(string $app): array
    {
        return $this->post("/apps/{$app}/www/clear");
    }

    // ── SSL ───────────────────────────────────────────────────────────

    public function installSsl(string $app): array
    {
        return $this->post("/apps/{$app}/ssl");
    }

    public function forceSsl(string $app): array
    {
        return $this->post("/apps/{$app}/ssl/force");
    }

    // ── Databases ─────────────────────────────────────────────────────

    public function listDatabaseEngines(): array
    {
        return $this->get('/dbs/engines')['data'] ?? [];
    }

    public function listDatabases(?string $engine = null): array
    {
        $query = [];
        if ($engine !== null && $engine !== '') {
            $query['engine'] = $engine;
        }

        $data = $this->get('/dbs', $query)['data'] ?? [];

        if (isset($data['databases']) && is_array($data['databases'])) {
            $data = $data['databases'];
        }

        if (! is_array($data)) {
            return [];
        }

        $normalized = [];

        foreach ($data as $item) {
            $app = null;
            $link = null;
            $linkable = false;
            if (is_string($item)) {
                $name = $item;
                $size = null;
                $dbEngine = null;
                $user = null;
            } elseif (is_array($item)) {
                $name = $item['name'] ?? $item['database'] ?? null;
                $size = $item['size'] ?? null;
                $dbEngine = $item['engine'] ?? null;
                $user = $item['user'] ?? null;
                // API 1.39+ / Cipi ≥ 5.9.0: the app a database belongs to.
                $app = $item['app'] ?? null;
                $link = $item['link'] ?? null;
                $linkable = array_key_exists('link', $item);
            } else {
                continue;
            }

            if (! is_string($name) || $name === '') {
                continue;
            }

            if (in_array(strtolower($name), ['databases', 'name', 'database', 'size', 'engine', 'user'], true)) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'size' => is_string($size) ? $size : null,
                'engine' => is_string($dbEngine) && $dbEngine !== '' ? $dbEngine : null,
                'user' => is_string($user) && $user !== '' ? $user : null,
                'app' => is_string($app) && $app !== '' ? $app : null,
                // linked (cipi db link) | own (created with a Laravel app) | namespace (<app>_…)
                'link' => is_string($link) && $link !== '' ? $link : null,
                // false on a server that does not report links (before API 1.39 / Cipi 5.9.0)
                'linkable' => $linkable,
            ];
        }

        return $normalized;
    }

    /** @param  ?string  $app  show the database as this app's (API 1.39+ / Cipi ≥ 5.9.0) */
    public function createDatabase(string $name, ?string $engine = null, ?string $app = null): array
    {
        $payload = ['name' => $name];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }
        if ($app !== null && $app !== '') {
            $payload['app'] = $app;
        }

        return $this->post('/dbs', $payload);
    }

    /** Show a database as an app's (`cipi db link`, API 1.39+ / Cipi ≥ 5.9.0). Synchronous. */
    public function linkDatabase(string $name, string $app, ?string $engine = null): array
    {
        $payload = ['app' => $app];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post("/dbs/{$name}/link", $payload)['data'] ?? [];
    }

    public function unlinkDatabase(string $name, ?string $engine = null): array
    {
        return $this->delete("/dbs/{$name}/link", $engine !== null && $engine !== '' ? ['engine' => $engine] : [])['data'] ?? [];
    }

    // Dump upload in chunks (API 1.39+ / Cipi ≥ 5.9.0): start → chunks in order → restore with the upload id.

    /** @return array{max_size: int, chunk_size: int, extensions: list<string>}|null null when the server has no upload */
    public function dbUploadLimits(): ?array
    {
        $data = $this->get('/dbs/uploads/limits')['data'] ?? null;

        return is_array($data) && isset($data['max_size'], $data['chunk_size']) ? $data : null;
    }

    /** @return array{upload_id: string, size: int, chunk_size: int, chunks: int, max_size: int} */
    public function startDbUpload(string $filename, int $size): array
    {
        return $this->post('/dbs/uploads', ['filename' => $filename, 'size' => $size])['data'] ?? [];
    }

    /**
     * Send one chunk (multipart). $contents is the chunk itself: a few
     * megabytes, never the whole dump.
     *
     * @return array{upload_id: string, received: int, size: int, next: int, complete: bool}
     */
    public function sendDbUploadChunk(string $uploadId, int $index, string $contents): array
    {
        $url = $this->server->api_url.'/dbs/uploads/'.rawurlencode($uploadId).'/chunks';

        try {
            $response = self::configure(Http::createPendingRequest(), $this->server)
                ->timeout(max(120, (int) config('cipi-gui.http_timeout', 30)))
                ->attach('chunk', $contents, 'chunk.bin')
                ->post($url, ['index' => (string) $index]);
        } catch (ConnectionException $e) {
            throw new CipiApiException('Unable to connect to server. Check the URL and network.', 503, ['connection' => $e->getMessage()], $e);
        }

        return $this->handle($response)['data'] ?? [];
    }

    public function cancelDbUpload(string $uploadId): void
    {
        $this->delete('/dbs/uploads/'.rawurlencode($uploadId));
    }

    /** Restore a database from a dump uploaded with startDbUpload()/sendDbUploadChunk() (async job). */
    public function restoreDatabaseFromUpload(string $name, string $uploadId, ?string $engine = null): array
    {
        $payload = ['upload_id' => $uploadId];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post("/dbs/{$name}/restore", $payload);
    }

    // ── Backups (API 1.39+ / Cipi ≥ 5.9.0) ────────────────────────────

    /**
     * Destinations (never their keys), profiles with what each covers, last
     * run, overdue, running, and what no enabled profile takes.
     */
    public function backupStatus(): array
    {
        return $this->get('/backups')['data'] ?? [];
    }

    /**
     * Runs each destination holds, per profile. Lists the buckets: slower than
     * the rest, so it gets the long timeout.
     *
     * @return array{profiles: array<string, list<array{run: string, destinations: list<string>}>>, errors: list<string>}
     */
    public function backupRuns(?string $profile = null): array
    {
        $data = $this->get('/backups/runs', $profile !== null && $profile !== '' ? ['profile' => $profile] : [], $this->diskTimeout())['data'] ?? [];

        return [
            'profiles' => is_array($data['profiles'] ?? null) ? $data['profiles'] : [],
            'errors' => array_values(array_filter((array) ($data['errors'] ?? []), 'is_string')),
        ];
    }

    /** Start a run in the background: one profile, or every enabled one. Returns at once. */
    public function backupRun(?string $profile = null): array
    {
        return $this->post('/backups/run', $profile !== null && $profile !== '' ? ['profile' => $profile] : [])['data'] ?? [];
    }

    /** Read the newest run back and check that it opens (async job). */
    public function backupVerify(?string $profile = null): array
    {
        return $this->post('/backups/verify', $profile !== null && $profile !== '' ? ['profile' => $profile] : []);
    }

    /** @param  array<string, mixed>  $payload */
    public function createBackupProfile(array $payload): array
    {
        return $this->post('/backups/profiles', $payload)['data'] ?? [];
    }

    /** @param  array<string, mixed>  $payload */
    public function updateBackupProfile(string $name, array $payload): array
    {
        return $this->put('/backups/profiles/'.rawurlencode($name), $payload)['data'] ?? [];
    }

    public function deleteBackupProfile(string $name): array
    {
        return $this->delete('/backups/profiles/'.rawurlencode($name))['data'] ?? [];
    }

    public function toggleBackupProfile(string $name, bool $enabled): array
    {
        return $this->post('/backups/profiles/'.rawurlencode($name).'/'.($enabled ? 'enable' : 'disable'))['data'] ?? [];
    }

    /** @return array{ok: bool, output: string} */
    public function testBackupDestinations(?string $name = null): array
    {
        $data = $this->post('/backups/destinations/test', $name !== null && $name !== '' ? ['name' => $name] : [])['data'] ?? [];

        return ['ok' => (bool) ($data['ok'] ?? false), 'output' => (string) ($data['output'] ?? '')];
    }

    // SFTP backup destinations (API 1.48+ / Cipi ≥ 5.18.0): a server over SSH,
    // with a key the server makes — no secret goes through the panel.

    /** @param  array{name: string, host: string, user: string, port?: int, path?: string, profiles?: list<string>}  $payload  profiles: sent there too (Cipi 5.19.1+) */
    public function createSftpDestination(array $payload): array
    {
        return $this->post('/backups/destinations', ['type' => 'sftp'] + $payload)['data'] ?? [];
    }

    /** @param  array<string, mixed>  $payload  host, port, user, path, new_key */
    public function updateSftpDestination(string $name, array $payload): array
    {
        return $this->put('/backups/destinations/'.rawurlencode($name), $payload)['data'] ?? [];
    }

    public function backupDestinationKey(string $name): string
    {
        return (string) ($this->get('/backups/destinations/'.rawurlencode($name).'/key')['data']['public_key'] ?? '');
    }

    public function deleteBackupDestination(string $name): array
    {
        return $this->request('delete', '/backups/destinations/'.rawurlencode($name))['data'] ?? [];
    }

    // Backups set up from the panel (API 1.49+ / Cipi ≥ 5.19.0): the setup on
    // the server, S3 buckets (on a par with the SFTP servers), the encryption
    // key, the retention now. A secret key
    // is sent once, in the body of a synchronous request; the API hands it to
    // the CLI on its standard input and never answers it.

    /** @param  array{name: string, bucket: string, key: string, secret: string, region?: string, endpoint?: string, profiles?: list<string>}  $payload  profiles: sent there too (Cipi 5.19.1+) */
    public function createS3Destination(array $payload): array
    {
        return $this->post('/backups/destinations', ['type' => 's3'] + $payload)['data'] ?? [];
    }

    /** @param  array<string, string>  $payload  bucket, key, secret (left out: kept), region, endpoint */
    public function updateS3Destination(string $name, array $payload): array
    {
        return $this->put('/backups/destinations/'.rawurlencode($name), $payload)['data'] ?? [];
    }

    /**
     * `cipi backup configure`: backups on the server — the local folder and
     * the nightly profile. S3 buckets and SFTP servers are then destinations.
     *
     * @return array<string, mixed> the backup status after it
     */
    public function configureBackups(): array
    {
        return $this->put('/backups/config', [])['data'] ?? [];
    }

    /** The encryption key — shown once, never kept by the panel. */
    public function backupKey(): string
    {
        return (string) ($this->get('/backups/key')['data']['key'] ?? '');
    }

    public function rotateBackupKey(): array
    {
        return $this->post('/backups/key/rotate')['data'] ?? [];
    }

    /** The retention applied now: a job (`backup-prune`). */
    public function pruneBackups(?string $profile = null, bool $dryRun = false): array
    {
        return $this->post('/backups/prune', array_filter(['profile' => $profile, 'dry_run' => $dryRun ?: null], fn ($v) => $v !== null));
    }

    // How the server updates Cipi (API 1.48+ / Cipi ≥ 5.18.0): the release
    // signature check and the delay. Both off unless turned on.

    public function selfUpdatePolicy(): array
    {
        return $this->get('/self-update/policy')['data'] ?? [];
    }

    /** @param  array{verify?: bool, delay_hours?: int, fingerprint?: string}  $payload */
    public function setSelfUpdatePolicy(array $payload): array
    {
        return $this->put('/self-update/policy', $payload)['data'] ?? [];
    }

    public function backupDatabase(string $name, ?string $engine = null): array
    {
        $payload = [];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post("/dbs/{$name}/backup", $payload);
    }

    public function restoreDatabase(string $name, string $file, ?string $engine = null): array
    {
        $payload = ['file' => $file];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post("/dbs/{$name}/restore", $payload);
    }

    public function regenerateDbPassword(string $name, ?string $engine = null): array
    {
        $payload = [];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post("/dbs/{$name}/password", $payload);
    }

    // ── PHP (API 1.15+ / Cipi CLI ≥ 5.0.6) ────────────────────────────

    /**
     * @return array{default: ?string, installable: list<string>, versions: list<array{version: string, status: string, apps: int, default: bool}>}
     */
    public function listPhp(): array
    {
        return $this->get('/php')['data'] ?? ['default' => null, 'installable' => [], 'versions' => []];
    }

    public function installPhp(string $version): array
    {
        return $this->post('/php/install', ['version' => $version]);
    }

    // ── DB engines manage (API 1.15+) ─────────────────────────────────

    public function installDbEngine(string $engine): array
    {
        return $this->post('/dbs/engines/install', ['engine' => $engine]);
    }

    // ── SSH keys (API 1.15+) ──────────────────────────────────────────

    public function listSshKeys(): array
    {
        return $this->get('/ssh/keys')['data'] ?? [];
    }

    public function addSshKey(string $key): array
    {
        return $this->post('/ssh/keys', ['key' => $key])['data'] ?? [];
    }

    public function removeSshKey(int $id): array
    {
        return $this->delete("/ssh/keys/{$id}")['data'] ?? [];
    }

    // ── Services (API 1.15+) ──────────────────────────────────────────

    public function listServices(?string $service = null): array
    {
        $query = $service ? ['service' => $service] : [];

        return $this->get('/services', $query)['data'] ?? [];
    }

    public function restartService(string $name): array
    {
        return $this->post("/services/{$name}/restart");
    }

    // ── SMTP (API 1.16+ / Cipi CLI ≥ 5.0.7) ───────────────────────────

    public function getSmtp(): array
    {
        return $this->get('/smtp')['data'] ?? [];
    }

    public function updateSmtp(array $payload): array
    {
        return $this->put('/smtp', $payload)['data'] ?? [];
    }

    public function enableSmtp(): array
    {
        return $this->post('/smtp/enable')['data'] ?? [];
    }

    public function disableSmtp(): array
    {
        return $this->post('/smtp/disable')['data'] ?? [];
    }

    public function testSmtp(): array
    {
        return $this->post('/smtp/test')['data'] ?? [];
    }

    public function deleteSmtp(): array
    {
        return $this->delete('/smtp')['data'] ?? [];
    }

    // ── Valkey per app (API 1.47+ / Cipi ≥ 5.17.0). No password is ever in an answer. ──

    /** shared_port, apps [{app, uses: own|shared|external|none, port, instance, memory, keys}], shared, isolated. */
    public function valkey(): array
    {
        return $this->get('/valkey')['data'] ?? [];
    }

    /** Async job (`valkey-isolate`): one app, or — null — every app on the shared instance. */
    public function valkeyIsolate(?string $app, bool $copy = true): array
    {
        return $this->post('/valkey/isolate', ($app === null ? ['all' => true] : ['app' => $app]) + ($copy ? [] : ['copy' => false]));
    }

    /** Async job (`valkey-share`): back to the shared instance; its own is removed. */
    public function valkeyShare(string $app, bool $copy = true): array
    {
        return $this->post('/valkey/share', ['app' => $app] + ($copy ? [] : ['copy' => false]));
    }

    /** The list after the restart. */
    public function valkeyRestart(string $app): array
    {
        return $this->post('/valkey/'.rawurlencode($app).'/restart')['data'] ?? [];
    }

    /** Empties the shared instance and changes its password; 409 while an app uses it. */
    public function valkeyFlushShared(): array
    {
        return $this->post('/valkey/flush-shared')['data'] ?? [];
    }

    // ── Notifications: every channel and trigger (API 1.42+ / Cipi CLI ≥ 5.12.0) ──

    /** {channels: [...], triggers: {enabled, total}, types: [...]} — secrets come back as *_set only. */
    public function notifications(): array
    {
        return $this->get('/notifications')['data'] ?? [];
    }

    /** @return array{channel: ?array, test: ?array} */
    public function addNotificationChannel(array $payload): array
    {
        return $this->post('/notifications/channels', $payload)['data'] ?? [];
    }

    /** Only the fields given change; a secret left out is kept. */
    public function updateNotificationChannel(string $id, array $payload): array
    {
        return $this->put('/notifications/channels/'.rawurlencode($id), $payload)['data'] ?? [];
    }

    public function removeNotificationChannel(string $id): array
    {
        return $this->delete('/notifications/channels/'.rawurlencode($id))['data'] ?? [];
    }

    public function setNotificationChannelEnabled(string $id, bool $enabled): array
    {
        return $this->post('/notifications/channels/'.rawurlencode($id).'/'.($enabled ? 'enable' : 'disable'))['data'] ?? [];
    }

    /** {ok, message}; a refused test (422) comes back as ok: false with the reason. */
    public function testNotificationChannel(string $id): array
    {
        try {
            return $this->post('/notifications/channels/'.rawurlencode($id).'/test')['data'] ?? ['ok' => true, 'message' => 'Test sent'];
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 422) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
            throw $e;
        }
    }

    /** {triggers: [{id, category, label, enabled}], enabled, total} */
    public function notificationTriggers(): array
    {
        return $this->get('/notifications/triggers')['data'] ?? [];
    }

    /** @param  array<string, bool>  $triggers */
    public function updateNotificationTriggers(array $payload): array
    {
        return $this->put('/notifications/triggers', $payload)['data'] ?? [];
    }

    public function resetNotificationTriggers(): array
    {
        return $this->post('/notifications/triggers/reset')['data'] ?? [];
    }

    /** How notifications read (API 1.47+ / Cipi ≥ 5.17.0): timezone, server_timezone, server_name, hostname, now. */
    public function notificationSettings(): array
    {
        return $this->get('/notifications/settings')['data'] ?? [];
    }

    /** `timezone` and/or `server_name`; null puts the server's own time zone, or the hostname, back. */
    public function updateNotificationSettings(array $payload): array
    {
        return $this->put('/notifications/settings', $payload)['data'] ?? [];
    }

    // ── Healthchecks (API 1.16+) ──────────────────────────────────────

    /**
     * Server-wide HTTP healthchecks (API 1.16+).
     */
    public function listHealth(): array
    {
        return $this->get('/health')['data'] ?? [];
    }

    public function getAppHealth(string $name): array
    {
        return $this->get("/apps/{$name}/health")['data'] ?? [];
    }

    public function setAppHealth(string $name, ?string $url = null, int $expect = 200): array
    {
        $payload = ['expect' => $expect];
        if ($url !== null && $url !== '') {
            $payload['url'] = $url;
        }

        return $this->put("/apps/{$name}/health", $payload)['data'] ?? [];
    }

    public function unsetAppHealth(string $name): array
    {
        return $this->delete("/apps/{$name}/health")['data'] ?? [];
    }

    public function checkAppHealth(string $name): array
    {
        return $this->post("/apps/{$name}/health/check")['data'] ?? [];
    }

    // ── Jobs & Status ─────────────────────────────────────────────────

    public function getJob(string $id): array
    {
        return $this->get("/jobs/{$id}")['data'] ?? [];
    }

    public function getStatus(): array
    {
        return $this->get('/status')['data'] ?? [];
    }

    public function testConnection(): array
    {
        return $this->getStatus();
    }

    // ── HTTP layer ────────────────────────────────────────────────────

    protected function get(string $path, array $query = [], ?int $timeout = null): array
    {
        return $this->request('get', $path, query: $query, timeout: $timeout);
    }

    protected function post(string $path, array $data = []): array
    {
        return $this->request('post', $path, data: $data);
    }

    protected function put(string $path, array $data = []): array
    {
        return $this->request('put', $path, data: $data);
    }

    /**
     * PUT with a raw JSON body (used by auth.json replace — not a wrapped payload).
     */
    protected function putRaw(string $path, string $rawBody): array
    {
        return $this->request('putRaw', $path, rawBody: $rawBody);
    }

    protected function delete(string $path, array $query = []): array
    {
        return $this->request('delete', $path, query: $query);
    }

    /** @param  ?int  $timeout  Per-call override of `http_timeout` (seconds) for slow endpoints. */
    protected function request(string $method, string $path, array $data = [], array $query = [], ?string $rawBody = null, ?int $timeout = null): array
    {
        $url = $this->server->api_url.$path;

        // An answer asked ahead with prefetch(): taken once, handled like any other.
        if ($method === 'get' && self::$prefetched !== []) {
            $key = $this->prefetchKey($path, $query);
            if (isset(self::$prefetched[$key])) {
                $ahead = self::$prefetched[$key];
                unset(self::$prefetched[$key]);
                if ($ahead instanceof Response) {
                    return $this->handle($ahead);
                }
                $this->server->markError('Connection failed: '.$ahead->getMessage());
                throw new CipiApiException(
                    'Unable to connect to server. Check the URL and network.',
                    503,
                    ['connection' => $ahead->getMessage()],
                    $ahead instanceof \Exception ? $ahead : null,
                );
            }
        }

        try {
            $pending = self::configure(Http::createPendingRequest(), $this->server);
            if ($timeout !== null) {
                $pending = $pending->timeout($timeout);
            }

            /** @var Response $response */
            $response = match ($method) {
                'get' => $pending->get($url, $query),
                'post' => $pending->post($url, $data),
                'put' => $pending->put($url, $data),
                'putRaw' => $pending->withBody($rawBody ?? '', 'application/json')->put($url),
                'delete' => match (true) {
                    $data !== [] => $pending->delete($url, $data),
                    $query !== [] => $pending->withQueryParameters($query)->delete($url),
                    default => $pending->delete($url),
                },
                default => throw new CipiApiException("Unsupported HTTP method: {$method}"),
            };
        } catch (ConnectionException $e) {
            $this->server->markError('Connection failed: '.$e->getMessage());
            throw new CipiApiException(
                'Unable to connect to server. Check the URL and network.',
                503,
                ['connection' => $e->getMessage()],
                $e,
            );
        }

        return $this->handle($response);
    }

    protected static function configure(PendingRequest $request, CipiServer $server): PendingRequest
    {
        // Behind Cloudflare Access (cipi zt access enable --api): the service token, with every call.
        $access = $server->cloudflareAccessHeaders();
        if ($access !== []) {
            $request = $request->withHeaders($access);
        }

        return $request
            ->withToken((string) $server->token)
            ->acceptJson()
            ->withUserAgent('cipi-gui/'.\CipiGui\Support\Theme::VERSION)
            ->timeout((int) config('cipi-gui.http_timeout', 30))
            ->connectTimeout((int) config('cipi-gui.http_connect_timeout', 10));
    }

    protected function handle(Response $response): array
    {
        if ($response->successful()) {
            $this->server->markConnected();

            return $response->json() ?? [];
        }

        $body = $response->json();
        $status = $response->status();

        // 4xx answers such as 404/409/422 prove the server is reachable; only
        // auth failures and server errors flag the connection as broken.
        // So does a 503 that only says the server's Cipi is older than a feature needs.
        $needsNewerCipi = $status === 503 && is_array($body) && str_contains((string) ($body['error'] ?? ''), ' needs Cipi ');
        if ($status === 401 || ($status === 403 && str_contains((string) ($body['error'] ?? ''), 'IP not allowed')) || ($status >= 500 && ! $needsNewerCipi)) {
            $this->server->markError($body['error'] ?? $body['message'] ?? "HTTP {$status}");
        } else {
            $this->server->markConnected();
        }

        throw CipiApiException::fromResponse($status, is_array($body) ? $body : null);
    }
}
