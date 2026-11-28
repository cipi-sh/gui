<?php

/**
 * Cipi GUI demo API — a stateful stand-in for `cipi api` (REST API 1.36, the firewall of 1.44,
 * the page cache, Octane's PHP and Valkey per app of 1.47).
 *
 * It answers the same paths, payloads and status codes the GUI consumes, with
 * realistic fixtures (fixtures.php) and async jobs that move pending → running →
 * completed and return CLI-like output. It never touches the host: it exists so
 * the panel can be developed, demoed and screenshotted without a Cipi server.
 *
 *   php -S 127.0.0.1:8787 dev/demo/api/index.php
 *
 * Several servers share one process. The profile comes from the first label of
 * the Host header (fra1.cipi-demo.test → fra1) or from a path prefix
 * (http://127.0.0.1:8787/fra1/api/status). State lives in CIPI_DEMO_STATE
 * (default: dev/demo/storage/state.json); delete the file to reset the demo.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const API_VERSION = '1.36.0';
const JOB_PENDING_SECONDS = 0.6;
const JOB_RUNNING_SECONDS = 2.4;

$statePath = getenv('CIPI_DEMO_STATE') ?: dirname(__DIR__).'/storage/state.json';

// ── Request ──────────────────────────────────────────────────────────────

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
$rawBody = file_get_contents('php://input') ?: '';
$body = json_decode($rawBody, true);
$body = is_array($body) ? $body : [];
$query = $_GET;

$profile = null;
if (preg_match('#^/([a-z0-9-]+)(/api/.*)$#', $path, $m)) {
    $profile = $m[1];
    $path = $m[2];
} elseif (! filter_var($host, FILTER_VALIDATE_IP) && substr_count($host, '.') >= 2) {
    $profile = explode('.', $host)[0];
}

if ($path === '/' || $path === '') {
    respond(200, ['name' => 'Cipi GUI demo API', 'version' => API_VERSION, 'profiles' => array_keys(loadState($statePath)['servers'])]);
}

if (! str_starts_with($path, '/api/')) {
    respond(404, ['error' => 'Not found']);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (! preg_match('/^Bearer\s+(\S{10,})$/i', $auth)) {
    respond(401, ['message' => 'Unauthenticated.']);
}

$fp = fopen($statePath.'.lock', 'c');
flock($fp, LOCK_EX);
$state = loadState($statePath);

$profile ??= array_key_first($state['servers']);
if (! isset($state['servers'][$profile])) {
    respond(404, ['error' => "Unknown demo server '{$profile}'"]);
}

$s = &$state['servers'][$profile];
$route = substr($path, 4); // strip "/api"

try {
    [$code, $payload] = handle($method, $route, $body, $query, $rawBody, $s, $state, $profile);
} catch (DemoHttpError $e) {
    [$code, $payload] = [$e->status, ['error' => $e->getMessage()]];
}

saveState($statePath, $state);
flock($fp, LOCK_UN);
respond($code, $payload);

// ── Router ───────────────────────────────────────────────────────────────

function handle(string $method, string $route, array $body, array $query, string $rawBody, array &$s, array &$state, string $profile): array
{
    $m = [];
    $r = function (string $pattern) use ($route, &$m): bool {
        return preg_match('#^'.$pattern.'$#', $route, $m) === 1;
    };
    $p = function (int $i) use (&$m): string {
        return $m[$i] ?? '';
    };

    // Status & jobs
    if ($method === 'GET' && $r('/status')) {
        $status = $s['status'];
        $status['apps'] = count($s['apps']);

        return [200, ['data' => $status]];
    }
    if ($method === 'GET' && $r('/jobs/([0-9a-f-]+)')) {
        return [200, ['data' => jobView($state, $p(1))]];
    }

    // Apps
    if ($method === 'GET' && $r('/apps')) {
        return [200, ['data' => array_values(array_map(fn ($a) => publicApp($a['info'], false), $s['apps']))]];
    }
    if ($method === 'POST' && $r('/apps')) {
        return createApp($s, $state, $profile, $body);
    }
    if ($r('/apps/([a-z][a-z0-9]{2,31})(/.*)?')) {
        $name = $p(1);
        $sub = $p(2);
        if (! isset($s['apps'][$name])) {
            throw new DemoHttpError(404, "App '{$name}' not found");
        }

        return appRoute($method, $sub, $name, $body, $query, $rawBody, $s, $state, $profile);
    }

    if ($method === 'GET' && $r('/run-commands')) {
        return [200, ['data' => [
            'commands' => ['composer', 'npm', 'npx', 'yarn', 'pnpm', 'php', 'node', 'git', 'ls', 'll', 'cat', 'head', 'tail', 'grep', 'find', 'du', 'df', 'mkdir', 'cp', 'mv', 'rm', 'touch', 'chmod', 'tar', 'zip', 'unzip', 'wc'],
            'notes' => [
                'Runs as the app user inside the app home, non-interactive, 10 minute timeout.',
                'Editors, pagers, shells and REPLs (nano, vim, less, bash, tinker) are refused.',
                'Shell metacharacters (; | & > < `) and ".." path traversal are refused.',
            ],
        ]]];
    }

    // Databases
    if ($method === 'GET' && $r('/dbs/engines')) {
        return [200, ['data' => $s['engines']]];
    }
    if ($method === 'POST' && $r('/dbs/engines/install')) {
        $engine = (string) ($body['engine'] ?? '');

        return [202, dispatchJob($state, $profile, 'db-install', ['engine' => $engine])];
    }
    if ($method === 'GET' && $r('/dbs')) {
        $engine = $query['engine'] ?? null;
        $dbs = array_values(array_filter($s['dbs'], fn ($d) => $engine === null || $d['engine'] === $engine));

        return [200, ['data' => $dbs]];
    }
    if ($method === 'POST' && $r('/dbs')) {
        $name = (string) ($body['name'] ?? '');
        if (! preg_match('/^[a-z][a-z0-9]{2,31}$/', $name)) {
            throw new DemoHttpError(422, 'Invalid database name');
        }
        foreach ($s['dbs'] as $db) {
            if ($db['name'] === $name) {
                throw new DemoHttpError(409, "Database '{$name}' already exists");
            }
        }

        return [202, dispatchJob($state, $profile, 'db-create', ['name' => $name, 'engine' => $body['engine'] ?? $s['engines']['default']])];
    }
    if ($method === 'POST' && $r('/dbs/([a-z0-9_]+)/(backup|restore|password)')) {
        $params = ['name' => $p(1), 'engine' => $body['engine'] ?? null];
        if ($p(2) === 'restore') {
            $file = (string) ($body['file'] ?? '');
            if (! preg_match('#^[/\w.\-]+\.sql\.gz$#', $file)) {
                throw new DemoHttpError(422, 'Invalid backup file path. Must be a .sql.gz file with safe characters.');
            }
            $params['file'] = $file;
        }

        return [202, dispatchJob($state, $profile, 'db-'.$p(2), $params)];
    }

    // PHP
    if ($method === 'GET' && $r('/php')) {
        return [200, ['data' => $s['php']]];
    }
    if ($method === 'POST' && $r('/php/install')) {
        $version = (string) ($body['version'] ?? '');
        if (! in_array($version, $s['php']['installable'], true)) {
            throw new DemoHttpError(422, "PHP {$version} is not installable. Allowed: ".implode(', ', $s['php']['installable']));
        }

        return [202, dispatchJob($state, $profile, 'php-install', ['version' => $version])];
    }

    // Node runtimes
    if ($method === 'GET' && $r('/node')) {
        return [200, ['data' => $s['node']]];
    }

    // SSH keys
    if ($method === 'GET' && $r('/ssh/keys')) {
        return [200, ['data' => $s['ssh_keys']]];
    }
    if ($method === 'POST' && $r('/ssh/keys')) {
        $key = trim((string) ($body['key'] ?? ''));
        if (! preg_match('/^(ssh-(?:ed25519|rsa)|ecdsa-sha2-nistp256)\s+([A-Za-z0-9+\/=]{20,})(?:\s+(.+))?$/', $key, $km)) {
            throw new DemoHttpError(422, 'Invalid public key');
        }
        $id = count($s['ssh_keys']) + 1;
        $s['ssh_keys'][] = [
            'id' => $id, 'type' => $km[1], 'comment' => $km[3] ?? 'key-'.$id,
            'fingerprint' => 'SHA256:'.substr(base64_encode(hash('sha256', $km[2], true)), 0, 43), 'current_session' => false,
        ];

        return [200, ['data' => ['output' => "SSH key #{$id} added for user cipi"]]];
    }
    if ($method === 'DELETE' && $r('/ssh/keys/(\d+)')) {
        $id = (int) $p(1);
        $keys = array_values(array_filter($s['ssh_keys'], fn ($k) => $k['id'] !== $id));
        if (count($keys) === count($s['ssh_keys'])) {
            throw new DemoHttpError(422, "No key #{$id}");
        }
        foreach ($keys as $i => &$k) {
            $k['id'] = $i + 1;
        }
        $s['ssh_keys'] = $keys;

        return [200, ['data' => ['output' => "SSH key #{$id} removed"]]];
    }

    // Services
    if ($method === 'GET' && $r('/services')) {
        return [200, ['data' => $s['services']]];
    }
    if ($method === 'POST' && $r('/services/([a-z0-9.-]+)/restart')) {
        return [202, dispatchJob($state, $profile, 'service-restart', ['service' => $p(1)])];
    }

    // Valkey per app (cipi/api 1.47, Cipi 5.17.0). No password is ever in an answer.
    if ($method === 'GET' && $r('/valkey')) {
        return [200, ['data' => valkeyView($s)]];
    }
    if ($method === 'POST' && $r('/valkey/isolate')) {
        $all = ! empty($body['all']);
        $app = (string) ($body['app'] ?? '');
        if (($app === '') === ! $all) {
            throw new DemoHttpError(422, 'Give either app (one app) or all: true (every app on the shared instance)');
        }
        if (! $all && (($s['valkey'][$app] ?? 'shared') === 'own' || ! isset($s['apps'][$app]))) {
            throw new DemoHttpError(isset($s['apps'][$app]) ? 422 : 404, isset($s['apps'][$app]) ? "'{$app}' has its own Valkey instance already" : "App '{$app}' not found");
        }

        return [202, dispatchJob($state, $profile, 'valkey-isolate', ['app' => $all ? null : $app, 'all' => $all])];
    }
    if ($method === 'POST' && $r('/valkey/share')) {
        $app = (string) ($body['app'] ?? '');
        if (($s['valkey'][$app] ?? null) !== 'own') {
            throw new DemoHttpError(422, "'{$app}' has no instance of its own to leave");
        }

        return [202, dispatchJob($state, $profile, 'valkey-share', ['app' => $app])];
    }
    if ($method === 'POST' && $r('/valkey/flush-shared')) {
        if (valkeyView($s)['shared'] !== []) {
            throw new DemoHttpError(409, 'The shared Valkey is still in use by: '.implode(' ', valkeyView($s)['shared']).' — give them their own instance first');
        }

        return [200, ['data' => valkeyView($s) + ['flushed' => true]]];
    }
    if ($method === 'POST' && $r('/valkey/([a-z][a-z0-9]{2,31})/restart')) {
        if (($s['valkey'][$p(1)] ?? null) !== 'own') {
            throw new DemoHttpError(422, "'{$p(1)}' has no Valkey instance of its own");
        }

        return [200, ['data' => valkeyView($s)]];
    }
    if ($method === 'POST' && $r('/frankenphp/upgrade')) {
        return [202, dispatchJob($state, $profile, 'frankenphp-upgrade', ['app' => $body['app'] ?? null])];
    }

    // SMTP
    if ($r('/smtp(/enable|/disable|/test)?')) {
        return smtpRoute($method, $p(1), $body, $s);
    }

    // Healthchecks (server-wide)
    if ($method === 'GET' && $r('/health')) {
        $checks = [];
        foreach ($s['apps'] as $name => $a) {
            $h = $a['health'] ?? [];
            if (! empty($h['enabled'])) {
                $checks[] = ['app' => $name, 'url' => $h['url'], 'expect' => $h['expect'], 'state' => $h['state'], 'failcount' => $h['failcount']];
            }
        }

        return [200, ['data' => $checks]];
    }

    // Search, packages, monitor, Zero Trust
    if ($method === 'GET' && $r('/search')) {
        return [200, ['data' => $s['search']]];
    }
    if ($method === 'GET' && $r('/packages')) {
        return [200, ['data' => $s['packages']]];
    }
    if (in_array($method, ['POST', 'DELETE'], true) && $r('/packages/([^/]+)')) {
        $id = rawurldecode($p(1));
        $entry = null;
        foreach ($s['packages'] as $pkg) {
            if ($pkg['id'] === $id) {
                $entry = $pkg;
            }
        }
        if ($entry === null) {
            throw new DemoHttpError(404, "'{$id}' is not in this server's package catalog (cipi package list)");
        }
        if ($method === 'POST' && $entry['installed']) {
            throw new DemoHttpError(409, "{$id} is already installed");
        }
        if ($method === 'DELETE' && ! $entry['installed'] && ! $entry['partial'] && empty($entry['outdated'])) {
            throw new DemoHttpError(409, "{$id} is not installed");
        }

        return [202, dispatchJob($state, $profile, $method === 'POST' ? 'package-install' : 'package-remove', ['package' => $id])];
    }
    if ($method === 'GET' && $r('/monitor')) {
        return [200, ['data' => $s['monitor']]];
    }
    if ($method === 'GET' && $r('/zt')) {
        return [200, ['data' => $s['zt']]];
    }
    // The mesh network of Zero Trust (Cipi 5.18): join, leave, locks, Tailscale SSH
    if ($r('/zt/mesh(/lock|/ssh)?')) {
        return ztMeshRoute($method, $p(1), $body, $s);
    }

    // Transfers between servers (`cipi sync`): key, check, trust, push
    if ($r('/sync/(key|check|trust|push)')) {
        return syncRoute($method, $p(1), $body, $s, $state, $profile);
    }

    // Backups: the status, destinations (SFTP from Cipi 5.18, S3 from 5.19), the
    // default bucket, the encryption key and the retention (Cipi 5.19)
    if ($r('/backups(/runs|/destinations|/destinations/test|/destinations/[a-z0-9_-]+(?:/key)?|/config|/key|/key/rotate|/prune)?')) {
        return backupsRoute($method, $p(1), $body, $s, $state, $profile);
    }
    // How the server updates Cipi (Cipi 5.18): signature check and delay
    if ($r('/self-update/policy')) {
        return selfUpdateRoute($method, $body, $s);
    }

    // Disk usage (API 1.33+): `cipi disk --json` and `cipi disk db --json`
    if ($method === 'GET' && $r('/disk/dbs')) {
        return [200, ['data' => $s['disk_dbs'] ?? []]];
    }
    if ($method === 'GET' && $r('/disk')) {
        return [200, ['data' => $s['disk'] ?? []]];
    }

    // Firewall (API 1.44 / Cipi 5.14.0): UFW, fail2ban, CrowdSec
    if ($r('/firewall(/rules|/blocks|/attempts|/bans|/crowdsec/enable|/crowdsec/disable|/crowdsec/allowlist|/real-ip|/real-ip/refresh)?')) {
        return firewallRoute($method, $p(1), $body, $s, $state, $profile);
    }

    // IP whitelist
    if ($r('/ip-whitelist(/allow-all)?')) {
        return ipWhitelistRoute($method, $p(1), $body, $s);
    }

    throw new DemoHttpError(404, "No demo route for {$method} /api{$route}");
}

function appRoute(string $method, string $sub, string $name, array $body, array $query, string $rawBody, array &$s, array &$state, string $profile): array
{
    $a = &$s['apps'][$name];
    $info = &$a['info'];
    $isNode = ! empty($info['node']);
    $isCustom = ! empty($info['custom']);
    $isLaravel = ! $isNode && ! $isCustom;
    $job = function (string $type, array $params = []) use (&$state, $profile, $name): array {
        return [202, dispatchJob($state, $profile, $type, ['app' => $name] + $params)];
    };
    $laravelOnly = function () use ($isLaravel, $isNode) {
        if (! $isLaravel) {
            throw new DemoHttpError(422, $isNode ? 'Not available for Node apps' : 'Not available for custom apps');
        }
    };

    switch (true) {
        case $sub === '' && $method === 'GET':
            return [200, ['data' => publicApp($info, true)]];
        case $sub === '' && $method === 'PUT':
            $changes = array_intersect_key($body, array_flip(['php', 'branch', 'repository', 'domain', 'env', 'node', 'node_version', 'build', 'start', 'output', 'health_path']));
            if ($changes === []) {
                throw new DemoHttpError(422, 'Nothing to change');
            }
            if (isset($changes['env']) && ! preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', (string) $changes['env'])) {
                throw new DemoHttpError(422, 'env must be 1-32 lowercase letters, digits, - or _, starting with a letter or digit');
            }

            return $job('app-edit', ['changes' => $changes]);
        case $sub === '' && $method === 'DELETE':
            return $job('app-delete');
        case in_array($sub, ['/suspend', '/unsuspend'], true) && $method === 'POST':
            $want = $sub === '/suspend';
            if ((bool) $info['suspended'] === $want) {
                throw new DemoHttpError(409, $want ? "App '{$name}' is already suspended" : "App '{$name}' is not suspended");
            }

            return $job('app'.str_replace('/', '-', $sub));
        case $sub === '/fix-permissions' && $method === 'POST':
            return $job('app-fix-permissions');
        case $sub === '/webhook/recreate' && $method === 'POST':
            return $job('app-webhook-recreate', ['rotate' => (bool) ($body['rotate_secret'] ?? false)]);

        // Logs
        case $sub === '/logs' && $method === 'GET':
            return [200, ['data' => appLogs($name, $info, $query, $a['audit'] ?? [])]];

        // Basic auth
        case $sub === '/basicauth' && $method === 'GET':
            return [200, ['data' => $a['basic_auth']]];
        case $sub === '/basicauth/enable' && $method === 'POST':
            $user = (string) ($body['user'] ?? 'admin');
            $password = (string) ($body['password'] ?? '');
            $generated = $password === '' ? randomSecret(22) : null;
            $a['basic_auth'] = ['enabled' => true, 'users' => [$user]];
            $info['basic_auth'] = true;

            return [200, ['data' => array_filter(['app' => $name, 'enabled' => true, 'user' => $user, 'users' => [$user], 'password' => $generated])]];
        case $sub === '/basicauth/disable' && $method === 'POST':
            if (empty($a['basic_auth']['enabled'])) {
                throw new DemoHttpError(409, 'Basic auth is not enabled');
            }
            $a['basic_auth'] = ['enabled' => false, 'users' => []];
            $info['basic_auth'] = false;

            return [200, ['data' => ['app' => $name, 'enabled' => false]]];

        // .env
        case $sub === '/env' && $method === 'GET':
            $laravelOnly();

            return [200, ['data' => ['app' => $name, 'vars' => $a['env']]]];
        case $sub === '/env' && $method === 'PUT':
            $laravelOnly();
            foreach ((array) ($body['set'] ?? []) as $k => $v) {
                if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $k)) {
                    throw new DemoHttpError(422, "Invalid key: {$k}");
                }
                $a['env'][$k] = (string) $v;
            }
            foreach ((array) ($body['unset'] ?? []) as $k) {
                unset($a['env'][$k]);
            }

            return [200, ['data' => ['app' => $name, 'vars' => $a['env']]]];

        // auth.json
        case $sub === '/auth' && $method === 'GET':
            if (($a['auth'] ?? null) === null) {
                throw new DemoHttpError(404, "auth.json not found for '{$name}'");
            }

            return [200, ['data' => ['app' => $name, 'content' => $a['auth']]]];
        case $sub === '/auth' && $method === 'POST':
            if (($a['auth'] ?? null) !== null && empty($body['force'])) {
                throw new DemoHttpError(409, 'auth.json already exists');
            }
            $a['auth'] = ['http-basic' => (object) []];

            return [201, ['data' => ['app' => $name, 'content' => $a['auth']]]];
        case $sub === '/auth' && $method === 'PUT':
            $decoded = json_decode($rawBody, true);
            if (! is_array($decoded)) {
                throw new DemoHttpError(422, 'Body must be a JSON object');
            }
            $a['auth'] = $decoded;

            return [200, ['data' => ['app' => $name, 'content' => $a['auth']]]];
        case $sub === '/auth' && $method === 'DELETE':
            $a['auth'] = null;

            return [200, ['data' => ['app' => $name, 'deleted' => true]]];

        // Artisan + run
        case $sub === '/artisan' && $method === 'POST':
            $laravelOnly();
            $command = trim((string) ($body['command'] ?? ''));
            if ($command === '' || preg_match('/^(tinker|serve|pail)\b/', $command)) {
                throw new DemoHttpError(422, 'Interactive or long-running Artisan commands are not allowed');
            }

            return $job('app-artisan', ['command' => $command]);
        case $sub === '/run' && $method === 'POST':
            $command = trim((string) ($body['command'] ?? ''));
            if ($command === '' || preg_match('/[;&|`<>]|\.\./', $command)) {
                throw new DemoHttpError(422, 'Command refused: shell metacharacters and path traversal are not allowed');
            }
            if (preg_match('/^(nano|vim?|less|more|bash|sh|zsh|top|htop)\b/', $command)) {
                throw new DemoHttpError(422, 'Command refused: interactive programs are not allowed');
            }

            return $job('app-run', ['command' => $command]);

        // Deploy config
        case $sub === '/deploy-config' && $method === 'GET':
        case $sub === '/deploy-config' && $method === 'PUT':
            // A Node app has deploy options too: the releases kept, the snapshot, the two clocks.
            if ($isNode) {
                $nodeKeys = ['keep_releases' => 5, 'predeploy_snapshot' => false, 'build_timeout' => 1800, 'step_timeout' => 300];
                $a['deploy_config'] = array_intersect_key(($a['deploy_config'] ?? []) + $nodeKeys, $nodeKeys);
                if ($method === 'PUT') {
                    if (array_diff_key(array_filter($body, fn ($v) => $v !== null), $nodeKeys) !== []) {
                        return [422, ['error' => "'{$name}' is a Node app: its recipe has no artisan hooks."]];
                    }
                    $a['deploy_config'] = array_merge($a['deploy_config'], array_intersect_key($body, $nodeKeys));
                }

                return [200, ['data' => ['app' => $name, 'runtime' => 'node'] + $a['deploy_config']]];
            }
            $laravelOnly();
            $a['deploy_config'] += ['build_timeout' => 1800, 'step_timeout' => 300];
            if ($method === 'PUT') {
                $a['deploy_config'] = array_merge($a['deploy_config'], array_intersect_key($body, $a['deploy_config']));
            }

            return [200, ['data' => $a['deploy_config'] + ['app' => $name]]];

        // Aliases
        case $sub === '/aliases' && $method === 'GET':
            return [200, ['data' => array_values($info['aliases'])]];
        case (bool) preg_match('#^/aliases/([^/]+)$#', $sub, $am) && $method === 'POST':
            if (in_array($am[1], $info['aliases'], true)) {
                throw new DemoHttpError(409, "Alias '{$am[1]}' already exists");
            }

            return $job('alias-create', ['alias' => $am[1]]);
        case (bool) preg_match('#^/aliases/([^/]+)$#', $sub, $am) && $method === 'DELETE':
            return $job('alias-delete', ['alias' => $am[1]]);

        // WWW
        case $sub === '/www' && $method === 'GET':
            return [200, ['data' => wwwStatus($name, $info)]];
        case in_array($sub, ['/www/add', '/www/force-to-root', '/www/force-from-root', '/www/clear'], true) && $method === 'POST':
            return $job('www-'.substr($sub, 5));

        // Redirects
        case $sub === '/redirects' && $method === 'GET':
            return [200, ['data' => redirectsView($name, $info)]];
        case $sub === '/redirect' && $method === 'PUT':
            $to = (string) ($body['to'] ?? '');
            if (! preg_match('#^https?://#', $to)) {
                throw new DemoHttpError(422, 'to must be an absolute http(s) URL');
            }
            if (str_contains($to, '//'.$info['domain'])) {
                throw new DemoHttpError(422, "Refused: {$to} is served by this app (redirect loop)");
            }
            $info['redirect'] = ['enabled' => true, 'to' => $to, 'code' => (int) ($body['code'] ?? 301), 'keep_path' => (bool) ($body['keep_path'] ?? true)];

            return [200, ['data' => redirectsView($name, $info)]];
        case in_array($sub, ['/redirect/enable', '/redirect/disable'], true) && $method === 'POST':
            if ($info['redirect'] === null) {
                throw new DemoHttpError(422, 'No saved redirect — set one first');
            }
            $info['redirect']['enabled'] = $sub === '/redirect/enable';

            return [200, ['data' => redirectsView($name, $info)]];
        case $sub === '/redirect' && $method === 'DELETE':
            $info['redirect'] = null;

            return [200, ['data' => redirectsView($name, $info)]];
        case $sub === '/redirects' && $method === 'POST':
            $from = (string) ($body['from'] ?? '');
            if (! str_starts_with($from, '/')) {
                throw new DemoHttpError(422, 'from must start with /');
            }
            $rule = ['from' => $from, 'to' => (string) ($body['to'] ?? ''), 'code' => (int) ($body['code'] ?? 301), 'keep_path' => (bool) ($body['keep_path'] ?? true)];
            $info['redirects'] = array_values(array_filter($info['redirects'], fn ($x) => $x['from'] !== $from));
            $info['redirects'][] = $rule;

            return [200, ['data' => redirectsView($name, $info)]];
        case $sub === '/redirects' && $method === 'DELETE':
            $from = (string) ($body['from'] ?? '');
            $info['redirects'] = array_values(array_filter($info['redirects'], fn ($x) => $x['from'] !== $from));

            return [200, ['data' => redirectsView($name, $info)]];

        // Proxies
        case $sub === '/proxies' && $method === 'GET':
            return [200, ['data' => ['app' => $name, 'proxies' => $info['proxies']]]];
        case $sub === '/proxies' && $method === 'POST':
            $upstream = (string) ($body['upstream'] ?? '');
            if (preg_match('#127\.0\.0\.1:(22|80|443|3306|5432|6379|7700)\b#', $upstream, $pm)) {
                throw new DemoHttpError(422, "Refused: port {$pm[1]} is used by Cipi (loopback guard)");
            }
            $prefix = (string) ($body['prefix'] ?? '');
            $info['proxies'] = array_values(array_filter($info['proxies'], fn ($x) => $x['prefix'] !== $prefix));
            $info['proxies'][] = [
                'prefix' => $prefix, 'upstream' => $upstream,
                'strip_prefix' => (bool) ($body['strip_prefix'] ?? false), 'preserve_host' => (bool) ($body['preserve_host'] ?? false),
                'timeout' => (int) ($body['timeout'] ?? 60), 'buffering' => (bool) ($body['buffering'] ?? true),
            ];

            return [200, ['data' => ['app' => $name, 'proxies' => $info['proxies']]]];
        case $sub === '/proxies' && $method === 'DELETE':
            $prefix = (string) ($body['prefix'] ?? '');
            $info['proxies'] = array_values(array_filter($info['proxies'], fn ($x) => $x['prefix'] !== $prefix));

            return [200, ['data' => ['app' => $name, 'proxies' => $info['proxies']]]];

        // The nginx page cache of an app (cipi/api 1.47, Cipi 5.17.0).
        case $sub === '/cache' && $method === 'GET':
            return [200, ['data' => pageCacheView($name, $info)]];
        case $sub === '/cache' && $method === 'PUT':
            if ($isNode) {
                throw new DemoHttpError(422, "'{$name}' is a Node app: its pages come from its own server, and the page cache is written for PHP apps");
            }
            $cache = ($info['page_cache'] ?? []) + ['enabled' => false, 'ttl' => '10m', 'size' => '256m', 'clear_on_deploy' => true, 'files' => 0];
            if (! empty($body['enabled'])) {
                foreach (['ttl' => '/^[1-9][0-9]{0,6}(s|m|h|d)$/', 'size' => '/^[1-9][0-9]{0,5}(m|g)$/'] as $key => $pattern) {
                    if (isset($body[$key]) && $body[$key] !== '') {
                        if (preg_match($pattern, (string) $body[$key]) !== 1) {
                            throw new DemoHttpError(422, "{$key}: not a value the page cache takes");
                        }
                        $cache[$key] = (string) $body[$key];
                    }
                }
                if (array_key_exists('clear_on_deploy', $body) && $body['clear_on_deploy'] !== null) {
                    $cache['clear_on_deploy'] = (bool) $body['clear_on_deploy'];
                }
                if (is_array($body['exclude'] ?? null)) {
                    $cache['exclude'] = pageCacheExclude($body['exclude']);
                }
                if (! $cache['enabled']) {
                    // Just turned on: nothing is kept yet.
                    $cache['files'] = 0;
                    $cache['since'] = time();
                }
                $cache['enabled'] = true;
            } else {
                if (array_filter(array_intersect_key($body, array_flip(['ttl', 'size', 'clear_on_deploy', 'exclude'])), fn ($v) => $v !== null && $v !== '') !== []) {
                    throw new DemoHttpError(422, 'ttl, size, clear_on_deploy and exclude apply when enabled is true (the list alone: PUT /apps/{name}/cache/exclude)');
                }
                $cache['enabled'] = false;
                $cache['files'] = 0;
            }
            $info['page_cache'] = $cache;

            return [200, ['data' => pageCacheView($name, $info)]];
        // The app's own paths that are never cached: the whole list, cache on or off.
        case $sub === '/cache/exclude' && $method === 'PUT':
            if ($isNode) {
                throw new DemoHttpError(422, "'{$name}' is a Node app: its pages come from its own server, and the page cache is written for PHP apps");
            }
            if (! is_array($body['exclude'] ?? null)) {
                throw new DemoHttpError(422, 'The exclude field must be present.');
            }
            $info['page_cache'] = ['exclude' => pageCacheExclude($body['exclude'])] + ($info['page_cache'] ?? []);

            return [200, ['data' => pageCacheView($name, $info)]];
        case $sub === '/cache/clear' && $method === 'POST':
            $cleared = (int) (pageCacheView($name, $info)['files'] ?? 0);
            if (isset($info['page_cache'])) {
                $info['page_cache']['files'] = 0;
                $info['page_cache']['since'] = time();
            }

            return [200, ['data' => pageCacheView($name, $info) + ['cleared' => $cleared]]];
        case $sub === '/cache/purge' && $method === 'POST':
            $paths = is_array($body['paths'] ?? null) ? array_values($body['paths']) : [];
            if ($paths === [] || count($paths) > 500) {
                throw new DemoHttpError(422, 'paths: from 1 to 500 pages');
            }
            foreach ($paths as $p) {
                if (! is_string($p) || ! preg_match('#^(https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?)?/[A-Za-z0-9._~%:@!,;=+/-]{0,2047}\*?$#', $p)) {
                    throw new DemoHttpError(422, 'paths: a page is a path (/blog/post/), a prefix (/blog/*) or a URL on a domain of the app');
                }
            }
            $view = pageCacheView($name, $info);
            $on = (bool) ($view['enabled'] ?? false);
            // About two files a page (http and https, a ?utm variant), never more than there are.
            $purged = $on ? min((int) ($view['files'] ?? 0), 2 * count($paths)) : 0;
            if ($on && isset($info['page_cache'])) {
                $info['page_cache']['files'] = max(0, (int) ($view['files'] ?? 0) - $purged);
            }

            return [200, ['data' => ['app' => $name, 'enabled' => $on, 'purged' => $purged, 'targets' => $paths]]];

        // The PHP of an Octane app (cipi/api 1.47, Cipi 5.17.0).
        case $sub === '/octane' && $method === 'GET':
            if (empty($info['octane'])) {
                throw new DemoHttpError(422, "App '{$name}' does not run on Octane");
            }

            return [200, ['data' => octaneView($name, $info)]];
        case $sub === '/octane/php' && $method === 'PUT':
            if (empty($info['octane'])) {
                throw new DemoHttpError(422, "App '{$name}' does not run on Octane");
            }
            if (preg_match('/^[0-9]\.[0-9]$/', (string) ($body['php'] ?? '')) !== 1) {
                throw new DemoHttpError(422, 'The php field format is invalid.');
            }

            return $job('octane-php', ['php' => (string) $body['php']]);
        case $sub === '/octane/restart' && $method === 'POST':
            if (empty($info['octane'])) {
                throw new DemoHttpError(422, "App '{$name}' does not run on Octane");
            }

            return $job('octane-restart');

        // An app's own nginx directives (cipi/api 1.44). The real server tests them with
        // nginx -t and takes a subset of the language from the API; here a few words stand in.
        case $sub === '/nginx' && $method === 'GET':
            return [200, ['data' => nginxView($name, $info)]];
        case $sub === '/nginx' && $method === 'PUT':
            $config = str_replace(["\r\n", "\r"], "\n", (string) ($body['config'] ?? ''));
            $reasons = [];
            foreach (explode("\n", $config) as $i => $line) {
                if (preg_match('/^\s*(access_log\s+\/|error_log|include|listen|server_name|\w+_pass)\b/', $line, $m)) {
                    $reasons[] = 'line '.($i + 1).': `'.trim(preg_replace('/\s.*$/', '', trim($m[1]))).'` is not allowed from the API';
                } elseif (preg_match('/^\s*boom\b/', $line)) {
                    $reasons[] = 'line '.($i + 1).': unknown directive "boom"';
                }
            }
            if ($reasons !== []) {
                $first = str_contains($reasons[0], 'unknown directive') ? 'nginx refused the directives — nothing changed' : 'Not accepted from the API — the whole nginx language is for \'cipi nginx edit '.$name.'\' at the server';

                return [422, ['error' => $first, 'reasons' => array_merge([$first], $reasons)]];
            }
            $info['nginx'] = trim($config) === '' ? '' : rtrim($config, "\n")."\n";

            return [200, ['data' => nginxView($name, $info)]];
        case $sub === '/nginx' && $method === 'DELETE':
            $info['nginx'] = '';

            return [200, ['data' => nginxView($name, $info)]];

        // Node
        case $sub === '/node' && $method === 'GET':
            return [200, ['data' => array_filter([
                'app' => $name, 'node' => $isNode, 'mode' => $info['node_mode'], 'framework' => $info['framework'],
                'version' => $info['node_version'], 'build' => $info['build'], 'start' => $info['start'],
                'health_path' => $info['health_path'], 'output' => $info['output'],
            ], fn ($v) => $v !== null)]];
        case $sub === '/node/restart' && $method === 'POST':
            if (($info['node_mode'] ?? null) !== 'ssr') {
                throw new DemoHttpError(409, 'Only SSR Node apps run a process to restart');
            }

            return $job('node-restart');

        // Deploy
        case in_array($sub, ['/deploy', '/deploy/rollback', '/deploy/unlock'], true) && $method === 'POST':
            return $job(str_replace('/', '-', 'app'.$sub));
        case $sub === '/deploy/audit' && $method === 'GET':
            $days = max(1, min(3650, (int) ($query['days'] ?? 90)));
            $since = time() - $days * 86400;
            $records = array_values(array_filter($a['audit'] ?? [], fn ($x) => strtotime($x['ts']) >= $since));

            return [200, ['data' => ['app' => $name, 'records' => $records]]];
        // `cipi deploy <app> --status --json` (API 1.37+ / Cipi 5.8.4+)
        case $sub === '/deploy/status' && $method === 'GET':
            return [200, ['data' => deployStatusView($state, $profile, $name, $a, max(0, min(500, (int) ($query['tail'] ?? 0))))]];

        // cipi.yml (API 1.35+): `cipi yml plan --json`, apply, auto on/off; `env` since 1.36
        case $sub === '/yml' && $method === 'GET':
            return [200, ['data' => ymlView($name, $a['yml'] ?? null, (string) ($info['env'] ?? 'production'))]];
        case $sub === '/yml/apply' && $method === 'POST':
            return $job('app-yml-apply');
        case in_array($sub, ['/yml/auto/enable', '/yml/auto/disable'], true) && $method === 'POST':
            $on = $sub === '/yml/auto/enable';
            if (isset($a['yml'])) {
                $a['yml']['auto'] = $on;
            } elseif ($on) {
                $a['yml'] = ['file' => null, 'auto' => true];
            }

            return [200, ['data' => ['app' => $name, 'auto' => $on, 'sudoers' => $on, 'file' => $a['yml']['file'] ?? null]]];

        // SSL
        case $sub === '/ssl' && $method === 'POST':
            return $job('ssl-install');
        case $sub === '/ssl/force' && $method === 'POST':
            return $job('ssl-force');

        // Health
        case $sub === '/health' && $method === 'GET':
            return [200, ['data' => $a['health']]];
        case $sub === '/health' && $method === 'PUT':
            $a['health'] = [
                'enabled' => true, 'url' => (string) ($body['url'] ?? 'https://'.$info['domain'].'/up'),
                'expect' => (int) ($body['expect'] ?? 200), 'state' => null, 'failcount' => 0,
            ];

            return [200, ['data' => $a['health']]];
        case $sub === '/health' && $method === 'DELETE':
            $a['health'] = ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0];

            return [200, ['data' => $a['health']]];
        case $sub === '/health/check' && $method === 'POST':
            if (empty($a['health']['enabled'])) {
                throw new DemoHttpError(422, "No healthcheck configured for '{$name}'");
            }
            $failing = ($a['health']['state'] ?? null) === 'fail';
            $got = $failing ? '502' : (string) $a['health']['expect'];
            $a['health']['state'] = $failing ? 'fail' : 'ok';

            return [200, ['data' => ['app' => $name, 'url' => $a['health']['url'], 'expect' => $a['health']['expect'], 'got' => $got, 'ok' => ! $failing]]];

        // Search
        case in_array($sub, ['/search/enable', '/search/disable'], true) && $method === 'POST':
            $laravelOnly();
            if ($sub === '/search/enable') {
                if (empty($s['search']['installed'])) {
                    throw new DemoHttpError(422, 'Meilisearch is not installed on this server (cipi search install)');
                }
                $s['search']['apps'][$name] = ['prefix' => $name.'_', 'key' => 'scoped'];
                $a['env']['SCOUT_DRIVER'] = 'meilisearch';
            } else {
                unset($s['search']['apps'][$name]);
                $a['env']['SCOUT_DRIVER'] = 'database';
            }

            return [200, ['data' => ['app' => $name, 'enabled' => $sub === '/search/enable']]];
    }

    throw new DemoHttpError(404, "No demo route for {$method} /api/apps/{$name}{$sub}");
}

function smtpRoute(string $method, string $action, array $body, array &$s): array
{
    if ($action === '' && $method === 'GET') {
        return [200, ['data' => $s['smtp']]];
    }
    if ($action === '' && $method === 'PUT') {
        foreach (['host', 'user', 'from', 'to'] as $required) {
            if (empty($body[$required])) {
                throw new DemoHttpError(422, "The {$required} field is required.");
            }
        }
        if (empty($s['smtp']['configured']) && empty($body['password'])) {
            throw new DemoHttpError(422, 'password is required when configuring SMTP for the first time');
        }
        $s['smtp'] = [
            'configured' => true, 'enabled' => (bool) ($body['enabled'] ?? true), 'host' => $body['host'],
            'port' => (string) ($body['port'] ?? 587), 'user' => $body['user'], 'from' => $body['from'],
            'to' => $body['to'], 'tls' => (bool) ($body['tls'] ?? true),
        ];

        return [200, ['data' => $s['smtp'], 'message' => 'SMTP saved'.(! empty($body['test']) ? ' — test email sent to '.$body['to'] : '')]];
    }
    if ($action === '' && $method === 'DELETE') {
        $s['smtp'] = ['configured' => false, 'enabled' => false, 'host' => null, 'port' => null, 'user' => null, 'from' => null, 'to' => null, 'tls' => null];

        return [200, ['data' => $s['smtp']]];
    }
    if ($method === 'POST' && empty($s['smtp']['configured'])) {
        throw new DemoHttpError(422, 'SMTP is not configured');
    }
    if ($action === '/enable' || $action === '/disable') {
        $s['smtp']['enabled'] = $action === '/enable';
    }

    return [200, ['data' => $s['smtp'], 'message' => $action === '/test' ? 'Test email sent to '.$s['smtp']['to'] : 'OK']];
}

// ── Firewall (API 1.44 / Cipi 5.14.0) ────────────────────────────────────

function demoFirewall(): array
{
    $rule = fn (string $action, string $port, string $proto, string $from, string $family, string $kind, bool $protected = false, string $managed = '', string $comment = '') => [
        'action' => $action, 'port' => $port, 'proto' => $proto, 'from' => $from, 'to' => 'any', 'direction' => 'in', 'interface' => '', 'app' => '',
        'comment' => $comment, 'family' => $family, 'log' => false, 'kind' => $kind, 'managed_by' => $managed, 'protected' => $protected,
        'removable' => in_array($kind, ['port', 'block'], true) && $managed === '' && ! $protected,
    ];

    return [
        'ufw' => ['installed' => true, 'active' => true, 'default_incoming' => 'deny', 'default_outgoing' => 'allow', 'rules' => [
            $rule('deny', 'any', 'any', '185.220.101.47', 'v4', 'block'),
            $rule('allow', '22', 'tcp', 'any', 'both', 'port', true),
            $rule('allow', '80', 'tcp', 'any', 'both', 'port', true),
            $rule('allow', '443', 'tcp', 'any', 'both', 'port', true),
            $rule('allow', '9100', 'tcp', '203.0.113.5', 'v4', 'port', false, 'metrics', 'cipi metrics'),
            $rule('allow', '5432', 'tcp', '10.10.0.0/16', 'v4', 'port'),
        ]],
        'protected_ports' => [22, 80, 443],
        'zero_trust' => ['lock_http' => false, 'lock_ssh' => false, 'by' => ['http' => [], 'ssh' => []]],
        'fail2ban' => ['installed' => true, 'running' => true, 'attempts' => 3, 'attempts_default' => 3, 'attempts_custom' => null, 'attempts_pending' => false,
            'findtime' => 3600, 'bantime' => 86400, 'jails' => [
                ['jail' => 'recidive', 'banned' => [], 'count' => 0, 'total' => 2, 'failed' => 0],
                ['jail' => 'sshd', 'banned' => ['193.32.162.38', '218.92.0.112'], 'count' => 2, 'total' => 41, 'failed' => 3],
            ]],
        'crowdsec' => ['installed' => false, 'running' => false, 'bouncer' => 'not installed', 'mode' => '', 'rescue' => 'stopped', 'rescue_port' => null,
            'allowlist' => [], 'decisions' => [], 'ram_available_mb' => 2810, 'ram_required_mb' => 512, 'enable_blockers' => []],
        'real_ip' => ['enabled' => false, 'provider' => null, 'header' => null, 'kept_by' => null, 'ranges' => 0,
            'refreshed_at' => null, 'nightly_refresh' => false, 'can_disable' => false, 'bans_active' => true],
    ];
}

/**
 * `POST|DELETE /zt/mesh`, `POST|DELETE /zt/mesh/lock`, `PUT /zt/mesh/ssh`: the
 * mesh network as the server would change it. The demo panel reaches its
 * servers on their public address, so closing 80/443 is refused the way the
 * CLI refuses it (fra1's API is on the tunnel: there it goes); SSH is closed
 * once somebody has signed in over the mesh — which, on a server that has
 * just joined, nobody has.
 */
