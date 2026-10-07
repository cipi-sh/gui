<div>
    <x-cipi::page-header title="Databases">
        <x-slot:meta>
            @if($server)
                MariaDB and PostgreSQL databases on <strong class="text-strong">{{ $server->name }}</strong>.
            @endif
        </x-slot:meta>
        @if($server)
            <x-slot:actions>
                <button type="button" wire:click="loadDatabases" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="loadDatabases">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="loadDatabases" /> Refresh
                </button>
                <button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> New database</button>
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
        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif

        @if($engines)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                @foreach($engines as $item)
                    <div class="card">
                        <div class="flex items-center justify-between gap-2">
                            <p class="stat-label">{{ $this->engineLabel($item['engine']) }}</p>
                            @if(($item['default'] ?? false) || $defaultEngine === $item['engine'])
                                <span class="badge badge-accent">Default</span>
                            @endif
                        </div>
                        <p class="stat-value mt-1">{{ $counts[$item['engine']] ?? 0 }}</p>
                        <p class="stat-meta">{{ \Illuminate\Support\Str::plural('database', $counts[$item['engine']] ?? 0) }}@if(!empty($item['port'])) · port {{ $item['port'] }}@endif</p>
                    </div>
                @endforeach
            </div>
        @elseif($enginesUnsupported && ! $loading)
            <x-cipi::alert type="info" class="mb-4">Engine details need API 1.12+ (Cipi 4.8). Databases are listed without engine information.</x-cipi::alert>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="segmented" role="tablist" aria-label="Filter by engine">
                <button type="button" wire:click="setEngineFilter('all')" class="segmented-item {{ $engineFilter === 'all' ? 'active' : '' }}">All<span class="count">{{ $counts['all'] }}</span></button>
                @foreach($engines as $item)
                    <button type="button" wire:click="setEngineFilter('{{ $item['engine'] }}')" class="segmented-item {{ $engineFilter === $item['engine'] ? 'active' : '' }}">{{ $this->engineLabel($item['engine']) }}<span class="count">{{ $counts[$item['engine']] ?? 0 }}</span></button>
                @endforeach
            </div>
            <div class="search-input w-full sm:w-auto" style="min-width: 14rem;">
                <x-cipi::icon name="search" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search databases…" aria-label="Search databases">
            </div>
        </div>

        @if($loading)
            <div class="card card-flush">
                @foreach(range(1, 3) as $i)
                    <div class="flex items-center gap-4 px-5 py-4 border-b"><div class="skeleton h-4 w-32"></div><div class="skeleton h-4 w-24"></div><div class="skeleton h-4 w-20 ml-auto"></div></div>
                @endforeach
            </div>
        @elseif(empty($databases))
            <div class="card">
                <x-cipi::empty icon="database" title="No databases yet">
                    Laravel apps get their own database automatically. Create extra ones here — credentials are shown once.
                    <x-slot:actions><button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> Create database</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @elseif(empty($visible))
            <div class="card"><x-cipi::empty icon="search" title="No databases match your filters" /></div>
        @else
            <div class="card card-flush">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr><th>Database</th><th>Engine</th><th>User</th><th class="text-right">Size</th><th>Last backup</th><th class="text-right">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach($visible as $db)
                                @php $engine = $db['engine'] ?? ''; @endphp
                                <tr wire:key="db-{{ $engine }}-{{ $db['name'] }}">
                                    <td class="font-mono font-semibold text-strong">{{ $db['name'] }}</td>
                                    <td><span class="badge {{ $engine === 'pgsql' ? 'badge-blue' : 'badge-neutral' }}">{{ $this->engineLabel($engine ?: null) }}</span></td>
                                    <td class="font-mono text-xs text-muted">{{ $db['user'] ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-soft">{{ $db['size'] ?? '—' }}</td>
                                    <td class="text-xs text-muted font-mono truncate" style="max-width: 16rem;" title="{{ $backups[$db['name']] ?? '' }}">{{ isset($backups[$db['name']]) ? basename($backups[$db['name']]) : '—' }}</td>
                                    <td>
                                        <div class="btn-actions">
                                            <button type="button" wire:click="backup(@js($db['name']), @js($engine))" class="btn btn-ghost btn-sm" title="Dump to /home/cipi/backups"><x-cipi::icon name="download" /> Backup</button>
                                            <button type="button" wire:click="openRestore(@js($db['name']), @js($engine))" class="btn btn-ghost btn-sm"><x-cipi::icon name="upload" /> Restore</button>
                                            <button type="button" wire:click="regeneratePassword(@js($db['name']), @js($engine))" wire:confirm="Generate a new password for {{ $db['name'] }}? Apps using it need the new DB_PASSWORD." class="btn btn-ghost btn-sm"><x-cipi::icon name="key" /> New password</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-xs text-subtle mt-3">Dropping a database stays on the server CLI (<code>cipi db delete</code>) by design.</p>
        @endif
    @endif

    @if($showCreateModal)
        <x-cipi::modal title="Create database" close="closeCreate" subtitle="A user with the same name is created; its password is shown once.">
            <form wire:submit="createDatabase">
                <div class="modal-body space-y-4">
                    <div>
                        <label for="db-name">Name</label>
                        <input id="db-name" type="text" wire:model="dbName" placeholder="analytics" autocomplete="off" autofocus>
                        @error('dbName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if(count($engines) > 0)
                        <div>
                            <span class="label">Engine</span>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach($engines as $item)
                                    <label class="check-card">
                                        <input type="radio" wire:model="dbEngine" value="{{ $item['engine'] }}">
                                        <span><span class="check-card-title">{{ $this->engineLabel($item['engine']) }}</span><span class="check-card-text">{{ ($item['default'] ?? false) ? 'Server default' : 'Installed' }}@if(!empty($item['port'])) · :{{ $item['port'] }}@endif</span></span>
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
        <x-cipi::modal title="Restore {{ $restoreName }}" close="closeRestore">
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
