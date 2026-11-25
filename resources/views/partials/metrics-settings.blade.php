{{-- Collector settings of the server's Metrics tab: retention, probe, heartbeat, OpenMetrics. --}}
@php
    $st = $settings;
    $db = (array) ($st['database'] ?? []);
    $hb = (array) ($st['heartbeat'] ?? []);
    $om = (array) ($st['openmetrics'] ?? []);
@endphp
<div class="card" data-metrics-settings>
    <div class="card-header">
        <div>
            <h2 class="card-title">Collection &amp; retention</h2>
            <p class="card-subtitle">
                SQLite on the server, {{ number_format(((int) ($db['bytes'] ?? 0)) / 1048576, 1) }} MB ·
                {{ number_format((int) ($db['samples'] ?? 0)) }} minutes, {{ number_format((int) ($db['rollups'] ?? 0)) }} rollups, {{ number_format((int) ($db['events'] ?? 0)) }} events.
                It records; alerts stay with the monitor and the healthchecks.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="badge {{ ($st['enabled'] ?? true) ? 'badge-green' : 'badge-amber' }}">{{ ($st['enabled'] ?? true) ? 'Collecting' : 'Off' }}</span>
            @if($canManage)
                <button type="button" wire:click="toggleCollector" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="toggleCollector">{{ ($st['enabled'] ?? true) ? 'Turn off' : 'Turn on' }}</button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <form wire:submit="saveRetention" class="space-y-3 lg:col-span-2" @if(! $canManage) inert @endif>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div>
                    <label class="label" for="m-raw">Minutes, days</label>
                    <input id="m-raw" type="number" min="1" max="90" wire:model="rawDays" class="input">
                    @error('rawDays') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="m-res">Rollup every</label>
                    <select id="m-res" wire:model="resolutionMinutes">
                        @foreach([5, 10, 15, 30, 60] as $m)<option value="{{ $m }}">{{ $m }} min</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="m-roll">Rollups, days</label>
                    <input id="m-roll" type="number" min="1" max="400" wire:model="rollupDays" class="input">
                    @error('rollupDays') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="m-ev">Events, days</label>
                    <input id="m-ev" type="number" min="7" max="730" wire:model="eventsDays" class="input">
                    @error('eventsDays') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="field-hint">Every minute is kept for the first period, then an average and a peak per rollup until the second. A shorter retention removes older data at the next hourly prune. Defaults: 7 days, 15 minutes, 30 days, 90 days.</p>
            <div class="flex flex-wrap items-center gap-3">
                @if($canManage)<button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveRetention">Save retention</button>@endif
                <label class="check text-sm">
                    <input type="checkbox" @checked($st['probe'] ?? true) wire:click="toggleProbe" @disabled(! $canManage)>
                    Probe each app's healthcheck URL every minute
                </label>
            </div>
        </form>

        {{-- The heartbeat is a field like the retention's: its label on the line of theirs, the URL and the
             interval side by side on the line of their inputs, what it is under them, the buttons below. --}}
        <div class="space-y-5" data-metrics-heartbeat>
            <form wire:submit="saveHeartbeat" @if(! $canManage) inert @endif>
                <div class="flex items-start gap-2">
                    <div class="flex-1 min-w-0">
                        <label class="label" for="m-hb">Heartbeat URL</label>
                        <input id="m-hb" type="url" wire:model="heartbeatUrl" class="input" placeholder="https://hc-ping.com/…" autocomplete="off" @disabled(! $canManage)>
                    </div>
                    <div class="shrink-0">
                        <label class="label" for="m-hb-every">Every</label>
                        <select id="m-hb-every" wire:model="heartbeatEvery" class="w-auto" @disabled(! $canManage)>
                            @foreach([1, 2, 5, 10, 15, 30, 60] as $m)<option value="{{ $m }}">{{ $m }} min</option>@endforeach
                        </select>
                    </div>
                </div>
                @error('heartbeatUrl') <p class="field-error">{{ $message }}</p> @enderror
                <p class="field-hint">
                    @if(! empty($hb['enabled']))
                        <span class="badge {{ ($hb['last_ok'] ?? null) === false ? 'badge-red' : 'badge-green' }}">{{ ($hb['last_ok'] ?? null) === false ? 'last call HTTP '.($hb['last_code'] ?? '?') : (($hb['last_ok'] ?? null) === true ? 'On · last call ok' : 'On') }}</span>
                        Called every {{ (int) ($hb['every'] ?? 1) }} min → <span class="font-mono">{{ $hb['url_host'] ?? '?' }}</span>: when the calls stop, it alerts you — the one outage a server cannot report itself.
                    @else
                        Off. A URL called every few minutes — healthchecks.io, Uptime Kuma (push), Better Stack — alerts you when the calls stop: the one outage a server cannot report itself.
                    @endif
                </p>
                @if($heartbeatTest)
                    <p class="field-hint {{ ! empty($heartbeatTest['ok']) ? 'text-success' : 'text-danger' }}">Test: HTTP {{ $heartbeatTest['code'] ?? '?' }} ← {{ $heartbeatTest['host'] ?? '' }}{{ ! empty($heartbeatTest['error']) ? ' — '.$heartbeatTest['error'] : '' }}</p>
                @endif
                @if($canManage)
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        <button type="submit" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="saveHeartbeat">{{ ! empty($hb['enabled']) ? 'Change' : 'Set heartbeat' }}</button>
                        @if(! empty($hb['enabled']))
                            <button type="button" wire:click="testHeartbeat" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="testHeartbeat">Test</button>
                            <button type="button" wire:click="removeHeartbeat" class="btn btn-ghost btn-sm text-danger">Turn off</button>
                        @endif
                    </div>
                @endif
            </form>
            <div data-metrics-openmetrics>
                <p class="label">OpenMetrics</p>
                @if(! empty($om['enabled']))
                    <p class="field-hint"><span class="badge badge-green">On</span> Port {{ $om['port'] ?? 9273 }}, from <span class="font-mono">{{ implode(', ', (array) ($om['allow'] ?? [])) }}</span>.</p>
                @else
                    <p class="field-hint">Off. For an existing Prometheus, on the server: <code>cipi metrics openmetrics on --allow=&lt;IP&gt;</code> — a port and firewall rules, so not from the panel.</p>
                @endif
            </div>
        </div>
    </div>
</div>