function ztMeshRoute(string $method, string $action, array $body, array &$s): array
{
    $zt = &$s['zt'];
    if (! is_array($zt['details'] ?? null)) {
        throw new DemoHttpError(503, 'The mesh network from the panel needs Cipi 5.18.0+ — this server runs Cipi 5.15.2');
    }
    $mesh = is_array($zt['details']['mesh'] ?? null) ? $zt['details']['mesh'] : ['enabled' => false, 'provider' => null];
    $on = ! empty($mesh['enabled']);
    $labels = ['tailscale' => 'Tailscale', 'netbird' => 'NetBird'];
    $save = function (array $mesh, string $message) use (&$zt): array {
        if (! empty($mesh['enabled'])) {
            $label = $mesh['label'];
            $seen = $mesh['ssh']['seen'] ?? null;
            $via = $mesh['panel']['via'] ?? 'public';
            $mesh['panel'] = ['via' => $via, 'from' => '203.0.113.10', 'client' => null, 'blocked' => [
                'lock_ssh' => ! empty($mesh['ssh']['provider_ssh'])
                    ? "{$label} SSH answers on the mesh address and its sessions are not in sshd's log: lock from a session over the network, at the server's shell (cipi zt {$mesh['provider']} lock ssh)."
                    : ($seen === null ? "Nobody has signed in over SSH through {$label} yet. Do it once from a device of the network (ssh cipi@{$mesh['ip']}): that login is the proof that it works, and then the port can be closed." : null),
                'lock_http' => in_array($via, ['mesh', 'local', 'tunnel'], true) ? null
                    : "The panel reaches this server's API on its public address (from 203.0.113.10): with 80/443 closed it could not reach it any more, not even to unlock. Have the panel call the API over {$label} (the API's DNS record on {$mesh['ip']}) or through the Cloudflare tunnel, or lock at the server's shell (cipi zt {$mesh['provider']} lock http).",
                'disable' => $via === 'mesh' ? "The panel reaches this server over {$label}: leaving the network would cut it off. Do it at the server's shell (cipi zt {$mesh['provider']} disable)." : null,
            ]];
        }
        $zt['details']['mesh'] = $mesh;
        $zt['mesh'] = ! empty($mesh['enabled']) ? $mesh['provider'] : null;
        $zt['mesh_state'] = ! empty($mesh['enabled']) ? 'connected' : null;
        $zt['mesh_ip'] = ! empty($mesh['enabled']) ? $mesh['ip'] : null;

        return [200, ['data' => $zt, 'message' => $message]];
    };

    if ($action === '' && $method === 'POST') {
        $provider = (string) ($body['provider'] ?? '');
        if (! isset($labels[$provider])) {
            throw new DemoHttpError(422, "'provider' is tailscale or netbird");
        }
        if ($on) {
            throw new DemoHttpError(422, "This server is on {$mesh['label']} already: one mesh network at a time (cipi zt {$mesh['provider']} disable first)");
        }
        $key = (string) ($body['key'] ?? '');
        if ($key === '') {
            throw new DemoHttpError(422, "{$labels[$provider]} is not installed on this server: give a key to join with");
        }
        if (! preg_match('/^[A-Za-z0-9._~:?&=%+\/-]{8,512}$/', $key)) {
            throw new DemoHttpError(422, 'That does not look like a key (8 to 512 characters, no spaces, no quotes)');
        }
        $name = (string) ($body['hostname'] ?? '') ?: (string) ($s['status']['system']['hostname'] ?? 'server');
        $tags = array_values(array_filter((array) ($body['tags'] ?? [])));
        $tailscale = $provider === 'tailscale';
        $ip = $tailscale ? '100.90.14.22' : '100.76.3.41';

        return $save([
            'enabled' => true, 'provider' => $provider, 'label' => $labels[$provider], 'installed' => true, 'installed_by_cipi' => true,
            'running' => true, 'connected' => true, 'state' => $tailscale ? 'Running' : 'Connected', 'online' => true,
            'interface' => $tailscale ? 'tailscale0' : 'wt0', 'interface_up' => true, 'ip' => $ip, 'ip6' => null, 'name' => $name,
            'fqdn' => $tailscale ? $name.'.tail1234.ts.net' : $name.'.netbird.cloud', 'network' => $tailscale ? 'example.org' : null,
            'control_url' => ($body['control_url'] ?? '') ?: null, 'version' => $tailscale ? '1.80.2' : '0.36.5', 'tags' => $tailscale ? $tags : [],
            // An untagged Tailscale machine has a node key that expires: lock ssh will ask for that to be settled.
            'key_expiry' => $tailscale && $tags === [] ? gmdate('Y-m-d\TH:i:s\Z', time() + 180 * 86400) : null,
            'key_expires_in_days' => $tailscale && $tags === [] ? 180 : null,
            'peers' => ['total' => 4, 'online' => 3],
            'ssh' => ['provider_ssh' => $tailscale && ! empty($body['ssh']), 'ports' => [22], 'command' => 'ssh cipi@'.$ip, 'seen' => null],
            'lock' => ['ssh' => false, 'http' => false], 'direct' => ['port' => $tailscale ? 41641 : 51820, 'open' => ! empty($body['direct'])],
            'accept_dns' => ! empty($body['accept_dns']), 'accept_routes' => ! empty($body['accept_routes']),
            'firewall_active' => true, 'http01_apps' => [], 'enabled_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'warnings' => $tailscale && $tags === [] ? ['The node key expires in 180 days: disable key expiry for this machine, or join with a tagged key'] : [],
        ], "Installing {$labels[$provider]}...\n{$labels[$provider]}: {$name} is on the network as {$ip}\nNothing was closed: SSH and 80/443 answer as before.");
    }

    if (! $on) {
        throw new DemoHttpError(422, 'This server is on no mesh network');
    }
    $label = (string) $mesh['label'];
    $blocked = (array) ($mesh['panel']['blocked'] ?? []);

    if ($action === '' && $method === 'DELETE') {
        if (! empty($blocked['disable'])) {
            throw new DemoHttpError(422, (string) $blocked['disable']);
        }

        return $save(['enabled' => false, 'provider' => null, 'active' => null], "Reopening what the locks closed...\n{$label} removed: this server left the network.");
    }
    if ($action === '/lock') {
        $port = (string) ($body['port'] ?? '');
        if (! in_array($port, ['ssh', 'http'], true)) {
            throw new DemoHttpError(422, "'port' is ssh or http");
        }
        if ($method === 'POST') {
            if (! empty($blocked['lock_'.$port])) {
                throw new DemoHttpError(422, (string) $blocked['lock_'.$port]);
            }
            if ($port === 'ssh' && ! empty($mesh['key_expiry'])) {
                throw new DemoHttpError(422, 'The node key of this machine expires on '.substr((string) $mesh['key_expiry'], 0, 10).": that day the server leaves the network, and with port 22 closed the provider's console is the only way in. Disable key expiry for the machine, or join again with a tagged key.");
            }
            $mesh['lock'][$port] = true;

            return $save($mesh, $port === 'ssh' ? "SSH is closed to the world: port 22 answers on {$label} only." : "80/443 are closed to the world: they answer on {$label} only.");
        }
        if ($method === 'DELETE') {
            $mesh['lock'][$port] = false;

            return $save($mesh, $port === 'ssh' ? 'SSH is open to the world again.' : '80/443 are open to the world again.');
        }
    }
    if ($action === '/ssh' && $method === 'PUT') {
        if ($mesh['provider'] !== 'tailscale') {
            throw new DemoHttpError(422, "NetBird's own SSH server is not turned on by Cipi: SSH over NetBird is OpenSSH, with the server's keys");
        }
        $mesh['ssh']['provider_ssh'] = ! empty($body['enabled']);

        return $save($mesh, 'Tailscale SSH is '.($mesh['ssh']['provider_ssh'] ? 'on' : 'off').'.');
    }

    throw new DemoHttpError(405, 'Method not allowed');
}

