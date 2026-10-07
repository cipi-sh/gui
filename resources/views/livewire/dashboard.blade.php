<div wire:init="loadStatuses">
    <x-cipi::page-header title="Dashboard" :subtitle="$hasServers ? 'Live status of every connected Cipi server.' : 'Connect your first Cipi server to get started.'">
        @if($hasServers)
            <x-slot:actions>
                @if($checkedAt)
                    <span class="text-xs text-subtle">Updated {{ $checkedAt }}</span>
                @endif
                <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="btn btn-secondary">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="refresh,loadStatuses" />
                    Refresh
                </button>
            </x-slot:actions>
        @endif
    </x-cipi::page-header>

    @if(! $hasServers)
        <div class="card">
            <x-cipi::empty icon="servers" title="No servers connected yet">
                The panel manages your servers through the Cipi REST API. Enable it on a server with
                cipi api, create a token, then add the connection here.
                <x-slot:actions>
                    <a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary"><x-cipi::icon name="plus" /> Connect a server</a>
                    <a href="https://cipi.sh/docs/gui#gui-requirements" target="_blank" rel="noopener" class="btn btn-secondary">Read the setup guide</a>
                </x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        {{-- Fleet summary --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="card">
                <p class="stat-label">Servers online</p>
                @if($loaded)
                    <p class="stat-value mt-1">{{ $summary['online'] }}<span class="text-muted text-lg"> / {{ $servers->count() }}</span></p>
                    <p class="stat-meta">{{ $servers->count() - $summary['online'] > 0 ? ($servers->count() - $summary['online']).' unreachable' : 'All reachable' }}</p>
                @else
                    <div class="skeleton h-8 w-24 mt-2"></div>
                @endif
            </div>
            <div class="card">
                <p class="stat-label">Apps</p>
                @if($loaded)
                    <p class="stat-value mt-1">{{ $summary['apps'] }}</p>
                    <p class="stat-meta">across {{ $summary['online'] }} {{ \Illuminate\Support\Str::plural('server', $summary['online']) }}</p>
                @else
                    <div class="skeleton h-8 w-24 mt-2"></div>
                @endif
            </div>
            <div class="card">
                <p class="stat-label">Services running</p>
                @if($loaded)
                    <p class="stat-value mt-1">{{ $summary['services_up'] }}<span class="text-muted text-lg"> / {{ $summary['services'] }}</span></p>
                    <p class="stat-meta {{ $summary['services_up'] < $summary['services'] ? 'text-danger' : '' }}">{{ $summary['services_up'] < $summary['services'] ? 'Some services are down' : 'nginx, databases, workers, fail2ban' }}</p>
                @else
                    <div class="skeleton h-8 w-24 mt-2"></div>
                @endif
            </div>
            <div class="card">
                <p class="stat-label">Cipi version</p>
                @if($loaded)
                    <p class="stat-value mt-1">{{ $summary['versions'] ? end($summary['versions']) : '—' }}</p>
                    <p class="stat-meta">{{ count($summary['versions']) > 1 ? 'Mixed: '.implode(', ', $summary['versions']) : 'Same on every server' }}</p>
                @else
                    <div class="skeleton h-8 w-24 mt-2"></div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            @foreach($servers as $server)
                @php
                    $entry = $serverStatuses[$server->id] ?? null;
                    $status = $entry['status'] ?? null;
                    $error = $entry['error'] ?? null;
                    $disk = $this->disk($status);
                    $cpu = isset($status['resources']['cpu']['usage_percent']) ? (int) $status['resources']['cpu']['usage_percent'] : null;
                    $mem = $status['resources']['memory'] ?? [];
                    $memPercent = isset($mem['usage_percent']) ? (int) $mem['usage_percent'] : null;
                    $services = (array) ($status['services'] ?? []);
                    $down = array_keys(array_filter($services, fn ($s) => $s !== 'running'));
                    $ip = $server->ip ?: ($status['system']['ip'] ?? null);
                @endphp
                <div class="card card-hover" wire:key="server-{{ $server->id }}">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="dot {{ ! $loaded ? '' : ($error ? 'dot-red' : ($down ? 'dot-amber' : 'dot-green')) }}"></span>
                                <h2 class="text-lg font-semibold truncate">{{ $server->name }}</h2>
                            </div>
                            <p class="text-xs text-muted mt-0.5 truncate">
                                {{ $server->host }}@if($ip) · <span class="font-mono">{{ $ip }}</span>@endif
                            </p>
                        </div>
                        @if(! $loaded)
                            <span class="badge"><span class="spinner" style="width:.6rem;height:.6rem;border-width:1.5px"></span> Checking</span>
                        @elseif($error)
                            <span class="badge badge-red">Unreachable</span>
                        @elseif($down)
                            <span class="badge badge-amber">{{ count($down) }} down</span>
                        @else
                            <span class="badge badge-green">Healthy</span>
                        @endif
                    </div>

                    @if(! $loaded)
                        <div class="server-card-metrics">
                            @foreach(['CPU', 'Memory', 'Disk', 'Apps'] as $label)
                                <div><p class="stat-label">{{ $label }}</p><div class="skeleton h-6 w-12 mt-1"></div></div>
                            @endforeach
                        </div>
                    @elseif($error)
                        <x-cipi::alert type="danger">{{ $error }}</x-cipi::alert>
                        <div class="flex gap-2 mt-4">
                            <a href="{{ route('cipi-gui.servers') }}" class="btn btn-secondary btn-sm">Check connection</a>
                        </div>
                    @else
                        <div class="server-card-metrics">
                            <div>
                                <div class="metric-row"><span class="stat-label">CPU</span><span class="stat-value-sm tabular-nums">{{ $cpu ?? '—' }}%</span></div>
                                <div class="progress-bar"><div class="progress-fill {{ $this->meterClass($cpu) }}" style="width: {{ $cpu ?? 0 }}%"></div></div>
                            </div>
                            <div>
                                <div class="metric-row"><span class="stat-label">Memory</span><span class="stat-value-sm tabular-nums">{{ $memPercent ?? '—' }}%</span></div>
                                <div class="progress-bar"><div class="progress-fill {{ $this->meterClass($memPercent) }}" style="width: {{ $memPercent ?? 0 }}%"></div></div>
                                @if(isset($mem['used_mb'], $mem['total_mb']))
                                    <p class="stat-meta mt-1 tabular-nums">{{ number_format($mem['used_mb'] / 1024, 1) }} / {{ number_format($mem['total_mb'] / 1024, 1) }} GB</p>
                                @endif
                            </div>
                            <div>
                                <div class="metric-row"><span class="stat-label">Disk</span><span class="stat-value-sm tabular-nums">{{ $disk['percent'] ?? '—' }}%</span></div>
                                <div class="progress-bar"><div class="progress-fill {{ $this->meterClass($disk['percent']) }}" style="width: {{ $disk['percent'] ?? 0 }}%"></div></div>
                                @if($disk['used'] !== '' && $disk['total'] !== '')
                                    <p class="stat-meta mt-1 tabular-nums">{{ $disk['used'] }} / {{ $disk['total'] }}</p>
                                @endif
                            </div>
                            <div>
                                <div class="metric-row"><span class="stat-label">Apps</span><span class="stat-value-sm tabular-nums">{{ $status['apps'] ?? 0 }}</span></div>
                                @if(!empty($status['php']) && is_array($status['php']))
                                    <p class="stat-meta">PHP {{ collect($status['php'])->pluck('version')->filter()->implode(' · ') }}</p>
                                @endif
                            </div>
                        </div>

                        @if($services)
                            <div class="flex flex-wrap gap-1.5 mt-4">
                                @foreach($services as $service => $state)
                                    <span class="badge {{ $state === 'running' ? 'badge-neutral' : 'badge-red' }}" title="{{ $service }}: {{ $state }}">
                                        <span class="dot {{ $state === 'running' ? 'dot-green' : 'dot-red' }}" style="width:.4rem;height:.4rem;box-shadow:none"></span>{{ $service }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3 mt-4 pt-4 border-t">
                            <p class="text-xs text-muted">
                                {{ $status['system']['os'] ?? '' }}
                                @if(!empty($status['system']['cipi'])) · Cipi {{ $status['system']['cipi'] }} @endif
                                @if(!empty($status['system']['uptime'])) · {{ preg_replace('/^up\s+/', 'up ', \Illuminate\Support\Str::before($status['system']['uptime'], ',')) }} @endif
                            </p>
                            <div class="btn-group">
                                <button type="button" wire:click="open({{ $server->id }}, 'apps')" class="btn btn-secondary btn-sm"><x-cipi::icon name="apps" /> Apps</button>
                                <button type="button" wire:click="open({{ $server->id }}, 'server')" class="btn btn-secondary btn-sm"><x-cipi::icon name="server" /> Manage</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
