<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Metrics as M;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Metrics & health history (API 1.41+ / Cipi ≥ 5.11.0): the server's Metrics
 * tab, and with $app set the app's. The core collects every minute; this
 * reads what it kept — about 240 points whatever the range — and, while the
 * tab is visible and "Live" is on, asks again every 30 seconds (every two
 * minutes for 7 and 30 days, where a new minute changes nothing visible).
 *
 * Reading needs a level on the server (server tab) or the `view` ability on
 * the app (app tab). Retention, the probe, the collector and the heartbeat
 * need `manage` on the server; the API token needs `metrics-manage`.
 */
class Metrics extends Component
{
    use InteractsWithCipiServer;

    public const HOST_SERIES = ['cpu', 'mem', 'mem_used_mb', 'load1', 'disk', 'net_rx', 'net_tx'];

    public const APP_SERIES = ['cpu', 'mem_mb', 'req', 'http_5xx', 'http_ms', 'http_ok', 'fpm_procs', 'fpm_max', 'fpm_saturated', 'workers_up', 'workers_total'];

    /** The app of an app's tab; null on the server's. Locked: set by the page, never by the browser. */
    #[Locked]
    public ?string $app = null;

    public string $range = M::DEFAULT_RANGE;

    /** Poll while the tab is visible. */
    public bool $live = true;

    public bool $loaded = false;

    public bool $unsupported = false;

    public string $unsupportedReason = '';

    /** Bumped at every read: the charts are keyed on it, so new data draws new charts. */
    public int $version = 0;

    public int $fetchedAt = 0;

    public array $overview = [];

    public array $series = [];

    public array $events = [];

    /** One app's uptime, or {range, apps: [...]} on the server tab. */
    public array $uptime = [];

    public int $uptimeAt = 0;

    public array $settings = [];

    // ── settings form (server tab) ──
    public int $rawDays = 7;

    public int $rollupDays = 30;

    public int $resolutionMinutes = 15;

    public int $eventsDays = 90;

    public string $heartbeatUrl = '';

    public int $heartbeatEvery = 1;

    public ?array $heartbeatTest = null;

    public function mount(?int $serverId = null, ?string $app = null): void
    {
        $this->serverId = $serverId ?? (int) session('cipi_gui_server_id');
        $this->app = $app;
        // Nothing is read here: wire:init in the view calls init(), so the page
        // around the tab is sent at once.
    }

    public function init(): void
    {
        if (! $this->mayRead()) {
            $this->loaded = true;

            return;
        }
        $this->load(true);
    }

    public function setRange(string $range): void
    {
        $this->range = M::validRange($range);
        $this->load(false);
    }

    public function toggleLive(): void
    {
        $this->live = ! $this->live;
        if ($this->live) {
            $this->load(false);
        }
    }

    /** wire:poll — only while visible and live (the view drops the poll otherwise). */
    public function poll(): void
    {
        if ($this->live && $this->loaded && ! $this->unsupported) {
            $this->load(false);
        }
    }

    public function refresh(): void
    {
        $this->uptimeAt = 0;
        $this->load(true);
    }

    protected function mayRead(): bool
    {
        $id = (int) ($this->serverId ?? 0);
        if ($id === 0) {
            return false;
        }
        $user = $this->panelUser();

        return $this->app !== null
            ? Access::can($user, 'view', $id, $this->app)
            : Access::canViewServer($user, $id);
    }

    public function canManage(): bool
    {
        return $this->app === null && Access::canManageServer($this->panelUser(), (int) ($this->serverId ?? 0));
    }

    /** @return array<string, mixed> */
    private function seriesQuery(): array
    {
        return ['metrics' => implode(',', $this->app !== null ? self::APP_SERIES : self::HOST_SERIES), 'range' => $this->range];
    }

