<?php

namespace CipiGui\Services;

use CipiGui\Models\CipiServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CipiApiClient
{
    public function __construct(
        protected CipiServer $server,
    ) {}

    public static function for(CipiServer $server): self
    {
        return new self($server);
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
     */
    public function updateEnv(string $name, array $set = [], array $unset = []): array
    {
        $payload = [];
        if ($set !== []) {
            $payload['set'] = $set;
        }
        if ($unset !== []) {
            $payload['unset'] = $unset;
        }

        return $this->put("/apps/{$name}/env", $payload)['data'] ?? [];
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

    // ── Deploy config (structured deploy.php options — API 1.31+ / Cipi ≥ 5.0.3) ──

    public function showDeployConfig(string $app): array
    {
        return $this->get("/apps/{$app}/deploy-config")['data'] ?? [];
    }

    public function updateDeployConfig(string $app, array $payload): array
    {
        return $this->put("/apps/{$app}/deploy-config", $payload)['data'] ?? [];
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

    // ── Host insights (read-only — API 1.31+) ─────────────────────────

    /**
     * @return list<array{id: string, packages: list<string>, description?: string, installed: bool, partial?: bool}>
     */
    public function listPackages(): array
    {
        $data = $this->get('/packages')['data'] ?? [];

        if (isset($data['packages']) && is_array($data['packages'])) {
            $data = $data['packages'];
        }

        return is_array($data) ? array_values($data) : [];
    }

    public function monitorStatus(): array
    {
        return $this->get('/monitor')['data'] ?? [];
    }

    public function ztStatus(): array
    {
        return $this->get('/zt')['data'] ?? [];
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
            ];
        }

        return $normalized;
    }

    public function createDatabase(string $name, ?string $engine = null): array
    {
        $payload = ['name' => $name];
        if ($engine !== null && $engine !== '') {
            $payload['engine'] = $engine;
        }

        return $this->post('/dbs', $payload);
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

    protected function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, query: $query);
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

    protected function request(string $method, string $path, array $data = [], array $query = [], ?string $rawBody = null): array
    {
        $url = $this->server->api_url.$path;

        try {
            $pending = Http::withToken($this->server->token)
                ->acceptJson()
                ->timeout(config('cipi-gui.http_timeout', 30))
                ->connectTimeout(config('cipi-gui.http_connect_timeout', 10));

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

        if ($response->successful() || in_array($response->status(), [202], true)) {
            $this->server->markConnected();

            return $response->json() ?? [];
        }

        $body = $response->json();
        $this->server->markError($body['error'] ?? $body['message'] ?? "HTTP {$response->status()}");

        throw CipiApiException::fromResponse($response->status(), is_array($body) ? $body : null);
    }
}
