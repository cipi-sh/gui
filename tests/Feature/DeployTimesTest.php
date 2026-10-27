<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\AppDetail;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

class DeployTimesTest extends TestCase
{
    /** @var list<string> */
    private array $deployLog = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === '/api/apps/shop' => Http::response(['data' => [
                    'app' => 'shop', 'domain' => 'shop.example.com', 'php' => '8.5', 'engine' => 'mariadb', 'node' => false, 'aliases' => [],
                    'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main',
                ]]),
                $path === '/api/apps/shop/deploy/audit' => Http::response(['data' => ['app' => 'shop', 'records' => [
                    ['seq' => 1, 'ts' => '2026-10-20T08:00:00Z', 'event' => 'published', 'release' => '28', 'origin' => 'webhook'],
                    ['seq' => 2, 'ts' => '2026-10-24T08:55:55Z', 'event' => 'published', 'release' => '29', 'origin' => 'panel'],
                    ['seq' => 3, 'ts' => '2026-10-25T10:00:40Z', 'event' => 'failed', 'release' => '30', 'origin' => 'panel'],
                    ['seq' => 4, 'ts' => '2026-10-26T09:00:00Z', 'event' => 'published', 'release' => '31', 'origin' => 'webhook'],
                ]]]),
                $path === '/api/apps/shop/logs' && ($query['type'] ?? '') === 'deploy' => Http::response(['data' => [
                    'available_types' => ['deploy'], 'files' => [['path' => '/home/shop/logs/deploy.log', 'lines' => $this->deployLog]], 'warnings' => [],
                ]]),
                default => Http::response(['data' => []]),
            };
        });

        $server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $server->id]);
    }

    #[Test]
    public function the_deploy_tab_shows_how_long_each_deploy_took(): void
    {
        $this->deployLog = [
            '[2026-10-24 08:54:00] ===== deploy start  app=shop trigger=panel branch=main from-release=28 =====',
            '[2026-10-24 08:55:55] ===== deploy OK  app=shop release=29 duration=54s exit=0 =====',
            '[2026-10-25 10:00:40] ===== deploy FAILED  app=shop release=29 duration=40s exit=1 =====',
            '[2026-10-26 09:01:16] ===== deploy OK  app=shop release=31 duration=76s exit=0 =====',
        ];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('auditTook', [0 => 76, 1 => 40, 2 => 54])
            ->assertSee('Took')
            ->assertSeeInOrder(['31', '1m16s', '30', '40s', '29', '54s', '28'])
            ->assertSeeHtml('tabular-nums text-xs text-subtle">') // release 28: no banner left in the log
            ->assertSee('Last deploy took')->assertSee('1m16s')
            ->assertSee('1m05s')->assertSee('on average over 2 deploys');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/apps/shop/logs?') && str_contains($r->url(), 'per_page=1000'));
    }

    #[Test]
    public function a_log_without_banners_says_timing_needs_a_newer_cipi(): void
    {
        $this->deployLog = ['[2026-10-24 08:54:01] task deploy:info'];

        Livewire::test(AppDetail::class, ['name' => 'shop'])->call('setTab', 'deploy')
            ->assertSet('auditTook', [])
            ->assertSee('No timing yet')->assertSee('Cipi 5.6+');
    }
}
