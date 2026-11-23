<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Livewire\Metrics;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Metrics tab (4.3.0, API 1.41 / Cipi 5.11.0): the server's and an app's,
 * what is read, what polling does, the settings a manager may change and an
 * API that does not have the routes.
 */
class MetricsTest extends TestCase
{
    private CipiServer $server;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> path => [body, status] */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();
        $points = fn (float $base) => array_map(fn ($i) => [$now - (60 - $i) * 600, $base + $i % 5, $base + 10], range(1, 60));
        $this->answers = [
            '/api/metrics' => [['data' => [
                'collector' => ['enabled' => true, 'sqlite' => true, 'last_run' => $now - 30],
                'host' => ['values' => ['cpu' => 31.4, 'mem' => 62.3, 'mem_used_mb' => 2489, 'load1' => 0.86, 'disk' => 47, 'net_rx' => 412000, 'net_tx' => 1340000]],
                'apps' => [
                    ['app' => 'shop', 'health' => ['url' => 'https://shop.example.com/up', 'expect' => 200], 'values' => ['cpu' => 22.5, 'mem_mb' => 497, 'req' => 182, 'http_5xx' => 2, 'http_ms' => 91, 'http_ok' => 1, 'http_code' => 200, 'fpm_procs' => 5, 'fpm_max' => 12], 'spark' => ['req' => [100, 120, 140]], 'uptime' => ['24h' => 100, '7d' => 99.86, '30d' => 99.91]],
                    ['app' => 'docs', 'health' => null, 'values' => ['cpu' => 0.1, 'mem_mb' => 41], 'spark' => [], 'uptime' => null],
                ],
                'events' => [],
            ]], 200],
            '/api/metrics/series' => [['data' => ['scope' => 'host', 'from' => $now - 86400, 'to' => $now, 'step' => 600, 'source' => 'raw', 'series' => ['cpu' => $points(20), 'mem' => $points(60)]]], 200],
            '/api/apps/shop/metrics' => [['data' => ['scope' => 'app:shop', 'from' => $now - 86400, 'to' => $now, 'step' => 600, 'source' => 'raw', 'series' => ['req' => $points(150), 'http_ms' => $points(90), 'fpm_procs' => $points(4)]]], 200],
            '/api/metrics/events' => [['data' => ['from' => $now - 86400, 'to' => $now, 'events' => [
                ['ts' => $now - 3600, 'source' => 'deploy', 'subject' => 'shop', 'status' => 'ok', 'summary' => 'deploy — release 42 — healthcheck passed — HTTP 200'],
            ]]], 200],
            '/api/metrics/uptime' => [['data' => ['range' => 2592000, 'apps' => [['app' => 'shop', 'uptime' => 99.91, 'days' => [[intdiv($now, 86400) * 86400, 99.5]]]]]], 200],
            '/api/metrics/settings' => [['data' => [
                'enabled' => true, 'probe' => true, 'database' => ['bytes' => 23500000, 'samples' => 60480, 'rollups' => 14400, 'events' => 37],
                'retention' => ['raw_days' => 7, 'rollup_days' => 30, 'resolution_minutes' => 15, 'events_days' => 90],
                'heartbeat' => ['enabled' => false, 'url_set' => false, 'url_host' => null, 'every' => 1], 'openmetrics' => ['enabled' => false],
            ]], 200],
            '/api/metrics/heartbeat' => [['data' => ['enabled' => true, 'url_set' => true, 'url_host' => 'hc-ping.com', 'every' => 5]], 200],
        ];

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'PUT' && $path === '/api/metrics/settings') {
                $data = $this->answers['/api/metrics/settings'][0]['data'];
                $data['retention'] = array_merge($data['retention'], array_intersect_key($request->data(), $data['retention']));

                return Http::response(['data' => $data]);
            }
            [$body, $status] = $this->answers[$path] ?? [['data' => []], 200];

            return Http::response($body, $status);
        });

        $this->server = $this->server();
        $this->actingAs($this->admin());
        session(['cipi_gui_server_id' => $this->server->id]);
    }

    private function sent(string $method, string $path): ?Request
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path);

        return $sent->isEmpty() ? null : $sent->last()[0];
    }

    #[Test]
    public function the_server_tab_reads_the_overview_the_series_and_the_settings(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('loaded', true)
            ->assertSet('unsupported', false)
            ->assertSet('rawDays', 7)
            ->assertSee('31.4%')
            ->assertSee('shop')
            ->assertSee('99.91%')
            ->assertSeeHtml('Collection &amp; retention')
            ->assertSeeHtml('data-cipi-chart=')
            ->assertSeeHtml('wire:poll.30s.visible="poll"');

        $series = $this->sent('GET', '/api/metrics/series');
        $this->assertNotNull($series);
        parse_str((string) parse_url($series->url(), PHP_URL_QUERY), $q);
        $this->assertSame('24h', $q['range']);
        $this->assertStringContainsString('cpu', $q['metrics']);
    }

    #[Test]
    public function the_app_tab_reads_the_app_series_and_has_no_settings(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id, 'app' => 'shop'])
            ->call('init')
            ->assertSet('loaded', true)
            ->assertSee('Metrics — shop')
            ->assertSee('182')
            ->assertSee('Requests')
            ->assertSee('PHP-FPM')
            ->assertDontSeeHtml('Collection &amp; retention');

        $this->assertNotNull($this->sent('GET', '/api/apps/shop/metrics'));
        $this->assertNull($this->sent('GET', '/api/metrics/settings'));
    }

    #[Test]
    public function a_longer_range_polls_less_often_and_paused_does_not_poll(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->call('setRange', '30d')
            ->assertSet('range', '30d')
            ->assertSeeHtml('wire:poll.120s.visible="poll"')
            ->call('setRange', 'nonsense')
            ->assertSet('range', '24h')
            ->call('toggleLive')
            ->assertSet('live', false)
            ->assertDontSeeHtml('wire:poll');
    }

    #[Test]
    public function a_manager_changes_the_retention(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->set('rawDays', 3)
            ->set('rollupDays', 60)
            ->set('resolutionMinutes', 30)
            ->set('eventsDays', 120)
            ->call('saveRetention')
            ->assertHasNoErrors()
            ->assertSet('rollupDays', 60);

        $this->assertSame(['raw_days' => 3, 'rollup_days' => 60, 'resolution_minutes' => 30, 'events_days' => 120], $this->sent('PUT', '/api/metrics/settings')?->data());
    }

    #[Test]
    public function rollups_cannot_be_kept_less_than_raw_minutes(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->set('rawDays', 10)
            ->set('rollupDays', 5)
            ->call('saveRetention')
            ->assertHasErrors(['rollupDays']);

        $this->assertNull($this->sent('PUT', '/api/metrics/settings'));
    }

    #[Test]
    public function a_manager_sets_the_heartbeat(): void
    {
        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->set('heartbeatUrl', 'https://hc-ping.com/abc-123')
            ->set('heartbeatEvery', 5)
            ->call('saveHeartbeat')
            ->assertHasNoErrors()
            ->assertSet('heartbeatUrl', '')
            ->assertSee('hc-ping.com');

        $this->assertSame(['url' => 'https://hc-ping.com/abc-123', 'every' => 5], $this->sent('PUT', '/api/metrics/heartbeat')?->data());
    }

    #[Test]
    public function a_viewer_reads_but_does_not_change_anything(): void
    {
        $viewer = $this->member();
        Access::replaceGrants($viewer->id, [$this->server->id => 'view'], []);
        $this->actingAs($viewer);

        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('loaded', true)
            ->assertSee('31.4%')
            ->set('rawDays', 3)
            ->call('saveRetention')
            ->call('saveHeartbeat');

        $this->assertNull($this->sent('PUT', '/api/metrics/settings'));
        $this->assertNull($this->sent('PUT', '/api/metrics/heartbeat'));
    }

    #[Test]
    public function an_api_without_the_routes_says_what_to_update(): void
    {
        $this->answers['/api/metrics'] = [['message' => 'Not Found'], 404];

        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('unsupported', true)
            ->assertSee('Cipi 5.11.0')
            ->assertSee('cipi/api 1.41.0')
            ->assertDontSeeHtml('wire:poll');
    }

    #[Test]
    public function an_older_core_is_reported_as_unsupported_with_its_reason(): void
    {
        $this->answers['/api/metrics'] = [['error' => 'Metrics needs Cipi 5.11.0+ — this server runs Cipi 5.10.0. Run: cipi self-update'], 503];

        Livewire::test(Metrics::class, ['serverId' => $this->server->id])
            ->call('init')
            ->assertSet('unsupported', true)
            ->assertSee('this server runs Cipi 5.10.0');
    }

    #[Test]
    public function the_version_is_4_3_0(): void
    {
        $this->assertTrue(version_compare(\CipiGui\Support\Theme::VERSION, '4.3.0', '>='));
        $this->assertStringContainsString('## [4.3.0]', (string) file_get_contents(\CipiGui\Support\Theme::packageRoot().'/CHANGELOG.md'));
    }
}
