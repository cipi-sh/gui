<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Support\Theme;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * 4.5.0 — what Cipi 5.13.0 and cipi/api 1.43 added: the .env reordered by
 * dragging its rows, the commit whose automatic deploy failed, basic auth read
 * right (and the raw terminal output of an older API no longer shown), MySQL on
 * port 3306, paths left out of the backups.
 */
class Release450Test extends TestCase
{
    private CipiServer $server;

    /** @var array<string, string> the .env as the server has it, in file order */
    private array $env = ['APP_NAME' => 'Shop', 'APP_ENV' => 'production', 'DB_HOST' => '127.0.0.1'];

    /** The API keeps the order it is sent (1.43); false plays an older one that drops it. */
    private bool $keepsOrder = true;

    /** @var array<string, mixed> body of GET …/deploy/status */
    private array $status = ['app' => 'shop', 'state' => 'idle', 'running' => false, 'queued' => false, 'locked' => false, 'paused_commit' => null, 'current' => null, 'last' => null, 'tail' => []];

    /** @var array{0: array<string, mixed>, 1: int} body and status of GET …/basicauth */
    private array $basicAuth = [['data' => ['app' => 'shop', 'enabled' => false, 'users' => []]], 200];

    /** what GET /dbs/engines says runs on 3306 */
    private string $mysqlServer = 'mariadb';

