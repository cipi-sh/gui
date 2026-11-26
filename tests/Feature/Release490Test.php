<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Notifications;
use CipiGui\Livewire\ServerManage;
use CipiGui\Livewire\Servers;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Support\Theme;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.9.0 (cipi/api 1.47, Cipi 5.17.0): the page cache and Octane of an app,
 * Valkey per app, the server's own name, and how notifications are written.
 */
class Release490Test extends TestCase
{
    private CipiServer $server;

    /** The page cache the server holds for shop. */
    private array $cache = ['enabled' => false, 'ttl' => '10m', 'size' => '256m', 'clear_on_deploy' => true, 'exclude' => [], 'files' => 0];

    /** What the server says of shop's Octane. */
    private array $octane = ['app' => 'shop', 'php' => '8.5', 'octane_php' => '8.6.0', 'build' => '1.14.0-php8.6.0-99137391', 'frankenphp' => '1.14.0', 'state' => 'mismatch', 'restart_pending' => false, 'available_php' => ['8.5', '8.6'], 'fix' => ['command' => 'cipi frankenphp php shop 8.5', 'why' => 'a FrankenPHP build with PHP 8.5 is on this server']];

    /** app => own|shared */
    private array $valkey = ['shop' => 'own', 'blog' => 'shared'];

    private array $settings = ['timezone' => null, 'server_timezone' => 'Etc/UTC', 'server_name' => null, 'hostname' => 'vmi12334', 'now' => '2026-11-26 10:00:00 UTC'];

    /** @var (\Closure(Request): mixed)|null answers a write instead of taking it */
    private ?\Closure $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = fn (string $name, array $extra = []) => $extra + ['app' => $name, 'domain' => $name.'.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/'.$name.'.git', 'branch' => 'main'];

