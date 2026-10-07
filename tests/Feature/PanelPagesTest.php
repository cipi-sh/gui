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
            '*' => Http::response(['data' => []]),
        ]);
        $this->server();
        $this->actingAs($this->admin());

        $this->get('/apps')->assertOk()->assertSee('shop.example.com')->assertSee('Custom PHP');
        $this->get('/databases')->assertOk()->assertSee('MariaDB');
        $this->get('/server')->assertOk()->assertSee('fra1');
        $this->get('/servers')->assertOk()->assertSee('cipi api token create');
        $this->get('/settings')->assertOk()->assertSee('Two-factor authentication');
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
