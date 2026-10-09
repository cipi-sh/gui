<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class PanelPagesTest extends TestCase
{
    #[Test]
    public function guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in to Cipi')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function the_dashboard_renders_and_defers_server_calls(): void
    {
        Http::fake();
        $this->server();

        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee('Dashboard')->assertSee('production');

        Http::assertNothingSent();
    }

    #[Test]
    public function pages_render_against_the_api(): void
    {
        Http::fake([
            '*/api/apps' => Http::response(['data' => [
                ['app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => []],
                ['app' => 'blog', 'domain' => 'blog.example.com', 'php' => '8.4', 'engine' => null, 'node' => false, 'aliases' => []],
            ]]),
            '*/api/dbs/engines' => Http::response(['data' => ['default' => 'mariadb', 'engines' => [['engine' => 'mariadb', 'status' => 'running', 'port' => 3306, 'default' => true]]]]),
            '*/api/dbs' => Http::response(['data' => [['engine' => 'mariadb', 'name' => 'shop', 'user' => 'shop', 'size' => '1 MB']]]),
            '*/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1'], 'resources' => [], 'services' => ['nginx' => 'running'], 'apps' => 2]]),
            '*/api/disk/dbs' => Http::response(['data' => [
                ['engine' => 'mariadb', 'databases' => [['name' => 'shop', 'size_mb' => 842.2]], 'on_disk_mb' => 1200.5, 'memory_mb' => null, 'note' => null],
                ['engine' => 'valkey', 'databases' => [['name' => 'db0', 'size_mb' => null, 'keys' => 1204]], 'on_disk_mb' => 3.0, 'memory_mb' => 12.3, 'note' => null],
            ]]),
            '*/api/disk' => Http::response(['data' => [
                'disk' => ['mount' => '/', 'size_gb' => 154.0, 'used_gb' => 61.2, 'free_gb' => 92.8, 'used_percent' => 40],
                'apps' => [
                    ['app' => 'shop', 'files_gb' => 12.4, 'database_gb' => 0.82, 'total_gb' => 13.22, 'percent' => 8.6, 'files_kb' => 13002342, 'database_kb' => 859832, 'total_kb' => 13862174, 'limit_gb' => 20, 'limit_percent' => 66, 'over_limit' => false],
                    ['app' => 'blog', 'files_gb' => 2.25, 'database_gb' => 0.09, 'total_gb' => 2.34, 'percent' => 1.5, 'files_kb' => 2359296, 'database_kb' => 94372, 'total_kb' => 2453668, 'limit_gb' => 2, 'limit_percent' => 117, 'over_limit' => true],
                ],
                'apps_total_gb' => 15.56, 'apps_percent' => 10.1, 'other_gb' => 45.64, 'other_percent' => 29.6,
            ]]),
            '*' => Http::response(['data' => []]),
        ]);
        $this->server();
        $this->actingAs($this->admin());

        $this->get('/apps')->assertOk()->assertSee('shop.example.com')->assertSee('Custom PHP');
        $this->get('/databases')->assertOk()->assertSee('MariaDB');
        $this->get('/server')->assertOk()->assertSee('fra1');
        $this->get('/server?tab=disk')->assertOk()
            ->assertSee('Everything else')->assertSee('13.22 GB')->assertSee('20 GB · 66%')->assertSee('2 GB · 117% · over')
            ->assertSee('1 over the limit')->assertSee('Valkey')->assertSee('1,204')->assertSee('12.3 MB');
        $this->get('/servers')->assertOk()->assertSee('cipi api token create');
        $this->get('/settings')->assertOk()->assertSee('Two-factor authentication');
    }

    #[Test]
    public function the_disk_tab_degrades_when_the_server_cannot_measure(): void
    {
        Http::fake([
            '*/api/status' => Http::response(['data' => ['system' => ['hostname' => 'fra1'], 'resources' => [], 'services' => [], 'apps' => 0]]),
            '*/api/disk' => Http::response(['error' => 'sudo: a terminal is required to read the password'], 503),
            '*' => Http::response(['data' => []]),
        ]);
        $this->server();

        $this->actingAs($this->admin())->get('/server?tab=disk')->assertOk()->assertSee('Not available on this server')->assertSee('Cipi 5.5.2+')->assertDontSee('Everything else');
    }

    #[Test]
    public function the_header_switcher_changes_the_current_server(): void
    {
        $first = $this->server();
        $second = $this->server(['name' => 'staging', 'url' => 'https://staging.example.com']);

        $this->actingAs($this->admin())
            ->from('/apps/shop?server='.$first->id)
            ->post('/servers/switch', ['server_id' => $second->id])
            ->assertRedirect('/apps')
            ->assertSessionHas('cipi_gui_server_id', $second->id);
    }
}
