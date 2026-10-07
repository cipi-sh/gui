<?php

/**
 * Register the demo servers in the dev host (dev/host) and reset the admin user.
 *
 *   php dev/demo/seed.php [url-pattern]
 *
 * The URL pattern contains {profile}; it defaults to the bundled PHP server
 * (http://127.0.0.1:8787/{profile}). With Laravel Herd you can link the demo API
 * and use https://{profile}.cipi-demo.test instead — see dev/README.md.
 */

use CipiGui\Models\CipiServer;
use Illuminate\Contracts\Console\Kernel;

$host = dirname(__DIR__).'/host';

if (! is_file($host.'/vendor/autoload.php')) {
    fwrite(STDERR, "dev/host is missing — run ./dev/setup.sh first.\n");
    exit(1);
}

require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$pattern = $argv[1] ?? getenv('CIPI_DEMO_URL') ?: 'http://127.0.0.1:8787/{profile}';

$servers = [
    ['name' => 'production', 'profile' => 'fra1', 'ip' => '203.0.113.24'],
    ['name' => 'clients', 'profile' => 'nyc1', 'ip' => '198.51.100.42'],
    ['name' => 'staging', 'profile' => 'staging', 'ip' => '203.0.113.77'],
];

CipiServer::query()->delete();

foreach ($servers as $i => $server) {
    // Fixed ids keep demo links (/server/1, ?server=1) stable across re-seeds.
    CipiServer::forceCreate([
        'id' => $i + 1,
        'name' => $server['name'],
        'url' => str_replace('{profile}', $server['profile'], $pattern),
        'ip' => $server['ip'],
        'token' => 'demo|'.hash('sha256', 'cipi-demo-'.$server['profile']),
        'is_active' => true,
    ]);
    echo "  ✓ {$server['name']} → ".str_replace('{profile}', $server['profile'], $pattern)."\n";
}

$user = \App\Models\User::updateOrCreate(
    ['email' => 'admin@cipi.local'],
    ['name' => 'Alice Admin', 'password' => 'admin'],
);
$user->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false, 'two_factor_confirmed_at' => null])->save();

echo "  ✓ admin@cipi.local / admin\n";
