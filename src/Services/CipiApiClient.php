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

    public function deleteApp(string $name): array
    {
        return $this->delete("/apps/{$name}");
    }

    public function suspendApp(string $name): array
    {
        return $this->post("/apps/{$name}/suspend");
    }

    public function unsuspendApp(string $name): array
    {
        return $this->post("/apps/{$name}/unsuspend");
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

    // ── SSL ───────────────────────────────────────────────────────────

    public function installSsl(string $app): array
    {
        return $this->post("/apps/{$app}/ssl");
    }

    // ── Databases ─────────────────────────────────────────────────────

    public function listDatabases(): array
    {
        return $this->get('/dbs')['data'] ?? [];
    }

    public function createDatabase(string $name): array
    {
        return $this->post('/dbs', ['name' => $name]);
    }

    public function deleteDatabase(string $name): array
    {
        return $this->delete("/dbs/{$name}");
    }

    public function backupDatabase(string $name): array
    {
        return $this->post("/dbs/{$name}/backup");
    }

    public function restoreDatabase(string $name, string $file): array
    {
        return $this->post("/dbs/{$name}/restore", ['file' => $file]);
    }

    public function regenerateDbPassword(string $name): array
    {
        return $this->post("/dbs/{$name}/password");
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

    protected function delete(string $path): array
    {
        return $this->request('delete', $path);
    }

    protected function request(string $method, string $path, array $data = [], array $query = []): array
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
                'delete' => $pending->delete($url),
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