/**
 * The firewall as Zero Trust leaves it: who holds a lock (`zero_trust.by`),
 * and the rules a lock replaces — the mesh interface let through, the SSH
 * port and 80/443 no longer open to everyone while a lock holds them.
 */
function demoFirewallZeroTrust(array &$fw, array $zt): void
{
    $d = is_array($zt['details'] ?? null) ? $zt['details'] : [];
    $cf = ! empty($d['enabled']) && is_array($d['lock'] ?? null) ? $d['lock'] : [];
    $mesh = is_array($d['mesh'] ?? null) && ! empty($d['mesh']['enabled']) ? $d['mesh'] : null;
    $by = ['http' => [], 'ssh' => []];
    foreach (['http', 'ssh'] as $port) {
        if (! empty($cf[$port])) {
            $by[$port][] = 'cloudflare';
        }
        if ($mesh !== null && ! empty($mesh['lock'][$port])) {
            $by[$port][] = (string) $mesh['provider'];
        }
    }
    $fw['zero_trust'] = ['lock_http' => $by['http'] !== [], 'lock_ssh' => $by['ssh'] !== [], 'by' => $by];

    $rule = fn (string $port, string $from, string $family, string $comment, string $interface = '') => [
        'action' => 'allow', 'port' => $port, 'proto' => $port === 'any' ? 'any' : 'tcp', 'from' => $from, 'to' => 'any', 'direction' => 'in', 'interface' => $interface, 'app' => '',
        'comment' => $comment, 'family' => $family, 'log' => false, 'kind' => $port === 'any' ? 'other' : 'port', 'managed_by' => $comment === '' ? '' : 'zero-trust',
        'protected' => $comment === '', 'removable' => false,
    ];
    // What the server is reached on, as the locks leave it — ahead of the other allows, after the blocks.
    $reach = [];
    if ($mesh !== null) {
        $reach[] = $rule('any', 'any', 'both', 'cipi-zt-mesh', (string) ($mesh['interface'] ?? ''));
    }
    if ($by['ssh'] === []) {
        $reach[] = $rule('22', 'any', 'both', '');
    }
    if ($by['http'] === []) {
        $reach[] = $rule('80', 'any', 'both', '');
        $reach[] = $rule('443', 'any', 'both', '');
    } elseif (in_array('cloudflare', $by['http'], true) && ($cf['http_mode'] ?? '') !== 'dark' && count($by['http']) === 1) {
        $reach[] = $rule('80,443', 'Cloudflare (22 ranges)', 'both', 'cipi-zt-cf');
    }
    $rules = (array) ($fw['ufw']['rules'] ?? []);
    $blocks = array_values(array_filter($rules, fn ($r) => ($r['kind'] ?? '') === 'block'));
    $others = array_values(array_filter($rules, fn ($r) => ($r['kind'] ?? '') !== 'block' && ($r['managed_by'] ?? '') !== 'zero-trust' && empty($r['protected'])));
    $fw['ufw']['rules'] = array_merge($blocks, $reach, $others);
}

/**
 * `GET /sync/key`, `POST /sync/check`, `POST|DELETE /sync/trust`, `POST /sync/push`:
 * the apps of one server sent to another (`cipi sync`). The demo servers reach
 * each other on the addresses of their fixtures; any other host does not answer.
 */
function syncRoute(string $method, string $action, array $body, array &$s, array &$state, string $profile): array
{
    $s['sync'] ??= ['trusted' => []];
    $hostRe = '/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,252}[A-Za-z0-9])?$/';
    $target = function () use ($body, $hostRe): array {
        $host = trim((string) ($body['host'] ?? ''));
        $port = (int) ($body['port'] ?? 22);
        if (! preg_match($hostRe, $host)) {
            throw new DemoHttpError(422, 'host must be an IP address or a hostname');
        }
        if ($port < 1 || $port > 65535) {
            throw new DemoHttpError(422, 'port must be between 1 and 65535');
        }

        return [$host, $port];
    };
    $keyOf = function () use ($body): array {
        $key = preg_replace('/\s+/', ' ', trim((string) ($body['key'] ?? '')));
        if (! preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+/=]{40,} ?[\w@.:+-]{0,128}$#', $key)) {
            throw new DemoHttpError(422, 'That is not one SSH public key (type, key, comment — on one line)');
        }
        [$type, $blob] = explode(' ', $key);

        return ['key' => $key, 'type' => $type, 'blob' => $blob, 'comment' => explode(' ', $key, 3)[2] ?? '',
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($blob), true)), '=')];
    };

    if ($action === 'key' && $method === 'GET') {
        return [200, ['data' => syncDemoKey($s, $profile)]];
    }
    if ($action === 'check' && $method === 'POST') {
        [$host, $port] = $target();
        $base = ['ok' => false, 'host' => $host, 'port' => $port, 'user' => 'cipi', 'cipi' => null, 'auto_passphrase' => false, 'reason' => null, 'message' => ''];
        $peer = syncDemoPeer($state, $host);
        if ($peer === $profile) {
            throw new DemoHttpError(422, 'That address is this server');
        }
        if ($peer === null || $port !== 22) {
            return [200, ['data' => ['reason' => 'unreachable', 'message' => "No SSH answer from {$host}:{$port}"] + $base]];
        }
        $mine = explode(' ', syncDemoKey($s, $profile)['key'])[1];
        $trusted = array_column($state['servers'][$peer]['sync']['trusted'] ?? [], 'blob');
        if (! in_array($mine, $trusted, true)) {
            return [200, ['data' => ['reason' => 'not-trusted', 'message' => "{$host} does not accept this server's key"] + $base]];
        }

        return [200, ['data' => ['ok' => true, 'cipi' => '5.18.0', 'auto_passphrase' => true] + $base]];
    }
    if ($action === 'trust' && in_array($method, ['POST', 'DELETE'], true)) {
        $key = $keyOf();
        $at = array_search($key['blob'], array_column($s['sync']['trusted'], 'blob'), true);
        $changed = $method === 'POST' ? $at === false : $at !== false;
        if ($method === 'POST' && $at === false) {
            $s['sync']['trusted'][] = $key;
        } elseif ($method === 'DELETE' && $at !== false) {
            array_splice($s['sync']['trusted'], (int) $at, 1);
        }
        unset($key['blob']);

        return [200, ['data' => $key + ['changed' => $changed],
            'message' => $method === 'POST' ? 'The key is trusted: its server can push apps here.' : 'The key is no longer trusted here.']];
    }
    if ($action === 'push' && $method === 'POST') {
        [$host, $port] = $target();
        $apps = array_values(array_map('strval', is_array($body['apps'] ?? null) ? $body['apps'] : []));
        foreach ($apps as $app) {
            if (! isset($s['apps'][$app])) {
                throw new DemoHttpError(422, "App '{$app}' is not on this server");
            }
        }

        return [202, dispatchJob($state, $profile, 'sync-push', [
            'host' => $host, 'port' => $port, 'user' => 'cipi', 'apps' => $apps,
            'with_db' => ! empty($body['with_db']), 'with_storage' => ! empty($body['with_storage']),
        ])];
    }

    throw new DemoHttpError(405, "Method {$method} not allowed on /api/sync/{$action}");
}

