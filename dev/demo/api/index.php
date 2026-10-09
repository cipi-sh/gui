<?php

/**
 * Cipi GUI demo API — a stateful stand-in for `cipi api` (REST API 1.33).
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

const API_VERSION = '1.33.0';
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
    if ($method === 'GET' && $r('/monitor')) {
        return [200, ['data' => $s['monitor']]];
    }
    if ($method === 'GET' && $r('/zt')) {
        return [200, ['data' => $s['zt']]];
    }

    // Disk usage (API 1.33+): `cipi disk --json` and `cipi disk db --json`
    if ($method === 'GET' && $r('/disk/dbs')) {
        return [200, ['data' => $s['disk_dbs'] ?? []]];
    }
    if ($method === 'GET' && $r('/disk')) {
        return [200, ['data' => $s['disk'] ?? []]];
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
            $changes = array_intersect_key($body, array_flip(['php', 'branch', 'repository', 'domain', 'node', 'node_version', 'build', 'start', 'output', 'health_path']));
            if ($changes === []) {
                throw new DemoHttpError(422, 'Nothing to change');
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
            return [200, ['data' => appLogs($name, $info, $query)]];

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
            $laravelOnly();

            return [200, ['data' => $a['deploy_config'] + ['app' => $name]]];
        case $sub === '/deploy-config' && $method === 'PUT':
            $laravelOnly();
            $a['deploy_config'] = array_merge($a['deploy_config'], array_intersect_key($body, $a['deploy_config']));

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
            [$ok, $output, $result] = runJob($state['servers'][$job['profile']], $job['type'], $job['params']);
            $job['finished'] = true;
            $job['status'] = $ok ? 'completed' : 'failed';
            $job['exit_code'] = $ok ? 0 : 1;
            $job['output'] = $output;
            $job['result'] = $result;
        }
    }

    $created = gmdate('Y-m-d\TH:i:s.000000\Z', (int) $job['created']);

    return [
        'id' => $job['id'], 'type' => $job['type'], 'status' => $job['status'],
        'result' => $job['result'], 'output' => $job['output'], 'exit_code' => $job['exit_code'],
        'created_at' => $created, 'updated_at' => gmdate('Y-m-d\TH:i:s.000000\Z'),
    ];
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
            $out = array_merge($out, [
                '→ PHP-FPM pool...', '  ✓ php'.($info['php'] ?? '8.5').'-fpm',
                '→ Deploy key...', '  ✓ ed25519',
                '→ Nginx vhost...', "  ✓ Nginx → {$info['domain']}",
                '→ Queue worker...', $kind === 'laravel' ? '  ✓ Worker (default)' : '  – not needed',
                '', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',
                "  APP CREATED: {$app}",
                '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━',
                "  Domain:     {$info['domain']}",
                "  IP:         {$s['status']['system']['ip']}",
                '  PHP:        '.($info['php'] ?? '8.5'),
                "  Runtime:    {$runtime}",
                "  Home:       /home/{$app}",
                '',
                "  SSH         {$app} / {$sshPass}",
            ]);
            if ($kind === 'laravel') {
                $out[] = "  Database    {$app} / {$dbPass}  (".engineLabel($info['engine'] ?? 'mariadb').')';
            }
            if (! empty($info['repository'])) {
                $out[] = '';
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

            return [true, "→ Editing app '{$app}'...\n".implode("\n", $lines)."\n\n✓ App '{$app}' updated", ['changes' => $changes]];

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

        case 'service-restart':
            foreach ($s['services'] as &$svc) {
                if ($svc['name'] === $p['service']) {
                    $svc['since'] = $ts;
                }
            }
            unset($svc);

            return [true, "→ Restarting {$p['service']}...\n  ✓ {$p['service']} active (running)", ['service' => $p['service'], 'restarted' => true]];
    }

    return [true, "✓ {$type} done", null];
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

    $framework = $body['framework'] ?? null;
    $info = [
        'app' => $user, 'domain' => $domain, 'php' => $php, 'branch' => $body['branch'] ?? 'main',
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
        'deploy_config' => ['keep_releases' => 5, 'migrate' => true, 'optimize' => true, 'storage_link' => true, 'queue_restart' => true, 'horizon_terminate' => false, 'predeploy_snapshot' => false, 'node_build' => 'npm ci && npm run build', 'extra_artisan' => []],
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
    $keys = ['app', 'domain', 'php', 'branch', 'repository', 'aliases', 'engine', 'octane', 'octane_port', 'node', 'node_mode', 'node_version', 'www_redirect', 'redirect', 'redirects', 'proxies', 'force_https', 'suspended', 'basic_auth', 'created_at'];
    if ($show) {
        $keys = array_merge($keys, ['custom', 'docroot']);
    }
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = $info[$key] ?? null;
    }
    $out['php'] ??= '';
    $out['branch'] ??= '';
    $out['repository'] ??= '';
    if ($show && empty($info['custom'])) {
        unset($out['custom'], $out['docroot']);
    }

    return $out;
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

function appLogs(string $name, array $info, array $query): array
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