    /** @var list<array<string, mixed>> */
    private array $profiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            return match (true) {
                $path === '/api/apps/shop' && $method === 'GET' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/env' && $method === 'GET' => Http::response(['data' => ['app' => 'shop', 'vars' => $this->env]]),
                $path === '/api/apps/shop/env' && $method === 'PUT' => Http::response(['data' => ['app' => 'shop', 'vars' => $this->applyEnv($request->data())]]),
                $path === '/api/apps/shop/deploy/status' => Http::response(['data' => $this->status]),
                $path === '/api/apps/shop/deploy/unlock' => Http::response(['job_id' => 'job-unlock', 'status' => 'pending'], 202),
                $path === '/api/apps/shop/basicauth' => Http::response($this->basicAuth[0], $this->basicAuth[1]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [
                    ['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true, 'server' => $this->mysqlServer, 'label' => $this->mysqlServer === 'mysql' ? 'MySQL' : 'MariaDB'],
                ]]]),
                $path === '/api/backups' => Http::response(['data' => $this->backups()]),
                str_starts_with($path, '/api/backups/profiles') && in_array($method, ['POST', 'PUT'], true) => Http::response(['data' => ['saved' => true]], $method === 'POST' ? 201 : 200),
                default => Http::response(['data' => []]),
            };
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    /**
     * The .env after a PUT: set, unset, then the order — or, for an API before
     * 1.43, set and unset only.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, string>
     */
    private function applyEnv(array $in): array
    {
        foreach ((array) ($in['set'] ?? []) as $k => $v) {
            $this->env[$k] = (string) $v;
        }
        foreach ((array) ($in['unset'] ?? []) as $k) {
            unset($this->env[$k]);
        }
        if ($this->keepsOrder && is_array($in['order'] ?? null)) {
            $ordered = [];
            foreach ($in['order'] as $k) {
                $ordered[$k] = $this->env[$k];
            }
            $this->env = $ordered;
        }

        return $this->env;
    }

    /** @return array<string, mixed> */
    private function backups(): array
    {
        return [
            'configured' => true, 'local_dir' => '/var/backups/cipi', 'encryption_key' => false,
            'destinations' => [['name' => 'local', 'type' => 'local', 'path' => '/var/backups/cipi', 'default' => false, 'profiles' => array_column($this->profiles, 'name')]],
            'profiles' => $this->profiles,
            'apps' => ['shop'], 'databases' => [['engine' => 'mariadb', 'name' => 'shop']],
            'uncovered' => ['apps' => [], 'databases' => []],
        ];
    }

    /** @param  list<string>  $paths */
    private function profile(string $name, array $paths = []): array
    {
        return [
            'name' => $name, 'scope' => 'all', 'enabled' => true, 'cron' => '0 2 * * *', 'interval_seconds' => 86400,
            'apps' => ['*'], 'databases' => ['*'], 'exclude_databases' => [], 'exclude_tables' => [], 'exclude_paths' => $paths,
            'destinations' => ['local'], 'retention' => ['keep' => 0, 'days' => 28, 'weeks' => 0], 'encrypt' => false, 'created_at' => time() - 900000,
            'covers' => ['apps' => ['shop'], 'databases' => [['engine' => 'mariadb', 'name' => 'shop']]],
            'last' => null, 'overdue' => false, 'running' => false,
        ];
    }

    /** @return array<string, mixed>|null the body of the last PUT of the .env */
    private function envPut(): ?array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && parse_url($r->url(), PHP_URL_PATH) === '/api/apps/shop/env');

        return $sent->isEmpty() ? null : $sent->last()[0]->data();
    }

    // ── .env: drag and drop ──────────────────────────────────────────

    #[Test]
    public function a_row_moved_is_one_change_and_the_order_is_saved_with_it(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'env')
            ->assertSeeHtml('data-env-grip="APP_NAME"')
            ->assertSee('drag ⠿ to reorder');

        $page->call('moveEnvRow', 2, 0);
        $this->assertSame(['DB_HOST', 'APP_NAME', 'APP_ENV'], array_column($page->get('envRows'), 'key'));
        $this->assertSame(1, $page->instance()->envChangeCount());
        $page->assertSee('1 unsaved change · new order');

        $page->call('saveEnv')->assertDispatched('notify', type: 'success');
        $this->assertSame(['order' => ['DB_HOST', 'APP_NAME', 'APP_ENV']], $this->envPut());
        $this->assertSame(['DB_HOST', 'APP_NAME', 'APP_ENV'], array_keys($this->env));
        $this->assertSame(0, $page->instance()->envChangeCount());
    }

    #[Test]
    public function a_new_key_at_the_end_is_no_reorder_but_one_moved_up_is(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'env')
            ->set('envNewKey', 'MAIL_HOST')->set('envNewValue', 'smtp.example.com')->call('addEnvRow');
        $this->assertFalse($page->instance()->envOrderChanged());

        $page->call('moveEnvRow', 3, 1)->call('saveEnv');
        $this->assertSame(['set' => ['MAIL_HOST' => 'smtp.example.com'], 'order' => ['APP_NAME', 'MAIL_HOST', 'APP_ENV', 'DB_HOST']], $this->envPut());
    }

    #[Test]
    public function an_api_that_drops_the_order_is_told_apart(): void
    {
        $this->keepsOrder = false;

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'env')
            ->call('moveEnvRow', 0, 2)->set('envRows.0.value', 'production-eu')
            ->call('saveEnv')
            ->assertDispatched('notify', type: 'warning');
    }

    #[Test]
    public function rows_are_moved_and_removed_by_key_and_keep_their_values(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'env')
            // Each row is keyed by its place too, so one that moves is drawn again (4.5.1).
            ->assertSeeHtml('wire:key="env-2-DB_HOST"')
            ->call('moveEnvKey', 'DB_HOST', 'APP_NAME')
            ->assertSeeHtml('wire:key="env-0-DB_HOST"')
            ->assertSeeHtml('wire:model.live.debounce.400ms="envRows.0.value"');
        $this->assertSame(['DB_HOST' => '127.0.0.1', 'APP_NAME' => 'Shop', 'APP_ENV' => 'production'], array_column($page->get('envRows'), 'value', 'key'));

        $page->call('moveEnvKey', 'NOPE', 'APP_NAME')->call('moveEnvKey', 'APP_NAME', 'NOPE')->call('removeEnvKey', 'NOPE');
        $this->assertSame(['DB_HOST', 'APP_NAME', 'APP_ENV'], array_column($page->get('envRows'), 'key'));

        $page->call('removeEnvKey', 'APP_NAME')->call('saveEnv');
        $this->assertSame(['unset' => ['APP_NAME'], 'order' => ['DB_HOST', 'APP_ENV']], $this->envPut());
        $this->assertSame(['DB_HOST' => '127.0.0.1', 'APP_ENV' => 'production'], $this->env);
    }

    #[Test]
    public function a_move_out_of_range_changes_nothing(): void
    {
        $page = Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'env')
            ->call('moveEnvRow', 9, 0)->call('moveEnvRow', -1, 1)->call('moveEnvRow', 1, 1);
        $this->assertSame(['APP_NAME', 'APP_ENV', 'DB_HOST'], array_column($page->get('envRows'), 'key'));
        $page->call('saveEnv')->assertDispatched('notify', type: 'info');
        $this->assertNull($this->envPut());
    }

    // ── Deploys: the paused commit ───────────────────────────────────

    #[Test]
    public function a_commit_whose_automatic_deploy_failed_is_shown_with_a_way_to_resume(): void
    {
        $this->status['paused_commit'] = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertSeeHtml('data-deploy-paused')
            ->assertSee('Automatic deploys paused on commit')
            ->assertSee('a1b2c3d4e5f6')
            ->assertSee('Resume automatic deploys')
            ->call('setTab', 'deploy')
            ->assertSee('Resume automatic deploys')
            ->assertDontSee('Unlock a stuck deploy')
            ->call('unlockDeploy')
            ->assertSet('jobLabel', 'Resume automatic deploys');
    }

    #[Test]
    public function without_a_paused_commit_nothing_changes(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])
            ->assertDontSeeHtml('data-deploy-paused')
            ->call('setTab', 'deploy')
            ->assertSee('Unlock a stuck deploy');
    }

    // ── Basic auth ───────────────────────────────────────────────────

    #[Test]
    public function the_status_an_older_api_answered_as_an_error_is_read_not_shown_raw(): void
    {
        // cipi/api 1.42 on Cipi 5.12: the command printed the state, then exited 1.
        $this->basicAuth = [['error' => "\e[1mBasic auth — shop\e[0m\n━━━━━━━━━━━━━━━━\n  Status     \e[2mdisabled\e[0m\n"], 500];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'access')
            ->assertSet('basicAuth', ['enabled' => false, 'users' => []])
            ->assertNotDispatched('notify', type: 'error')
            ->assertDontSee('[1m')
            ->assertDontSee('━━');
    }

    #[Test]
    public function any_other_error_is_shown_without_terminal_colours(): void
    {
        $this->basicAuth = [['error' => "\e[0;31mApp 'shop' not found\e[0m"], 422];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'access')
            ->assertDispatched('notify', type: 'error', message: "App 'shop' not found");
    }

    // ── MySQL on port 3306 ───────────────────────────────────────────

    #[Test]
    public function the_app_page_says_mysql_where_the_server_runs_it(): void
    {
        $this->mysqlServer = 'mysql';
        Livewire::test(AppDetail::class, ['name' => 'shop'])->assertSee('MySQL')->assertDontSee('MariaDB');

        // Remembered: the next page does not ask again.
        $asked = Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs/engines'))->count();
        Livewire::test(AppDetail::class, ['name' => 'shop'])->assertSee('MySQL');
        $this->assertSame($asked, Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/api/dbs/engines'))->count());
    }

    #[Test]
    public function mariadb_stays_mariadb(): void
    {
        Livewire::test(AppDetail::class, ['name' => 'shop'])->assertSee('MariaDB')->assertDontSee('MySQL');
    }

    // ── Backups: paths left out ──────────────────────────────────────

    #[Test]
    public function a_profile_can_leave_paths_out(): void
    {
        $page = Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->call('openBackupForm', '')
            ->set('bpName', 'nightly')->set('bpExcludePaths', '/var/www/shop')
            ->call('saveBackupProfile')->assertHasErrors('bpExcludePaths')
            ->set('bpExcludePaths', '*')
            ->call('saveBackupProfile')->assertHasErrors('bpExcludePaths')
            ->set('bpExcludePaths', "storage/app/uploads, *.mp4\nshop:public/media files")
            ->call('saveBackupProfile')->assertHasNoErrors()->assertSet('showBackupForm', false);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/api/backups/profiles'
            && $r['name'] === 'nightly' && $r['exclude_paths'] === ['storage/app/uploads', '*.mp4', 'shop:public/media files']);
    }

    #[Test]
    public function exclude_paths_are_sent_only_to_set_or_to_clear_them(): void
    {
        $this->profiles = [$this->profile('plain'), $this->profile('big', ['storage/app/uploads'])];
        $page = Livewire::withQueryParams(['tab' => 'backups'])->test(ServerManage::class, ['serverId' => $this->server->id])->call('init')
            ->assertSee('1 path left out');

        // A profile without them: an older server is never sent the field.
        $page->call('openBackupForm', 'plain')->assertSet('bpExcludePaths', '')->call('saveBackupProfile')->assertHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/profiles/plain') && ! isset($r['exclude_paths']));

        // A profile with them, emptied: an empty list clears them.
        $page->call('openBackupForm', 'big')->assertSet('bpExcludePaths', 'storage/app/uploads')->set('bpExcludePaths', '')->call('saveBackupProfile')->assertHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/profiles/big') && $r['exclude_paths'] === []);
    }

    #[Test]
    public function the_version_is_4_5_0(): void
    {
        $this->assertTrue(version_compare(Theme::VERSION, '4.5.0', '>='));
        $this->assertTrue(version_compare(Theme::API, '1.43', '>='));
        $this->assertStringContainsString('## [4.5.0]', (string) file_get_contents(Theme::packageRoot().'/CHANGELOG.md'));
    }
}
