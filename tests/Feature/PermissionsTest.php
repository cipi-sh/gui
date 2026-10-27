<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Livewire\Databases;
use CipiGui\Livewire\ServerManage;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\Fixtures\User;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class PermissionsTest extends TestCase
{
    private CipiServer $prod;

    private CipiServer $staging;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps' => Http::response(['data' => [
                    ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main'],
                    ['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.4', 'engine' => null, 'node' => false, 'aliases' => []],
                ]]),
                $path === '/api/apps/shop' => Http::response(['data' => ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [], 'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main']]),
                $path === '/api/apps/blog' => Http::response(['data' => ['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.4', 'engine' => null, 'node' => false, 'aliases' => []]]),
                $path === '/api/dbs' => Http::response(['data' => [['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '1 MB']]]),
                $path === '/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true]]]]),
                $path === '/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 2]]),
                $path === '/api/services' => Http::response(['data' => [['name' => 'nginx', 'status' => 'running']]]),
                $request->method() !== 'GET' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
                default => Http::response(['data' => []]),
            };
        });

        $this->prod = $this->server();
        $this->staging = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);
        $this->user = $this->member();
        $this->actingAs($this->user);
    }

    #[Test]
    public function an_app_grant_shows_only_that_app_and_its_abilities(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['shop' => ['deploy']]]);

        Livewire::test(Apps::class)->call('loadApps')
            ->assertSee('shop.example.com')->assertDontSee('blog.example.com')
            ->assertDontSee('staging')
            ->assertDontSee('New app')
            ->assertSee('Deploy');

        $detail = Livewire::withQueryParams(['server' => $this->prod->id])->test(AppDetail::class, ['name' => 'shop'])
            ->assertSet('can.deploy', true)->assertSet('can.edit', false)
            ->assertSee('Read-only')
            ->assertDontSee('Environment')->assertDontSee('Logs')->assertDontSee('Commands')
            ->call('setTab', 'deploy')->assertSee('Deploy now')->assertDontSee('Git webhook');

        $detail->call('saveApp')->assertSet('error', 'Your access does not include this action. Ask an admin of the panel.');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

        $detail->call('deploy')->assertSet('showJobOverlay', true);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps/shop/deploy'));
    }

    #[Test]
    public function an_app_the_user_cannot_view_is_forbidden(): void
    {
        Access::replaceGrants($this->user->id, [], [$this->prod->id => ['blog' => ['view']]]);

        $this->get('/apps/shop?server='.$this->prod->id)->assertForbidden();
        $this->get('/server/'.$this->prod->id)->assertForbidden(); // an app grant alone does not open the server page
        $this->get('/server/'.$this->staging->id)->assertForbidden();
        $this->get('/apps/blog?server='.$this->prod->id)->assertOk()->assertDontSee('href="'.route('cipi-gui.server-manage', ['serverId' => $this->prod->id]).'"');
    }

    #[Test]
    public function server_view_is_read_only_and_manage_opens_everything(): void
    {
        Access::replaceGrants($this->user->id, [$this->prod->id => 'view', $this->staging->id => 'manage'], []);

        $page = Livewire::test(ServerManage::class, ['serverId' => $this->prod->id])
            ->assertSee('fra1')->assertDontSee('Restart');
        $page->call('restartService', 'nginx');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/services/'));

        Livewire::test(ServerManage::class, ['serverId' => $this->staging->id])->call('setTab', 'services')->assertSee('Restart');

        Livewire::test(Apps::class)->call('loadApps')
            ->assertSee('shop.example.com')->assertSee('blog.example.com')
            ->assertSee('New app');
        $this->assertSame($this->staging->id, Livewire::test(Apps::class)->call('openCreate')->get('createServerId'), 'apps are created only on managed servers');

        Livewire::test(Databases::class)->call('loadDatabases')
            ->assertSee('read-only')->assertSee('Backup');
        Livewire::test(Databases::class)->call('loadDatabases')->call('backup', 'shop', 'mariadb', $this->prod->id);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'vps.example.com/api/dbs/'));
        Livewire::test(Databases::class)->call('loadDatabases')->call('backup', 'shop', 'mariadb', $this->staging->id)->assertSet('showJobOverlay', true);
    }

    #[Test]
    public function the_header_switcher_only_offers_visible_servers(): void
    {
        Access::replaceGrants($this->user->id, [$this->prod->id => 'view'], []);
        \CipiGui\Support\Panel::set(\CipiGui\Support\Panel::FLEET_VIEW, '0');

        $this->get('/')->assertOk()->assertSee('production')->assertDontSee('staging');
        $this->post('/servers/switch', ['server_id' => $this->staging->id])->assertNotFound();
    }
}