        Http::fake(function (Request $request) use ($app) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if ($method !== 'GET' && $this->refuse) {
                return ($this->refuse)($request);
            }

            return match (true) {
                $path === '/api/apps/shop/cache' && $method === 'GET' => Http::response(['data' => $this->cacheView()]),
                $path === '/api/apps/shop/cache' && $method === 'PUT' => $this->putCache($request),
                $path === '/api/apps/shop/cache/exclude' => $this->putExclude($request),
                $path === '/api/apps/shop/cache/clear' => Http::response(['data' => $this->clearCache()]),
                $path === '/api/apps/shop/octane' => Http::response(['data' => $this->octane]),
                $path === '/api/apps/shop/octane/php', $path === '/api/apps/shop/octane/restart', $path === '/api/frankenphp/upgrade',
                $path === '/api/valkey/isolate', $path === '/api/valkey/share' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                $path === '/api/valkey' => Http::response(['data' => $this->valkeyView()]),
                $path === '/api/valkey/flush-shared' => Http::response(['data' => $this->valkeyView() + ['flushed' => true]]),
                $path === '/api/valkey/shop/restart' => Http::response(['data' => $this->valkeyView()]),
                $path === '/api/notifications' => Http::response(['data' => ['channels' => [], 'triggers' => ['enabled' => 0, 'total' => 0], 'types' => [], 'settings' => $this->settings]]),
                $path === '/api/notifications/triggers' => Http::response(['data' => ['triggers' => [], 'enabled' => 0, 'total' => 0]]),
                $path === '/api/notifications/settings' && $method === 'PUT' => $this->putSettings($request),
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'vmi12334', 'cipi' => '5.17.0', 'ip' => '203.0.113.24'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 2]]),
                $path === '/api/services' => Http::response(['data' => [['name' => 'nginx', 'status' => 'running', 'since' => '2026-11-01 08:00:00']]]),
                $path === '/api/apps/shop' => Http::response(['data' => $app('shop', ['octane' => 'frankenphp', 'octane_port' => 8100])]),
                $path === '/api/apps/blog' => Http::response(['data' => $app('blog')]),
                $path === '/api/php' => Http::response(['data' => ['default' => '8.5', 'installable' => ['8.3', '8.4', '8.5', '8.6'], 'versions' => [['version' => '8.5'], ['version' => '8.6']]]]),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /** @return array<string, mixed> */
    private function cacheView(): array
    {
        return ['app' => 'shop', 'supported' => true, 'kind' => 'proxy', 'active' => $this->cache['enabled'], 'ttl_seconds' => 600, 'bytes' => $this->cache['files'] * 40000, 'path' => '/var/cache/nginx/cipi/shop', 'suspended' => false, 'clear_command' => 'sudo /usr/local/bin/cipi-cache-clear shop'] + $this->cache;
    }

    private function putCache(Request $request): mixed
    {
        $data = $request->data();
        $this->cache = ['enabled' => (bool) $data['enabled'], 'files' => 0] + array_intersect_key($data, array_flip(['ttl', 'size', 'clear_on_deploy'])) + $this->cache;

        return Http::response(['data' => $this->cacheView()]);
    }

    private function putExclude(Request $request): mixed
    {
        $this->cache = ['exclude' => array_values($request->data()['exclude'])] + $this->cache;

        return Http::response(['data' => $this->cacheView()]);
    }

    /** @return array<string, mixed> */
    private function clearCache(): array
    {
        $cleared = $this->cache['files'];
        $this->cache['files'] = 0;

        return $this->cacheView() + ['cleared' => $cleared];
    }

    /** @return array<string, mixed> */
    private function valkeyView(): array
    {
        $apps = [];
        foreach ($this->valkey as $name => $uses) {
            $apps[] = ['app' => $name, 'uses' => $uses, 'port' => $uses === 'own' ? 6401 : null, 'instance' => $uses === 'own' ? 'running' : null, 'memory' => $uses === 'own' ? '2.31M' : null, 'keys' => $uses === 'own' ? 412 : null];
        }

        return ['shared_port' => 6379, 'apps' => $apps, 'shared' => array_keys($this->valkey, 'shared', true), 'isolated' => count(array_keys($this->valkey, 'own', true))];
    }

    private function putSettings(Request $request): mixed
    {
        $this->settings = array_intersect_key($request->data(), array_flip(['timezone', 'server_name'])) + $this->settings;

        return Http::response(['data' => $this->settings]);
    }

    private function sent(string $method, string $path): ?Request
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)
            ->map(fn (array $pair) => $pair[0])->last();
    }

    // ── Page cache ───────────────────────────────────────────────────

    #[Test]
    public function the_page_cache_tab_turns_it_on_empties_it_and_turns_it_off(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'cache')
            ->assertSet('pageCacheLoaded', true)
            ->assertSet('cacheTtl', '10m')
            ->assertSeeHtml('data-page-cache')
            ->assertSee('Turn the page cache on')
            // What it is, and what it is not.
            ->assertSee('OPcache is on already')
            ->assertSee('PHP still runs')
            ->assertSee('WordPress, WooCommerce, blogs, catalogues, landing pages')
            // How to empty it from a recipe of one's own.
            ->assertSee('sudo /usr/local/bin/cipi-cache-clear shop')
            ->assertSee('deploy:symlink')
            ->assertSee('save_post');

        $page->set('cacheTtl', '1h')->set('cacheSize', '1g')->set('cacheClearOnDeploy', false)
            ->call('savePageCache')
            ->assertSet('pageCache.enabled', true)
            ->assertSet('pageCacheRefused', null)
            ->assertDispatched('notify')
            ->assertSee('Empty the cache now');
        $this->assertSame(['enabled' => true, 'ttl' => '1h', 'size' => '1g', 'clear_on_deploy' => false], $this->sent('PUT', '/api/apps/shop/cache')->data());

        $this->cache['files'] = 128;
        $page->call('clearPageCache')->assertSet('pageCache.files', 0)->assertDispatched('notify');
        $this->assertNotNull($this->sent('POST', '/api/apps/shop/cache/clear'));

        $page->call('disablePageCache')->assertSet('pageCache.enabled', false);
        $this->assertSame(['enabled' => false], $this->sent('PUT', '/api/apps/shop/cache')->data());
    }

    #[Test]
    public function a_value_the_server_would_refuse_never_leaves_and_a_refusal_is_shown(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'cache');

        $page->set('cacheTtl', 'ten minutes')->call('savePageCache')->assertHasErrors('cacheTtl');
        $page->set('cacheTtl', '10m')->set('cacheSize', '8m')->call('savePageCache')->assertHasErrors('cacheSize');
        $this->assertNull($this->sent('PUT', '/api/apps/shop/cache'));

        $this->refuse = fn () => Http::response(['error' => 'nginx refused the configuration — nothing changed'], 422);
        $page->set('cacheSize', '256m')->call('savePageCache')
            ->assertSet('pageCacheRefused', 'nginx refused the configuration — nothing changed')
            ->assertSeeHtml('data-page-cache-refused')
            ->assertSet('pageCache.enabled', false);
    }

    #[Test]
    public function the_paths_an_app_keeps_out_of_its_cache_are_saved_on_their_own(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'cache')
            ->assertSeeHtml('data-page-cache-exclude')
            ->assertSee('Paths that are never cached')
            ->assertSee('The cache is off: the list is kept for when it is turned on.')
            ->assertSet('cacheExclude', '');

        // One per line, as typed: each gets its /, a path given twice is one.
        $page->set('cacheExclude', "cart*\n/feed\n/cart*")->call('saveCacheExclude')
            ->assertHasNoErrors()
            ->assertSet('cacheExclude', "/cart*\n/feed")
            ->assertSet('pageCache.exclude', ['/cart*', '/feed'])
            ->assertSet('cacheExcludeRefused', null)
            ->assertDispatched('notify');
        $this->assertSame(['exclude' => ['/cart*', '/feed']], $this->sent('PUT', '/api/apps/shop/cache/exclude')->data());
        // The cache itself was not touched.
        $this->assertNull($this->sent('PUT', '/api/apps/shop/cache'));

        // What is not a path never leaves the panel.
        $page->set('cacheExclude', "/ok\n/x;rm")->call('saveCacheExclude')->assertHasErrors('cacheExclude');
        $page->set('cacheExclude', '/*')->call('saveCacheExclude')->assertHasErrors('cacheExclude');
        $this->assertSame(['exclude' => ['/cart*', '/feed']], $this->sent('PUT', '/api/apps/shop/cache/exclude')->data());

        // The server's refusal, in its words; the list on the server stays.
        $this->refuse = fn () => Http::response(['error' => 'nginx refused the configuration — nothing changed'], 422);
        $page->set('cacheExclude', '/account*')->call('saveCacheExclude')
            ->assertSet('cacheExcludeRefused', 'nginx refused the configuration — nothing changed')
            ->assertSeeHtml('data-page-cache-exclude-refused')
            ->assertSet('pageCache.exclude', ['/cart*', '/feed']);

        // An empty form is no path of its own.
        $this->refuse = null;
        $page->set('cacheExclude', " \n")->call('saveCacheExclude')->assertSet('pageCache.exclude', [])->assertSet('cacheExclude', '');
        $this->assertSame(['exclude' => []], $this->sent('PUT', '/api/apps/shop/cache/exclude')->data());
    }

    #[Test]
    public function the_paths_are_read_only_without_edit_on_the_app(): void
    {
        $member = $this->member();
        Access::replaceGrants((int) $member->getKey(), [], [$this->server->id => ['shop' => ['view', 'deploy']]]);
        $this->actingAs($member);
        $this->cache = ['enabled' => true, 'exclude' => ['/cart*', '/feed']] + $this->cache;

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'cache')
            ->assertSeeHtml('data-page-cache-exclude')
            ->assertSee('/cart*')
            ->assertDontSee('Save paths')
            ->set('cacheExclude', '/x')
            ->call('saveCacheExclude');

        $this->assertNull($this->sent('PUT', '/api/apps/shop/cache/exclude'));
    }

    #[Test]
    public function deploy_access_empties_the_cache_but_does_not_turn_it_on(): void
    {
        $member = $this->member();
        Access::replaceGrants((int) $member->getKey(), [], [$this->server->id => ['shop' => ['view', 'deploy']]]);
        $this->actingAs($member);
        $this->cache = ['enabled' => true, 'files' => 12] + $this->cache;

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'cache')
            ->assertSee('Empty the cache now')
            ->assertDontSee('Turn off')
            ->call('disablePageCache')
            ->call('savePageCache')
            ->call('clearPageCache');

        $this->assertNull($this->sent('PUT', '/api/apps/shop/cache'));
        $this->assertNotNull($this->sent('POST', '/api/apps/shop/cache/clear'));
    }

    // ── Octane ───────────────────────────────────────────────────────

    #[Test]
    public function the_octane_tab_shows_two_php_versions_and_puts_them_on_one(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSee('Octane')
            ->call('setTab', 'octane')
            ->assertSet('octaneLoaded', true)
            ->assertSet('octanePhpTarget', '8.5')
            ->assertSeeHtml('data-octane-mismatch')
            ->assertSee('PHP 8.6.0')
            ->assertSee('Two PHP versions in one app')
            ->assertSee('Put everything on PHP 8.5');

        $page->call('switchOctanePhp', '8.6')
            ->assertSet('showJobOverlay', true)
            ->assertSet('jobLabel', 'Octane on PHP 8.6');
        $this->assertSame(['php' => '8.6'], $this->sent('PUT', '/api/apps/shop/octane/php')->data());
    }

    #[Test]
    public function a_php_version_that_is_not_installed_is_not_asked_for(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'octane')
            ->call('switchOctanePhp', '8.4')->assertHasErrors('octanePhpTarget')
            ->call('switchOctanePhp', '8.6 --force')->assertHasErrors('octanePhpTarget');

        $this->assertNull($this->sent('PUT', '/api/apps/shop/octane/php'));
    }

    #[Test]
    public function an_app_on_php_fpm_has_no_octane_tab(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'blog'])
            ->assertSee('Page cache')
            ->assertDontSeeHtml("wire:click=\"setTab('octane')\"")
            ->call('setTab', 'octane')
            ->assertSet('activeTab', 'overview');
    }

    // ── Valkey per app ───────────────────────────────────────────────

    #[Test]
    public function the_services_tab_lists_valkey_per_app_and_isolates_what_is_shared(): void
    {
        $page = Livewire::test(ServerManage::class)->call('init')->call('setTab', 'services')
            ->assertSeeHtml('data-valkey')
            ->assertSee('1 on the shared instance')
            ->assertSee('127.0.0.1:6401')
            ->assertSee('No password travels through the panel');

        $page->call('isolateValkey', 'shop'); // has its own already: nothing to ask
        $this->assertNull($this->sent('POST', '/api/valkey/isolate'));

        $page->call('isolateValkey', 'blog')->assertSet('showJobOverlay', true);
        $this->assertSame(['app' => 'blog'], $this->sent('POST', '/api/valkey/isolate')->data());

        // While an app is on the shared instance it is not emptied.
        $page->call('flushSharedValkey');
        $this->assertNull($this->sent('POST', '/api/valkey/flush-shared'));

        $this->valkey['blog'] = 'own';
        Livewire::test(ServerManage::class)->call('init')->call('setTab', 'services')
            ->assertSee('Every app isolated')
            ->assertSee('Empty the shared instance')
            ->call('flushSharedValkey')
            ->assertSet('valkeyFlushed', true);
        $this->assertNotNull($this->sent('POST', '/api/valkey/flush-shared'));
    }

    // ── The server's own name ────────────────────────────────────────

    #[Test]
    public function the_hostname_is_kept_from_the_status_and_shown_in_connections(): void
    {
        $this->assertNull($this->server->hostname);

        Livewire::test(Servers::class)->call('testConnection', $this->server->id)
            ->assertSee('Hostname')
            ->assertSeeHtml('data-server-hostname')
            ->assertSee('vmi12334')
            ->assertSeeHtml('data-hostname-hint');

        $this->assertSame('vmi12334', $this->server->fresh()->hostname);
        $this->assertSame('vmi12334', $this->server->fresh()->machine_name);
    }

    #[Test]
    public function a_hostname_is_only_what_a_machine_can_be_called(): void
    {
        $this->assertSame('fra1-prod.example.com', CipiServer::cleanHostname(' fra1-prod.example.com '));
        $this->assertNull(CipiServer::cleanHostname('<b>x</b>'));
        $this->assertNull(CipiServer::cleanHostname('a b'));
        $this->assertNull(CipiServer::cleanHostname(''));
        $this->assertNull(CipiServer::cleanHostname(null));

        // The same name twice says nothing.
        $same = $this->server(['name' => 'vmi12334', 'url' => 'https://other.example.com']);
        $same->rememberHostname(['system' => ['hostname' => 'vmi12334']]);
        $this->assertNull($same->fresh()->machine_name);
    }

    // ── Notifications → Written as ───────────────────────────────────

    #[Test]
    public function written_as_takes_the_panel_name_and_a_time_zone(): void
    {
        $page = Livewire::test(Notifications::class, ['serverId' => $this->server->id])->call('init')
            ->assertSeeHtml('data-notification-settings')
            ->assertSee('vmi12334')
            ->assertSeeHtml('data-use-panel-name');

        $page->call('usePanelName')->assertSet('settings.server_name', 'production');
        $this->assertSame(['server_name' => 'production'], $this->sent('PUT', '/api/notifications/settings')->data());

        $page->set('settingsTimezone', 'Europe/Rome')->call('saveSettings')->assertSet('settings.timezone', 'Europe/Rome');
        $this->assertSame(['timezone' => 'Europe/Rome'], $this->sent('PUT', '/api/notifications/settings')->data());

        // Back to the server's own, and a name that is not one.
        $page->set('settingsTimezone', '')->set('settingsServerName', '')->call('saveSettings');
        $this->assertSame(['timezone' => null, 'server_name' => null], $this->sent('PUT', '/api/notifications/settings')->data());
        $page->set('settingsServerName', 'prod; rm -rf /')->call('saveSettings')->assertHasErrors('settingsServerName');
        $page->set('settingsServerName', '')->set('settingsTimezone', 'Mars/Olympus')->call('saveSettings')->assertHasErrors('settingsTimezone');
    }

    #[Test]
    public function the_release_is_in_the_changelog(): void
    {
        $this->assertTrue(version_compare(Theme::VERSION, '4.9.0', '>='));
        $this->assertTrue(version_compare(Theme::API, '1.47', '>='));
        $this->assertStringContainsString('## [4.9.0]', (string) file_get_contents(__DIR__.'/../../CHANGELOG.md'));
    }
}
