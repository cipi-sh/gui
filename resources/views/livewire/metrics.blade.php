@php
    $collector = (array) ($overview['collector'] ?? []);
    $host = (array) ($overview['host']['values'] ?? []);
    $apps = (array) ($overview['apps'] ?? []);
    $row = $app !== null ? $this->appRow() : [];
    $vals = (array) ($row['values'] ?? []);
    $lastRun = $collector['last_run'] ?? null;
    $spark = function (array $values, string $class = 'kpi-spark', string $color = '') {
        $p = \CipiGui\Support\Metrics::spark($values);
        if (! $p) { return ''; }
        return '<svg class="'.$class.'" viewBox="0 0 96 24" preserveAspectRatio="none" aria-hidden="true"'.($color ? ' style="color:var(--'.$color.')"' : '').'><path class="spark-area" d="'.$p['area'].'"/><path class="spark-line" d="'.$p['line'].'"/></svg>';
    };
@endphp
<div wire:init="init" data-metrics="{{ $app ?? 'server' }}">
    @if(! $loaded)
        <div class="card" aria-busy="true" data-metrics-loading>
            <div class="kpi-grid">
                @for($i = 0; $i < 6; $i++)<div class="skeleton h-20"></div>@endfor
            </div>
            <div class="chart-grid">
                <div class="skeleton h-40"></div><div class="skeleton h-40"></div>
            </div>
            <p class="sr-only">Loading metrics from the server…</p>
        </div>
    @elseif($unsupported)
        <x-cipi::alert type="warn" title="Metrics are not available on this server" data-metrics-unsupported>
            The history needs <strong>Cipi 5.11.0</strong> on the server and <strong>cipi/api 1.41.0</strong>, with a token that has the
            <code>metrics-view</code> ability (and <code>metrics-manage</code> to change the retention). On the server:
            <code>cipi self-update &amp;&amp; cipi api update</code>, then recreate the token from
            <a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>.
            @if($unsupportedReason !== '')
                <p class="mt-2">The server answered: <span class="font-mono text-xs break-words">{{ $unsupportedReason }}</span></p>
            @endif
            <p class="mt-3"><button type="button" wire:click="refresh" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Check again</button></p>
        </x-cipi::alert>
    @else
        @if($live)
            {{-- Every 30 s (2 min for 7 and 30 days), and only while the tab is on screen. --}}
            <span wire:poll.{{ $poll }}s.visible="poll" wire:key="metrics-poll-{{ $poll }}" aria-hidden="true"></span>
        @endif

        <div class="metrics-toolbar">
            <div class="min-w-0">
                <h2 class="card-title">{{ $app !== null ? 'Metrics — '.$app : 'Metrics' }}</h2>
                <p class="card-subtitle" data-metrics-freshness>
                    @if($lastRun)
                        Collected every minute · last {{ \CipiGui\Support\Clock::format((int) $lastRun, 'H:i') }}
                    @else
                        The collector has not written a minute yet — it runs every minute.
                    @endif
                    · read {{ \CipiGui\Support\Clock::format($fetchedAt, 'H:i:s') }}
                </p>
            </div>
            <div class="metrics-toolbar-end">
                <div class="segmented" role="tablist" aria-label="Range">
                    @foreach(\CipiGui\Support\Metrics::RANGES as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $range === $key ? 'true' : 'false' }}" wire:click="setRange('{{ $key }}')" class="segmented-item {{ $range === $key ? 'active' : '' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="toggleLive" class="live-toggle {{ $live ? 'is-live' : '' }}" title="{{ $live ? 'Reading every '.$poll.' s while this tab is visible — click to pause' : 'Paused — click to read every '.$poll.' s' }}" data-live="{{ $live ? 'on' : 'off' }}">
                    <span class="dot"></span>{{ $live ? 'Live' : 'Paused' }}
                </button>
                <button type="button" wire:click="refresh" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="refresh,setRange,poll" aria-label="Refresh">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="refresh,setRange,poll" />
                </button>
            </div>
        </div>

        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif
        @if(($collector['sqlite'] ?? true) === false)
            <x-cipi::alert type="danger" class="mb-4" title="sqlite3 is missing on the server">Nothing is collected until it is installed: <code>apt-get install -y sqlite3</code>.</x-cipi::alert>
        @elseif(($collector['enabled'] ?? true) === false)
            <x-cipi::alert type="warn" class="mb-4" title="The collector is off">The history is kept, nothing new is recorded. @if($canManage) Turn it on below, or @endif on the server: <code>cipi metrics enable</code>.</x-cipi::alert>
        @elseif($lastRun && $lastRun < time() - 600)
            <x-cipi::alert type="warn" class="mb-4" title="No new minute for {{ intdiv(time() - (int) $lastRun, 60) }} minutes">Is cron running on the server? <code>systemctl status cron</code>, and <code>cat /etc/cron.d/cipi-metrics</code>.</x-cipi::alert>
        @endif

        @if($app === null)
            {{-- ═══ Server ═══ --}}
            @php
                $cpu = $host['cpu'] ?? null; $mem = $host['mem'] ?? null; $disk = $host['disk'] ?? null;
                $withHealth = array_values(array_filter($apps, fn ($a) => ! empty($a['health'])));
                $passing = count(array_filter($withHealth, fn ($a) => ($a['values']['http_ok'] ?? null) == 1));
                $lvl = fn ($v, $warn, $bad) => ! is_numeric($v) ? '' : ($v >= $bad ? 'is-bad' : ($v >= $warn ? 'is-warn' : ''));
            @endphp
            <div class="kpi-grid" data-kpis>
                <div class="kpi">
                    <span class="stat-label">CPU</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($cpu, '%') }}</span>
                    <div class="kpi-bar"><span class="{{ $lvl($cpu, 75, 90) }}" style="width: {{ min(100, (float) $cpu) }}%"></span></div>
                    {!! $spark($this->seriesValues('cpu')) !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">Memory</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($mem, '%') }}</span>
                    <span class="kpi-meta">{{ \CipiGui\Support\Metrics::format($host['mem_used_mb'] ?? null, 'mb') }} in use · swap {{ \CipiGui\Support\Metrics::format($host['swap'] ?? null, '%') }}</span>
                    {!! $spark($this->seriesValues('mem'), 'kpi-spark', 'info') !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">Load</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($host['load1'] ?? null, '', 2) }}</span>
                    <span class="kpi-meta">1-minute average</span>
                    {!! $spark($this->seriesValues('load1'), 'kpi-spark', 'env') !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">Disk /</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($disk, '%', 0) }}</span>
                    <div class="kpi-bar"><span class="{{ $lvl($disk, 80, 90) }}" style="width: {{ min(100, (float) $disk) }}%"></span></div>
                </div>
                <div class="kpi">
                    <span class="stat-label">Network</span>
                    <span class="kpi-value text-base">↓ {{ \CipiGui\Support\Metrics::format($host['net_rx'] ?? null, 'bps') }}</span>
                    <span class="kpi-meta">↑ {{ \CipiGui\Support\Metrics::format($host['net_tx'] ?? null, 'bps') }}</span>
                    {!! $spark($this->seriesValues('net_rx'), 'kpi-spark', 'info') !!}
                </div>
                <div class="kpi {{ $withHealth ? ($passing === count($withHealth) ? 'is-ok' : 'is-bad') : '' }}">
                    <span class="stat-label">Healthchecks</span>
                    <span class="kpi-value">{{ $withHealth ? $passing.' / '.count($withHealth) : '—' }}</span>
                    <span class="kpi-meta">{{ $withHealth ? 'answering as expected' : 'none set — from an app\'s overview' }}</span>
                </div>
            </div>

            <div class="chart-grid">
                @foreach($charts as $key => $c)
                    @include('cipi-gui::partials.metrics-chart', ['key' => $key, 'c' => $c])
                @endforeach
            </div>

            <div class="card card-flush mb-4">
                <div class="card-header p-5 mb-0">
                    <div><h2 class="card-title">Apps</h2><p class="card-subtitle">Latest minute · requests and 5xx from each app's access log · the last hour of requests.</p></div>
                </div>
                <div class="table-scroll">
                    <table data-metrics-apps>
                        <thead><tr><th>App</th><th>Health</th><th class="text-right">CPU</th><th class="text-right">Memory</th><th class="text-right">Req/min</th><th class="text-right">5xx</th><th class="text-right">Uptime 24h</th><th class="text-right">30d</th><th>Last hour</th></tr></thead>
                        <tbody>
                            @forelse($apps as $a)
                                @php $v = (array) ($a['values'] ?? []); $u = (array) ($a['uptime'] ?? []); $ok = $v['http_ok'] ?? null; @endphp
                                <tr wire:key="metrics-app-{{ $a['app'] }}">
                                    <td><a href="{{ route('cipi-gui.apps.show', ['name' => $a['app'], 'server' => $serverId, 'tab' => 'metrics']) }}" class="row-link">{{ $a['app'] }}</a>@if(!empty($a['suspended']))<span class="badge badge-gray ml-1">suspended</span>@endif</td>
                                    <td class="whitespace-nowrap">
                                        @if(empty($a['health']))
                                            <span class="text-subtle text-xs">no check</span>
                                        @else
                                            <span class="dot {{ $ok == 1 ? 'dot-green' : ($ok === null ? '' : 'dot-red') }}"></span>
                                            <span class="text-xs tabular-nums ml-1">{{ isset($v['http_code']) ? (int) $v['http_code'] : '—' }} · {{ \CipiGui\Support\Metrics::format($v['http_ms'] ?? null, 'ms') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ \CipiGui\Support\Metrics::format($v['cpu'] ?? null, '%') }}</td>
                                    <td class="text-right tabular-nums">{{ \CipiGui\Support\Metrics::format($v['mem_mb'] ?? null, 'mb') }}</td>
                                    <td class="text-right tabular-nums">{{ \CipiGui\Support\Metrics::format($v['req'] ?? null, 'count') }}</td>
                                    <td class="text-right tabular-nums {{ ($v['http_5xx'] ?? 0) > 0 ? 'text-danger' : '' }}">{{ \CipiGui\Support\Metrics::format($v['http_5xx'] ?? null, 'count') }}</td>
                                    <td class="text-right"><span class="uptime-pct {{ \CipiGui\Support\Metrics::uptimeClass($u['24h'] ?? null) }}">{{ is_numeric($u['24h'] ?? null) ? \CipiGui\Support\Metrics::format($u['24h'], '%', 2) : '—' }}</span></td>
                                    <td class="text-right"><span class="uptime-pct {{ \CipiGui\Support\Metrics::uptimeClass($u['30d'] ?? null) }}">{{ is_numeric($u['30d'] ?? null) ? \CipiGui\Support\Metrics::format($u['30d'], '%', 2) : '—' }}</span></td>
                                    <td>{!! $spark((array) ($a['spark']['req'] ?? []), 'spark') !!}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-muted">No apps on this server.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">Uptime — 30 days</h2><p class="card-subtitle">Healthcheck URL probed every minute; one bar per day.</p></div></div>
                    @php $uptimeApps = (array) ($uptime['apps'] ?? []); @endphp
                    @forelse($uptimeApps as $u)
                        @include('cipi-gui::partials.metrics-uptime-row', ['u' => $u, 'label' => $u['app'] ?? '?'])
                    @empty
                        <p class="text-sm text-muted">No app has a healthcheck yet. Set one from an app's overview (or <code>cipi health set &lt;app&gt;</code>): it is probed every minute and its uptime is kept here.</p>
                    @endforelse
                </div>
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">Events</h2><p class="card-subtitle">Monitor checks changing state, healthcheck incidents, releases, heartbeat.</p></div></div>
                    @include('cipi-gui::partials.metrics-events', ['events' => $events, 'showSubject' => true])
                </div>
            </div>

            @include('cipi-gui::partials.metrics-settings')
        @else
            {{-- ═══ App ═══ --}}
            @php
                $u = (array) ($row['uptime'] ?? []);
                $hasHealth = ! empty($row['health']);
            @endphp
            <div class="kpi-grid" data-kpis>
                <div class="kpi {{ $hasHealth ? \CipiGui\Support\Metrics::uptimeClass($u['24h'] ?? null) : '' }}">
                    <span class="stat-label">Uptime 24h</span>
                    <span class="kpi-value">{{ $hasHealth && is_numeric($u['24h'] ?? null) ? \CipiGui\Support\Metrics::format($u['24h'], '%', 2) : '—' }}</span>
                    <span class="kpi-meta">{{ $hasHealth ? '7d '.(is_numeric($u['7d'] ?? null) ? \CipiGui\Support\Metrics::format($u['7d'], '%', 2) : '—').' · 30d '.(is_numeric($u['30d'] ?? null) ? \CipiGui\Support\Metrics::format($u['30d'], '%', 2) : '—') : 'No healthcheck — set one in Overview' }}</span>
                </div>
                <div class="kpi">
                    <span class="stat-label">Response</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($vals['http_ms'] ?? null, 'ms') }}</span>
                    <span class="kpi-meta">{{ $hasHealth ? 'HTTP '.(isset($vals['http_code']) ? (int) $vals['http_code'] : '—').' · '.($row['health']['url'] ?? '') : 'healthcheck URL' }}</span>
                    {!! $spark($this->seriesValues('http_ms'), 'kpi-spark', 'info') !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">Requests</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($vals['req'] ?? null, 'count') }}<small>/min</small></span>
                    {!! $spark($this->seriesValues('req')) !!}
                </div>
                <div class="kpi {{ ($vals['http_5xx'] ?? 0) > 0 ? 'is-bad' : '' }}">
                    <span class="stat-label">5xx</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($vals['http_5xx'] ?? null, 'count') }}<small>/min</small></span>
                    {!! $spark($this->seriesValues('http_5xx'), 'kpi-spark', 'danger') !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">CPU</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($vals['cpu'] ?? null, '%') }}</span>
                    <span class="kpi-meta">of one core</span>
                    {!! $spark($this->seriesValues('cpu')) !!}
                </div>
                <div class="kpi">
                    <span class="stat-label">Memory</span>
                    <span class="kpi-value">{{ \CipiGui\Support\Metrics::format($vals['mem_mb'] ?? null, 'mb') }}</span>
                    <span class="kpi-meta">{{ isset($vals['fpm_procs']) ? (int) $vals['fpm_procs'].' FPM children'.(isset($vals['fpm_max']) ? ' of '.(int) $vals['fpm_max'] : '') : (isset($vals['procs']) ? (int) $vals['procs'].' processes' : '') }}</span>
                    {!! $spark($this->seriesValues('mem_mb'), 'kpi-spark', 'env') !!}
                </div>
            </div>

            <div class="chart-grid">
                @foreach($charts as $key => $c)
                    @include('cipi-gui::partials.metrics-chart', ['key' => $key, 'c' => $c])
                @endforeach
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">Uptime — 30 days</h2><p class="card-subtitle">{{ $hasHealth ? 'Probed every minute: '.($row['health']['url'] ?? '').' (expects '.($row['health']['expect'] ?? 200).')' : 'No healthcheck on this app.' }}</p></div></div>
                    @if($hasHealth && ! empty($uptime))
                        @include('cipi-gui::partials.metrics-uptime-row', ['u' => $uptime, 'label' => null])
                        <dl class="kv mt-4">
                            <dt>Down</dt><dd class="tabular-nums">{{ (int) round((float) ($uptime['down_minutes'] ?? 0)) }} min over {{ number_format((int) ($uptime['checks'] ?? 0)) }} checks</dd>
                            <dt>Average response</dt><dd class="tabular-nums">{{ \CipiGui\Support\Metrics::format($uptime['avg_ms'] ?? null, 'ms') }}</dd>
                            <dt>Incidents</dt><dd class="tabular-nums">{{ (int) ($uptime['incidents'] ?? 0) }}</dd>
                        </dl>
                        @php $incidents = array_reverse((array) ($uptime['events'] ?? [])); @endphp
                        @if($incidents)
                            <h3 class="stat-label mt-5 mb-2">Incidents</h3>
                            @include('cipi-gui::partials.metrics-events', ['events' => array_map(fn ($e) => $e + ['source' => 'health', 'subject' => $app], $incidents), 'showSubject' => false])
                        @endif
                    @else
                        <p class="text-sm text-muted">Turn on the healthcheck in Overview: the URL is then probed every minute, and its uptime, response time and incidents are kept here for 30 days.</p>
                    @endif
                </div>
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">Events</h2><p class="card-subtitle">Releases put live, healthcheck incidents.</p></div></div>
                    @include('cipi-gui::partials.metrics-events', ['events' => $events, 'showSubject' => false])
                </div>
            </div>
        @endif
    @endif
</div>
