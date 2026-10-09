<?php

/**
 * Seed data for the Cipi GUI demo API.
 *
 * Three fictional servers that mirror what a real fleet looks like: a production box
 * with Laravel (FPM + Octane), Node (SPA / static / SSR) and custom PHP apps, a client
 * hosting box, and a small staging server. Domains use example.com/org/net and
 * documentation IP ranges (RFC 5737), so nothing points at a real host.
 */

$now = time();
$day = 86400;

$iso = fn (int $ts): string => gmdate('Y-m-d\TH:i:s\Z', $ts);
$date = fn (int $ts): string => gmdate('Y-m-d', $ts);

$laravelEnv = function (string $app, string $domain, string $engine = 'mariadb', array $extra = []): array {
    $db = $engine === 'pgsql'
        ? ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432']
        : ['DB_CONNECTION' => 'mariadb', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'];

    return array_merge([
        'APP_NAME' => ucfirst($app),
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:'.base64_encode(hash('sha256', $app, true)),
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://'.$domain,
        'LOG_CHANNEL' => 'single',
        'LOG_LEVEL' => 'warning',
    ], $db, [
        'DB_DATABASE' => $app,
        'DB_USERNAME' => $app,
        'DB_PASSWORD' => substr(hash('sha256', 'db'.$app), 0, 24),
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'SESSION_DRIVER' => 'redis',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PASSWORD' => substr(hash('sha256', 'valkey'), 0, 32),
        'MAIL_MAILER' => 'smtp',
        'MAIL_HOST' => 'smtp.postmarkapp.com',
        'MAIL_PORT' => '587',
        'MAIL_FROM_ADDRESS' => 'hello@'.preg_replace('/^[^.]+\./', '', $domain),
        'CIPI_WEBHOOK_TOKEN' => substr(hash('sha256', 'webhook'.$app), 0, 40),
    ], $extra);
};

$deployConfig = fn (array $overrides = []): array => array_merge([
    'keep_releases' => 5,
    'migrate' => true,
    'optimize' => true,
    'storage_link' => true,
    'queue_restart' => true,
    'horizon_terminate' => false,
    'predeploy_snapshot' => false,
    'node_build' => 'npm ci && npm run build',
    'extra_artisan' => [],
], $overrides);

/** Build a hash-chained deploy ledger, oldest first (same shape as `deploy --audit --json`). */
$audit = function (string $app, array $events) use ($iso): array {
    $records = [];
    $prev = str_repeat('0', 64);
    foreach ($events as $seq => $event) {
        $record = array_merge([
            'ts' => $iso($event['at']),
            'app' => $app,
            'event' => 'published',
            'release' => null,
            'commit' => null,
            'origin' => 'webhook',
            'trigger' => 'push',
            'operator' => null,
            'ip' => null,
            'claimed' => (object) [],
            'seq' => $seq + 1,
            'prev_sha256' => $prev,
        ], array_diff_key($event, ['at' => true]));
        $prev = hash('sha256', json_encode($record));
        $records[] = $record;
    }

    return $records;
};

$app = fn (array $attrs): array => array_merge([
    'php' => '8.5',
    'branch' => 'main',
    'repository' => null,
    'aliases' => [],
    'suspended' => false,
    'basic_auth' => false,
    'custom' => false,
    'engine' => null,
    'www_redirect' => null,
    'force_https' => true,
    'octane' => null,
    'octane_port' => null,
    'node' => false,
    'node_mode' => null,
    'node_version' => null,
    'framework' => null,
    'build' => null,
    'start' => null,
    'output' => null,
    'health_path' => null,
    'docroot' => null,
    'redirect' => null,
    'redirects' => [],
    'proxies' => [],
], $attrs);

/** One app of `cipi disk`: GB of files (home) and database, optional soft limit in GB. */
$diskApp = fn (string $name, float $filesGb, float $dbGb, ?float $limitGb = null): array => [
    'app' => $name, 'files_gb' => $filesGb, 'database_gb' => $dbGb, 'limit_gb' => $limitGb,
];

/** Same shape as `cipi disk --json`: the filesystem /home is on, then every app largest first. */
$diskReport = function (string $mount, float $sizeGb, float $usedGb, array $apps): array {
    $kb = fn (float $gb): int => (int) round($gb * 1048576);
    $gb = fn (int $kb): float => round($kb / 1048576, 2);
    $sizeKb = $kb($sizeGb);
    $usedKb = $kb($usedGb);

    $rows = [];
    foreach ($apps as $a) {
        $files = $kb($a['files_gb']);
        $db = $kb($a['database_gb']);
        $total = $files + $db;
        $limit = $a['limit_gb'];
        $rows[] = [
            'app' => $a['app'],
            'files_gb' => $gb($files), 'database_gb' => $gb($db), 'total_gb' => $gb($total),
            'percent' => round($total * 100 / $sizeKb, 1),
            'files_kb' => $files, 'database_kb' => $db, 'total_kb' => $total,
            'limit_gb' => $limit,
            'limit_percent' => $limit ? (int) floor($total * 100 / ($limit * 1048576)) : null,
            'over_limit' => $limit ? $total > $limit * 1048576 : false,
        ];
    }
    usort($rows, fn ($x, $y) => [$y['total_kb'], $x['app']] <=> [$x['total_kb'], $y['app']]);

    $appsKb = array_sum(array_column($rows, 'total_kb'));
    $otherKb = max($usedKb - $appsKb, 0);

    return [
        'disk' => ['mount' => $mount, 'size_gb' => $gb($sizeKb), 'used_gb' => $gb($usedKb), 'free_gb' => $gb($sizeKb - $usedKb), 'used_percent' => (int) round($usedKb * 100 / $sizeKb)],
        'apps' => $rows,
        'apps_total_gb' => $gb($appsKb), 'apps_percent' => round($appsKb * 100 / $sizeKb, 1),
        'other_gb' => $gb($otherKb), 'other_percent' => round($otherKb * 100 / $sizeKb, 1),
    ];
};

return [
    'fra1' => [
        'status' => [
            'system' => [
                'ip' => '203.0.113.24',
                'hostname' => 'fra1-prod',
                'os' => 'Ubuntu 24.04.3 LTS',
                'uptime' => 'up 6 weeks, 2 days, 4 hours, 11 minutes',
                'cipi' => '5.5.0',
            ],
            'resources' => [
                'cpu' => ['usage_percent' => 18],
                'memory' => ['used_mb' => 5243, 'total_mb' => 7941, 'usage_percent' => 66],
                'disk' => ['display' => '61G/154G (40%)', 'used' => '61G', 'total' => '154G', 'usage_percent' => 40],
            ],
            'services' => [
                'nginx' => 'running', 'mariadb' => 'running', 'postgresql' => 'running',
                'valkey-server' => 'running', 'supervisor' => 'running', 'fail2ban' => 'running',
            ],
            'php' => [
                ['version' => '8.4', 'status' => 'running', 'pools' => 1],
                ['version' => '8.5', 'status' => 'running', 'pools' => 4],
            ],
        ],
        'php' => [
            'default' => '8.5',
            'installable' => ['8.3', '8.4', '8.5'],
            'versions' => [
                ['version' => '8.4', 'status' => 'running', 'apps' => 1, 'default' => false],
                ['version' => '8.5', 'status' => 'running', 'apps' => 4, 'default' => true],
            ],
        ],
        'engines' => [
            'default' => 'mariadb',
            'engines' => [
                ['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true],
                ['engine' => 'pgsql', 'status' => 'running', 'port' => 5432, 'default' => false],
            ],
        ],
        'dbs' => [
            ['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '842.17 MB'],
            ['engine' => 'mariadb', 'name' => 'blog', 'user' => 'blog', 'size' => '96.40 MB'],
            ['engine' => 'mariadb', 'name' => 'analytics', 'user' => 'analytics', 'size' => '1.93 GB'],
            ['engine' => 'pgsql', 'name' => 'shopapi', 'user' => 'shopapi', 'size' => '311.52 MB'],
        ],
        'backups' => [
            'shop' => '/home/cipi/backups/shop_'.$date($now - $day).'_0300.sql.gz',
        ],
        'ssh_keys' => [
            ['id' => 1, 'type' => 'ssh-ed25519', 'comment' => 'alice@laptop', 'fingerprint' => 'SHA256:mF1v8Yt3x0uN6sQ2pZr9kL4wH7cJ5eB1aD3gR8tV2yU', 'current_session' => true],
            ['id' => 2, 'type' => 'ssh-ed25519', 'comment' => 'github-actions@ci', 'fingerprint' => 'SHA256:Q7wE2rT9yU1iO4pA6sD8fG3hJ5kL0zX2cV7bN9mM1qW', 'current_session' => false],
            ['id' => 3, 'type' => 'ssh-rsa', 'comment' => 'ops-backup@vault', 'fingerprint' => 'SHA256:Z3xC5vB7nM9aS1dF4gH6jK8lP0oI2uY4tR6eW8qQ1aZ', 'current_session' => false],
        ],
        'services' => [
            ['name' => 'nginx', 'status' => 'running', 'since' => '2026-08-24 09:12:41'],
            ['name' => 'mariadb', 'status' => 'running', 'since' => '2026-08-24 09:12:39'],
            ['name' => 'postgresql', 'status' => 'running', 'since' => '2026-08-24 09:12:40'],
            ['name' => 'valkey-server', 'status' => 'running', 'since' => '2026-08-24 09:12:38'],
            ['name' => 'supervisor', 'status' => 'running', 'since' => '2026-09-30 22:04:17'],
            ['name' => 'fail2ban', 'status' => 'running', 'since' => '2026-08-24 09:12:44'],
            ['name' => 'php8.4-fpm', 'status' => 'running', 'since' => '2026-10-01 03:00:12'],
            ['name' => 'php8.5-fpm', 'status' => 'running', 'since' => '2026-10-06 18:41:03'],
            ['name' => 'meilisearch', 'status' => 'running', 'since' => '2026-09-12 11:20:55'],
        ],
        'smtp' => [
            'configured' => true, 'enabled' => true, 'host' => 'smtp.postmarkapp.com', 'port' => '587',
            'user' => 'postmark-server-token', 'from' => 'cipi@example.com', 'to' => 'ops@example.com', 'tls' => true,
        ],
        'search' => [
            'installed' => true, 'running' => true, 'version' => '1.16.0', 'host' => '127.0.0.1', 'port' => 7700,
            'health' => 'available', 'data_size' => '412M',
            'apps' => ['shop' => ['prefix' => 'shop_', 'key' => 'scoped']],
        ],
        'packages' => [
            ['id' => 'image-optimizers', 'packages' => ['jpegoptim', 'optipng', 'pngquant', 'gifsicle', 'webp'], 'description' => 'Image optimisers for spatie/laravel-image-optimizer', 'installed' => true, 'partial' => false],
            ['id' => 'ffmpeg', 'packages' => ['ffmpeg'], 'description' => 'Audio/video transcoding for pbmedia/laravel-ffmpeg', 'installed' => false, 'partial' => false],
            ['id' => 'imagemagick', 'packages' => ['imagemagick'], 'description' => 'ImageMagick CLI (the PHP extension is already installed; this is convert/magick)', 'installed' => true, 'partial' => false],
            ['id' => 'poppler-utils', 'packages' => ['poppler-utils'], 'description' => 'PDF text extraction for spatie/pdf-to-text', 'installed' => false, 'partial' => false],
        ],
        'monitor' => [
            'reminder_minutes' => 240,
            'checks' => [
                ['check' => 'disk', 'config' => ['enabled' => true, 'warn' => 80, 'crit' => 90], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'app_disk', 'config' => ['enabled' => true, 'warn' => 90, 'minutes' => 30], 'state' => 'warn', 'last_alert' => $now - 3 * 3600],
                ['check' => 'ssl', 'config' => ['enabled' => true, 'days' => 14], 'state' => 'ok', 'last_alert' => $now - 19 * $day],
                ['check' => 'services', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'workers', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => $now - 6 * $day],
                ['check' => 'http_5xx', 'config' => ['enabled' => true, 'count' => 20, 'ratio' => 5], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'fs', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'load', 'config' => ['enabled' => true, 'factor' => 4, 'runs' => 2], 'state' => 'ok', 'last_alert' => null],
            ],
        ],
        'zt' => [
            'enabled' => true, 'cloudflared' => 'running', 'tunnel' => 'fra1-prod', 'real_ip' => 'CF-Connecting-IP',
            'lock_http' => 'true', 'lock_ssh' => 'false', 'ssh_hostname' => 'ssh-fra1.example.com',
            'raw' => "Zero Trust: enabled\ncloudflared: running (2026.9.1)\nTunnel: fra1-prod (4 connections: FRA, AMS)\nReal IP header: CF-Connecting-IP\nHTTP lock: on — ports 80/443 accept Cloudflare ranges only\nSSH lock: off\nSSH hostname: ssh-fra1.example.com",
        ],
        // `cipi disk --json` / `cipi disk db --json` (API 1.33+). blog sits at 93% of its 2.5 GB
        // limit — the monitor's app_disk check above is in `warn` for that reason.
        'disk' => $diskReport('/', 154.0, 61.2, [
            $diskApp('shop', 12.4, 0.82, 20),
            $diskApp('blog', 2.25, 0.09, 2.5),
            $diskApp('shopapi', 1.2, 0.3),
            $diskApp('console', 0.9, 0),
            $diskApp('launch', 0.45, 0),
            $diskApp('docs', 0.3, 0),
        ]),
        'disk_dbs' => [
            ['engine' => 'mariadb', 'databases' => [['name' => 'analytics', 'size_mb' => 1976.3], ['name' => 'blog', 'size_mb' => 96.4], ['name' => 'shop', 'size_mb' => 842.2]], 'on_disk_mb' => 3210.5, 'memory_mb' => null, 'note' => null],
            ['engine' => 'pgsql', 'databases' => [['name' => 'shopapi', 'size_mb' => 311.5]], 'on_disk_mb' => 402.8, 'memory_mb' => null, 'note' => null],
            ['engine' => 'valkey', 'databases' => [['name' => 'db0', 'size_mb' => null, 'keys' => 18422], ['name' => 'db1', 'size_mb' => null, 'keys' => 240]], 'on_disk_mb' => 41.2, 'memory_mb' => 96.4, 'note' => null],
            ['engine' => 'meilisearch', 'databases' => [['name' => 'shop_products', 'size_mb' => 184.6, 'documents' => 12840]], 'on_disk_mb' => 412.0, 'memory_mb' => null, 'note' => null],
        ],
        'ip_whitelist' => [
            'allow_all' => false, 'entries' => ['203.0.113.10', '198.51.100.0/28'],
            'file' => '/etc/cipi/api-ip-whitelist', 'client_ip' => '203.0.113.10',
        ],
        'node' => [
            ['major' => '22', 'version' => '22.20.0', 'default' => false, 'apps' => ['docs']],
            ['major' => '24', 'version' => '24.9.0', 'default' => true, 'apps' => ['console', 'launch', 'shop']],
        ],
        'apps' => [
            'shop' => [
                'info' => $app([
                    'app' => 'shop', 'domain' => 'shop.example.com', 'repository' => 'git@github.com:example-org/shop.git',
                    'aliases' => ['www.shop.example.com', 'store.example.com'], 'engine' => 'mariadb', 'www_redirect' => 'from-root',
                    'octane' => 'frankenphp', 'octane_port' => 8100, 'node_version' => '24', 'created_at' => '2026-03-05',
                    'redirects' => [
                        ['from' => '/blog/', 'to' => 'https://blog.example.com/', 'code' => 301, 'keep_path' => true],
                        ['from' => '/black-friday', 'to' => 'https://shop.example.com/collections/sale', 'code' => 302, 'keep_path' => false],
                    ],
                    'proxies' => [
                        ['prefix' => '/stats/', 'upstream' => 'http://127.0.0.1:8090', 'strip_prefix' => true, 'preserve_host' => false, 'timeout' => 60, 'buffering' => true],
                    ],
                ]),
                'env' => $laravelEnv('shop', 'shop.example.com', 'mariadb', [
                    'OCTANE_SERVER' => 'frankenphp', 'SCOUT_DRIVER' => 'meilisearch', 'MEILISEARCH_HOST' => 'http://127.0.0.1:7700',
                    'MEILISEARCH_KEY' => substr(hash('sha256', 'meili-shop'), 0, 32), 'STRIPE_KEY' => 'pk_live_51Ndemo0000000000000000',
                    'STRIPE_SECRET' => 'sk_live_51Ndemo0000000000000000',
                ]),
                'auth' => ['http-basic' => ['nova.laravel.com' => ['username' => 'ops@example.com', 'password' => 'nova-license-key']]],
                'deploy_config' => $deployConfig(['horizon_terminate' => true, 'predeploy_snapshot' => true, 'extra_artisan' => ['scout:sync-index-settings', 'octane:reload']]),
                'audit' => $audit('shop', [
                    ['at' => $now - 9 * $day, 'release' => '128', 'commit' => '3f9c2a1e7b8d4c6f0a2e5b9d1c3f7a8e2b4d6c0f', 'origin' => 'webhook', 'trigger' => 'push', 'operator' => 'github', 'ip' => '140.82.115.14'],
                    ['at' => $now - 6 * $day, 'release' => '129', 'commit' => 'a71d0c94e2f35b8c61d7e0a9b3f4c2d1e8a7b6c5', 'origin' => 'webhook', 'trigger' => 'push', 'operator' => 'github', 'ip' => '140.82.115.14'],
                    ['at' => $now - 6 * $day + 420, 'event' => 'failed', 'release' => '130', 'commit' => 'c02b7e5d91a84f3e6b2c0d9a7f1e3b5c8d4a2f6e', 'origin' => 'panel', 'trigger' => 'manual', 'operator' => 'alice', 'ip' => '203.0.113.10'],
                    ['at' => $now - 6 * $day + 900, 'release' => '131', 'commit' => 'c02b7e5d91a84f3e6b2c0d9a7f1e3b5c8d4a2f6e', 'origin' => 'panel', 'trigger' => 'manual', 'operator' => 'alice', 'ip' => '203.0.113.10'],
                    ['at' => $now - 2 * $day, 'event' => 'rollback', 'release' => '130', 'commit' => 'a71d0c94e2f35b8c61d7e0a9b3f4c2d1e8a7b6c5', 'origin' => 'cli', 'trigger' => 'manual', 'operator' => 'root', 'ip' => '198.51.100.7'],
                    ['at' => $now - 1 * $day, 'release' => '132', 'commit' => 'e4a8b1c7d2f05e9a3b6c8d0f1a2e4b7c9d3f5a1e', 'origin' => 'webhook', 'trigger' => 'push', 'operator' => 'github', 'ip' => '140.82.115.14'],
                    ['at' => $now - 3 * 3600, 'release' => '133', 'commit' => '9d1f3a5c7e2b4d6f8a0c1e3b5d7f9a2c4e6b8d0f', 'origin' => 'panel', 'trigger' => 'manual', 'operator' => 'alice', 'ip' => '203.0.113.10', 'claimed' => ['by' => 'cipi-gui', 'note' => 'hotfix: checkout tax rounding']],
                ]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => true, 'url' => 'https://shop.example.com/up', 'expect' => 200, 'state' => 'ok', 'failcount' => 0],
            ],
            'shopapi' => [
                'info' => $app([
                    'app' => 'shopapi', 'domain' => 'api.example.com', 'repository' => 'git@github.com:example-org/shop-api.git',
                    'engine' => 'pgsql', 'created_at' => '2026-03-07',
                    'redirect' => null,
                ]),
                'env' => $laravelEnv('shopapi', 'api.example.com', 'pgsql', ['SANCTUM_STATEFUL_DOMAINS' => 'shop.example.com,app.example.com']),
                'auth' => null,
                'deploy_config' => $deployConfig(['node_build' => '', 'keep_releases' => 8]),
                'audit' => $audit('shopapi', [
                    ['at' => $now - 12 * $day, 'release' => '57', 'commit' => '1b3d5f7a9c2e4b6d8f0a1c3e5b7d9f2a4c6e8b0d'],
                    ['at' => $now - 4 * $day, 'release' => '58', 'commit' => '7e9a1c3b5d2f4a6c8e0b1d3f5a7c9e2b4d6f8a0c'],
                ]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => true, 'url' => 'https://api.example.com/up', 'expect' => 200, 'state' => 'ok', 'failcount' => 0],
            ],
            'console' => [
                'info' => $app([
                    'app' => 'console', 'domain' => 'app.example.com', 'repository' => 'git@github.com:example-org/console.git',
                    'node' => true, 'node_mode' => 'ssr', 'node_version' => '24', 'framework' => 'next', 'php' => null,
                    'build' => 'npm run build', 'start' => 'npx next start -H 127.0.0.1', 'health_path' => '/api/health', 'created_at' => '2026-09-18',
                ]),
                'audit' => $audit('console', [
                    ['at' => $now - 5 * $day, 'release' => '12', 'commit' => '5c7e9a1b3d2f4c6e8a0b2d4f6c8e1a3b5d7f9c2e'],
                    ['at' => $now - 20 * 3600, 'release' => '13', 'commit' => 'b2d4f6a8c1e3b5d7f9a2c4e6b8d1f3a5c7e9b2d4'],
                ]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => true, 'url' => 'https://app.example.com/api/health', 'expect' => 200, 'state' => 'ok', 'failcount' => 0],
            ],
            'docs' => [
                'info' => $app([
                    'app' => 'docs', 'domain' => 'docs.example.com', 'repository' => 'git@github.com:example-org/docs.git',
                    'node' => true, 'node_mode' => 'static', 'node_version' => '22', 'framework' => 'astro', 'php' => null,
                    'build' => 'npm run build', 'output' => 'dist', 'created_at' => '2026-09-18',
                    'redirects' => [['from' => '/v4/', 'to' => 'https://docs.example.com/archive/v4/', 'code' => 301, 'keep_path' => true]],
                ]),
                'audit' => $audit('docs', [
                    ['at' => $now - 2 * $day, 'release' => '41', 'commit' => 'd3f5a7c9e1b2d4f6a8c0e2b4d6f8a1c3e5b7d9f2'],
                ]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
            'launch' => [
                'info' => $app([
                    'app' => 'launch', 'domain' => 'launch.example.com', 'repository' => 'git@github.com:example-org/launch.git',
                    'branch' => 'release', 'node' => true, 'node_mode' => 'spa', 'node_version' => '24', 'framework' => 'vite', 'php' => null,
                    'build' => 'npm run build', 'output' => 'dist', 'basic_auth' => true, 'created_at' => '2026-10-02',
                ]),
                'audit' => $audit('launch', [
                    ['at' => $now - 5 * 3600, 'release' => '3', 'commit' => 'f6a8c0e2b4d6f8a1c3e5b7d9f2a4c6e8b0d1f3a5', 'origin' => 'panel', 'trigger' => 'manual', 'operator' => 'alice', 'ip' => '203.0.113.10'],
                ]),
                'basic_auth' => ['enabled' => true, 'users' => ['preview']],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
            'blog' => [
                'info' => $app([
                    'app' => 'blog', 'domain' => 'blog.example.com', 'custom' => true, 'php' => '8.4', 'docroot' => 'public',
                    'repository' => null, 'branch' => null, 'created_at' => '2026-03-11', 'www_redirect' => null,
                ]),
                'auth' => null,
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => true, 'url' => 'https://blog.example.com/', 'expect' => 200, 'state' => 'fail', 'failcount' => 2],
            ],
        ],
    ],

    'nyc1' => [
        'status' => [
            'system' => [
                'ip' => '198.51.100.42',
                'hostname' => 'nyc1-clients',
                'os' => 'Ubuntu 26.04 LTS',
                'uptime' => 'up 3 weeks, 1 day, 7 hours, 52 minutes',
                'cipi' => '5.5.0',
            ],
            'resources' => [
                'cpu' => ['usage_percent' => 7],
                'memory' => ['used_mb' => 2210, 'total_mb' => 3819, 'usage_percent' => 58],
                'disk' => ['display' => '23G/76G (31%)', 'used' => '23G', 'total' => '76G', 'usage_percent' => 31],
            ],
            'services' => [
                'nginx' => 'running', 'mariadb' => 'running', 'valkey-server' => 'running',
                'supervisor' => 'running', 'fail2ban' => 'running',
            ],
            'php' => [
                ['version' => '8.3', 'status' => 'running', 'pools' => 1],
                ['version' => '8.5', 'status' => 'running', 'pools' => 3],
            ],
        ],
        'php' => [
            'default' => '8.5',
            'installable' => ['8.3', '8.4', '8.5'],
            'versions' => [
                ['version' => '8.3', 'status' => 'running', 'apps' => 1, 'default' => false],
                ['version' => '8.5', 'status' => 'running', 'apps' => 3, 'default' => true],
            ],
        ],
        'engines' => [
            'default' => 'mariadb',
            'engines' => [
                ['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true],
                ['engine' => 'pgsql', 'status' => 'not_installed', 'port' => null, 'default' => false],
            ],
        ],
        'dbs' => [
            ['engine' => 'mariadb', 'name' => 'bakery', 'user' => 'bakery', 'size' => '58.12 MB'],
            ['engine' => 'mariadb', 'name' => 'dentalcare', 'user' => 'dentalcare', 'size' => '204.88 MB'],
            ['engine' => 'mariadb', 'name' => 'crm', 'user' => 'crm', 'size' => '1.12 GB'],
        ],
        'backups' => [],
        'ssh_keys' => [
            ['id' => 1, 'type' => 'ssh-ed25519', 'comment' => 'alice@laptop', 'fingerprint' => 'SHA256:mF1v8Yt3x0uN6sQ2pZr9kL4wH7cJ5eB1aD3gR8tV2yU', 'current_session' => false],
        ],
        'services' => [
            ['name' => 'nginx', 'status' => 'running', 'since' => '2026-09-15 06:30:02'],
            ['name' => 'mariadb', 'status' => 'running', 'since' => '2026-09-15 06:29:58'],
            ['name' => 'valkey-server', 'status' => 'running', 'since' => '2026-09-15 06:29:57'],
            ['name' => 'supervisor', 'status' => 'running', 'since' => '2026-09-15 06:30:05'],
            ['name' => 'fail2ban', 'status' => 'running', 'since' => '2026-09-15 06:30:09'],
            ['name' => 'php8.3-fpm', 'status' => 'running', 'since' => '2026-09-15 06:30:01'],
            ['name' => 'php8.5-fpm', 'status' => 'running', 'since' => '2026-10-04 12:11:40'],
        ],
        'smtp' => [
            'configured' => true, 'enabled' => true, 'host' => 'smtp.eu.mailgun.org', 'port' => '587',
            'user' => 'postmaster@mg.example.org', 'from' => 'cipi@example.org', 'to' => 'ops@example.com', 'tls' => true,
        ],
        'search' => ['installed' => false, 'running' => false, 'apps' => []],
        'packages' => [
            ['id' => 'image-optimizers', 'packages' => ['jpegoptim', 'optipng', 'pngquant', 'gifsicle', 'webp'], 'description' => 'Image optimisers for spatie/laravel-image-optimizer', 'installed' => false, 'partial' => true],
            ['id' => 'ffmpeg', 'packages' => ['ffmpeg'], 'description' => 'Audio/video transcoding for pbmedia/laravel-ffmpeg', 'installed' => false, 'partial' => false],
            ['id' => 'imagemagick', 'packages' => ['imagemagick'], 'description' => 'ImageMagick CLI (the PHP extension is already installed; this is convert/magick)', 'installed' => false, 'partial' => false],
            ['id' => 'poppler-utils', 'packages' => ['poppler-utils'], 'description' => 'PDF text extraction for spatie/pdf-to-text', 'installed' => true, 'partial' => false],
        ],
        'monitor' => [
            'reminder_minutes' => 240,
            'checks' => [
                ['check' => 'disk', 'config' => ['enabled' => true, 'warn' => 80, 'crit' => 90], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'app_disk', 'config' => ['enabled' => true, 'warn' => 90, 'minutes' => 30], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'ssl', 'config' => ['enabled' => true, 'days' => 14], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'services', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'workers', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'http_5xx', 'config' => ['enabled' => true, 'count' => 20, 'ratio' => 5], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'fs', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'load', 'config' => ['enabled' => true, 'factor' => 4, 'runs' => 2], 'state' => 'ok', 'last_alert' => null],
            ],
        ],
        'zt' => [
            'enabled' => false, 'cloudflared' => 'not installed', 'tunnel' => null, 'real_ip' => null,
            'lock_http' => 'false', 'lock_ssh' => 'false', 'ssh_hostname' => null, 'raw' => "Zero Trust: disabled\ncloudflared: not installed",
        ],
        'disk' => $diskReport('/', 76.0, 23.4, [
            $diskApp('crm', 4.6, 1.12, 10),
            $diskApp('dentalcare', 3.4, 0.2),
            $diskApp('bakery', 1.8, 0.06),
            $diskApp('portfolio', 0.35, 0),
        ]),
        'disk_dbs' => [
            ['engine' => 'mariadb', 'databases' => [['name' => 'bakery', 'size_mb' => 58.1], ['name' => 'crm', 'size_mb' => 1146.9], ['name' => 'dentalcare', 'size_mb' => 204.9]], 'on_disk_mb' => 1620.4, 'memory_mb' => null, 'note' => null],
            ['engine' => 'valkey', 'databases' => [['name' => 'db0', 'size_mb' => null, 'keys' => 2210]], 'on_disk_mb' => 6.2, 'memory_mb' => 18.6, 'note' => null],
        ],
        'ip_whitelist' => ['allow_all' => true, 'entries' => ['*'], 'file' => '/etc/cipi/api-ip-whitelist', 'client_ip' => '203.0.113.10'],
        'node' => [
            ['major' => '24', 'version' => '24.9.0', 'default' => true, 'apps' => ['portfolio']],
        ],
        'apps' => [
            'bakery' => [
                'info' => $app([
                    'app' => 'bakery', 'domain' => 'bakery.example.org', 'repository' => 'git@github.com:example-agency/bakery.git',
                    'aliases' => ['www.bakery.example.org'], 'engine' => 'mariadb', 'www_redirect' => 'to-root', 'created_at' => '2026-04-14',
                ]),
                'env' => $laravelEnv('bakery', 'bakery.example.org'),
                'auth' => null,
                'deploy_config' => $deployConfig(),
                'audit' => $audit('bakery', [['at' => $now - 8 * $day, 'release' => '22', 'commit' => '2a4c6e8b0d1f3a5c7e9b2d4f6a8c0e1b3d5f7a9c']]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => true, 'url' => 'https://bakery.example.org/up', 'expect' => 200, 'state' => 'ok', 'failcount' => 0],
            ],
            'dentalcare' => [
                'info' => $app([
                    'app' => 'dentalcare', 'domain' => 'dentalcare.example.org', 'repository' => 'git@gitlab.com:example-agency/dentalcare.git',
                    'engine' => 'mariadb', 'created_at' => '2026-05-02', 'php' => '8.3',
                ]),
                'env' => $laravelEnv('dentalcare', 'dentalcare.example.org'),
                'auth' => null,
                'deploy_config' => $deployConfig(['node_build' => '']),
                'audit' => $audit('dentalcare', [['at' => $now - 15 * $day, 'release' => '64', 'commit' => '8c0e2b4d6f8a1c3e5b7d9f2a4c6e8b0d1f3a5c7e']]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
            'crm' => [
                'info' => $app([
                    'app' => 'crm', 'domain' => 'crm.example.net', 'repository' => 'git@github.com:example-agency/crm.git',
                    'engine' => 'mariadb', 'created_at' => '2026-06-21', 'suspended' => true,
                ]),
                'env' => $laravelEnv('crm', 'crm.example.net'),
                'auth' => null,
                'deploy_config' => $deployConfig(),
                'audit' => $audit('crm', [['at' => $now - 40 * $day, 'release' => '9', 'commit' => '4e6b8d0f1a3c5e7b9d2f4a6c8e0b1d3f5a7c9e2b']]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
            'portfolio' => [
                'info' => $app([
                    'app' => 'portfolio', 'domain' => 'studio.example.net', 'repository' => 'git@github.com:example-agency/portfolio.git',
                    'node' => true, 'node_mode' => 'static', 'node_version' => '24', 'framework' => 'astro', 'php' => null,
                    'build' => 'npm run build', 'output' => 'dist', 'created_at' => '2026-09-25', 'www_redirect' => 'to-root',
                    'aliases' => ['www.studio.example.net'],
                ]),
                'audit' => $audit('portfolio', [['at' => $now - 3 * $day, 'release' => '7', 'commit' => '0d2f4a6c8e1b3d5f7a9c2e4b6d8f0a1c3e5b7d9f']]),
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
        ],
    ],

    'staging' => [
        'status' => [
            'system' => [
                'ip' => '203.0.113.77',
                'hostname' => 'staging',
                'os' => 'Ubuntu 24.04.3 LTS',
                'uptime' => 'up 4 days, 22 hours, 3 minutes',
                'cipi' => '5.5.0',
            ],
            'resources' => [
                'cpu' => ['usage_percent' => 3],
                'memory' => ['used_mb' => 1180, 'total_mb' => 1967, 'usage_percent' => 60],
                'disk' => ['display' => '14G/38G (37%)', 'used' => '14G', 'total' => '38G', 'usage_percent' => 37],
            ],
            'services' => [
                'nginx' => 'running', 'mariadb' => 'running', 'postgresql' => 'running',
                'valkey-server' => 'running', 'supervisor' => 'running', 'fail2ban' => 'running',
            ],
            'php' => [['version' => '8.5', 'status' => 'running', 'pools' => 2]],
        ],
        'php' => [
            'default' => '8.5',
            'installable' => ['8.3', '8.4', '8.5'],
            'versions' => [['version' => '8.5', 'status' => 'running', 'apps' => 2, 'default' => true]],
        ],
        'engines' => [
            'default' => 'mariadb',
            'engines' => [
                ['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true],
                ['engine' => 'pgsql', 'status' => 'running', 'port' => 5432, 'default' => false],
            ],
        ],
        'dbs' => [
            ['engine' => 'mariadb', 'name' => 'shopstg', 'user' => 'shopstg', 'size' => '120.04 MB'],
            ['engine' => 'pgsql', 'name' => 'apistg', 'user' => 'apistg', 'size' => '38.71 MB'],
        ],
        'backups' => [],
        'ssh_keys' => [
            ['id' => 1, 'type' => 'ssh-ed25519', 'comment' => 'alice@laptop', 'fingerprint' => 'SHA256:mF1v8Yt3x0uN6sQ2pZr9kL4wH7cJ5eB1aD3gR8tV2yU', 'current_session' => false],
            ['id' => 2, 'type' => 'ssh-ed25519', 'comment' => 'github-actions@ci', 'fingerprint' => 'SHA256:Q7wE2rT9yU1iO4pA6sD8fG3hJ5kL0zX2cV7bN9mM1qW', 'current_session' => false],
        ],
        'services' => [
            ['name' => 'nginx', 'status' => 'running', 'since' => '2026-10-02 08:00:31'],
            ['name' => 'mariadb', 'status' => 'running', 'since' => '2026-10-02 08:00:27'],
            ['name' => 'postgresql', 'status' => 'running', 'since' => '2026-10-02 08:00:28'],
            ['name' => 'valkey-server', 'status' => 'running', 'since' => '2026-10-02 08:00:26'],
            ['name' => 'supervisor', 'status' => 'running', 'since' => '2026-10-02 08:00:35'],
            ['name' => 'fail2ban', 'status' => 'running', 'since' => '2026-10-02 08:00:38'],
            ['name' => 'php8.5-fpm', 'status' => 'running', 'since' => '2026-10-02 08:00:30'],
        ],
        'smtp' => ['configured' => false, 'enabled' => false, 'host' => null, 'port' => null, 'user' => null, 'from' => null, 'to' => null, 'tls' => null],
        'search' => ['installed' => false, 'running' => false, 'apps' => []],
        'packages' => [
            ['id' => 'image-optimizers', 'packages' => ['jpegoptim', 'optipng', 'pngquant', 'gifsicle', 'webp'], 'description' => 'Image optimisers for spatie/laravel-image-optimizer', 'installed' => false, 'partial' => false],
            ['id' => 'ffmpeg', 'packages' => ['ffmpeg'], 'description' => 'Audio/video transcoding for pbmedia/laravel-ffmpeg', 'installed' => false, 'partial' => false],
            ['id' => 'imagemagick', 'packages' => ['imagemagick'], 'description' => 'ImageMagick CLI (the PHP extension is already installed; this is convert/magick)', 'installed' => false, 'partial' => false],
            ['id' => 'poppler-utils', 'packages' => ['poppler-utils'], 'description' => 'PDF text extraction for spatie/pdf-to-text', 'installed' => false, 'partial' => false],
        ],
        'monitor' => [
            'reminder_minutes' => 240,
            'checks' => [
                ['check' => 'disk', 'config' => ['enabled' => true, 'warn' => 80, 'crit' => 90], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'services', 'config' => ['enabled' => true], 'state' => 'ok', 'last_alert' => null],
                ['check' => 'load', 'config' => ['enabled' => true, 'factor' => 4, 'runs' => 2], 'state' => null, 'last_alert' => null],
            ],
        ],
        'zt' => [
            'enabled' => false, 'cloudflared' => 'not installed', 'tunnel' => null, 'real_ip' => null,
            'lock_http' => 'false', 'lock_ssh' => 'false', 'ssh_hostname' => null, 'raw' => "Zero Trust: disabled\ncloudflared: not installed",
        ],
        'disk' => $diskReport('/', 38.0, 14.1, [
            $diskApp('shopstg', 2.6, 0.12),
            $diskApp('apistg', 0.8, 0.04),
        ]),
        'disk_dbs' => [
            ['engine' => 'mariadb', 'databases' => [['name' => 'shopstg', 'size_mb' => 120.0]], 'on_disk_mb' => 180.3, 'memory_mb' => null, 'note' => null],
            ['engine' => 'pgsql', 'databases' => [['name' => 'apistg', 'size_mb' => 38.7]], 'on_disk_mb' => 61.0, 'memory_mb' => null, 'note' => null],
            ['engine' => 'valkey', 'databases' => [['name' => 'db0', 'size_mb' => null, 'keys' => 312]], 'on_disk_mb' => 0.3, 'memory_mb' => 4.1, 'note' => null],
        ],
        'ip_whitelist' => ['allow_all' => true, 'entries' => ['*'], 'file' => '/etc/cipi/api-ip-whitelist', 'client_ip' => '203.0.113.10'],
        'node' => [
            ['major' => '24', 'version' => '24.9.0', 'default' => true, 'apps' => []],
        ],
        'apps' => [
            'shopstg' => [
                'info' => $app([
                    'app' => 'shopstg', 'domain' => 'shop.staging.example.com', 'repository' => 'git@github.com:example-org/shop.git',
                    'branch' => 'develop', 'engine' => 'mariadb', 'basic_auth' => true, 'created_at' => '2026-10-02',
                ]),
                'env' => $laravelEnv('shopstg', 'shop.staging.example.com', 'mariadb', ['APP_ENV' => 'staging', 'APP_DEBUG' => 'true']),
                'auth' => null,
                'deploy_config' => $deployConfig(['migrate' => true, 'extra_artisan' => ['db:seed --class=DemoSeeder --force']]),
                'audit' => $audit('shopstg', [['at' => $now - 40 * 60, 'release' => '18', 'commit' => '6a8c0e2b4d1f3a5c7e9b2d4f6a8c1e3b5d7f9a2c', 'operator' => 'github', 'ip' => '140.82.115.14']]),
                'basic_auth' => ['enabled' => true, 'users' => ['qa']],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
            'apistg' => [
                'info' => $app([
                    'app' => 'apistg', 'domain' => '*.preview.example.com', 'repository' => 'git@github.com:example-org/shop-api.git',
                    'branch' => 'develop', 'engine' => 'pgsql', 'created_at' => '2026-10-02', 'force_https' => false,
                ]),
                'env' => $laravelEnv('apistg', 'api.preview.example.com', 'pgsql', ['APP_ENV' => 'staging']),
                'auth' => null,
                'deploy_config' => $deployConfig(['node_build' => '']),
                'audit' => [],
                'basic_auth' => ['enabled' => false, 'users' => []],
                'health' => ['enabled' => false, 'url' => null, 'expect' => null, 'state' => null, 'failcount' => 0],
            ],
        ],
    ],
];
