<div wire:init="loadDatabases">
    <x-cipi::page-header title="Databases">
        <x-slot:meta>
            @if($scopeServer)
                MariaDB and PostgreSQL databases on <strong class="text-strong">{{ $scopeServer->name }}</strong>.
                @if($multiServer)<button type="button" wire:click="setScope(0)" class="text-link">Show every server</button>@endif
            @elseif($multiServer)
                MariaDB and PostgreSQL databases on every connected server.
            @elseif($servers->isNotEmpty())
                MariaDB and PostgreSQL databases on <strong class="text-strong">{{ $servers->first()->name }}</strong>.
            @endif
        </x-slot:meta>
        @if($servers->isNotEmpty())
            <x-slot:actions>
                <button type="button" wire:click="loadDatabases" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="loadDatabases">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="loadDatabases" /> Refresh
                </button>
                @if($canCreate)
                    <button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> New database</button>
                @endif
            </x-slot:actions>
        @endif
    </x-cipi::page-header>

    @if($servers->isEmpty())
        <div class="card">
            @if($isAdmin)
                <x-cipi::empty icon="servers" title="No server connected">
                    <x-slot:actions><a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Connect a server</a></x-slot:actions>
                </x-cipi::empty>
            @else
                <x-cipi::empty icon="lock" title="No access yet">An admin of this panel has not given you a server yet.</x-cipi::empty>
            @endif
        </div>
    @else
        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif

        @if(! $loading && $serverErrors !== [])
            <x-cipi::alert type="danger" class="mb-4" :title="count($serverErrors) === 1 ? 'One server did not answer' : count($serverErrors).' servers did not answer'">
                <ul class="space-y-0.5">
                    @foreach($serverErrors as $id => $message)
                        <li><strong class="text-strong">{{ $servers->firstWhere('id', $id)?->name ?? 'Server '.$id }}</strong> — {{ $message }}</li>
                    @endforeach
                </ul>
                <div class="flex flex-wrap gap-2 mt-2">
                    <button type="button" wire:click="loadDatabases" class="btn btn-secondary btn-sm">Retry</button>
                    @if($isAdmin)<a href="{{ route('cipi-gui.servers') }}" class="btn btn-ghost btn-sm">Check connections</a>@endif
                </div>
            </x-cipi::alert>
        @endif

        @if(! $loading && $engineCards !== [])
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                @foreach($engineCards as $card)
                    <div class="card" wire:key="engine-card-{{ $card['engine'] }}">
                        <div class="flex items-center justify-between gap-2">
                            <p class="stat-label">{{ $this->engineLabel($card['engine']) }}</p>
                            @if($card['default'])
                                <span class="badge badge-accent">Default</span>
                            @endif
                        </div>
                        <p class="stat-value mt-1">{{ $counts[$card['engine']] ?? 0 }}</p>
                        <p class="stat-meta">
                            {{ \Illuminate\Support\Str::plural('database', $counts[$card['engine']] ?? 0) }}
                            @if($scopeId !== 0 && ! empty($card['port'])) · port {{ $card['port'] }}
                            @elseif($multiServer && $scopeId === 0 && $card['servers'] > 0) · on {{ $card['servers'] }} {{ \Illuminate\Support\Str::plural('server', $card['servers']) }}
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @elseif(! $loading && $enginesUnsupported !== [])
            <x-cipi::alert type="info" class="mb-4">Engine details need API 1.12+ (Cipi 4.8). Databases are listed without engine information.</x-cipi::alert>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex flex-wrap items-center gap-2">
                @if($multiServer)
                    <div class="segmented" role="tablist" aria-label="Filter by server">
                        <button type="button" role="tab" aria-selected="{{ $scopeId === 0 ? 'true' : 'false' }}" wire:click="setScope(0)" class="segmented-item {{ $scopeId === 0 ? 'active' : '' }}">
                            All servers<span class="count">{{ count($databases) }}</span>
                        </button>
                        @foreach($servers as $s)
                            <button type="button" role="tab" aria-selected="{{ $scopeId === $s->id ? 'true' : 'false' }}" wire:click="setScope({{ $s->id }})" class="segmented-item {{ $scopeId === $s->id ? 'active' : '' }}" title="{{ $s->host }}">
                                <span class="dot {{ isset($this->serverErrors[$s->id]) ? 'dot-red' : 'dot-green' }}" style="width:.4rem;height:.4rem;box-shadow:none;margin-right:.4rem;vertical-align:middle"></span>{{ $s->name }}<span class="count">{{ $serverCounts[$s->id] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <div class="segmented" role="tablist" aria-label="Filter by engine">
                    <button type="button" wire:click="setEngineFilter('all')" class="segmented-item {{ $engineFilter === 'all' ? 'active' : '' }}">All<span class="count">{{ $counts['all'] }}</span></button>
                    @foreach($engineCards as $card)
                        <button type="button" wire:click="setEngineFilter('{{ $card['engine'] }}')" class="segmented-item {{ $engineFilter === $card['engine'] ? 'active' : '' }}">{{ $this->engineLabel($card['engine']) }}<span class="count">{{ $counts[$card['engine']] ?? 0 }}</span></button>
                    @endforeach
                </div>
            </div>
            <div class="search-input w-full sm:w-auto" style="min-width: 14rem;">
                <x-cipi::icon name="search" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ $multiServer && $scopeId === 0 ? 'Search databases, servers…' : 'Search databases…' }}" aria-label="Search databases">
            </div>
        </div>

        @if($loading)
            <div class="card card-flush">
                @foreach(range(1, 3) as $i)
                    <div class="flex items-center gap-4 px-5 py-4 border-b"><div class="skeleton h-4 w-32"></div><div class="skeleton h-4 w-24"></div><div class="skeleton h-4 w-20 ml-auto"></div></div>
                @endforeach
            </div>
        @elseif($scopedCount === 0 && ($scopeId !== 0 ? isset($serverErrors[$scopeId]) : ($databases === [] && $serverErrors !== [])))
            <div class="card">
                <x-cipi::empty icon="warning" :title="$scopeServer ? 'Databases on '.$scopeServer->name.' could not be loaded' : 'Databases could not be loaded'">
                    The server did not answer — the reason is above.
                    <x-slot:actions><button type="button" wire:click="loadDatabases" class="btn btn-primary">Try again</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @elseif($scopedCount === 0)
            <div class="card">
                <x-cipi::empty icon="database" :title="$scopeServer ? 'No databases on '.$scopeServer->name.' yet' : 'No databases yet'">
                    Laravel apps get their own database automatically. @if($canCreate) Create extra ones here — credentials are shown once. @endif
                    @if($canCreate)
                        <x-slot:actions><button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> Create database</button></x-slot:actions>
                    @endif
                </x-cipi::empty>
            </div>
        @elseif(empty($visible))
            <div class="card"><x-cipi::empty icon="search" title="No databases match your filters" /></div>
        @else
            <div class="card card-flush">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>Database</th>@if($multiServer && $scopeId === 0)<th>Server</th>@endif<th>Engine</th><th>User</th><th class="text-right">Size</th><th>Last backup</th><th class="text-right">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach($visible as $db)
                                @php $engine = $db['engine'] ?? ''; $last = $backups[$db['server_id']][$db['name']] ?? null; @endphp
                                <tr wire:key="db-{{ $db['server_id'] }}-{{ $engine }}-{{ $db['name'] }}">
                                    <td class="font-mono font-semibold text-strong">{{ $db['name'] }}</td>
                                    @if($multiServer && $scopeId === 0)
                                        <td class="whitespace-nowrap"><button type="button" wire:click="setScope({{ $db['server_id'] }})" class="badge badge-neutral" title="Only the databases on {{ $db['server_name'] }}"><x-cipi::icon name="server" class="h-3 w-3" /> {{ $db['server_name'] }}</button></td>
                                    @endif
                                    <td><span class="badge {{ $engine === 'pgsql' ? 'badge-blue' : 'badge-neutral' }}">{{ $this->engineLabel($engine ?: null) }}</span></td>
                                    <td class="font-mono text-xs text-muted">{{ $db['user'] ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-soft">{{ $db['size'] ?? '—' }}</td>
                                    <td class="text-xs text-muted font-mono truncate" style="max-width: 16rem;" title="{{ $last ?? '' }}">{{ $last ? basename($last) : '—' }}</td>
                                    <td>
                                        @if($db['can_manage'])
                                            <div class="btn-actions">
                                                <button type="button" wire:click="backup(@js($db['name']), @js($engine), {{ $db['server_id'] }})" class="btn btn-ghost btn-sm" title="Dump to /home/cipi/backups"><x-cipi::icon name="download" /> Backup</button>
                                                <button type="button" wire:click="openRestore(@js($db['name']), @js($engine), {{ $db['server_id'] }})" class="btn btn-ghost btn-sm"><x-cipi::icon name="upload" /> Restore</button>
                                                <button type="button" wire:click="regeneratePassword(@js($db['name']), @js($engine), {{ $db['server_id'] }})" wire:confirm="Generate a new password for {{ $db['name'] }}? Apps using it need the new DB_PASSWORD." class="btn btn-ghost btn-sm"><x-cipi::icon name="key" /> New password</button>
                                            </div>
                                        @else
                                            <p class="text-xs text-subtle text-right">read-only</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-xs text-subtle mt-3">
                {{ count($visible) }} of {{ $scopedCount }} databases
                @if($scopeServer) on {{ $scopeServer->name }} @elseif($multiServer) on {{ $servers->count() }} servers @endif
                · Dropping a database stays on the server CLI (<code>cipi db delete</code>) by design.
            </p>
        @endif
    @endif

    @if($showCreateModal)
        <x-cipi::modal title="Create database" close="closeCreate" persistent :subtitle="'On '.($createServer?->name ?? 'this server').' — a user with the same name is created; its password is shown once.'">
            <form wire:submit="createDatabase">
                <div class="modal-body space-y-4">
                    @if($createServers->count() > 1)
                        <div>
                            <label for="db-server">Server</label>
                            <select id="db-server" wire:model.live="createServerId">
                                @foreach($createServers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->host }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="db-name">Name</label>
                        <input id="db-name" type="text" wire:model="dbName" placeholder="analytics" autocomplete="off" autofocus>
                        @error('dbName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if(count($createEngines) > 0)
                        <div>
                            <span class="label">Engine</span>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach($createEngines as $item)
                                    <label class="check-card" wire:key="create-engine-{{ $createServerId }}-{{ $item['engine'] }}">
                                        <input type="radio" wire:model="dbEngine" value="{{ $item['engine'] }}">
                                        <span><span class="check-card-title">{{ $this->engineLabel($item['engine']) }}</span><span class="check-card-text">{{ $createDefaultEngine === $item['engine'] ? 'Server default' : 'Installed' }}@if(!empty($item['port'])) · :{{ $item['port'] }}@endif</span></span>
                                    </label>
                                @endforeach
                            </div>
                            @error('dbEngine') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeCreate" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create database</button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @if($restoreName)
        <x-cipi::modal title="Restore {{ $restoreName }}" close="closeRestore" persistent>
            <form wire:submit="restore">
                <div class="modal-body space-y-4">
                    <x-cipi::alert type="warn">The dump replaces the current contents of <strong>{{ $restoreName }}</strong>. Take a backup first if you may need them.</x-cipi::alert>
                    <div>
                        <label for="restore-file">Dump file on the server</label>
                        <input id="restore-file" type="text" wire:model="restoreFile" class="font-mono" placeholder="/home/cipi/backups/{{ $restoreName }}_2026-10-07_0300.sql.gz">
                        <p class="field-hint">A <code>.sql.gz</code> created by Backup or <code>cipi db backup</code>.</p>
                        @error('restoreFile') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="restore-confirm">Type <code>{{ $restoreName }}</code> to confirm</label>
                        <input id="restore-confirm" type="text" wire:model="restoreConfirmation" autocomplete="off">
                        @error('restoreConfirmation') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeRestore" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger-solid"><x-cipi::icon name="upload" /> Restore</button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