/** A demo server's sync key: the same at every call, different on every server. */
function syncDemoKey(array $s, string $profile): array
{
    $raw = hash('sha256', 'cipi-demo-sync-'.$profile, true);
    $blob = base64_encode("\0\0\0\x0bssh-ed25519\0\0\0\x20".$raw);
    $comment = 'cipi@'.($s['status']['system']['hostname'] ?? $profile);

    return ['key' => "ssh-ed25519 {$blob} {$comment}", 'type' => 'ssh-ed25519', 'comment' => $comment,
        'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($blob), true)), '=')];
}

/** The demo server that answers on this address, if one does. */
function syncDemoPeer(array $state, string $host): ?string
{
    foreach ($state['servers'] as $name => $server) {
        $system = $server['status']['system'] ?? [];
        if (in_array($host, array_filter([$system['ip'] ?? null, $system['ipv6'] ?? null, $system['hostname'] ?? null, $server['zt']['mesh_ip'] ?? null]), true)) {
            return (string) $name;
        }
    }

    return null;
}

/** A finished transfer: the apps of the source are on the target too. [ok, output, result] */
function syncPushJob(array &$state, string $profile, array $p): array
{
    $source = &$state['servers'][$profile];
    $peer = syncDemoPeer($state, (string) $p['host']);
    $names = $p['apps'] !== [] ? $p['apps'] : array_keys($source['apps']);
    $out = ['→ Exporting '.count($names).' app(s)'.($p['with_db'] ? ', with databases' : '').($p['with_storage'] ? ', with storage' : '').'…',
        '→ Encrypting the archive (AES-256, one-time passphrase)…'];
    $mine = explode(' ', syncDemoKey($source, $profile)['key'])[1];
    if ($peer === null || ! in_array($mine, array_column($state['servers'][$peer]['sync']['trusted'] ?? [], 'blob'), true)) {
        return [false, implode("\n", $out)."\n✗ {$p['host']} does not accept this server's key — nothing was sent", null];
    }

    $target = &$state['servers'][$peer];
    $out[] = "→ Sending it to cipi@{$p['host']}:{$p['port']}…";
    $out[] = '→ Importing on the target…';
    $created = $updated = [];
    foreach ($names as $name) {
        if (! isset($source['apps'][$name])) {
            continue;
        }
        if (! empty($source['apps'][$name]['info']['node'])) {
            $out[] = "  skipped  {$name} (a Node app: sync does not handle Node apps yet)";

            continue;
        }
        isset($target['apps'][$name]) ? $updated[] = $name : $created[] = $name;
        $target['apps'][$name] = $source['apps'][$name];
        if ($p['with_db']) {
            foreach ($source['dbs'] ?? [] as $db) {
                if (($db['name'] ?? '') === $name && ! in_array($name, array_column($target['dbs'] ?? [], 'name'), true)) {
                    $target['dbs'][] = $db;
                }
            }
        }
        $out[] = '  '.(in_array($name, $created, true) ? 'created' : 'updated')."  {$name}";
    }
    $out[] = '→ Archive removed from both servers';
    $out[] = '✓ '.count($created).' created, '.count($updated).' updated on '.$p['host'];

    return [true, implode("\n", $out), ['host' => $p['host'], 'created' => $created, 'updated' => $updated]];
}

/**
 * `GET /backups`, `GET /backups/runs`, `POST /backups/destinations/test` and the
 * SFTP destinations (`POST /backups/destinations`, `PUT|DELETE
 * /backups/destinations/{name}`, `GET …/{name}/key`) — what `cipi backup status
 * --json` and `cipi backup sftp …` answer. One nightly profile to the default
 * bucket; no S3 destination can be added here, as on a real panel.
 */
function backupsRoute(string $method, string $sub, array $body, array &$s, array &$state, string $profile): array
{
    // The staging server has no backups yet: the setup card (this server, a bucket, an SFTP server).
    $s['backup_demo'] ??= $profile === 'staging' ? [
        'configured' => false, 'destinations' => [['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false]],
        'keys' => [], 'encryption_key' => 'tG4nW8xQ2mZ6pR0vB5cY9kL3hS7dF1jA4eU8iO2oP6qX0wE3rT7yN5uM9bV1zC4a', 'profile_to' => null,
    ] : [
        'configured' => true,
        'destinations' => [
            ['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false],
            ['name' => 's3', 'type' => 's3', 'bucket' => 'acme-backups', 'region' => 'fsn1', 'endpoint' => 'https://fsn1.your-objectstorage.com', 'default' => true],
        ],
        'keys' => [],
        'encryption_key' => 'q8Vd2kLx0Rf7Tn3Yb6Wm1Pz9Hs4Gc5Ja8Ue2Io7Kl0Mn3Bv6Cx9Zq1Wd4Ef7Rt2Yu',
        'profile_to' => ['s3'],
    ];
    $b = &$s['backup_demo'];
    $apps = array_keys($s['apps']);
    $dbs = array_map(fn ($a) => ['engine' => 'mariadb', 'name' => $a], array_values(array_filter($apps, fn ($a) => empty($s['apps'][$a]['info']['node']))));
    $profiles = $b['profile_to'] === null ? [] : [[
        'name' => 'nightly', 'enabled' => true, 'scope' => 'all', 'apps' => '*', 'databases' => '*', 'every' => '1d', 'cron' => '0 2 * * *',
        'destinations' => $b['profile_to'], 'keep_days' => 14, 'encrypt' => $b['profile_to'] === ['s3'], 'running' => false,
        'last' => $b['profile_to'] === ['s3'] ? ['status' => 'ok', 'epoch' => time() - 6 * 3600, 'detail' => 'ok'] : ['status' => null, 'epoch' => null, 'detail' => null],
        'covers' => ['apps' => $apps, 'databases' => $dbs],
    ]];
    $dests = array_map(function ($d) use ($profiles) {
        $d['profiles'] = array_values(array_map(fn ($p) => $p['name'], array_filter($profiles, fn ($p) => in_array($d['name'], $p['destinations'], true))));

        return $d;
    }, $b['destinations']);
    $find = function (string $name) use (&$b): ?int {
        foreach ($b['destinations'] as $i => $d) {
            if ($d['name'] === $name) {
                return $i;
            }
        }

        return null;
    };
    $key = fn (string $name) => $b['keys'][$name] ??= 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.substr(hash('sha256', $name), 0, 43).' cipi-backup-'.$name.'@'.($s['status']['system']['hostname'] ?? $profile);

    if ($sub === '' && $method === 'GET' && ! $b['configured']) {
        return [200, ['data' => ['configured' => false, 'host' => (string) ($s['status']['system']['hostname'] ?? $profile), 'config_file' => '/etc/cipi/backup.json']]];
    }
    if ($sub === '' && $method === 'GET') {
        return [200, ['data' => ['configured' => true, 'host' => (string) ($s['status']['system']['hostname'] ?? $profile), 'config_file' => '/etc/cipi/backup.json', 'local_dir' => '/var/backups/cipi', 'encryption_key' => true,
            'destinations' => $dests, 'profiles' => $profiles, 'apps' => $apps, 'databases' => $dbs, 'uncovered' => ['apps' => [], 'databases' => []]]]];
    }
    if ($sub === '/runs' && $method === 'GET') {
        $runs = [];
        for ($i = 0; $i < 5; $i++) {
            $runs[] = ['run' => gmdate('Y-m-d_020000', time() - $i * 86400), 'destinations' => ['s3']];
        }

        return [200, ['data' => ['profiles' => ['nightly' => $runs], 'errors' => []]]];
    }
    if ($sub === '/destinations/test' && $method === 'POST') {
        $names = isset($body['name']) ? [(string) $body['name']] : array_column(array_filter($b['destinations'], fn ($d) => $d['type'] !== 'local'), 'name');
        $out = [];
        foreach ($names as $n) {
            $i = $find($n);
            if ($i === null) {
                throw new DemoHttpError(404, "No such destination: {$n}");
            }
            $d = $b['destinations'][$i];
            $out[] = $d['type'] === 'sftp' ? "  {$n}  sftp://{$d['user']}@{$d['host']}:{$d['port']}/{$d['path']}  OK — a file written and removed" : "  {$n}  s3://{$d['bucket']}  OK";
        }

        return [200, ['data' => ['ok' => true, 'output' => implode("\n", $out)]]];
    }
    // An S3 bucket's fields, as the server checks them; the secret is only checked, never kept.
    $s3 = function (array $in, bool $isNew): array {
        $f = [];
        foreach (['bucket', 'region', 'endpoint'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                $f[$k] = (string) $in[$k];
            }
        }
        if ($isNew && (($in['key'] ?? '') === '' || ($in['secret'] ?? '') === '')) {
            throw new DemoHttpError(422, 'An S3 destination needs bucket, key and secret');
        }
        if (isset($f['bucket']) && preg_match('/^[a-z0-9][a-z0-9._-]{1,62}$/', $f['bucket']) !== 1) {
            throw new DemoHttpError(422, "Invalid bucket name '{$f['bucket']}'");
        }
        if (($f['bucket'] ?? '') === 'nope') {
            throw new DemoHttpError(422, "S3 connection failed — nothing saved:\n  An error occurred (InvalidAccessKeyId): The AWS Access Key Id you provided does not exist in our records.");
        }
        if (($f['endpoint'] ?? '') === 'none') {
            $f['endpoint'] = null;
        }

        return $f;
    };
    if ($sub === '/config' && $method === 'PUT') {
        $i = $find('s3');
        $type = ! empty($body['local_only']) ? 'local' : (string) ($body['type'] ?? (isset($body['host']) ? 'sftp' : 's3'));
        $first = ! $b['configured'];
        $dest = null;
        if ($type === 'local') {
            if ($i !== null) {
                array_splice($b['destinations'], $i, 1);
            }
            $first && $b['profile_to'] = ['local'];
        } elseif ($type === 'sftp') {
            $name = (string) ($body['name'] ?? 'sftp');
            if (preg_match('/^[a-z][a-z0-9-]{1,31}$/', $name) !== 1 || in_array($name, ['local', 's3'], true)) {
                throw new DemoHttpError(422, "Invalid destination name '{$name}'");
            }
            $d = ['name' => $name, 'type' => 'sftp', 'host' => (string) ($body['host'] ?? ''), 'port' => (int) ($body['port'] ?? 22), 'user' => (string) ($body['user'] ?? ''), 'path' => (string) ($body['path'] ?? ''), 'default' => false];
            if (($j = $find($name)) !== null) {
                $b['destinations'][$j] = $d;
            } else {
                $b['destinations'][] = $d;
            }
            $dest = $d + ['public_key' => $key($name)];
            $first && $b['profile_to'] = [$name];
        } else {
            $f = $s3($body, $i === null);
            $first && $b['profile_to'] = ['s3'];
            if ($i === null) {
                $b['destinations'][] = ['name' => 's3', 'type' => 's3', 'bucket' => '', 'region' => 'eu-central-1', 'endpoint' => null, 'default' => true];
                $i = array_key_last($b['destinations']);
            }
            $b['destinations'][$i] = array_merge($b['destinations'][$i], $f);
        }
        $b['configured'] = true;
        [, $status] = backupsRoute('GET', '', [], $s, $state, $profile);

        return [200, $status + ['type' => $type, 'destination' => $dest]];
    }
    if ($sub === '/key' && $method === 'GET') {
        return [200, ['data' => ['key' => $b['encryption_key']]]];
    }
    if ($sub === '/key/rotate' && $method === 'POST') {
        $b['encryption_key'] = substr(str_replace(['+', '/', '='], ['k', 'Q', ''], base64_encode(random_bytes(48))), 0, 64);

        return [200, ['data' => ['rotated' => true, 'message' => 'Backup encryption key rotated']]];
    }
    if ($sub === '/prune' && $method === 'POST') {
        return [202, dispatchJob($state, $profile, 'backup-prune', ['dry_run' => ! empty($body['dry_run'])])];
    }
    if ($sub === '/destinations' && $method === 'POST' && ($body['type'] ?? '') === 's3') {
        $name = (string) ($body['name'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name) !== 1 || in_array($name, ['local', 's3'], true) || $find($name) !== null) {
            throw new DemoHttpError(422, "Destination '{$name}' already exists or is not a name");
        }
        $d = ['name' => $name, 'type' => 's3', 'bucket' => '', 'region' => 'eu-central-1', 'endpoint' => null, 'default' => false];
        $d = array_merge($d, $s3($body, true));
        $b['destinations'][] = $d;

        return [201, ['data' => array_intersect_key($d, array_flip(['name', 'type', 'bucket', 'region', 'endpoint']))]];
    }
    if ($sub === '/destinations' && $method === 'POST') {
        if (($body['type'] ?? '') !== 'sftp') {
            throw new DemoHttpError(422, 'type is sftp or s3');
        }
        $name = (string) ($body['name'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name) !== 1 || $find($name) !== null) {
            throw new DemoHttpError(422, "Destination '{$name}' already exists or is not a name");
        }
        $d = ['name' => $name, 'type' => 'sftp', 'host' => (string) ($body['host'] ?? ''), 'port' => (int) ($body['port'] ?? 22), 'user' => (string) ($body['user'] ?? ''), 'path' => (string) ($body['path'] ?? ''), 'default' => false];
        $b['destinations'][] = $d;

        return [201, ['data' => $d + ['public_key' => $key($name)]]];
    }
    if (preg_match('#^/destinations/([a-z0-9_-]+)(/key)?$#', $sub, $dm) === 1) {
        $i = $find($dm[1]);
        if ($i !== null && $b['destinations'][$i]['type'] === 's3' && in_array($method, ['PUT', 'DELETE'], true) && ($dm[2] ?? '') === '') {
            if ($dm[1] === 's3') {
                throw new DemoHttpError(422, "The default bucket is changed with PUT /backups/config");
            }
            if ($method === 'DELETE') {
                array_splice($b['destinations'], $i, 1);

                return [200, ['data' => ['name' => $dm[1], 'removed' => true]]];
            }
            $b['destinations'][$i] = array_merge($b['destinations'][$i], $s3($body, false));

            return [200, ['data' => array_intersect_key($b['destinations'][$i], array_flip(['name', 'type', 'bucket', 'region', 'endpoint']))]];
        }
        if ($i === null || $b['destinations'][$i]['type'] !== 'sftp') {
            throw new DemoHttpError(404, "No SFTP destination '{$dm[1]}'");
        }
        if (($dm[2] ?? '') === '/key' && $method === 'GET') {
            return [200, ['data' => ['name' => $dm[1], 'public_key' => $key($dm[1])]]];
        }
        if ($method === 'PUT') {
            foreach (['host', 'user', 'path'] as $k) {
                if (isset($body[$k])) {
                    $b['destinations'][$i][$k] = (string) $body[$k];
                }
            }
            if (isset($body['port'])) {
                $b['destinations'][$i]['port'] = (int) $body['port'];
            }
            if (! empty($body['new_key'])) {
                unset($b['keys'][$dm[1]]);
                $b['keys'][$dm[1]] = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.substr(hash('sha256', $dm[1].microtime()), 0, 43).' cipi-backup-'.$dm[1].'@fra1-production';
            }

            return [200, ['data' => $b['destinations'][$i] + ['public_key' => $key($dm[1])]]];
        }
        if ($method === 'DELETE') {
            array_splice($b['destinations'], $i, 1);
            unset($b['keys'][$dm[1]]);

            return [200, ['data' => ['name' => $dm[1], 'removed' => true]]];
        }
    }

    throw new DemoHttpError(404, "No demo route for {$method} /api/backups{$sub}");
}

/**
 * `GET|PUT /self-update/policy` — `cipi self-update policy --json`. The demo
 * ships one signer; turning the check on the first time takes its fingerprint.
 */
function selfUpdateRoute(string $method, array $body, array &$s): array
{
    // Cipi releases are not signed yet: no release ships a signer (signed_releases false).
    $shipped = [];
    $s['selfupdate_demo'] ??= ['verify' => false, 'delay_hours' => 0, 'signers' => [],
        'pending' => null, 'held' => null, 'installed_sha' => 'a3f1c2e9b8d74f0e6c5b4a39281706f5e4d3c2b1'];
    $u = &$s['selfupdate_demo'];
    if ($method === 'PUT') {
        if (array_key_exists('delay_hours', $body)) {
            $h = $body['delay_hours'];
            if (! is_int($h) || $h < 0 || $h > 168) {
                throw new DemoHttpError(422, 'delay_hours is 0 to 168');
            }
            $u['delay_hours'] = $h;
            $u['pending'] = $h > 0 ? ['sha' => '5e0d7c1a9b2f48e3a6d0c9b8a7f6e5d4c3b2a190', 'version' => '5.18.1', 'first_seen' => time() - 3 * 3600] : null;
        }
        if (array_key_exists('verify', $body)) {
            if ($body['verify'] === true && $shipped === [] && $u['signers'] === []) {
                throw new DemoHttpError(422, 'Signed releases are not available yet: Cipi releases are not signed at the moment — nothing changed');
            }
            if ($body['verify'] === true && $u['signers'] === []) {
                if (($body['fingerprint'] ?? '') !== ($shipped[0]['fingerprint'] ?? '')) {
                    throw new DemoHttpError(422, 'Say which key you checked: the fingerprint published at https://cipi.sh/docs/self-update#signers — nothing changed');
                }
                $u['signers'] = $shipped;
            }
            $u['verify'] = (bool) $body['verify'];
        }
    } elseif ($method !== 'GET') {
        throw new DemoHttpError(405, 'GET or PUT');
    }

    return [200, ['data' => [
        'verify' => $u['verify'], 'delay_hours' => $u['delay_hours'], 'delay_default_hours' => 12,
        'signers_file' => '/etc/cipi/release-signers', 'signers' => $u['signers'], 'signers_ready' => $u['signers'] !== [],
        'shipped_signers' => $shipped, 'signed_releases' => $shipped !== [] || $u['signers'] !== [], 'version' => $s['status']['version'] ?? '5.18.0', 'installed_sha' => $u['installed_sha'],
        'pending' => $u['pending'], 'held' => $u['pending'] ? ['reason' => 'delay: v5.18.1 (5e0d7c1a9b2f) first seen 3 hours ago; installed in 9 hours (delay 12h)', 'at' => time() - 3 * 3600] : null,
    ]]];
}

function firewallRoute(string $method, string $action, array $body, array &$s, array &$state, string $profile): array
{
    $s['firewall'] ??= demoFirewall();
    $fw = &$s['firewall'];
    demoFirewallZeroTrust($fw, (array) ($s['zt'] ?? []));
    $addr = function (string $v): bool {
        [$ip, $mask] = array_pad(explode('/', $v, 2), 2, null);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false && ($mask === null || ctype_digit($mask));
    };
    $port = function ($v): ?string {
        return preg_match('/^(\d{1,5})(?::(\d{1,5}))?$/', (string) $v, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 65535 ? (string) $v : null;
    };
    $done = function (string $message) use (&$fw): array {
        return [200, ['data' => $fw, 'message' => $message]];
    };

    if ($action === '' && $method === 'GET') {
        $fw['real_ip'] ??= demoFirewall()['real_ip'];

        return [200, ['data' => $fw]];
    }
    if ($action === '/real-ip' || $action === '/real-ip/refresh') {
        $fw['real_ip'] ??= demoFirewall()['real_ip'];
        if ($method === 'DELETE') {
            if (! $fw['real_ip']['can_disable']) {
                throw new DemoHttpError(422, 'real-ip is already off');
            }
            $fw['real_ip'] = array_merge(demoFirewall()['real_ip'], ['bans_active' => $fw['real_ip']['bans_active']]);

            return $done('real-ip is off — nginx sees the address of each connection');
        }
        $was = $fw['real_ip']['enabled'];
        $fw['real_ip'] = array_merge($fw['real_ip'], ['enabled' => true, 'provider' => 'cloudflare', 'header' => 'CF-Connecting-IP',
            'kept_by' => 'real-ip', 'ranges' => 22, 'refreshed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'nightly_refresh' => true, 'can_disable' => true]);

        return $done($was ? "Already on — Cloudflare's ranges refreshed (22 ranges)" : "nginx now takes the visitor's address from Cloudflare (22 ranges, refreshed every night)");
    }
    if ($action === '/rules' && $method === 'POST') {
        $p = $port($body['port'] ?? '') ?? throw new DemoHttpError(422, 'A port (1-65535) or a range such as 8000:8100.');
        $from = trim((string) ($body['from'] ?? '')) ?: 'any';
        $proto = in_array($body['proto'] ?? 'tcp', ['tcp', 'udp'], true) ? ($body['proto'] ?? 'tcp') : 'tcp';
        if ($from !== 'any' && ! $addr($from)) {
            throw new DemoHttpError(422, 'An IPv4/IPv6 address or a CIDR range.');
        }
        $fw['ufw']['rules'][] = ['action' => 'allow', 'port' => $p, 'proto' => $proto, 'from' => $from, 'to' => 'any', 'direction' => 'in', 'interface' => '', 'app' => '', 'comment' => '',
            'family' => $from === 'any' ? 'both' : (str_contains($from, ':') ? 'v6' : 'v4'), 'log' => false, 'kind' => 'port', 'managed_by' => '',
            'protected' => $from === 'any' && in_array((int) $p, $fw['protected_ports'], true), 'removable' => ! ($from === 'any' && in_array((int) $p, $fw['protected_ports'], true))];

        return $done("Allowed {$p}/{$proto}".($from !== 'any' ? " from {$from}" : ''));
    }
    if ($action === '/rules' && $method === 'DELETE') {
        $p = (string) ($body['port'] ?? '');
        $from = trim((string) ($body['from'] ?? '')) ?: 'any';
        $proto = (string) ($body['proto'] ?? 'tcp');
        $act = (string) ($body['action'] ?? 'allow');
        if ($from === 'any' && $act === 'allow' && in_array((int) $p, $fw['protected_ports'], true)) {
            throw new DemoHttpError(422, "Port {$p} is how this server is reached (SSH, HTTP, HTTPS): it is not closed from here");
        }
        $before = count($fw['ufw']['rules']);
        $fw['ufw']['rules'] = array_values(array_filter($fw['ufw']['rules'], fn ($r) => ! ($r['kind'] === 'port' && $r['port'] === $p && $r['from'] === $from && $r['proto'] === $proto && $r['action'] === $act)));
        if (count($fw['ufw']['rules']) === $before) {
            throw new DemoHttpError(422, "No such rule: {$act} {$p}/{$proto}");
        }

        return $done("Removed: {$act} {$p}/{$proto}");
    }
    if ($action === '/blocks') {
        $a = trim((string) ($body['address'] ?? ''));
        if (! $addr($a)) {
            throw new DemoHttpError(422, 'An IPv4/IPv6 address or a CIDR range.');
        }
        if ($method === 'POST') {
            if ($a === ($s['ip_whitelist']['client_ip'] ?? '')) {
                throw new DemoHttpError(422, "{$a} takes in {$a}, the address this request comes from: blocking it would cut this server off from you.");
            }
            array_unshift($fw['ufw']['rules'], ['action' => 'deny', 'port' => 'any', 'proto' => 'any', 'from' => $a, 'to' => 'any', 'direction' => 'in', 'interface' => '', 'app' => '', 'comment' => '',
                'family' => str_contains($a, ':') ? 'v6' : 'v4', 'log' => false, 'kind' => 'block', 'managed_by' => '', 'protected' => false, 'removable' => true]);

            return $done("Blocked {$a}: no port of this server answers it");
        }
        $fw['ufw']['rules'] = array_values(array_filter($fw['ufw']['rules'], fn ($r) => ! ($r['kind'] === 'block' && $r['from'] === $a)));

        return $done("Unblocked {$a}");
    }
    if ($action === '/attempts' && $method === 'PUT') {
        $n = $body['attempts'] ?? null;
        if ($n !== null && (! is_int($n) || $n < 1 || $n > 100)) {
            throw new DemoHttpError(422, 'From 1 to 100.');
        }
        $fw['fail2ban']['attempts'] = $n ?? 3;
        $fw['fail2ban']['attempts_custom'] = $n;

        return $done($n === null ? 'Back to the default: 3 failed SSH logins ban the address' : "{$n} failed SSH login(s) now ban the address");
    }
    if ($action === '/bans' && $method === 'DELETE') {
        $ip = trim((string) ($body['ip'] ?? ''));
        $found = false;
        foreach ($fw['fail2ban']['jails'] as &$jail) {
            if (in_array($ip, $jail['banned'], true)) {
                $jail['banned'] = array_values(array_diff($jail['banned'], [$ip]));
                $jail['count'] = count($jail['banned']);
                $found = true;
            }
        }
        unset($jail);
        $decisions = array_values(array_filter($fw['crowdsec']['decisions'], fn ($d) => $d['ip'] !== $ip));
        $found = $found || count($decisions) !== count($fw['crowdsec']['decisions']);
        $fw['crowdsec']['decisions'] = $decisions;
        if (! $found) {
            throw new DemoHttpError(422, "{$ip} is not currently banned");
        }

        return $done("IP {$ip} unbanned");
    }
    if ($action === '/crowdsec/allowlist') {
        $a = trim((string) ($body['address'] ?? ''));
        if (! $addr($a)) {
            throw new DemoHttpError(422, 'An IPv4/IPv6 address or a CIDR range.');
        }
        $list = $fw['crowdsec']['allowlist'];
        $fw['crowdsec']['allowlist'] = $method === 'POST' ? array_values(array_unique([...$list, $a])) : array_values(array_diff($list, [$a]));

        return $done($method === 'POST' ? "Allowed {$a} (never banned by CrowdSec)" : "Removed {$a} from the extra allowlist");
    }
    if ($action === '/crowdsec/enable' && $method === 'POST') {
        return [202, dispatchJob($state, $profile, 'crowdsec-enable', ['allow' => $s['ip_whitelist']['client_ip'] ?? ''])];
    }
    if ($action === '/crowdsec/disable' && $method === 'POST') {
        if (! $fw['crowdsec']['installed']) {
            throw new DemoHttpError(409, 'CrowdSec is not installed');
        }

        return [202, dispatchJob($state, $profile, 'crowdsec-disable', [])];
    }

    throw new DemoHttpError(405, 'Method not allowed');
}

function ipWhitelistRoute(string $method, string $action, array $body, array &$s): array
{
    $wl = &$s['ip_whitelist'];
    $valid = fn (string $ip) => filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP) !== false;

    if ($action === '/allow-all' && $method === 'POST') {
        $wl['allow_all'] = true;
        $wl['entries'] = ['*'];
    } elseif ($method === 'PUT') {
        $entries = array_values(array_filter(array_map('trim', (array) ($body['entries'] ?? []))));
        if (($body['ensure_client_ip'] ?? true) && ! in_array($wl['client_ip'], $entries, true)) {
            $entries[] = $wl['client_ip'];
        }
        $wl['entries'] = $entries;
        $wl['allow_all'] = in_array('*', $entries, true);
    } elseif ($method === 'POST') {
        $ip = trim((string) ($body['ip'] ?? ''));
        if (! $valid($ip)) {
            throw new DemoHttpError(422, "Invalid IP or CIDR: {$ip}");
        }
        $entries = array_values(array_filter($wl['entries'], fn ($e) => $e !== '*'));
        if ($wl['allow_all'] && ! in_array($wl['client_ip'], $entries, true)) {
            $entries[] = $wl['client_ip'];
        }
        if (! in_array($ip, $entries, true)) {
            $entries[] = $ip;
        }
        $wl['entries'] = $entries;
        $wl['allow_all'] = false;
    } elseif ($method === 'DELETE') {
        $ip = trim((string) ($body['ip'] ?? ''));
        if ($ip === $wl['client_ip']) {
            throw new DemoHttpError(422, "Refused: removing {$ip} would lock this client out of the API");
        }
        $wl['entries'] = array_values(array_filter($wl['entries'], fn ($e) => $e !== $ip));
        if ($wl['entries'] === []) {
            $wl['entries'] = ['*'];
            $wl['allow_all'] = true;
        }
    }

    return [200, ['data' => $wl]];
}

// ── Jobs ─────────────────────────────────────────────────────────────────

function dispatchJob(array &$state, string $profile, string $type, array $params): array
{
    $id = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
    $state['jobs'][$id] = [
        'id' => $id, 'profile' => $profile, 'type' => $type, 'params' => $params,
        'created' => microtime(true), 'finished' => false, 'status' => 'pending',
        'result' => null, 'output' => null, 'exit_code' => null,
    ];
    // Keep the job table small.
    if (count($state['jobs']) > 60) {
        $state['jobs'] = array_slice($state['jobs'], -60, null, true);
    }

    return ['job_id' => $id, 'status' => 'pending'];
}

function jobView(array &$state, string $id): array
{
    if (! isset($state['jobs'][$id])) {
        throw new DemoHttpError(404, 'Job not found');
    }
    $job = &$state['jobs'][$id];
    $age = microtime(true) - $job['created'];

    if (! $job['finished']) {
        if ($age < JOB_PENDING_SECONDS) {
            $job['status'] = 'pending';
        } elseif ($age < JOB_PENDING_SECONDS + JOB_RUNNING_SECONDS + (str_contains($job['type'], 'install') ? 2.5 : 0)) {
            $job['status'] = 'running';
        } else {
            [$ok, $output, $result] = $job['type'] === 'sync-push'
                ? syncPushJob($state, $job['profile'], $job['params'])
                : runJob($state['servers'][$job['profile']], $job['type'], $job['params']);
            $job['finished'] = true;
            $job['status'] = $ok ? 'completed' : 'failed';
            $job['exit_code'] = $ok ? 0 : 1;
            $job['output'] = $output;
            $job['result'] = $result;
        }
    }

    $created = gmdate('Y-m-d\TH:i:s.000000\Z', (int) $job['created']);
    // A running job says what its command has printed so far (cipi/api 1.44).
    $live = $job['status'] === 'running' ? liveOutput($job['type'], $age - JOB_PENDING_SECONDS) : null;

    return [
        'id' => $job['id'], 'type' => $job['type'], 'status' => $job['status'],
        'result' => $job['result'], 'output' => $job['output'] ?? $live, 'exit_code' => $job['exit_code'],
        'created_at' => $created, 'updated_at' => gmdate('Y-m-d\TH:i:s.000000\Z'),
    ];
}

/** The first lines a command of this type prints, one more every 0.4 s. */
function liveOutput(string $type, float $seconds): string
{
    $steps = match (true) {
        $type === 'sync-push' => ['→ Exporting the apps…', '→ Encrypting the archive (AES-256)…', '→ Sending it over SSH…', '→ Importing on the target…'],
        $type === 'app-deploy' => ['→ Deploying…', 'task deploy:info', 'task deploy:lock', 'task deploy:release', 'task deploy:update_code', 'task deploy:vendors', 'task artisan:migrate', 'task deploy:symlink'],
        str_contains($type, 'install') => ['→ Reading package lists…', '→ Downloading…', '→ Unpacking…', '→ Setting up…'],
        default => ['→ Running…'],
    };

    return implode("\n", array_slice($steps, 0, max(1, (int) floor($seconds / 0.4) + 1)));
}

/** Apply a finished job to the server state and return [ok, cli output, parsed result]. */
function runJob(array &$s, string $type, array $p): array
{
    $app = $p['app'] ?? null;
    $a = $app !== null && isset($s['apps'][$app]) ? $s['apps'][$app] : null;
    $domain = $a['info']['domain'] ?? '';
    $ts = gmdate('Y-m-d H:i:s');

    switch ($type) {
        case 'app-create':
            $s['apps'][$app] = $p['record'];
            $info = $p['record']['info'];
            if (! empty($info['engine'])) {
                $s['dbs'][] = ['engine' => $info['engine'], 'name' => $app, 'user' => $app, 'size' => '0.02 MB'];
            }
            $sshPass = randomSecret(16);
            $dbPass = randomSecret(24);
            $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.substr(base64_encode(random_bytes(32)), 0, 43)." cipi-{$app}";
            $token = bin2hex(random_bytes(20));
            $kind = ! empty($info['node']) ? 'node' : (! empty($info['custom']) ? 'custom' : 'laravel');
            $runtime = match (true) {
                $kind === 'node' => 'Node '.$info['node_version'].' — '.$info['node_mode'].($info['framework'] ? ' ('.$info['framework'].')' : ''),
                ! empty($info['octane']) => 'Octane (frankenphp) :'.$info['octane_port'],
                default => 'PHP-FPM',
            };
            $out = [
                "→ Creating app '{$app}'...",
                '→ Linux user + home...', "  ✓ {$app} (/home/{$app})",
            ];
            if ($kind === 'laravel') {
                $out[] = '→ Database...';
                $out[] = "  ✓ {$app} on ".engineLabel($info['engine'] ?? 'mariadb');
            }
            $build = $kind === 'node' ? ($info['build'] ?? null) : ($p['record']['deploy_config']['node_build'] ?: null);
            $access = ! empty($info['repository']) ? repositoryAccess($app, (string) $info['repository'], (string) ($info['branch'] ?? 'main')) : null;
            $out = array_merge($out, [
                '→ PHP-FPM pool...', '  ✓ php'.($info['php'] ?? '8.5').'-fpm',
                '→ Deploy key...', '  ✓ ed25519',
            ], $access ? repositoryAccessLines($app, $access) : [], [
                '→ Nginx vhost...', "  ✓ Nginx → {$info['domain']}",
                '→ Queue worker...', $kind === 'laravel' ? '  ✓ Worker (default)' : '  – not needed',
                '', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',
                "  APP CREATED: {$app}",
                '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',
                "  Domain:     {$info['domain']}",
                "  IP:         {$s['status']['system']['ip']}",
                '  PHP:        '.($info['php'] ?? '8.5'),
                "  Runtime:    {$runtime}",
            ], $build !== null ? ["  Build:      {$build}".($kind === 'laravel' ? '  (every deploy, before the release goes live)' : '')] : [], [
                "  Home:       /home/{$app}",
                '',
                "  SSH         {$app} / {$sshPass}",
            ]);
            if ($kind === 'laravel') {
                $out[] = "  Database    {$app} / {$dbPass}  (".engineLabel($info['engine'] ?? 'mariadb').')';
            }
            if (! empty($info['repository'])) {
                $out[] = '';
                $out[] = '  '.match ($access['status'] ?? null) {
                    'ok' => "Repository  reachable ✓ (branch {$access['branch']})",
                    'nobranch' => "Repository  reachable, but it has no branch '{$access['branch']}' — cipi app edit {$app} --branch=<name>",
                    default => "Repository  not readable with the deploy key — the first deploy fails until it is; check with: cipi git check {$app}",
                };
                $out[] = '  Deploy Key  add it to the repository before the first deploy — the deploy fails without it';
                $out[] = "  {$key}";
                $out[] = '';
                $out[] = "  Webhook     https://{$info['domain']}/cipi/webhook  (push events)";
                $out[] = "  Token       {$token}";
                $out[] = '';
                $out[] = "  Next: cipi deploy {$app}";
                $out[] = "        cipi ssl install {$app}";
            }

            return [true, implode("\n", $out), array_filter([
                'app' => $app, 'domain' => $info['domain'], 'php' => $info['php'] ?? null, 'home' => "/home/{$app}",
                'ssh' => ['user' => $app, 'password' => $sshPass],
                'database' => $kind === 'laravel' ? ['user' => $app, 'password' => $dbPass] : null,
                'deploy_key' => ! empty($info['repository']) ? $key : null,
                'webhook' => ! empty($info['repository']) ? "https://{$info['domain']}/cipi/webhook" : null,
                'webhook_token' => ! empty($info['repository']) ? $token : null,
                'build' => $build,
                'repository_access' => $access,
            ], fn ($v) => $v !== null)];

        case 'app-edit':
            $lines = [];
            $changes = [];
            foreach ($p['changes'] as $k => $v) {
                if ($k === 'node') {
                    $s['apps'][$app]['info']['node_mode'] = $v;
                } elseif ($k === 'node_version' && $v === 'default') {
                    $s['apps'][$app]['info']['node_version'] = null;
                } else {
                    $s['apps'][$app]['info'][$k] = $v;
                }
                $lines[] = "  ✓ {$k} → {$v}";
                $changes[$k] = (string) $v;
            }
            if (isset($p['changes']['repository'])) {
                $lines[] = '  ✓ Repository updated';
                $lines[] = '  ✓ Deploy key + webhook recreated';
            }
            $editedInfo = $s['apps'][$app]['info'];
            $access = (isset($p['changes']['repository']) || isset($p['changes']['branch'])) && ! empty($editedInfo['repository'])
                ? repositoryAccess($app, (string) $editedInfo['repository'], (string) ($editedInfo['branch'] ?: 'main'))
                : null;
            if ($access) {
                $lines = array_merge($lines, repositoryAccessLines($app, $access));
            }

            return [true, "→ Editing app '{$app}'...\n".implode("\n", $lines)."\n\n✓ App '{$app}' updated", ['changes' => $changes] + ($access ? ['repository_access' => $access] : [])];

        case 'app-delete':
            unset($s['apps'][$app]);
            $s['dbs'] = array_values(array_filter($s['dbs'], fn ($d) => $d['name'] !== $app));

            return [true, "→ Deleting app '{$app}'...\n  ✓ Supervisor programs removed\n  ✓ Nginx vhost removed\n  ✓ PHP-FPM pool removed\n  ✓ Database dropped\n  ✓ Home /home/{$app} removed\n\n✓ App '{$app}' deleted", ['app' => $app, 'deleted' => true]];

        case 'app-suspend':
        case 'app-unsuspend':
            $suspend = $type === 'app-suspend';
            $s['apps'][$app]['info']['suspended'] = $suspend;

            return [true, $suspend
                ? "→ Suspending '{$app}'...\n  ✓ Maintenance vhost (HTTP 503) for {$domain}\n  ✓ Workers stopped\n\n✓ App '{$app}' suspended"
                : "→ Unsuspending '{$app}'...\n  ✓ Vhost restored for {$domain}\n  ✓ Workers started\n\n✓ App '{$app}' is back online",
                ['app' => $app, 'suspended' => $suspend]];

        case 'app-fix-permissions':
            return [true, "→ Restoring the permission model for '{$app}'...\n  ✓ Ownership {$app}:{$app}\n  ✓ /home/{$app} 750\n  ✓ /home/{$app}/.ssh 700\n  ✓ shared/.env 640\n  ✓ Log ACLs (www-data r)\n\n✓ Permissions fixed", ['app' => $app, 'fixed' => true]];

        case 'app-webhook-recreate':
            $token = bin2hex(random_bytes(20));

            return [true, "→ Recreating webhook on GitHub...\n  ✓ Old hook removed\n  ✓ Hook #".random_int(400000000, 499999999)." created (push)\n".($p['rotate'] ? "  ✓ CIPI_WEBHOOK_TOKEN rotated\n" : '')."WEBHOOK_URL: https://{$domain}/cipi/webhook\nWEBHOOK_TOKEN: {$token}\nWEBHOOK_ROTATED: ".($p['rotate'] ? 'true' : 'false')."\n\n✓ Webhook recreated",
                ['webhook_url' => "https://{$domain}/cipi/webhook", 'webhook_token' => $token, 'rotated' => (bool) $p['rotate'], 'recreated' => true]];

        case 'app-deploy':
            if (empty($a['info']['repository'])) {
                return [false, "[ERROR] App '{$app}' has no Git repository — upload via SFTP or set one with cipi app edit", ['error' => "App '{$app}' has no Git repository"]];
            }
            $audit = $s['apps'][$app]['audit'] ?? [];
            $release = (int) ($audit !== [] ? end($audit)['release'] : 0) + 1;
            $commit = bin2hex(random_bytes(20));
            $s['apps'][$app]['audit'][] = auditRecord($s['apps'][$app]['audit'], $app, 'published', (string) $release, $commit);
            $isNode = ! empty($a['info']['node']);
            $steps = $isNode
                ? ['deploy:info', 'deploy:setup', 'deploy:lock', 'deploy:release', 'deploy:update_code', 'node:install', 'node:build', 'deploy:symlink', $a['info']['node_mode'] === 'ssr' ? 'node:switch (blue → green)' : 'deploy:publish_static', 'deploy:unlock', 'deploy:cleanup']
                : ['deploy:info', 'deploy:setup', 'deploy:lock', 'deploy:release', 'deploy:update_code', 'deploy:shared', 'deploy:writable', 'deploy:vendors', 'artisan:storage:link', 'artisan:optimize', 'artisan:migrate', 'npm:build', 'deploy:symlink', 'artisan:queue:restart', 'deploy:unlock', 'deploy:cleanup'];
            $out = ["→ Deploying '{$app}' ({$a['info']['branch']})...", "task deploy:info\n[{$app}] info deploying {$a['info']['branch']} (release {$release})"];
            foreach (array_slice($steps, 1) as $step) {
                $out[] = "task {$step}";
                if ($step === 'deploy:vendors') {
                    $out[] = "[{$app}] run composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader";
                    $out[] = "[{$app}] Installing dependencies from lock file\n[{$app}] Package operations: 0 installs, 3 updates, 0 removals\n[{$app}] Generating optimized autoload files";
                } elseif ($step === 'artisan:migrate') {
                    $out[] = "[{$app}]   INFO  Nothing to migrate.";
                } elseif ($step === 'npm:build' || $step === 'node:build') {
                    $out[] = "[{$app}] vite v7.1.4 building for production...\n[{$app}] ✓ 412 modules transformed.\n[{$app}] ✓ built in 6.18s";
                }
            }
            $out[] = "[{$app}] info successfully deployed!";
            $out[] = '';
            $out[] = "✓ '{$app}' deployed successfully (release {$release}, ".substr($commit, 0, 7).')';

            return [true, implode("\n", $out), ['app' => $app, 'deployed' => true]];

        case 'app-deploy-rollback':
            $records = $s['apps'][$app]['audit'] ?? [];
            $release = max(1, (int) ($records !== [] ? end($records)['release'] : 2) - 1);
            $s['apps'][$app]['audit'][] = auditRecord($records, $app, 'rollback', (string) $release, bin2hex(random_bytes(20)));

            return [true, "→ Rolling back '{$app}'...\ntask rollback\n[{$app}] info rolled back to release {$release}\n\n✓ Rollback completed for '{$app}'", ['app' => $app, 'rolled_back' => true]];

        case 'app-deploy-unlock':
            return [true, "→ Unlocking '{$app}'...\ntask deploy:unlock\n\n✓ Deploy unlocked for '{$app}'", ['app' => $app, 'unlocked' => true]];

        case 'app-artisan':
            $cmd = $p['command'];
            $output = match (true) {
                str_starts_with($cmd, 'migrate:status') => "  Migration name .............................................. Batch / Status\n  0001_01_01_000000_create_users_table ................................ [1] Ran\n  0001_01_01_000001_create_cache_table ................................ [1] Ran\n  0001_01_01_000002_create_jobs_table ................................. [1] Ran\n  2026_09_02_101500_create_orders_table ............................... [2] Ran\n  2026_10_01_083000_add_tax_rounding_to_orders ........................ [3] Ran",
                str_starts_with($cmd, 'migrate') => "\n   INFO  Running migrations.\n\n  2026_10_06_141200_create_coupons_table ........................ 18.42ms DONE\n",
                str_starts_with($cmd, 'optimize:clear') => "\n   INFO  Clearing cached bootstrap files.\n\n  config ............................................................ 1.12ms DONE\n  cache ............................................................. 3.40ms DONE\n  compiled .......................................................... 0.61ms DONE\n  events ............................................................ 0.48ms DONE\n  routes ............................................................ 0.52ms DONE\n  views ............................................................. 4.88ms DONE",
                str_starts_with($cmd, 'optimize') => "\n   INFO  Caching framework bootstrap, configuration, and metadata.\n\n  config ............................................................ 9.81ms DONE\n  events ............................................................ 1.02ms DONE\n  routes ........................................................... 14.37ms DONE\n  views ............................................................ 96.20ms DONE",
                str_starts_with($cmd, 'cache:clear') => "\n   INFO  Application cache cleared successfully.",
                str_starts_with($cmd, 'queue:') => "\n   INFO  Broadcasting queue restart signal.",
                str_starts_with($cmd, 'about') => "  Environment ...............................................................\n  Application Name ................................................ ".ucfirst($app)."\n  Laravel Version ................................................. 13.35.0\n  PHP Version ...................................................... 8.5.10\n  Environment ................................................. production\n  Debug Mode ......................................................... OFF\n  Maintenance Mode ................................................... OFF",
                default => "\n   INFO  Command finished.",
            };

            return [true, "\$ php artisan {$cmd}\n{$output}", ['command' => $cmd, 'exit_code' => 0]];

        case 'app-run':
            $cmd = $p['command'];
            $output = match (true) {
                str_starts_with($cmd, 'composer') => "Installing dependencies from lock file\nVerifying lock file contents can be installed on current platform.\nNothing to install, update or remove\nGenerating optimized autoload files\n> Illuminate\\Foundation\\ComposerScripts::postAutoloadDump\n> @php artisan package:discover --ansi\n\n   INFO  Discovering packages.\n\n  laravel/octane ......................................................... DONE\n  laravel/scout .......................................................... DONE\n  livewire/livewire ...................................................... DONE\n\n94 packages you are using are looking for funding.",
                str_starts_with($cmd, 'npm') || str_starts_with($cmd, 'pnpm') || str_starts_with($cmd, 'yarn') => "\nadded 418 packages, and audited 419 packages in 9s\n\n142 packages are looking for funding\n  run `npm fund` for details\n\nfound 0 vulnerabilities",
                str_starts_with($cmd, 'git') => "On branch {$a['info']['branch']}\nYour branch is up to date with 'origin/{$a['info']['branch']}'.\n\nnothing to commit, working tree clean",
                str_starts_with($cmd, 'du') || str_starts_with($cmd, 'df') => "1.4G\t/home/{$app}/current\n612M\t/home/{$app}/shared\n88M\t/home/{$app}/logs",
                default => "total 24\ndrwxr-x--- 6 {$app} {$app} 4096 Oct  7 09:12 .\ndrwxr-xr-x 9 root    root    4096 Sep 18 14:02 ..\nlrwxrwxrwx 1 {$app} {$app}   24 Oct  7 09:12 current -> /home/{$app}/releases/133\ndrwxr-x--- 2 {$app} {$app} 4096 Oct  6 22:00 logs\ndrwxr-x--- 7 {$app} {$app} 4096 Oct  7 09:12 releases\ndrwxr-x--- 4 {$app} {$app} 4096 Mar  5  2026 shared",
            };

            return [true, "\$ {$cmd}\n{$output}", ['command' => $cmd, 'exit_code' => 0]];

        case 'alias-create':
            $s['apps'][$app]['info']['aliases'][] = $p['alias'];

            return [true, "→ Adding alias {$p['alias']} to '{$app}'...\n  ✓ Nginx vhost updated\n  ✓ nginx -t OK, reloaded\n\nTip: run SSL install again to cover the new hostname", ['app' => $app, 'alias' => $p['alias'], 'added' => true]];

        case 'alias-delete':
            $s['apps'][$app]['info']['aliases'] = array_values(array_diff($s['apps'][$app]['info']['aliases'], [$p['alias']]));

            return [true, "→ Removing alias {$p['alias']} from '{$app}'...\n  ✓ Nginx vhost updated\n  ✓ nginx -t OK, reloaded", ['app' => $app, 'alias' => $p['alias'], 'removed' => true]];

        case 'www-add':
            $www = wwwStatus($app, $s['apps'][$app]['info']);
            $counterpart = $domain === $www['apex'] ? $www['www'] : $www['apex'];
            if (! in_array($counterpart, $s['apps'][$app]['info']['aliases'], true)) {
                $s['apps'][$app]['info']['aliases'][] = $counterpart;
            }

            return [true, "→ Adding {$counterpart} as alias...\n  ✓ Nginx vhost updated", ['app' => $app, 'alias' => $counterpart]];

        case 'www-force-to-root':
        case 'www-force-from-root':
        case 'www-clear':
            $mode = $type === 'www-clear' ? null : substr($type, 10);
            $s['apps'][$app]['info']['www_redirect'] = $mode;

            return [true, $mode ? "→ Canonical host for '{$app}'...\n  ✓ 301 ".($mode === 'to-root' ? 'www → apex' : 'apex → www')."\n  ✓ nginx -t OK, reloaded" : "→ Clearing www redirect...\n  ✓ Both hosts served directly", ['app' => $app, 'redirect' => $mode]];

        case 'app-yml-apply':
            return ymlApply($s['apps'][$app], $app);

        case 'ssl-install':
            $hosts = array_merge([$domain], $a['info']['aliases']);
            $s['apps'][$app]['info']['force_https'] = true;

            return [true, "→ Requesting Let's Encrypt certificate...\nSaving debug log to /var/log/letsencrypt/letsencrypt.log\nRequesting a certificate for ".implode(' and ', $hosts)."\n\nSuccessfully received certificate.\nCertificate is saved at: /etc/letsencrypt/live/{$domain}/fullchain.pem\nThis certificate expires on ".gmdate('Y-m-d', time() + 90 * 86400).".\n  ✓ HTTP → HTTPS redirect enabled\n\n✓ SSL installed for {$domain}", ['domain' => $domain, 'installed' => true, 'force_https' => true]];

        case 'ssl-force':
            $s['apps'][$app]['info']['force_https'] = true;

            return [true, "→ Re-applying HTTPS redirect...\n  ✓ HTTP → HTTPS redirect enabled for {$domain}", ['domain' => $domain, 'force_https' => true]];

        case 'node-restart':
            $port = random_int(3100, 3199);

            return [true, "→ Restarting '{$app}' (blue/green)...\n  ✓ green started on 127.0.0.1:{$port}\n  ✓ health {$a['info']['health_path']} → 200 in 1.8s\n  ✓ nginx upstream switched\n  ✓ blue stopped\n\n✓ '{$app}' restarted", ['app' => $app, 'restarted' => true]];

        case 'db-create':
            $name = $p['name'];
            $engine = $p['engine'] ?: 'mariadb';
            $pass = randomSecret(24);
            $s['dbs'][] = ['engine' => $engine, 'name' => $name, 'user' => $name, 'size' => '0.02 MB'];
            $url = ($engine === 'pgsql' ? 'postgresql' : 'mysql')."://{$name}:{$pass}@127.0.0.1:".($engine === 'pgsql' ? 5432 : 3306)."/{$name}";

            return [true, "→ Creating database '{$name}' on ".engineLabel($engine)."...\n\n  Engine: {$engine}  Database: {$name}  User: {$name}  Password: {$pass}\n  URL: {$url}\n\n✓ Database created", ['engine' => $engine, 'database' => $name, 'user' => $name, 'password' => $pass, 'url' => $url]];

        case 'db-backup':
            $file = '/home/cipi/backups/'.$p['name'].'_'.gmdate('Y-m-d_Hi').'.sql.gz';
            $s['backups'][$p['name']] = $file;

            return [true, "→ Backing up '{$p['name']}'...\n  ✓ Dump compressed (gzip -6)\n  Backup: {$file}\n\n✓ Backup completed", ['file' => $file]];

        case 'db-restore':
            return [true, "→ Restoring '{$p['name']}' from {$p['file']}...\n  ✓ Pre-restore snapshot saved\n  ✓ Import finished\n\n✓ Database '{$p['name']}' restored successfully", ['database' => $p['name'], 'restored' => true]];

        case 'db-password':
            $pass = randomSecret(24);
            $engine = $p['engine'] ?: 'mariadb';

            return [true, "→ Regenerating password...\nNew password for '{$p['name']}' ({$engine}): {$pass}\n  ✓ Apps using this database: update DB_PASSWORD in their .env", ['engine' => $engine, 'user' => $p['name'], 'password' => $pass]];

        case 'db-install':
            foreach ($s['engines']['engines'] as &$engine) {
                if ($engine['engine'] === $p['engine']) {
                    $engine['status'] = 'running';
                    $engine['port'] = $p['engine'] === 'pgsql' ? 5432 : 3306;
                }
            }
            unset($engine);
            $label = engineLabel($p['engine']);

            return [true, "→ Installing {$label}...\n  ✓ apt packages\n  ✓ Hardened config\n  ✓ Service enabled\n\n✓ {$label} installed", ['engine' => $p['engine'], 'installed' => true]];

        case 'package-install':
        case 'package-remove':
            $install = $type === 'package-install';
            $apt = [];
            foreach ($s['packages'] as &$pkg) {
                if ($pkg['id'] === $p['package']) {
                    $upstream = ($pkg['source'] ?? 'ubuntu') === 'upstream';
                    $apt = $install ? ($upstream ? $pkg['packages'] : ($pkg['missing'] ?: $pkg['packages'])) : $pkg['packages'];
                    $pkg['installed'] = $install;
                    $pkg['partial'] = false;
                    $pkg['missing'] = $install ? [] : ($upstream ? $pkg['binaries'] : $pkg['packages']);
                    if ($upstream) {
                        $pkg['installed_version'] = $install ? $pkg['version'] : null;
                        $pkg['outdated'] = false;
                        $version = $pkg['version'];
                    }
                }
            }
            unset($pkg);
            $list = implode(' ', $apt);

            if (isset($version)) {
                return $install
                    ? [true, "→ Downloading {$p['package']} {$version}...\n[INFO] SHA-256 matches the pin\n\n✓ {$p['package']} {$version} installed", ['package' => $p['package'], 'version' => $version, 'installed' => true]]
                    : [true, "→ Removing {$p['package']}\n\n✓ {$p['package']} removed", ['package' => $p['package'], 'removed' => true, 'apt' => []]];
            }

            return $install
                ? [true, "→ Installing {$p['package']}: {$list}\n  ✓ apt packages\n\n✓ {$p['package']} installed", ['package' => $p['package'], 'installed' => true]]
                : [true, "→ Removing {$p['package']}: {$list}\n  ✓ Nothing else needs them\n\n✓ {$p['package']} removed", ['package' => $p['package'], 'installed' => false]];

        case 'php-install':
            $v = $p['version'];
            foreach ($s['php']['versions'] as $row) {
                if ($row['version'] === $v) {
                    return [false, "[ERROR] PHP {$v} is already installed", ['error' => "PHP {$v} is already installed"]];
                }
            }
            $s['php']['versions'][] = ['version' => $v, 'status' => 'running', 'apps' => 0, 'default' => false];
            usort($s['php']['versions'], fn ($x, $y) => version_compare($x['version'], $y['version']));
            $s['services'][] = ['name' => "php{$v}-fpm", 'status' => 'running', 'since' => $ts];

            return [true, "→ Installing PHP {$v}...\n  ✓ php{$v}-fpm php{$v}-cli + 24 extensions\n  ✓ OPcache + JIT tuned\n  ✓ FPM pool template\n\n✓ PHP {$v} installed", ['version' => $v, 'installed' => true]];

        case 'crowdsec-enable':
            $s['firewall'] ??= demoFirewall();
            $s['firewall']['crowdsec'] = array_merge($s['firewall']['crowdsec'], [
                'installed' => true, 'running' => true, 'bouncer' => 'running', 'mode' => 'nftables', 'rescue' => 'listening', 'rescue_port' => 48213,
                'allowlist' => array_values(array_unique(array_merge($s['firewall']['crowdsec']['allowlist'], array_filter([$p['allow'] ?? ''])))),
                'decisions' => [['ip' => '45.148.10.92', 'scope' => 'Ip', 'type' => 'ban', 'reason' => 'crowdsecurity/http-probing', 'origin' => 'crowdsec', 'duration' => '3h59m']],
            ]);

            return [true, "→ Installing CrowdSec...\n→ Registering firewall bouncer (cscli bouncers add)...\n→ Starting rescue TLS listener (allowlist only, not a login)...\n→ Allowlists (localhost, private nets, Let's Encrypt, GitHub/GitLab/Bitbucket webhooks)...\n[INFO] Allowed ".($p['allow'] ?? '')." (never banned by CrowdSec)\n✓ CrowdSec enabled (engine + nftables bouncer)", null];

        case 'crowdsec-disable':
            $s['firewall'] ??= demoFirewall();
            $s['firewall']['crowdsec'] = array_merge($s['firewall']['crowdsec'], ['installed' => false, 'running' => false, 'bouncer' => 'not installed', 'mode' => '', 'rescue' => 'stopped', 'rescue_port' => null, 'decisions' => []]);

            return [true, "→ Stopping rescue listener...\n→ Flushing CrowdSec firewall chains (before purge)...\n→ Removing CrowdSec packages...\n✓ CrowdSec removed — firewall is back to fail2ban only", null];

        case 'octane-php':
            $from = $s['apps'][$app]['info']['php'];
            $s['apps'][$app]['info']['php'] = $p['php'];
            $s['apps'][$app]['info']['octane_php'] = $p['php'].'.'.(['8.3' => '28', '8.4' => '15', '8.5' => '4', '8.6' => '0'][$p['php']] ?? '0');
            $build = '1.14.0-php'.$s['apps'][$app]['info']['octane_php'].'-'.substr(sha1($p['php']), 0, 8);

            return [true, "→ FrankenPHP 1.14.0 with PHP {$s['apps'][$app]['info']['octane_php']}: on this server\n→ Restarting {$app}-octane...\n  ✓ answering on its port\n→ Composer, artisan, cron and workers: PHP {$from} → {$p['php']}\n✓ '{$app}' is on PHP {$p['php']}: Octane on {$build}, Composer, artisan, cron and workers on /usr/bin/php{$p['php']}", ['app' => $app, 'php' => $p['php'], 'build' => $build, 'changed' => true]];

        case 'octane-restart':
            return [true, "→ Restarting {$app}-octane...\n✓ {$app}-octane restarted and answering", ['app' => $app, 'restarted' => true]];

        case 'frankenphp-upgrade':
            return [true, "→ Checking FrankenPHP's releases...\n✓ '{$app}' is up to date (".(octaneView((string) $app, $s['apps'][$app]['info'] ?? [])['build'] ?? 'its build').')', ['apps' => [(string) $app => ['result' => 'up-to-date']], 'rolled_back' => []]];

        case 'valkey-isolate':
            $targets = ! empty($p['all']) ? valkeyView($s)['shared'] : [(string) $p['app']];
            $moved = [];
            $lines = [];
            foreach ($targets as $i => $target) {
                $s['valkey'][$target] = 'own';
                $port = 6400 + count(array_keys($s['valkey'], 'own', true));
                $keys = 180 + (crc32($target) % 900);
                $moved[] = ['app' => $target, 'uses' => 'own', 'port' => $port, 'keys_copied' => $keys];
                $lines[] = "✓ {$target}: own Valkey on 127.0.0.1:{$port} — {$keys} key(s) copied";
            }
            $none = valkeyView($s)['shared'] === [];

            return [true, implode("\n", $lines).($none ? "\n[INFO] No app uses the shared instance any more. It still holds the old keys, and every app has seen its password:\n    cipi valkey flush-shared" : ''), ['moved' => $moved, 'left' => [], 'shared_can_be_flushed' => $none]];

        case 'valkey-share':
            $s['valkey'][$app] = 'shared';

            return [true, "✓ {$app}: back on the shared Valkey (127.0.0.1:6379)", ['moved' => [['app' => $app, 'uses' => 'shared', 'port' => 6379, 'keys_copied' => null]], 'left' => [], 'shared_can_be_flushed' => false]];

        case 'service-restart':
            foreach ($s['services'] as &$svc) {
                if ($svc['name'] === $p['service']) {
                    $svc['since'] = $ts;
                }
            }
            unset($svc);

            return [true, "→ Restarting {$p['service']}...\n  ✓ {$p['service']} active (running)", ['service' => $p['service'], 'restarted' => true]];

        case 'backup-prune':
            $dry = ! empty($p['dry_run']);
            $old = gmdate('Y-m-d_020000', time() - 15 * 86400);

            return [true, ($dry ? "Dry run — nothing is deleted\n" : '')."→ Profile nightly: keep 14 days\n  ".($dry ? 'would delete' : '✓ deleted')." {$old} on s3\n  kept 14 runs", ['dry_run' => $dry]];
    }

    return [true, "✓ {$type} done", null];
}

/**
 * What `cipi deploy <app> --status --json` answers: running while a deploy or
 * rollback job of the app is in flight (Deployer first, then the post-deploy
 * steps), idle otherwise, with the last run from the ledger. The demo has no
 * webhook, so a running deploy was always started by the panel.
 */
function deployStatusView(array $state, string $profile, string $app, array $a, int $tail): array
{
    $audit = $a['audit'] ?? [];
    $branch = $a['info']['branch'] ?? 'main';
    $tz = date('P');
    $iso = fn (int $t): string => date('Y-m-d\TH:i:s', $t).str_replace(':', '', $tz);

    $running = null;
    foreach ($state['jobs'] ?? [] as $job) {
        if ($job['profile'] === $profile && ! $job['finished'] && ($job['params']['app'] ?? null) === $app
            && in_array($job['type'], ['app-deploy', 'app-deploy-rollback'], true)
            && microtime(true) - $job['created'] < JOB_PENDING_SECONDS + JOB_RUNNING_SECONDS) {
            $running = $job;
        }
    }

    $last = null;
    $lastRelease = null;
    if ($audit !== []) {
        $record = end($audit);
        $release = (string) ($record['release'] ?? '?');
        $event = $record['event'] ?? 'published';
        $seed = abs(crc32($app.$release.$event));
        $seconds = match ($event) { 'failed' => 18 + $seed % 40, 'rollback' => 2 + $seed % 5, default => 34 + $seed % 80 }; // as appLogs() writes it
        $finished = strtotime((string) ($record['ts'] ?? 'now')) ?: time();
        $lastRelease = $release;
        $last = [
            'result' => $event === 'failed' ? 'failed' : 'ok',
            'kind' => $event === 'rollback' ? 'rollback' : 'deploy',
            'release' => $release, 'duration' => $seconds, 'exit' => $event === 'failed' ? 1 : 0,
            'trigger' => match ($record['origin'] ?? 'panel') { 'webhook' => 'webhook', 'cli' => 'cli', default => 'panel' },
            'branch' => $branch, 'from_release' => ctype_digit($release) && (int) $release > 1 ? (string) ((int) $release - 1) : null,
            'started_at' => $iso($finished - $seconds), 'finished_at' => $iso($finished),
        ];
    }

    $lines = $tail > 0 ? array_slice(deployLogLines($app, $a['info'], $audit), -$tail) : [];
    if ($running === null) {
        return ['app' => $app, 'state' => 'idle', 'running' => false, 'phase' => null, 'queued' => false, 'locked' => false,
            'current' => null, 'last' => $last, 'log' => "/home/{$app}/logs/deploy.log"] + ($tail > 0 ? ['tail' => $lines] : []);
    }

    $elapsed = (int) floor(microtime(true) - $running['created']);
    $postDeploy = $elapsed >= JOB_PENDING_SECONDS + JOB_RUNNING_SECONDS * 0.7;
    $t = '['.date('Y-m-d H:i:s').']';
    $trigger = $running['type'] === 'app-deploy-rollback' ? 'rollback' : 'panel';
    $live = ["{$t} ===== deploy start  app={$app} trigger={$trigger} branch={$branch} from-release=".($lastRelease ?? 'none').' =====', "{$t} task deploy:info", "{$t} task deploy:lock", "{$t} task deploy:release", "{$t} task deploy:update_code"];
    if ($postDeploy) {
        $live[] = "{$t} task deploy:symlink";
        $live[] = "{$t} [{$app}] info successfully deployed!";
        $live[] = "{$t} → Post-deploy steps (cipi.yml)...";
    }

    return ['app' => $app, 'state' => 'running', 'running' => true, 'phase' => $postDeploy ? 'post-deploy' : 'deploy', 'queued' => false, 'locked' => true,
        'current' => ['trigger' => $trigger, 'branch' => $branch, 'from_release' => $lastRelease, 'started_at' => $iso((int) $running['created']), 'elapsed' => $elapsed, 'pids' => [random_int(20000, 29999)]],
        'last' => $last, 'log' => "/home/{$app}/logs/deploy.log"] + ($tail > 0 ? ['tail' => array_slice($live, -$tail)] : []);
}

function auditRecord(array $records, string $app, string $event, string $release, string $commit): array
{
    $last = $records !== [] ? end($records) : null;

    return [
        'ts' => gmdate('Y-m-d\TH:i:s\Z'), 'app' => $app, 'event' => $event, 'release' => $release, 'commit' => $commit,
        'origin' => 'panel', 'trigger' => 'manual', 'operator' => 'admin@cipi.local', 'ip' => '203.0.113.10',
        'claimed' => ['by' => 'cipi-gui'], 'seq' => ($last['seq'] ?? 0) + 1,
        'prev_sha256' => $last ? hash('sha256', json_encode($last)) : str_repeat('0', 64),
    ];
}

function createApp(array &$s, array &$state, string $profile, array $body): array
{
    $user = (string) ($body['user'] ?? '');
    $domain = strtolower(trim((string) ($body['domain'] ?? '')));
    if (! preg_match('/^[a-z][a-z0-9]{2,31}$/', $user)) {
        throw new DemoHttpError(422, 'user must be 3-32 lowercase letters/digits, starting with a letter');
    }
    if (isset($s['apps'][$user])) {
        throw new DemoHttpError(409, "App '{$user}' already exists");
    }
    if (! preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
        throw new DemoHttpError(422, 'Invalid domain');
    }
    $node = $body['node'] ?? null;
    $custom = ! empty($body['custom']);
    if (! $custom && empty($body['repository'])) {
        throw new DemoHttpError(422, 'repository is required for Laravel and Node apps');
    }
    $php = $node ? null : (string) ($body['php'] ?? $s['php']['default']);
    if ($php !== null && ! in_array($php, array_column($s['php']['versions'], 'version'), true)) {
        throw new DemoHttpError(422, "PHP {$php} is not installed on this server");
    }

    $nodeBuild = trim((string) ($body['node_build'] ?? ''));
    if ($nodeBuild !== '') {
        if ($node || $custom) {
            throw new DemoHttpError(422, "'node_build' is for Laravel apps (Node apps take 'build'; custom apps have no build step)");
        }
        if ($err = nodeBuildError($nodeBuild)) {
            throw new DemoHttpError(422, $err);
        }
    }

    // Environment label (API 1.36+): production unless given, same rule as the CLI.
    $env = trim((string) ($body['env'] ?? 'production'));
    if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $env)) {
        throw new DemoHttpError(422, 'env must be 1-32 lowercase letters, digits, - or _, starting with a letter or digit');
    }

    $framework = $body['framework'] ?? null;
    $info = [
        'app' => $user, 'domain' => $domain, 'php' => $php, 'branch' => $body['branch'] ?? 'main', 'env' => $env,
        'repository' => $body['repository'] ?? null, 'aliases' => [], 'suspended' => false, 'basic_auth' => false,
        'custom' => $custom, 'engine' => (! $node && ! $custom) ? ($body['engine'] ?? $s['engines']['default']) : null,
        'www_redirect' => null, 'force_https' => false,
        'octane' => ! empty($body['octane']) ? 'frankenphp' : null, 'octane_port' => ! empty($body['octane']) ? 8100 + count($s['apps']) : null,
        'node' => (bool) $node, 'node_mode' => $node ?: null,
        'node_version' => $node ? ($body['node_version'] ?? defaultNode($s)) : null,
        'framework' => $framework, 'build' => $node ? ($body['build'] ?? 'npm run build') : null,
        // Same presets as `cipi app create --node --framework=…` (lib/node.sh).
        'start' => $node === 'ssr' ? ($body['start'] ?? match ($framework) {
            'next' => 'npx next start -H 127.0.0.1',
            'nuxt' => 'node .output/server/index.mjs',
            'sveltekit' => 'node build',
            'astro' => 'node ./dist/server/entry.mjs',
            default => 'npm run start',
        }) : null,
        'output' => in_array($node, ['spa', 'static'], true) ? ($body['output'] ?? 'dist') : null,
        'health_path' => $node === 'ssr' ? ($body['health_path'] ?? '/') : null,
        'docroot' => $custom ? ($body['docroot'] ?? '') : null,
        'redirect' => null, 'redirects' => [], 'proxies' => [], 'created_at' => gmdate('Y-m-d'),
    ];
    $record = [
        'info' => $info,
        'env' => (! $node && ! $custom) ? [
            'APP_NAME' => ucfirst($user), 'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false', 'APP_URL' => 'https://'.$domain, 'LOG_CHANNEL' => 'single',
            'DB_CONNECTION' => $info['engine'] === 'pgsql' ? 'pgsql' : 'mariadb', 'DB_DATABASE' => $user, 'DB_USERNAME' => $user,
            'DB_PASSWORD' => randomSecret(24), 'QUEUE_CONNECTION' => 'redis', 'CIPI_WEBHOOK_TOKEN' => bin2hex(random_bytes(20)),
        ] : null,
        'auth' => null,
        'deploy_config' => ['keep_releases' => 5, 'migrate' => true, 'optimize' => true, 'storage_link' => true, 'queue_restart' => true, 'horizon_terminate' => false, 'predeploy_snapshot' => false, 'node_build' => $nodeBuild, 'extra_artisan' => []],
        'audit' => [],
        'basic_auth' => ['enabled' => false, 'users' => []],
        'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
    ];
    return [202, dispatchJob($state, $profile, 'app-create', ['app' => $user, 'record' => $record])];
}

// ── Views ────────────────────────────────────────────────────────────────

/**
 * App payload as the real API builds it: the list is a fixed projection without
 * `custom`; show returns the apps.json record (custom, docroot, …) plus live flags.
 */
function publicApp(array $info, bool $show): array
{
    $keys = ['app', 'domain', 'php', 'branch', 'env', 'repository', 'aliases', 'engine', 'octane', 'octane_port', 'node', 'node_mode', 'node_version', 'www_redirect', 'redirect', 'redirects', 'proxies', 'force_https', 'suspended', 'basic_auth', 'created_at'];
    if ($show) {
        $keys = array_merge($keys, ['custom', 'docroot', 'horizon']);
    }
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = $info[$key] ?? null;
    }
    $out['user'] = $out['app']; // the Linux user of an app is named after it
    $out['php'] ??= '';
    $out['branch'] ??= '';
    $out['env'] ??= 'production';
    $out['repository'] ??= '';
    if ($show && empty($info['custom'])) {
        unset($out['custom'], $out['docroot']);
    }

    return $out;
}

/**
 * The paths an app keeps out of its cache, as the server keeps them — or the
 * 422 it answers to what is not one.
 *
 * @return list<string>
 */
function pageCacheExclude(array $paths): array
{
    if (count($paths) > 50) {
        throw new DemoHttpError(422, 'exclude: 50 paths at most — one with a * covers many (/shop/*)');
    }
    $out = [];
    foreach ($paths as $path) {
        if (! is_string($path) || preg_match('#^/?[A-Za-z0-9._~%/*-]{1,127}$#', $path) !== 1) {
            throw new DemoHttpError(422, 'exclude: a path is letters, digits, . _ ~ % - / and * for anything (/cart*, /feed, /shop/*/reviews), 128 characters at most');
        }
        $path = (string) preg_replace('/\*{2,}/', '*', '/'.ltrim($path, '/'));
        if ($path === '/*') {
            throw new DemoHttpError(422, 'exclude: /* is every page — turn the cache off instead');
        }
        if (! in_array($path, $out, true)) {
            $out[] = $path;
        }
    }

    return $out;
}

/** What `cipi cache <app> status --json` answers; the pages on disk grow with the minutes since the cache was emptied. */
function pageCacheView(string $name, array $info): array
{
    $cache = ($info['page_cache'] ?? []) + ['enabled' => false, 'ttl' => '10m', 'size' => '256m', 'clear_on_deploy' => true, 'exclude' => [], 'files' => 0, 'since' => time() - 5400];
    $node = ! empty($info['node']);
    $files = $cache['enabled'] ? min(1800, (int) $cache['files'] + (int) ((time() - (int) $cache['since']) / 20)) : 0;
    $seconds = (int) $cache['ttl'] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][substr((string) $cache['ttl'], -1)];

    return [
        'app' => $name, 'supported' => ! $node, 'kind' => $node ? null : (! empty($info['octane']) ? 'proxy' : 'fastcgi'),
        'enabled' => (bool) $cache['enabled'], 'active' => $cache['enabled'] && empty($info['suspended']),
        'ttl' => $cache['ttl'], 'ttl_seconds' => $seconds, 'size' => $cache['size'], 'clear_on_deploy' => (bool) $cache['clear_on_deploy'],
        'exclude' => array_values((array) $cache['exclude']), 'exclude_max' => 50,
        'files' => $files, 'bytes' => $files * 41800, 'path' => "/var/cache/nginx/cipi/{$name}",
        'suspended' => ! empty($info['suspended']), 'clear_command' => "sudo /usr/local/bin/cipi-cache-clear {$name}",
    ];
}

/** What `cipi frankenphp php <app> --json` answers. An app whose build carries a newer PHP than the rest shows the mismatch. */
function octaneView(string $name, array $info): array
{
    $php = (string) ($info['php'] ?? '8.5');
    $octanePhp = (string) ($info['octane_php'] ?? '8.6.0');
    $minor = implode('.', array_slice(explode('.', $octanePhp), 0, 2));
    $mismatch = $minor !== $php;

    return [
        'app' => $name, 'php' => $php, 'octane_php' => $octanePhp,
        'build' => '1.14.0-php'.$octanePhp.'-'.substr(sha1($minor), 0, 8), 'frankenphp' => '1.14.0',
        'state' => $mismatch ? 'mismatch' : 'ok', 'restart_pending' => false,
        'available_php' => array_values(array_unique([$php, $minor])),
        'fix' => $mismatch ? ['command' => "cipi frankenphp php {$name} {$php}", 'why' => "a FrankenPHP build with PHP {$php} is on this server"] : null,
    ];
}

/** What `cipi valkey list --json` answers, with the API's `shared` and `isolated`. Laravel apps only. */
function valkeyView(array $s): array
{
    $apps = [];
    $own = 0;
    foreach ($s['apps'] as $name => $a) {
        if (! empty($a['info']['node']) || ! empty($a['info']['custom'])) {
            continue;
        }
        $uses = $s['valkey'][$name] ?? 'shared';
        $own += $uses === 'own' ? 1 : 0;
        $apps[] = [
            'app' => $name, 'uses' => $uses,
            'port' => $uses === 'own' ? 6400 + $own : null, 'instance' => $uses === 'own' ? 'running' : null,
            'memory' => $uses === 'own' ? number_format(1.2 + (crc32($name) % 900) / 100, 2).'M' : null,
            'keys' => $uses === 'own' ? 180 + (crc32($name) % 900) : null,
        ];
    }

    return [
        'shared_port' => 6379, 'apps' => $apps,
        'shared' => array_values(array_map(fn ($a) => $a['app'], array_filter($apps, fn ($a) => $a['uses'] === 'shared'))),
        'isolated' => $own,
    ];
}

function nginxView(string $name, array $info): array
{
    $config = (string) ($info['nginx'] ?? '');

    return ['app' => $name, 'config' => $config, 'bytes' => strlen($config), 'max_bytes' => 32768, 'active' => $config !== '' && empty($info['suspended']), 'suspended' => ! empty($info['suspended'])];
}

function wwwStatus(string $name, array $info): array
{
    $domain = $info['domain'];
    $apex = preg_replace('/^www\./', '', $domain);

    return ['app' => $name, 'primary' => $domain, 'apex' => $apex, 'www' => 'www.'.$apex, 'redirect' => $info['www_redirect']];
}

function redirectsView(string $name, array $info): array
{
    return ['app' => $name, 'redirect' => $info['redirect'], 'redirects' => $info['redirects']];
}

/**
 * `cipi yml plan <app> --json`; a release without the file has only the auto flag.
 * `env` (API 1.36+) is the app's environment label: the server reads cipi-<env>.yml
 * first and falls back to cipi.yml.
 */
function ymlView(string $name, ?array $yml, string $env = 'production'): array
{
    $file = $yml['file'] ?? null;
    $valid = $file === null ? null : (bool) ($yml['valid'] ?? true);
    $changes = array_map(fn ($c) => ['kind' => $c['kind'], 'summary' => $c['summary']], $file !== null && $valid ? $yml['changes'] : []);
    $blockers = $file !== null && $valid ? $yml['blockers'] : [];

    return [
        'app' => $name, 'env' => $env, 'file' => $file, 'auto' => (bool) ($yml['auto'] ?? false), 'valid' => $valid,
        'in_sync' => $valid ? $changes === [] && $blockers === [] : null,
        'errors' => $file !== null ? $yml['errors'] : [], 'warnings' => $file !== null ? $yml['warnings'] : [],
        'changes' => $changes, 'blockers' => $blockers, 'notes' => $file !== null && $valid ? $yml['notes'] : [],
    ];
}

/** `cipi yml apply <app> --yes`: [ok, output, result] like the API job; applied changes leave the plan. */
function ymlApply(array &$a, string $app): array
{
    $yml = $a['yml'] ?? null;
    $result = fn (string $outcome, array $extra = []): array => array_merge(
        ['app' => $app, 'outcome' => $outcome, 'applied' => 0, 'failed' => 0, 'failures' => [], 'blockers' => [], 'errors' => []],
        $extra,
    );

    if (($yml['file'] ?? null) === null) {
        return [false, "[ERROR] No cipi.yml found for '{$app}'.\n  Looked in: /home/{$app}/current/cipi.yml, current/cipi.yaml, htdocs/cipi.yml, shared/cipi.yml", $result('no-file', ['errors' => ["No cipi.yml found for '{$app}'"]])];
    }
    if (! $yml['valid']) {
        return [false, "→ Validating {$yml['file']}...\n".implode("\n", array_map(fn ($e) => "  ✗ {$e}", $yml['errors']))."\n[ERROR] cipi.yml is not valid — nothing was applied", $result('invalid', ['errors' => $yml['errors']])];
    }
    if ($yml['blockers'] !== []) {
        return [false, "Blocking:\n".implode("\n", array_map(fn ($b) => "  ✗ {$b}", $yml['blockers']))."\n[ERROR] Nothing was applied — resolve the blocking items above first.", $result('blocked', ['blockers' => $yml['blockers']])];
    }
    if ($yml['changes'] === []) {
        return [true, '✓ Nothing to do — the server already matches cipi.yml', $result('unchanged')];
    }

    $lines = ["→ Applying {$yml['file']} to '{$app}'..."];
    $failures = [];
    $kept = [];
    foreach ($yml['changes'] as $change) {
        if (! empty($change['fails'])) {
            $failures[] = $change['summary'];
            $kept[] = $change;
            $lines[] = "[ERROR]   failed: {$change['summary']}";
        } else {
            $lines[] = "  ✓ {$change['summary']}";
        }
    }
    $applied = count($yml['changes']) - count($failures);
    $a['yml']['changes'] = $kept;
    $lines[] = '';

    if ($failures === []) {
        $lines[] = "✓ Applied {$applied} change(s) from cipi.yml";

        return [true, implode("\n", $lines), $result('applied', ['applied' => $applied])];
    }

    // The demo lets a failed change through on the next apply.
    foreach ($a['yml']['changes'] as &$change) {
        unset($change['fails']);
    }
    unset($change);
    $lines[] = '[ERROR] Applied '.$applied.' change(s), '.count($failures).' failed';

    return [false, implode("\n", $lines), $result('partial', ['applied' => $applied, 'failed' => count($failures), 'failures' => $failures])];
}

function appLogs(string $name, array $info, array $query, array $audit = []): array
{
    $type = $query['type'] ?? 'all';
    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = max(1, min(1000, (int) ($query['per_page'] ?? 50)));
    $isLaravel = empty($info['node']) && empty($info['custom']);
    $isSsr = ($info['node_mode'] ?? null) === 'ssr';
    $available = match (true) {
        $isLaravel => ['nginx', 'php', 'laravel', 'worker', 'deploy'],
        ! empty($info['node']) => $isSsr ? ['nginx', 'worker', 'deploy'] : ['nginx', 'deploy'],
        default => ['nginx', 'php'],
    };

    $sources = [
        'nginx' => ["/home/{$name}/logs/nginx-access.log", 'nginxLine', 1840],
        'php' => ["/home/{$name}/logs/php-fpm-error.log", 'phpLine', 46],
        'laravel' => ["/home/{$name}/shared/storage/logs/laravel.log", 'laravelLine', 312],
        'worker' => $isSsr ? ["/home/{$name}/logs/node-ssr.log", 'nodeLine', 640] : ["/home/{$name}/logs/worker-default.log", 'workerLine', 2210],
        'deploy' => ["/home/{$name}/logs/deploy.log", 'deployLine', 388],
    ];

    // The deploy log follows the ledger: one block per record, closed by the
    // banner Cipi 5.6 writes ("===== deploy OK  app=x release=N duration=54s exit=0").
    if ($audit !== []) {
        $deployLog = deployLogLines($name, $info, $audit);
        $sources['deploy'] = ["/home/{$name}/logs/deploy.log", fn (string $n, array $i, int $index): string => $deployLog[count($deployLog) - 1 - $index], count($deployLog)];
    }

    $files = [];
    foreach ($sources as $kind => [$file, $gen, $total]) {
        if (! in_array($kind, $available, true) || ($type !== 'all' && $type !== $kind)) {
            continue;
        }
        $n = $type === 'all' ? min($perPage, 12) : $perPage;
        $pages = (int) ceil($total / $n);
        $lines = [];
        $offset = ($page - 1) * $n;
        for ($i = $n - 1; $i >= 0; $i--) {
            $index = $offset + $i;
            if ($index >= $total) {
                continue;
            }
            $lines[] = $gen($name, $info, $index);
        }
        $files[] = ['path' => $file, 'total_lines' => $total, 'page' => $page, 'per_page' => $n, 'total_pages' => $pages, 'lines' => $lines];
    }

    return ['app' => $name, 'type' => $type, 'page' => $page, 'per_page' => $perPage, 'available_types' => $available, 'files' => $files, 'warnings' => []];
}

function seededPick(array $items, int $seed): mixed
{
    return $items[abs(crc32((string) $seed)) % count($items)];
}

function nginxLine(string $app, array $info, int $i): string
{
    $paths = ['/', '/products/espresso-machine', '/cart', '/api/v1/cart/items', '/checkout', '/build/assets/app-4f2c1b.js', '/collections/sale', '/up', '/account/orders', '/search?q=grinder', '/favicon.ico', '/livewire/update'];
    $ips = ['203.0.113.51', '198.51.100.23', '192.0.2.144', '203.0.113.9', '198.51.100.201', '192.0.2.77'];
    $agents = ['Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/605.1.15 Version/26.0 Safari/605.1.15', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0.0.0 Safari/537.36', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) Mobile/15E148', 'Cipi-Healthcheck/1.0'];
    $path = seededPick($paths, $i);
    $status = $path === '/checkout' && $i % 23 === 0 ? 502 : ($path === '/favicon.ico' ? 304 : ($i % 31 === 0 ? 404 : 200));
    $time = gmdate('d/M/Y:H:i:s', time() - $i * 7).' +0000';
    $method = str_starts_with($path, '/api') || $path === '/livewire/update' ? 'POST' : 'GET';

    return seededPick($ips, $i * 3).' - - ['.$time.'] "'.$method.' '.$path.' HTTP/2.0" '.$status.' '.(1200 + ($i * 937) % 48000).' "https://'.$info['domain'].'/" "'.seededPick($agents, $i * 7).'"';
}

function phpLine(string $app, array $info, int $i): string
{
    $msgs = ['NOTICE: [pool '.$app.'] child '.(41000 + $i).' started', 'WARNING: [pool '.$app.'] server reached pm.max_children setting (12), consider raising it', 'NOTICE: [pool '.$app.'] child '.(40990 + $i).' exited with code 0 after 3600.012s'];

    return '['.gmdate('d-M-Y H:i:s', time() - $i * 1800).'] '.seededPick($msgs, $i);
}

function laravelLine(string $app, array $info, int $i): string
{
    $at = '['.gmdate('Y-m-d H:i:s', time() - $i * 600).'] production.';
    $entries = [
        'INFO: Order #'.(48210 - $i).' paid {"amount":"129.00","currency":"EUR","gateway":"stripe"}',
        'INFO: Scout index products synced {"documents":1834,"ms":412}',
        'WARNING: Slow query (1240 ms) {"sql":"select * from `orders` where `status` = ? order by `created_at` desc"}',
        'ERROR: cURL error 28: Operation timed out after 10001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.shipping.example.net/v2/rates {"exception":"[object] (GuzzleHttp\\\\Exception\\\\ConnectException(code: 0))"}',
        'INFO: Newsletter queued {"recipients":312}',
        'INFO: User logged in {"user_id":'.(900 + $i % 80).'}',
    ];

    return $at.seededPick($entries, $i);
}

function workerLine(string $app, array $info, int $i): string
{
    $jobs = ['App\\Jobs\\SendOrderConfirmation', 'App\\Jobs\\SyncInventory', 'Laravel\\Scout\\Jobs\\MakeSearchable', 'App\\Jobs\\GenerateInvoicePdf', 'Illuminate\\Notifications\\SendQueuedNotifications'];
    $time = gmdate('Y-m-d H:i:s', time() - $i * 45);
    $job = seededPick($jobs, $i);

    return $i % 2 === 0 ? "  {$time} {$job} ............ ".(40 + ($i * 37) % 900).'ms DONE' : "  {$time} {$job} ............ RUNNING";
}

/**
 * The whole deploy log of an app, oldest line first, from its audit ledger: the
 * start banner, Deployer's tasks and the end banner with how long it took. A
 * failed deploy's banner names the release that stayed current, as on a real
 * server; a rollback's the release it went back to.
 *
 * @return list<string>
 */
function deployLogLines(string $app, array $info, array $audit): array
{
    $lines = [];
    $current = null;
    $branch = $info['branch'] ?? 'main';
    $isNode = ! empty($info['node']);

    foreach ($audit as $record) {
        $event = $record['event'] ?? 'published';
        $release = (string) ($record['release'] ?? '?');
        $at = strtotime((string) ($record['ts'] ?? 'now')) ?: time();
        $seed = abs(crc32($app.$release.$event));
        $seconds = match ($event) {
            'failed' => 18 + $seed % 40,
            'rollback' => 2 + $seed % 5,
            default => 34 + $seed % 80,
        };
        $t = fn (int $offset): string => '['.gmdate('Y-m-d H:i:s', $at - $seconds + $offset).']';
        $trigger = match ($record['origin'] ?? 'webhook') { 'webhook' => 'webhook', 'cli' => 'cli', default => 'panel' };

        if ($event === 'rollback') {
            $lines[] = "{$t(0)} ===== deploy start  app={$app} trigger=rollback branch={$branch} from-release=".($current ?? '?').' =====';
            $lines[] = "{$t(1)} task rollback";
            $lines[] = "{$t($seconds)} [{$app}] info rolled back to release {$release}";
            $lines[] = "{$t($seconds)} ===== deploy ROLLBACK OK  app={$app} release={$release} duration={$seconds}s exit=0 =====";
            $lines[] = '';
            $current = $release;

            continue;
        }

        $lines[] = "{$t(0)} ===== deploy start  app={$app} trigger={$trigger} branch={$branch} from-release=".($current ?? 'none').' =====';
        $lines[] = "{$t(1)} task deploy:info";
        $lines[] = "{$t(1)} [{$app}] info deploying {$branch} (release {$release})";
        $steps = $isNode
            ? ['deploy:setup', 'deploy:lock', 'deploy:release', 'deploy:update_code', 'node:install', 'node:build', 'deploy:symlink', 'deploy:unlock', 'deploy:cleanup']
            : ['deploy:setup', 'deploy:lock', 'deploy:release', 'deploy:update_code', 'deploy:shared', 'deploy:writable', 'deploy:vendors', 'artisan:storage:link', 'artisan:optimize', 'artisan:migrate', 'deploy:symlink', 'artisan:queue:restart', 'deploy:unlock', 'deploy:cleanup'];
        $failAt = $event === 'failed' ? ($isNode ? 'node:build' : 'deploy:vendors') : null;
        $n = count($steps);
        foreach ($steps as $i => $step) {
            $offset = (int) round($seconds * ($i + 1) / ($n + 1));
            $lines[] = "{$t($offset)} task {$step}";
            if ($step === $failAt) {
                $lines[] = $isNode
                    ? "{$t($offset)} [{$app}] error Command failed with exit code 1: npm run build"
                    : "{$t($offset)} [{$app}] error Your requirements could not be resolved to an installable set of packages.";
                $lines[] = "{$t($seconds)} task deploy:failed";
                $lines[] = "{$t($seconds)} task deploy:unlock";

                break;
            }
        }

        if ($event === 'failed') {
            $lines[] = "{$t($seconds)} ===== deploy FAILED  app={$app} release=".($current ?? '?')." duration={$seconds}s exit=1 =====";
        } else {
            $lines[] = "{$t($seconds)} [{$app}] info successfully deployed!";
            $lines[] = "{$t($seconds)} ===== deploy OK  app={$app} release={$release} duration={$seconds}s exit=0 =====";
            $current = $release;
        }
        $lines[] = '';
    }

    return $lines;
}

function deployLine(string $app, array $info, int $i): string
{
    $steps = ['task deploy:info', 'task deploy:setup', 'task deploy:lock', 'task deploy:release', 'task deploy:update_code', 'task deploy:shared', 'task deploy:vendors', 'task artisan:migrate', 'task deploy:symlink', 'task deploy:unlock', "[{$app}] info successfully deployed!"];

    return '['.gmdate('Y-m-d H:i:s', time() - 3 * 3600 - $i * 4).'] '.$steps[$i % count($steps)];
}

function nodeLine(string $app, array $info, int $i): string
{
    $lines = ['▲ Next.js 16.0.1', '- Local: http://127.0.0.1:3104', '✓ Ready in 1.2s', 'GET /dashboard 200 in 84ms', 'GET /api/health 200 in 3ms', 'POST /api/projects 201 in 142ms'];

    return gmdate('Y-m-d\TH:i:s\Z', time() - $i * 20).' '.seededPick($lines, $i);
}

// ── Helpers ──────────────────────────────────────────────────────────────

/** Same rule as CipiValidationService::nodeBuildError() in cipi/api 1.34. */
function nodeBuildError(string $command): ?string
{
    if (strlen($command) > 200) {
        return 'Invalid build command. At most 200 characters';
    }
    if (preg_match('/[|<>`]|\$\(/', $command)) {
        return 'Invalid build command. Pipes, redirects, backticks and $( ) are not allowed';
    }
    if (! preg_match('/^[a-zA-Z0-9_.\/= :&;\'"-]+$/', $command)) {
        return 'Invalid build command. Use npm/npx/yarn/pnpm/bun/node and safe characters only';
    }
    if (! preg_match('/^(npm|npx|yarn|pnpm|bun|node)( |$)/', $command)) {
        return 'Invalid build command. It must start with npm, npx, yarn, pnpm, bun or node';
    }

    return null;
}

/**
 * The deploy key check of Cipi 5.6.0 (git ls-remote as the app user), faked:
 * a repository path with "private" in it is refused, a branch outside the usual
 * names is missing, everything else is reachable.
 *
 * @return array{status: string, branch?: string, error?: string, hint?: string}
 */
function repositoryAccess(string $app, string $repository, string $branch): array
{
    if (str_contains(strtolower($repository), 'private')) {
        $host = preg_match('/^(?:ssh:\/\/)?(?:[^@\/]+@)?([^:\/]+)/', $repository, $m) ? strtolower($m[1]) : '';
        $provider = match (true) {
            str_contains($host, 'github') => 'github',
            str_contains($host, 'gitlab') => 'gitlab',
            str_contains($host, 'bitbucket') => 'bitbucket',
            default => null,
        };

        return [
            'status' => 'denied',
            'error' => 'ERROR: Repository not found.',
            'hint' => $provider ? "cipi git {$provider}-token <token> && cipi git refresh {$app}" : "cipi deploy {$app} --trust-host=<host[:port]>",
        ];
    }
    if (! in_array($branch, ['main', 'master', 'develop', 'staging', 'production'], true)) {
        return ['status' => 'nobranch', 'branch' => $branch, 'hint' => "cipi app edit {$app} --branch=<name>"];
    }

    return ['status' => 'ok', 'branch' => $branch];
}

/** @return list<string> CLI lines of git_verify_repo_access for a non-interactive run. */
function repositoryAccessLines(string $app, array $access): array
{
    return match ($access['status']) {
        'ok' => ['→ Repository access...', "  ✓ Repository reachable — branch '{$access['branch']}' found"],
        'nobranch' => [
            '→ Repository access...',
            "  ! Repository reachable, but it has no branch '{$access['branch']}'",
            "  Pick an existing one: cipi app edit {$app} --branch=<name>",
        ],
        default => [
            '→ Repository access...',
            '  ! Cannot read the repository with the deploy key: '.$access['error'],
            str_starts_with($access['hint'], 'cipi git ') ? '  Save a token and Cipi adds the deploy key for you:' : '  Add the deploy key to the repository (cipi deploy '.$app.' --key shows it)',
            '    '.$access['hint'],
            "  Check again at any time: cipi git check {$app}",
        ],
    };
}

function engineLabel(?string $engine): string
{
    return $engine === 'pgsql' ? 'PostgreSQL' : 'MariaDB';
}

function defaultNode(array $s): string
{
    foreach ($s['node'] as $runtime) {
        if (! empty($runtime['default'])) {
            return $runtime['major'];
        }
    }

    return '24';
}

function randomSecret(int $length): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}

function loadState(string $path): array
{
    if (is_file($path)) {
        $state = json_decode((string) file_get_contents($path), true);
        if (is_array($state) && isset($state['servers'])) {
            return $state;
        }
    }

    @mkdir(dirname($path), 0775, true);

    return ['servers' => require __DIR__.'/fixtures.php', 'jobs' => []];
}

function saveState(string $path, array $state): void
{
    file_put_contents($path.'.tmp', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($path.'.tmp', $path);
}

function respond(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Cipi-Demo: '.API_VERSION);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

final class DemoHttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
