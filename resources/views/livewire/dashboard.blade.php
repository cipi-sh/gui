<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-white">Dashboard</h2>
            <p class="text-sm text-surface-400 mt-1">Overview of your Cipi servers</p>
        </div>
        <button wire:click="refresh" class="btn btn-secondary btn-sm">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" /></svg>
            Refresh
        </button>
    </div>

    @if($servers->isEmpty())
        <div class="card text-center py-12">
            <svg class="h-12 w-12 mx-auto text-surface-600 mb-4" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 17.25v-.228a4.5 4.5 0 0 0-.12-1.03l-2.268-9.64a3.375 3.375 0 0 0-3.285-2.602H7.923a3.375 3.375 0 0 0-3.285 2.602l-2.268 9.64a4.5 4.5 0 0 0-.12 1.03v.228m19.5 0a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3m19.5 0a3 3 0 0 0-3-3H5.25a3 3 0 0 0-3 3m16.5 0h.008v.008h-.008v-.008Zm-3 0h.008v.008h-.008v-.008Z" /></svg>
            <h3 class="text-lg font-medium text-white mb-2">No servers configured</h3>
            <p class="text-sm text-surface-400 mb-4">Add your first Cipi server to get started.</p>
            <a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Add Server</a>
        </div>
    @else
        <div class="space-y-4">
            @foreach($serverStatuses as $id => $entry)
                @php
                    $server = $entry['server'];
                    $status = $entry['status'];
                    $error = $entry['error'];
                    $displayIp = $server->ip;
                    if (! $displayIp && is_array($status)) {
                        $displayIp = $status['system']['ip'] ?? $status['system']['ipv4'] ?? null;
                    }
                @endphp
                <div class="card card-hover cursor-pointer w-full" wire:click="selectServer({{ $id }})">
                    <div class="flex flex-col lg:flex-row lg:items-center gap-4 lg:gap-8">
                        <div class="flex items-start justify-between gap-4 lg:flex-col lg:items-start server-card-info flex-shrink-0">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-white">{{ $server->name }}</h3>
                                <p class="text-xs text-surface-500 truncate mt-1">{{ $server->url }}</p>
                                @if($displayIp)
                                    <p class="text-xs text-surface-500 font-mono truncate mt-1">{{ $displayIp }}</p>
                                @endif
                            </div>
                            @if($error)
                                <span class="badge badge-red flex-shrink-0">Offline</span>
                            @else
                                <span class="badge badge-green flex-shrink-0">Online</span>
                            @endif
                        </div>

                        @if($error)
                            <p class="text-sm text-red-400 flex-1">{{ $error }}</p>
                        @elseif($status)
                            <div class="server-card-metrics">
                                <div>
                                    <p class="text-xs text-surface-500 uppercase">CPU</p>
                                    <p class="text-lg font-semibold text-white">{{ $status['resources']['cpu']['usage_percent'] ?? '—' }}%</p>
                                    <div class="progress-bar mt-1">
                                        <div class="progress-fill" style="width:{{ $status['resources']['cpu']['usage_percent'] ?? 0 }}%"></div>
                                    </div>
                                </div>
                                @php
                                    $disk = $status['resources']['disk'] ?? null;
                                    $diskPercent = $disk['usage_percent'] ?? null;
                                    $diskUsed = $disk['used'] ?? '';
                                    $diskTotal = $disk['total'] ?? '';
                                    if (($diskUsed === '' || $diskTotal === '') && ! empty($disk['display']) && preg_match('/^(\S+)\/(\S+)\s*\((\d+)%\)/', $disk['display'], $diskMatch)) {
                                        $diskUsed = $diskUsed !== '' ? $diskUsed : $diskMatch[1];
                                        $diskTotal = $diskTotal !== '' ? $diskTotal : $diskMatch[2];
                                        $diskPercent = $diskPercent ?? (int) $diskMatch[3];
                                    }
                                @endphp
                                <div>
                                    <p class="text-xs text-surface-500 uppercase">RAM</p>
                                    <p class="text-lg font-semibold text-white">{{ $status['resources']['memory']['usage_percent'] ?? '—' }}%</p>
                                    <div class="progress-bar mt-1">
                                        <div class="progress-fill progress-fill-alt" style="width:{{ $status['resources']['memory']['usage_percent'] ?? 0 }}%"></div>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-surface-500 uppercase">Disk</p>
                                    @if($diskPercent !== null)
                                        <div class="flex items-baseline gap-2 min-w-0">
                                            <p class="text-lg font-semibold text-white shrink-0">{{ $diskPercent }}%</p>
                                            @if($diskUsed !== '' && $diskTotal !== '')
                                                <p class="text-xs text-surface-400 truncate">{{ $diskUsed }} / {{ $diskTotal }}</p>
                                            @endif
                                        </div>
                                        <div class="progress-bar mt-1">
                                            <div class="progress-fill" style="width:{{ $diskPercent }}%"></div>
                                        </div>
                                    @else
                                        <p class="text-sm font-medium text-white">{{ $disk['display'] ?? '—' }}</p>
                                    @endif
                                </div>
                                <div>
                                    <p class="text-xs text-surface-500 uppercase">Apps</p>
                                    <p class="text-lg font-semibold text-white">{{ $status['apps'] ?? 0 }}</p>
                                </div>
                            </div>
                        @endif
                    </div>

                    @if(!$error && $status)
                        @if(isset($status['system']))
                            <div class="server-card-system mt-4 pt-4 border-t border-surface-800 text-xs text-surface-500">
                                <p>{{ $status['system']['hostname'] ?? '' }} · {{ $status['system']['os'] ?? '' }}</p>
                                <p>Cipi {{ $status['system']['cipi'] ?? '?' }} · {{ $status['system']['uptime'] ?? '' }}</p>
                            </div>
                        @endif

                        @if(isset($status['services']))
                            <div class="server-card-services flex flex-wrap mt-4">
                                @foreach($status['services'] as $service => $state)
                                    <span class="badge {{ $state === 'running' ? 'badge-green' : 'badge-red' }}">{{ $service }}</span>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
