<div>
    <x-cipi::page-header :title="$server?->name ?? 'Server'">
        <x-slot:eyebrow><x-cipi::icon name="server" class="h-3.5 w-3.5" /> Server</x-slot:eyebrow>
        <x-slot:meta>
            @if($server)
                <span class="font-mono text-xs">{{ $server->host }}</span>@if($server->ip) · <span class="font-mono text-xs">{{ $server->ip }}</span>@endif
                @if(!empty($status['system']['cipi'])) · Cipi {{ $status['system']['cipi'] }}@endif
            @else
                Connect a server to manage PHP, Node, services, notifications and API access.
            @endif
        </x-slot:meta>
        @if($server)
            <x-slot:actions>
                <a href="{{ route('cipi-gui.apps') }}" class="btn btn-secondary"><x-cipi::icon name="apps" /> Apps</a>
                <button type="button" wire:click="refresh" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="refresh,setTab">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="refresh,setTab" /> Refresh
                </button>
            </x-slot:actions>
        @endif
    </x-cipi::page-header>

    @if(! $server)
        <div class="card">
            <x-cipi::empty icon="servers" title="No server selected">
                <x-slot:actions><a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Connect a server</a></x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        <nav class="tabs" aria-label="Server sections">
            @foreach($tabs as $tab => $label)
                <button type="button" wire:click="setTab('{{ $tab }}')" class="tab-btn {{ $activeTab === $tab ? 'active' : '' }}" @if($activeTab === $tab) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>

        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif

        @if($unsupported[$activeTab] ?? false)
            <x-cipi::alert type="warn" title="Not available on this server">
                This section needs a newer Cipi API or a token with the matching abilities.
                Run <code>cipi self-update &amp;&amp; cipi api update</code> on the server and recreate the token from
                <a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>.
                @if(in_array($activeTab, ['node', 'search', 'packages', 'monitor', 'zt'], true)) (API 1.31+ / Cipi 5.4.1+) @elseif($activeTab === 'disk') (API 1.33+ / Cipi 5.5.2+, whose migration lets the API run <code>cipi disk</code>) @endif
            </x-cipi::alert>
        @elseif($activeTab === 'overview')
            @php
                $sys = $status['system'] ?? [];
                $res = $status['resources'] ?? [];
                $disk = $res['disk'] ?? [];
                $cpu = (int) ($res['cpu']['usage_percent'] ?? 0);
                $mem = $res['memory'] ?? [];
                $checks = is_array($monitor['checks'] ?? null) ? $monitor['checks'] : [];
                $alerts = array_filter($checks, fn ($c) => in_array($c['state'] ?? null, ['warn', 'crit', 'fail'], true));
                $failingHealth = array_filter($healthChecks, fn ($h) => ($h['state'] ?? null) === 'fail');
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">System</h2></div>
                    <dl class="kv">
                        <dt>Hostname</dt><dd class="font-mono text-xs">{{ $sys['hostname'] ?? '—' }}</dd>
                        <dt>OS</dt><dd>{{ $sys['os'] ?? '—' }}</dd>
                        <dt>Cipi</dt><dd>{{ $sys['cipi'] ?? '—' }}</dd>
                        <dt>Public IP</dt><dd class="font-mono text-xs">{{ $sys['ip'] ?? ($server->ip ?? '—') }}</dd>
                        <dt>Uptime</dt><dd>{{ isset($sys['uptime']) ? preg_replace('/^up\s+/', '', $sys['uptime']) : '—' }}</dd>
                        <dt>Apps</dt><dd><a href="{{ route('cipi-gui.apps') }}" class="text-link">{{ $status['apps'] ?? 0 }}</a></dd>
                    </dl>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Resources</h2></div>
                    <div class="space-y-4">
                        <div>
                            <div class="metric-row"><span class="stat-label">CPU</span><span class="stat-value-sm tabular-nums">{{ $cpu }}%</span></div>
                            <div class="progress-bar"><div class="progress-fill {{ $cpu >= 90 ? 'is-danger' : ($cpu >= 75 ? 'is-warn' : '') }}" style="width: {{ $cpu }}%"></div></div>
                        </div>
                        <div>
                            @php $mp = (int) ($mem['usage_percent'] ?? 0); @endphp
                            <div class="metric-row"><span class="stat-label">Memory</span><span class="stat-value-sm tabular-nums">{{ $mp }}%</span></div>
                            <div class="progress-bar"><div class="progress-fill {{ $mp >= 90 ? 'is-danger' : ($mp >= 75 ? 'is-warn' : '') }}" style="width: {{ $mp }}%"></div></div>
                            @if(isset($mem['used_mb'], $mem['total_mb']))<p class="stat-meta mt-1">{{ number_format($mem['used_mb']) }} / {{ number_format($mem['total_mb']) }} MB</p>@endif
                        </div>
                        <div>
                            @php $dp = (int) ($disk['usage_percent'] ?? 0); @endphp
                            <div class="metric-row"><span class="stat-label">Disk /</span><span class="stat-value-sm tabular-nums">{{ $dp }}%</span></div>
                            <div class="progress-bar"><div class="progress-fill {{ $dp >= 90 ? 'is-danger' : ($dp >= 80 ? 'is-warn' : '') }}" style="width: {{ $dp }}%"></div></div>
                            <p class="stat-meta mt-1">{{ ($disk['used'] ?? '?').' / '.($disk['total'] ?? '?') }} — <button type="button" wire:click="setTab('disk')" class="text-link">per-app usage →</button></p>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Attention</h2></div>
                    @if(! $alerts && ! $failingHealth)
                        <div class="flex items-center gap-3 text-success"><x-cipi::icon name="check-circle" class="h-5 w-5" /><span>No monitor alerts or failing healthchecks.</span></div>
                    @else
                        <ul class="space-y-2">
                            @foreach($alerts as $check)
                                <li class="flex items-center justify-between gap-2"><span class="font-mono text-xs">monitor · {{ $check['check'] }}</span><span class="badge {{ $this->monitorStateClass($check['state']) }}">{{ $check['state'] }}</span></li>
                            @endforeach
                            @foreach($failingHealth as $h)
                                <li class="flex items-center justify-between gap-2"><a href="{{ route('cipi-gui.apps.show', ['name' => $h['app'], 'server' => $server->id]) }}" class="font-mono text-xs text-link">health · {{ $h['app'] }}</a><span class="badge badge-red">{{ $h['failcount'] ?? 0 }} fails</span></li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t">
                        <button type="button" wire:click="setTab('monitor')" class="btn btn-secondary btn-sm">Monitor</button>
                        <button type="button" wire:click="setTab('health')" class="btn btn-secondary btn-sm">Healthchecks</button>
                    </div>
                </div>

                <div class="card card-flush lg:col-span-2">
                    <div class="card-header p-5 mb-0">
                        <h2 class="card-title">Services</h2>
                        <button type="button" wire:click="setTab('services')" class="btn btn-ghost btn-sm">All services →</button>
                    </div>
                    <div class="table-scroll">
                        <table class="table-compact">
                            <tbody>
                                @foreach(array_slice($services ?: array_map(fn ($n, $s) => ['name' => $n, 'status' => $s, 'since' => null], array_keys($status['services'] ?? []), $status['services'] ?? []), 0, 9) as $svc)
                                    <tr>
                                        <td class="font-mono text-xs text-strong"><span class="dot {{ ($svc['status'] ?? '') === 'running' ? 'dot-green' : 'dot-red' }} mr-2"></span>{{ $svc['name'] }}</td>
                                        <td class="text-xs text-muted">{{ $svc['status'] ?? '' }}@if(!empty($svc['since'])) · since {{ $svc['since'] }}@endif</td>
                                        <td class="text-right"><button type="button" wire:click="restartService(@js($svc['name']))" wire:confirm="Restart {{ $svc['name'] }}?" class="btn btn-ghost btn-xs" @disabled(($svc['status'] ?? '') === 'not_installed')>Restart</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">PHP-FPM</h2><button type="button" wire:click="setTab('php')" class="btn btn-ghost btn-sm">Manage →</button></div>
                    <ul>
                        @forelse((array) ($status['php'] ?? []) as $row)
                            <li class="list-row"><span class="font-medium">PHP {{ $row['version'] ?? '?' }}</span><span class="text-xs text-muted">{{ $row['pools'] ?? 0 }} {{ \Illuminate\Support\Str::plural('pool', $row['pools'] ?? 0) }} · {{ $row['status'] ?? '' }}</span></li>
                        @empty
                            <li class="text-muted">No PHP information.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

        @elseif($activeTab === 'disk')
            @php
                $fs = is_array($disk['disk'] ?? null) ? $disk['disk'] : [];
                $fsPercent = (int) ($fs['used_percent'] ?? 0);
                $sizeGb = (float) ($fs['size_gb'] ?? 0);
                $diskApps = is_array($disk['apps'] ?? null) ? $disk['apps'] : [];
                $overLimit = count(array_filter($diskApps, fn ($a) => ! empty($a['over_limit'])));
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                <div class="card">
                    <div class="metric-row"><span class="stat-label">Filesystem {{ $fs['mount'] ?? '/' }}</span><span class="stat-value-sm tabular-nums">{{ $fsPercent }}%</span></div>
                    <div class="progress-bar"><div class="progress-fill {{ $this->diskMeterClass($fsPercent) }}" style="width: {{ min(100, $fsPercent) }}%"></div></div>
                    <p class="stat-meta mt-1 tabular-nums">{{ number_format((float) ($fs['used_gb'] ?? 0), 2) }} / {{ number_format($sizeGb, 2) }} GB used · {{ number_format((float) ($fs['free_gb'] ?? 0), 2) }} GB free</p>
                </div>
                <div class="card">
                    <p class="stat-label">All apps</p>
                    <p class="stat-value mt-1 tabular-nums">{{ number_format((float) ($disk['apps_total_gb'] ?? 0), 2) }} GB</p>
                    <p class="stat-meta {{ $overLimit ? 'text-danger' : '' }}">{{ $disk['apps_percent'] ?? 0 }}% of the disk · {{ count($diskApps) }} {{ \Illuminate\Support\Str::plural('app', count($diskApps)) }}{{ $overLimit ? ' · '.$overLimit.' over the limit' : '' }}</p>
                </div>
                <div class="card">
                    <p class="stat-label">Everything else</p>
                    <p class="stat-value mt-1 tabular-nums">{{ number_format((float) ($disk['other_gb'] ?? 0), 2) }} GB</p>
                    <p class="stat-meta">{{ $disk['other_percent'] ?? 0 }}% — system, packages, logs, local backups, other databases</p>
                </div>
            </div>

            <div class="card card-flush mb-4">
                <div class="card-header p-5 mb-0">
                    <div>
                        <h2 class="card-title">Apps</h2>
                        <p class="card-subtitle">Files are the app home (releases, shared storage, logs); the database is the one named after the app, plus <code>DB_DATABASE</code> of <code>shared/.env</code> when it points elsewhere. Largest first, as a share of the {{ number_format($sizeGb, 2) }} GB on {{ $fs['mount'] ?? '/' }}.</p>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>App</th><th class="text-right">Files</th><th class="text-right">Database</th><th class="text-right">Total</th><th>Disk</th><th>Limit</th></tr></thead>
                        <tbody>
                            @forelse($diskApps as $row)
                                @php
                                    $limit = is_numeric($row['limit_gb'] ?? null) ? $row['limit_gb'] + 0 : null;
                                    $limitPercent = $limit !== null ? (int) ($row['limit_percent'] ?? 0) : null;
                                    $share = (float) ($row['percent'] ?? 0);
                                @endphp
                                <tr wire:key="disk-app-{{ $row['app'] }}">
                                    <td><a href="{{ route('cipi-gui.apps.show', ['name' => $row['app'], 'server' => $server->id]) }}" class="row-link font-mono">{{ $row['app'] }}</a></td>
                                    <td class="text-right tabular-nums">{{ $this->diskSize($row['files_kb'] ?? null, $row['files_gb'] ?? 0) }}</td>
                                    <td class="text-right tabular-nums">{{ $this->diskSize($row['database_kb'] ?? null, $row['database_gb'] ?? 0) }}</td>
                                    <td class="text-right tabular-nums text-strong">{{ $this->diskSize($row['total_kb'] ?? null, $row['total_gb'] ?? 0) }}</td>
                                    <td style="min-width: 8rem">
                                        <div class="metric-row"><span class="text-xs tabular-nums">{{ number_format($share, 1) }}%</span></div>
                                        <div class="progress-bar"><div class="progress-fill" style="width: {{ min(100, $share) }}%"></div></div>
                                    </td>
                                    <td>
                                        @if($limit === null)
                                            <span class="text-muted">—</span>
                                        @elseif(! empty($row['over_limit']))
                                            <span class="badge badge-red tabular-nums">{{ $limit }} GB · {{ $limitPercent }}% · over</span>
                                        @elseif($limitPercent >= 90)
                                            <span class="badge badge-amber tabular-nums">{{ $limit }} GB · {{ $limitPercent }}%</span>
                                        @else
                                            <span class="badge badge-neutral tabular-nums">{{ $limit }} GB · {{ $limitPercent }}%</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted">No apps yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <p class="text-xs text-muted">Measured when this tab opens. Soft limit per app: <code>cipi app limits &lt;app&gt; --disk=&lt;GB&gt;</code> on the host — the monitor check <code>app_disk</code> warns at 90% and alerts when over; nothing is blocked.</p>
                    <button type="button" wire:click="refresh" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="refresh"><x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="refresh" /> Measure again</button>
                </div>
            </div>

            @if($diskDbsError)
                <x-cipi::alert type="warning" class="mb-4">Database sizes: {{ $diskDbsError }}</x-cipi::alert>
            @endif
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @forelse($diskDbs as $engine)
                    @php
                        $kind = $engine['engine'] ?? '';
                        $isValkey = $kind === 'valkey';
                        $isSearch = $kind === 'meilisearch';
                        $items = is_array($engine['databases'] ?? null) ? $engine['databases'] : [];
                    @endphp
                    <div class="card card-flush" wire:key="disk-engine-{{ $kind }}">
                        <div class="card-header p-5 mb-0">
                            <div>
                                <h2 class="card-title">{{ $this->engineLabel($kind) }}</h2>
                                <p class="card-subtitle">{{ count($items) }} {{ \Illuminate\Support\Str::plural($isSearch ? 'index' : 'database', count($items)) }}{{ !empty($engine['note']) ? ' · '.$engine['note'] : '' }}</p>
                            </div>
                            @if(isset($engine['on_disk_mb']))<span class="badge badge-neutral tabular-nums">{{ $this->diskMb($engine['on_disk_mb']) }} on disk</span>@endif
                        </div>
                        <table class="table-compact">
                            <thead><tr><th>{{ $isSearch ? 'Index' : 'Database' }}</th>@if($isValkey)<th class="text-right">Keys</th>@elseif($isSearch)<th class="text-right">Documents</th>@endif<th class="text-right">Size</th></tr></thead>
                            <tbody>
                                @forelse($items as $db)
                                    <tr>
                                        <td class="font-mono text-strong">{{ $db['name'] ?? '—' }}</td>
                                        @if($isValkey)<td class="text-right tabular-nums">{{ isset($db['keys']) ? number_format((int) $db['keys']) : '—' }}</td>@elseif($isSearch)<td class="text-right tabular-nums">{{ isset($db['documents']) ? number_format((int) $db['documents']) : '—' }}</td>@endif
                                        <td class="text-right tabular-nums">{{ $this->diskMb($db['size_mb'] ?? null) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">{{ $isSearch ? 'No indexes.' : ($isValkey ? 'No keys stored.' : 'No databases.') }}</td></tr>
                                @endforelse
                                @if($isValkey && isset($engine['memory_mb']))
                                    <tr><td class="text-xs text-muted" colspan="2">Memory in use</td><td class="text-right tabular-nums text-xs text-muted">{{ $this->diskMb($engine['memory_mb']) }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                @empty
                    @unless($diskDbsError)<div class="card text-muted">No database engine found.</div>@endunless
                @endforelse
            </div>

        @elseif($activeTab === 'php')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card card-flush lg:col-span-2">
                    <div class="card-header p-5 mb-0"><div><h2 class="card-title">Installed PHP versions</h2><p class="card-subtitle">Each app picks its own version; switching is per app from its configuration.</p></div></div>
                    <table>
                        <thead><tr><th>Version</th><th>FPM</th><th>Apps</th><th></th></tr></thead>
                        <tbody>
                            @forelse($phpData['versions'] as $row)
                                <tr>
                                    <td class="font-semibold text-strong">PHP {{ $row['version'] }}</td>
                                    <td><span class="badge {{ ($row['status'] ?? '') === 'running' ? 'badge-green' : 'badge-amber' }}">{{ $row['status'] ?? 'unknown' }}</span></td>
                                    <td class="tabular-nums">{{ $row['apps'] ?? 0 }}</td>
                                    <td class="text-right">@if(!empty($row['default']) || ($phpData['default'] ?? null) === $row['version'])<span class="badge badge-accent">CLI default</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted">No PHP versions detected.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <div class="card-header"><div><h2 class="card-title">Install PHP</h2><p class="card-subtitle">FPM, CLI and the extensions Laravel needs. Takes a few minutes.</p></div></div>
                    @if($phpInstallVersion === '')
                        <p class="text-muted">Every installable version ({{ implode(', ', $phpData['installable'] ?: ['8.3', '8.4', '8.5']) }}) is already installed.</p>
                    @else
                        <form wire:submit="installPhp" class="space-y-3">
                            <select wire:model="phpInstallVersion" aria-label="PHP version">
                                @foreach(array_diff($phpData['installable'] ?: ['8.3', '8.4', '8.5'], array_column($phpData['versions'], 'version')) as $ver)
                                    <option value="{{ $ver }}">PHP {{ $ver }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-primary w-full">Install</button>
                        </form>
                    @endif
                    <p class="field-hint mt-3">Removing a version and changing the CLI default stay on the host (<code>cipi php</code>).</p>
                </div>
            </div>

        @elseif($activeTab === 'node')
            <div class="card card-flush">
                <div class="card-header p-5 mb-0"><div><h2 class="card-title">Node runtimes</h2><p class="card-subtitle">Read-only: install, upgrade or change the default with <code>cipi node</code> on the host.</p></div></div>
                <table>
                    <thead><tr><th>Major</th><th>Version</th><th>Apps</th><th></th></tr></thead>
                    <tbody>
                        @forelse($nodeRuntimes as $runtime)
                            <tr>
                                <td class="font-semibold text-strong">Node {{ $runtime['major'] }}</td>
                                <td class="font-mono text-xs">{{ $runtime['version'] ?? '—' }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @forelse((array) ($runtime['apps'] ?? []) as $nodeApp)
                                            <a href="{{ route('cipi-gui.apps.show', ['name' => $nodeApp, 'server' => $server->id]) }}" class="badge badge-neutral">{{ $nodeApp }}</a>
                                        @empty
                                            <span class="text-subtle text-xs">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="text-right">@if(!empty($runtime['default']))<span class="badge badge-accent">Server default</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No Node runtimes installed.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif($activeTab === 'engines')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @forelse($enginesData['engines'] ?? [] as $engine)
                    @php $ready = in_array($engine['status'] ?? '', ['installed', 'running'], true); @endphp
                    <div class="card">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="card-title">{{ $this->engineLabel($engine['engine'] ?? null) }}</h2>
                                <p class="card-subtitle">{{ $ready ? 'Installed'.(!empty($engine['port']) ? ' · port '.$engine['port'] : '') : 'Not installed' }}</p>
                            </div>
                            <div class="flex gap-1">
                                @if(!empty($engine['default']) || ($enginesData['default'] ?? null) === ($engine['engine'] ?? null))<span class="badge badge-accent">Default</span>@endif
                                <span class="badge {{ $ready ? 'badge-green' : 'badge-gray' }}">{{ $engine['status'] ?? '' }}</span>
                            </div>
                        </div>
                        @unless($ready)
                            <button type="button" wire:click="installEngine('{{ $engine['engine'] }}')" wire:confirm="Install {{ $this->engineLabel($engine['engine']) }} on this server?" class="btn btn-primary btn-sm mt-4">Install</button>
                        @endunless
                    </div>
                @empty
                    <div class="card text-muted">No engine information.</div>
                @endforelse
            </div>
            <p class="field-hint mt-3">Per-database sizes: <button type="button" wire:click="setTab('disk')" class="text-link">Disk tab</button> (API 1.33+).</p>

        @elseif($activeTab === 'services')
            <div class="card card-flush">
                <table>
                    <thead><tr><th>Service</th><th>State</th><th>Since</th><th></th></tr></thead>
                    <tbody>
                        @forelse($services as $svc)
                            @php $state = $svc['status'] ?? 'unknown'; @endphp
                            <tr>
                                <td class="font-mono text-strong">{{ $svc['name'] }}</td>
                                <td><span class="badge {{ $state === 'running' ? 'badge-green' : ($state === 'not_installed' ? 'badge-gray' : 'badge-red') }}">{{ str_replace('_', ' ', $state) }}</span></td>
                                <td class="text-xs text-muted">{{ $svc['since'] ?? '—' }}</td>
                                <td class="text-right"><button type="button" wire:click="restartService(@js($svc['name']))" wire:confirm="Restart {{ $svc['name'] }}?" class="btn btn-secondary btn-sm" @disabled($state === 'not_installed')><x-cipi::icon name="refresh" /> Restart</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No services returned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif($activeTab === 'health')
            <div class="card card-flush">
                <div class="card-header p-5 mb-0"><div><h2 class="card-title">HTTP healthchecks</h2><p class="card-subtitle">Every 5 minutes; three failures in a row notify <code>health_fail</code>. Configure them per app.</p></div></div>
                <table>
                    <thead><tr><th>App</th><th>URL</th><th>Expect</th><th>State</th></tr></thead>
                    <tbody>
                        @forelse($healthChecks as $h)
                            <tr>
                                <td><a href="{{ route('cipi-gui.apps.show', ['name' => $h['app'], 'server' => $server->id]) }}" class="row-link">{{ $h['app'] }}</a></td>
                                <td class="font-mono text-xs break-all">{{ $h['url'] ?? '' }}</td>
                                <td class="tabular-nums">{{ $h['expect'] ?? 200 }}</td>
                                <td>
                                    @php $hs = $h['state'] ?? null; @endphp
                                    <span class="badge {{ $hs === 'ok' ? 'badge-green' : ($hs === 'fail' ? 'badge-red' : 'badge-gray') }}">{{ $hs ? strtoupper($hs) : 'Pending' }}</span>
                                    @if(($h['failcount'] ?? 0) > 0)<span class="text-xs text-danger ml-1">{{ $h['failcount'] }} fails</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No healthchecks yet — enable one from an app's overview.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif($activeTab === 'monitor')
            <div class="card card-flush">
                <div class="card-header p-5 mb-0">
                    <div>
                        <h2 class="card-title">System monitor</h2>
                        <p class="card-subtitle">Runs every 5 minutes, alerts on state changes{{ isset($monitor['reminder_minutes']) ? ' and reminds every '.$monitor['reminder_minutes'].' min while failing' : '' }}. Tune with <code>cipi monitor set</code>.</p>
                    </div>
                </div>
                <table>
                    <thead><tr><th>Check</th><th>State</th><th>Thresholds</th><th>Last alert</th></tr></thead>
                    <tbody>
                        @forelse((array) ($monitor['checks'] ?? []) as $check)
                            @php $config = is_array($check['config'] ?? null) ? $check['config'] : []; @endphp
                            <tr class="{{ ($config['enabled'] ?? true) ? '' : 'opacity-60' }}">
                                <td class="font-mono text-strong">{{ $check['check'] ?? '—' }}</td>
                                <td><span class="badge {{ $this->monitorStateClass($check['state'] ?? null) }}">{{ $check['state'] ?? 'pending' }}</span>@if(! ($config['enabled'] ?? true))<span class="badge badge-gray ml-1">disabled</span>@endif</td>
                                <td class="text-xs text-muted font-mono">
                                    {{ collect($config)->except('enabled')->map(fn ($v, $k) => $k.'='.(is_bool($v) ? ($v ? 'on' : 'off') : $v))->implode(' · ') ?: '—' }}
                                </td>
                                <td class="text-xs text-muted">{{ !empty($check['last_alert']) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $check['last_alert'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No checks returned.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif($activeTab === 'smtp')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card lg:col-span-2">
                    <div class="card-header">
                        <div><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="mail" /> Email notifications</h2><p class="card-subtitle">Deploys, healthchecks, monitor alerts, security events. The password is never sent back.</p></div>
                        @if(!empty($smtp['configured']))
                            <span class="badge {{ !empty($smtp['enabled']) ? 'badge-green' : 'badge-amber' }}">{{ !empty($smtp['enabled']) ? 'Enabled' : 'Paused' }}</span>
                        @else
                            <span class="badge badge-gray">Not configured</span>
                        @endif
                    </div>
                    <form wire:submit="saveSmtp" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <label for="smtp-host">SMTP host</label>
                                <input id="smtp-host" type="text" wire:model="smtpHost" placeholder="smtp.example.com" autocomplete="off">
                                @error('smtpHost') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="smtp-port">Port</label>
                                <input id="smtp-port" type="number" wire:model="smtpPort" min="1" max="65535">
                                @error('smtpPort') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="smtp-user">Username</label>
                                <input id="smtp-user" type="text" wire:model="smtpUser" autocomplete="off">
                                @error('smtpUser') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="smtp-pass">Password</label>
                                <input id="smtp-pass" type="password" wire:model="smtpPassword" autocomplete="new-password" placeholder="{{ !empty($smtp['configured']) ? 'Unchanged' : '' }}">
                                @error('smtpPassword') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="smtp-from">From</label>
                                <input id="smtp-from" type="email" wire:model="smtpFrom" placeholder="cipi@example.com">
                                @error('smtpFrom') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="smtp-to">Send alerts to</label>
                                <input id="smtp-to" type="email" wire:model="smtpTo" placeholder="ops@example.com">
                                @error('smtpTo') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <label class="check"><input type="checkbox" wire:model="smtpTls"> STARTTLS / TLS</label>
                            <label class="check"><input type="checkbox" wire:model="smtpEnabled"> Enabled</label>
                            <label class="check"><input type="checkbox" wire:model="smtpSendTest"> Send a test email</label>
                            <button type="submit" class="btn btn-primary ml-auto">Save</button>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Actions</h2></div>
                    @if(!empty($smtp['configured']))
                        <div class="space-y-2">
                            <button type="button" wire:click="testSmtp" class="btn btn-secondary w-full">Send test email</button>
                            @if(!empty($smtp['enabled']))
                                <button type="button" wire:click="disableSmtp" class="btn btn-secondary w-full">Pause notifications</button>
                            @else
                                <button type="button" wire:click="enableSmtp" class="btn btn-primary w-full">Resume notifications</button>
                            @endif
                            <button type="button" wire:click="deleteSmtp" wire:confirm="Remove the SMTP configuration from this server?" class="btn btn-ghost danger w-full">Remove configuration</button>
                        </div>
                    @else
                        <p class="text-muted">Save a configuration first.</p>
                    @endif
                    <p class="field-hint mt-4">Slack, Discord, Telegram, ntfy and webhook channels, plus per-event filters, are set on the host with <code>cipi notifications</code>.</p>
                </div>
            </div>

        @elseif($activeTab === 'ssh')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card card-flush lg:col-span-2">
                    <div class="card-header p-5 mb-0"><div><h2 class="card-title">Authorized keys — cipi user</h2><p class="card-subtitle">App users have their own SSH access (<code>cipi ssh apps</code>).</p></div></div>
                    <table>
                        <thead><tr><th>#</th><th>Key</th><th>Fingerprint</th><th></th></tr></thead>
                        <tbody>
                            @forelse($sshKeys as $key)
                                <tr>
                                    <td class="font-mono text-xs text-subtle">{{ $key['id'] }}</td>
                                    <td><p class="font-medium text-strong">{{ $key['comment'] ?: '—' }}</p><p class="text-2xs text-subtle font-mono">{{ $key['type'] }}</p></td>
                                    <td class="font-mono text-2xs text-muted break-all">{{ $key['fingerprint'] }}</td>
                                    <td class="text-right">
                                        @if(!empty($key['current_session']))
                                            <span class="badge badge-accent" title="Used by the current SSH session — cannot be removed from here">In use</span>
                                        @else
                                            <button type="button" wire:click="removeSshKey({{ (int) $key['id'] }})" wire:confirm="Remove the key {{ $key['comment'] ?: '#'.$key['id'] }}?" class="btn btn-ghost btn-sm danger">Remove</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted">No keys.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Add a key</h2></div>
                    <form wire:submit="addSshKey" class="space-y-3">
                        <textarea wire:model="sshKey" rows="5" placeholder="ssh-ed25519 AAAA… you@laptop" class="font-mono text-xs" aria-label="Public key"></textarea>
                        @error('sshKey') <p class="field-error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn-primary w-full">Add key</button>
                    </form>
                </div>
            </div>

        @elseif($activeTab === 'search')
            <div class="card">
                <div class="card-header">
                    <div><h2 class="card-title">Meilisearch</h2><p class="card-subtitle">Install, upgrade and key rotation stay on the host (<code>cipi search</code>); enable it per Laravel app.</p></div>
                    <span class="badge {{ !empty($search['installed']) ? (!empty($search['running']) ? 'badge-green' : 'badge-amber') : 'badge-gray' }}">{{ !empty($search['installed']) ? (!empty($search['running']) ? 'Running' : 'Stopped') : 'Not installed' }}</span>
                </div>
                @if(!empty($search['installed']))
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                        <div><p class="stat-label">Version</p><p class="stat-value-sm">{{ $search['version'] ?? '—' }}</p></div>
                        <div><p class="stat-label">Health</p><p class="stat-value-sm">{{ $search['health'] ?? '—' }}</p></div>
                        <div><p class="stat-label">Data</p><p class="stat-value-sm">{{ $search['data_size'] ?? '—' }}</p></div>
                        <div><p class="stat-label">Listen</p><p class="stat-value-sm font-mono text-sm">{{ ($search['host'] ?? '127.0.0.1').':'.($search['port'] ?? 7700) }}</p></div>
                    </div>
                    @php $searchApps = is_array($search['apps'] ?? null) ? $search['apps'] : []; @endphp
                    <p class="label">Apps using it</p>
                    @forelse($searchApps as $name => $meta)
                        <div class="list-row"><a href="{{ route('cipi-gui.apps.show', ['name' => $name, 'server' => $server->id]) }}" class="row-link">{{ $name }}</a>@if(is_array($meta) && !empty($meta['prefix']))<span class="font-mono text-xs text-muted">index prefix {{ $meta['prefix'] }}*</span>@endif</div>
                    @empty
                        <p class="text-muted">No app has search enabled yet.</p>
                    @endforelse
                @else
                    <p class="text-muted">Run <code>cipi search install</code> on the host, then enable it from a Laravel app's overview.</p>
                @endif
            </div>

        @elseif($activeTab === 'packages')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @forelse($packages as $pkg)
                    <div class="card">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="card-title font-mono">{{ $pkg['id'] ?? '—' }}</h2>
                                <p class="card-subtitle">{{ $pkg['description'] ?? '' }}</p>
                            </div>
                            <span class="badge {{ !empty($pkg['installed']) ? 'badge-green' : (!empty($pkg['partial']) ? 'badge-amber' : 'badge-gray') }}">{{ !empty($pkg['installed']) ? 'Installed' : (!empty($pkg['partial']) ? 'Partial' : 'Not installed') }}</span>
                        </div>
                        @if(!empty($pkg['packages']))<p class="font-mono text-2xs text-subtle mt-3">apt: {{ implode(' ', $pkg['packages']) }}</p>@endif
                    </div>
                @empty
                    <div class="card text-muted">No packages returned.</div>
                @endforelse
            </div>
            <p class="field-hint mt-3">Read-only by design: <code>cipi package install &lt;id&gt;</code> on the host.</p>

        @elseif($activeTab === 'zt')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card">
                    <div class="card-header">
                        <div><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="cloud" /> Cloudflare Zero Trust</h2><p class="card-subtitle">Status only — every change stays with the operator on the CLI (<code>cipi zt</code>).</p></div>
                        <span class="badge {{ !empty($zt['enabled']) ? 'badge-green' : 'badge-gray' }}">{{ !empty($zt['enabled']) ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <dl class="kv">
                        <dt>cloudflared</dt><dd>{{ $zt['cloudflared'] ?? '—' }}</dd>
                        <dt>Tunnel</dt><dd class="font-mono text-xs">{{ $zt['tunnel'] ?? '—' }}</dd>
                        <dt>Real IP header</dt><dd class="font-mono text-xs">{{ $zt['real_ip'] ?? '—' }}</dd>
                        <dt>HTTP lock</dt><dd>{{ in_array($zt['lock_http'] ?? null, ['true', true, '1'], true) ? 'On — only Cloudflare reaches 80/443' : 'Off' }}</dd>
                        <dt>SSH lock</dt><dd>{{ in_array($zt['lock_ssh'] ?? null, ['true', true, '1'], true) ? 'On' : 'Off' }}</dd>
                        <dt>SSH hostname</dt><dd class="font-mono text-xs">{{ $zt['ssh_hostname'] ?? '—' }}</dd>
                    </dl>
                </div>
                @if(!empty($zt['raw']))
                    <div>@include('cipi-gui::partials.terminal', ['lines' => explode("\n", (string) $zt['raw']), 'title' => 'cipi zt status', 'autoScroll' => false])</div>
                @endif
            </div>

        @elseif($activeTab === 'ip')
            @php $entries = is_array($ipWhitelist['entries'] ?? null) ? $ipWhitelist['entries'] : []; @endphp
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card lg:col-span-2">
                    <div class="card-header">
                        <div><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="shield" /> API IP whitelist</h2><p class="card-subtitle">Who may call <code>/api</code> and <code>/mcp</code> on this server.</p></div>
                        <span class="badge {{ !empty($ipWhitelist['allow_all']) ? 'badge-amber' : 'badge-green' }}">{{ !empty($ipWhitelist['allow_all']) ? 'Open to any IP' : 'Restricted' }}</span>
                    </div>
                    @if(!empty($ipWhitelist['client_ip']))
                        <x-cipi::alert type="info" class="mb-4">This panel calls the API from <code>{{ $ipWhitelist['client_ip'] }}</code>. Cipi refuses to drop it — you cannot lock yourself out from here.</x-cipi::alert>
                    @endif
                    <ul>
                        @forelse($entries as $entry)
                            <li class="list-row">
                                <span class="font-mono text-strong">{{ $entry }}@if(($ipWhitelist['client_ip'] ?? null) === $entry)<span class="badge badge-accent ml-2">this panel</span>@endif</span>
                                @if($entry !== '*' && ($ipWhitelist['client_ip'] ?? null) !== $entry)
                                    <button type="button" wire:click="removeIpWhitelistEntry(@js($entry))" wire:confirm="Remove {{ $entry }}?" class="btn btn-ghost btn-sm danger">Remove</button>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted">No entries.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Allow an address</h2></div>
                    <form wire:submit="addIpWhitelistEntry" class="space-y-3">
                        <input type="text" wire:model="ipWhitelistEntry" placeholder="203.0.113.10 or 10.0.0.0/8" class="font-mono" aria-label="IP or CIDR">
                        @error('ipWhitelistEntry') <p class="field-error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn-primary w-full">Add</button>
                    </form>
                    @if(!empty($entries) && empty($ipWhitelist['allow_all']))
                        <button type="button" wire:click="allowAllIpWhitelist" wire:confirm="Allow every client IP to call the API?" class="btn btn-ghost w-full mt-3">Allow all IPs</button>
                    @endif
                    <p class="field-hint mt-3">Stored in <code>{{ $ipWhitelist['file'] ?? '/etc/cipi/api-ip-whitelist' }}</code>.</p>
                </div>
            </div>
        @endif
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