    /** @return array<string, mixed> */
    private function eventsQuery(): array
    {
        $limit = $this->app !== null ? 100 : 60;

        return array_filter(['app' => $this->app, 'range' => $this->range === '1h' || $this->range === '6h' ? '24h' : $this->range, 'limit' => $limit], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function uptimeQuery(): array
    {
        return array_filter(['app' => $this->app, 'range' => '30d'], fn ($v) => $v !== null);
    }

    protected function load(bool $withSettings): void
    {
        if (! $this->mayRead()) {
            return;
        }

        try {
            $api = $this->client();
        } catch (CipiApiException $e) {
            $this->error = $this->friendlyApiError($e);
            $this->loaded = true;

            return;
        }

        $seriesPath = $this->app !== null ? "/apps/{$this->app}/metrics" : '/metrics/series';
        // Uptime per day reads 30 days of rollups: every five minutes is enough.
        $wantUptime = $this->uptimeAt < time() - 300;
        $paths = ['/metrics', [$seriesPath, $this->seriesQuery()], ['/metrics/events', $this->eventsQuery()]];
        if ($wantUptime) {
            $paths[] = ['/metrics/uptime', $this->uptimeQuery()];
        }
        if ($withSettings && $this->app === null) {
            $paths[] = '/metrics/settings';
        }
        // Each answer is a `sudo cipi metrics …` on the server: asked together.
        $api->prefetch($paths);

        try {
            $this->overview = $api->metricsOverview();
            $this->series = $api->metricsSeries($this->app, explode(',', $this->seriesQuery()['metrics']), $this->range);
            $q = $this->eventsQuery();
            $this->events = $api->metricsEvents($q['app'] ?? null, $q['range'], $q['limit']);
            if ($wantUptime) {
                $u = $this->uptimeQuery();
                $this->uptime = $api->metricsUptime($u['app'] ?? null, $u['range']);
                $this->uptimeAt = time();
            }
            if ($withSettings && $this->app === null) {
                $this->syncSettings($api->metricsSettings());
            }
            $this->unsupported = false;
            $this->unsupportedReason = '';
            $this->error = null;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->unsupported = true;
                $this->unsupportedReason = $this->friendlyApiError($e);
            } else {
                $this->error = $this->friendlyApiError($e);
            }
        } finally {
            CipiApiClient::forgetPrefetched();
            $this->loaded = true;
            $this->fetchedAt = time();
            $this->version++;
        }
    }

    protected function syncSettings(array $settings): void
    {
        $this->settings = $settings;
        $r = (array) ($settings['retention'] ?? []);
        $this->rawDays = (int) ($r['raw_days'] ?? 7);
        $this->rollupDays = (int) ($r['rollup_days'] ?? 30);
        $this->resolutionMinutes = (int) ($r['resolution_minutes'] ?? 15);
        $this->eventsDays = (int) ($r['events_days'] ?? 90);
        $this->heartbeatEvery = (int) ($settings['heartbeat']['every'] ?? 1);
    }

    // ── settings (server tab, manage) ────────────────────────────────

    public function saveRetention(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $this->validate([
            'rawDays' => 'required|integer|min:1|max:90',
            'rollupDays' => 'required|integer|min:1|max:400|gte:rawDays',
            'resolutionMinutes' => 'required|integer|in:5,10,15,30,60',
            'eventsDays' => 'required|integer|min:7|max:730',
        ], ['rollupDays.gte' => 'Rollups cannot be kept for less time than raw minutes.']);

        $this->writeSettings([
            'raw_days' => $this->rawDays,
            'rollup_days' => $this->rollupDays,
            'resolution_minutes' => $this->resolutionMinutes,
            'events_days' => $this->eventsDays,
        ], 'Retention saved — older data goes at the next hourly prune.');
    }

    public function toggleProbe(): void
    {
        if ($this->guardManage()) {
            $on = ! (bool) ($this->settings['probe'] ?? true);
            $this->writeSettings(['probe' => $on], $on ? 'Healthchecks probed every minute.' : 'Per-minute probe off.');
        }
    }

    public function toggleCollector(): void
    {
        if ($this->guardManage()) {
            $on = ! (bool) ($this->settings['enabled'] ?? true);
            $this->writeSettings(['enabled' => $on], $on ? 'Collector on.' : 'Collector off — the history is kept.');
        }
    }

    protected function writeSettings(array $payload, string $done): void
    {
        try {
            $this->syncSettings($this->client()->updateMetricsSettings($payload));
            $this->dispatch('notify', type: 'success', message: $done);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function saveHeartbeat(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $this->validate([
            'heartbeatUrl' => ['required', 'string', 'max:500', 'regex:/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]+)?(\/\S*)?$/'],
            'heartbeatEvery' => 'required|integer|min:1|max:60',
        ], ['heartbeatUrl.regex' => 'An http(s) URL, without spaces.']);

        try {
            $this->settings['heartbeat'] = $this->client()->setMetricsHeartbeat($this->heartbeatUrl, $this->heartbeatEvery);
            $this->heartbeatUrl = '';
            $this->heartbeatTest = null;
            $this->dispatch('notify', type: 'success', message: 'Heartbeat set: every '.$this->heartbeatEvery.' min.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function testHeartbeat(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        try {
            $this->heartbeatTest = $this->client()->testMetricsHeartbeat();
        } catch (CipiApiException $e) {
            $this->heartbeatTest = ['ok' => false, 'code' => 0, 'host' => $this->settings['heartbeat']['url_host'] ?? '', 'error' => $this->friendlyApiError($e)];
        }
    }

    public function removeHeartbeat(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        try {
            $this->settings['heartbeat'] = $this->client()->deleteMetricsHeartbeat();
            $this->heartbeatTest = null;
            $this->dispatch('notify', type: 'success', message: 'Heartbeat off.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function guardManage(): bool
    {
        if ($this->app !== null) {
            return false;
        }

        return $this->serverAllowed(Access::MANAGE, (int) ($this->serverId ?? 0));
    }

    // ── what the view draws ──────────────────────────────────────────

    /** The overview row of this app, or []. */
    public function appRow(): array
    {
        foreach ((array) ($this->overview['apps'] ?? []) as $row) {
            if (($row['app'] ?? null) === $this->app) {
                return (array) $row;
            }
        }

        return [];
    }

    /** Average values of a metric in the series, for a sparkline. */
    public function seriesValues(string $metric, int $last = 60): array
    {
        $values = array_map(fn ($p) => $p[1] ?? null, (array) ($this->series['series'][$metric] ?? []));

        return array_slice($values, -$last);
    }

    /** @return array<string, array<string, mixed>> chart key => config */
    public function charts(): array
    {
        $markers = M::markers($this->events, $this->series['from'] ?? null, $this->series['to'] ?? null, $this->app);
        $s = $this->series;

        if ($this->app === null) {
            return [
                'cpu' => ['title' => 'CPU', 'hint' => 'all cores · average and peak per point', 'chart' => M::chart($s, [['metric' => 'cpu', 'name' => 'CPU', 'color' => 'accent', 'band' => true]], '%', $markers, null, 100.0)],
                'mem' => ['title' => 'Memory', 'hint' => 'in use, of the total', 'chart' => M::chart($s, [['metric' => 'mem', 'name' => 'Memory', 'color' => 'info', 'band' => true]], '%', $markers, null, 100.0)],
                'load' => ['title' => 'Load', 'hint' => '1-minute load average', 'chart' => M::chart($s, [['metric' => 'load1', 'name' => 'Load', 'color' => 'env', 'band' => true]], '', $markers)],
                'net' => ['title' => 'Network', 'hint' => 'every interface but lo', 'chart' => M::chart($s, [
                    ['metric' => 'net_rx', 'name' => 'In', 'color' => 'info', 'kind' => 'line'],
                    ['metric' => 'net_tx', 'name' => 'Out', 'color' => 'accent', 'kind' => 'line'],
                ], 'bps', $markers)],
            ];
        }

        $fpmMax = $this->appRow()['values']['fpm_max'] ?? null;
        $charts = [
            'traffic' => ['title' => 'Requests', 'hint' => 'per minute, from the access log · 5xx in red', 'chart' => M::chart($s, [
                ['metric' => 'req', 'name' => 'Requests', 'color' => 'accent'],
                ['metric' => 'http_5xx', 'name' => '5xx', 'color' => 'danger', 'kind' => 'bar'],
            ], 'count', $markers)],
            'latency' => ['title' => 'Response time', 'hint' => 'healthcheck URL, every minute · average and slowest', 'chart' => M::chart($s, [['metric' => 'http_ms', 'name' => 'Response', 'color' => 'info', 'band' => true]], 'ms', $markers)],
            'cpu' => ['title' => 'CPU', 'hint' => 'the app user\'s processes · % of one core', 'chart' => M::chart($s, [['metric' => 'cpu', 'name' => 'CPU', 'color' => 'accent', 'band' => true]], '%', $markers)],
            'mem' => ['title' => 'Memory', 'hint' => 'resident, the app user\'s processes', 'chart' => M::chart($s, [['metric' => 'mem_mb', 'name' => 'Memory', 'color' => 'env', 'band' => true]], 'mb', $markers)],
        ];
        $fpm = M::chart($s, [
            ['metric' => 'fpm_procs', 'name' => 'Children', 'color' => 'info'],
            ['metric' => 'fpm_saturated', 'name' => 'max_children hit', 'color' => 'danger', 'kind' => 'bar'],
        ], 'count', $markers, is_numeric($fpmMax) ? ['value' => (float) $fpmMax, 'label' => 'pm.max_children '.(int) $fpmMax] : null);
        if (! M::chartEmpty($fpm)) {
            $charts['fpm'] = ['title' => 'PHP-FPM', 'hint' => 'children of the pool · times it hit pm.max_children', 'chart' => $fpm];
        }
        $workers = M::chart($s, [['metric' => 'workers_up', 'name' => 'Running', 'color' => 'success', 'kind' => 'line']], 'count', $markers,
            is_numeric($this->appRow()['values']['workers_total'] ?? null) ? ['value' => (float) $this->appRow()['values']['workers_total'], 'label' => 'configured'] : null);
        if (! M::chartEmpty($workers)) {
            $charts['workers'] = ['title' => 'Queue workers', 'hint' => 'programs running · configured', 'chart' => $workers];
        }

        return $charts;
    }

    public function render()
    {
        return view('cipi-gui::livewire.metrics', [
            'canManage' => $this->canManage(),
            'poll' => M::pollSeconds($this->range),
            'charts' => $this->loaded && ! $this->unsupported ? $this->charts() : [],
        ]);
    }
}
