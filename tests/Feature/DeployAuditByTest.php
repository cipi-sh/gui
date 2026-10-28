<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class DeployAuditByTest extends TestCase
{
    #[Test]
    public function the_by_column_shows_the_operator_or_the_actor_the_app_reported(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => [
                    // an SSH session of root: the login user root established, nothing claimed
                    ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'event' => 'published', 'release' => '28', 'origin' => 'cipi-cli', 'trigger' => 'cli', 'operator' => 'root', 'ip' => '203.0.113.9', 'claimed' => []],
                    // a Git push: nothing root could establish, the pusher reported by cipi/agent
                    ['seq' => 2, 'ts' => '2026-10-24T08:55:55Z', 'event' => 'published', 'release' => '29', 'origin' => 'webhook', 'trigger' => 'webhook', 'operator' => '', 'ip' => '', 'claimed' => ['source' => 'webhook', 'actor' => 'jane-doe', 'ip' => '140.82.115.1', 'ref' => 'main']],
                    // the panel: nothing known on either side
                    ['seq' => 3, 'ts' => '2026-10-25T10:00:40Z', 'event' => 'published', 'release' => '30', 'origin' => 'panel', 'trigger' => 'cli', 'operator' => '', 'ip' => '', 'claimed' => []],
                ]]]),
                default => Http::response(['data' => []]),
            };
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSee('root')
            ->assertSee('203.0.113.9')
            ->assertSee('jane-doe')
            ->assertSee('via webhook')
            ->assertSee('140.82.115.1')
            ->assertDontSee('claimed by')
            ->assertSeeHtml('not checked by the server">jane-doe');
    }
}
