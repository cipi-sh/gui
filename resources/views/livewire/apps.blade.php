<div>
    <x-cipi::page-header title="Apps">
        <x-slot:meta>
            @if($server)
                Laravel, Node and custom PHP apps on <strong class="text-strong">{{ $server->name }}</strong>.
            @endif
        </x-slot:meta>
        @if($server)
            <x-slot:actions>
                <button type="button" wire:click="loadApps" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="loadApps">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="loadApps" /> Refresh
                </button>
                <button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> New app</button>
            </x-slot:actions>
        @endif
    </x-cipi::page-header>

    @if(! $server)
        <div class="card">
            <x-cipi::empty icon="servers" title="No server selected">
                Connect a Cipi server first; its apps will show up here.
                <x-slot:actions><a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Connect a server</a></x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="segmented" role="tablist" aria-label="Filter by type">
                @foreach(['all' => 'All', 'laravel' => 'Laravel', 'node' => 'Node', 'custom' => 'Custom'] as $kind => $label)
                    <button type="button" role="tab" aria-selected="{{ $kindFilter === $kind ? 'true' : 'false' }}" wire:click="setKindFilter('{{ $kind }}')" class="segmented-item {{ $kindFilter === $kind ? 'active' : '' }}">
                        {{ $label }}<span class="count">{{ $counts[$kind] }}</span>
                    </button>
                @endforeach
            </div>
            <div class="search-input w-full sm:w-auto" style="min-width: 16rem;">
                <x-cipi::icon name="search" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search name, domain, alias…" aria-label="Search apps">
            </div>
        </div>

        @if($loading)
            <div class="card card-flush">
                @foreach(range(1, 4) as $i)
                    <div class="flex items-center gap-4 px-5 py-4 border-b">
                        <div class="skeleton h-4 w-32"></div><div class="skeleton h-4 w-48"></div><div class="skeleton h-4 w-24 ml-auto"></div>
                    </div>
                @endforeach
            </div>
        @elseif(empty($apps))
            <div class="card">
                <x-cipi::empty icon="apps" title="No apps on {{ $server->name }} yet">
                    Create a Laravel app (FPM or Octane), a Node app (SPA, static or SSR) or a custom PHP site.
                    Cipi provisions the user, vhost, database, workers and deploy key.
                    <x-slot:actions><button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> Create the first app</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @elseif(empty($visibleApps))
            <div class="card">
                <x-cipi::empty icon="search" title="No apps match">
                    Nothing matches “{{ $search }}”{{ $kindFilter !== 'all' ? ' in '.ucfirst($kindFilter).' apps' : '' }}.
                    <x-slot:actions><button type="button" class="btn btn-secondary" wire:click="clearFilters">Clear filters</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @else
            {{-- Phones: compact cards --}}
            <div class="space-y-2 md:hidden">
                @foreach($visibleApps as $app)
                    @php $kind = $this->appKind($app); @endphp
                    <a href="{{ route('cipi-gui.apps.show', ['name' => $app['app'], 'server' => $server->id]) }}" class="card card-hover p-4" wire:key="app-card-{{ $app['app'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-strong">{{ $app['app'] }}</p>
                                <p class="text-xs text-muted truncate">{{ $app['domain'] }}</p>
                            </div>
                            <span class="badge {{ ($app['suspended'] ?? false) ? 'badge-amber' : 'badge-green' }}">{{ ($app['suspended'] ?? false) ? 'Suspended' : 'Live' }}</span>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-3">
                            <span class="badge {{ $kind === 'laravel' ? 'badge-accent' : ($kind === 'node' ? 'badge-blue' : 'badge-neutral') }}">{{ $this->appKindLabel($app) }}</span>
                            <span class="badge">{{ $this->appRuntimeLabel($app) }}</span>
                            @if($kind === 'laravel')<span class="badge">{{ $this->engineLabel($app['engine']) }}</span>@endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="card card-flush hidden md:block">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>App</th>
                                <th>Type</th>
                                <th>Runtime</th>
                                <th>Database</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($visibleApps as $app)
                                @php
                                    $kind = $this->appKind($app);
                                    $url = route('cipi-gui.apps.show', ['name' => $app['app'], 'server' => $server->id]);
                                    $siteUrl = (($app['force_https'] ?? false) ? 'https://' : 'http://').ltrim(str_replace('*.', '', $app['domain']), '.');
                                @endphp
                                <tr wire:key="app-{{ $app['app'] }}">
                                    <td>
                                        <a href="{{ $url }}" class="row-link">{{ $app['app'] }}</a>
                                        <p class="text-xs text-muted flex items-center gap-1 mt-0.5">
                                            <span class="truncate" style="max-width: 18rem;">{{ $app['domain'] }}</span>
                                            @if(count($app['aliases']) > 0)
                                                <span class="text-subtle">+{{ count($app['aliases']) }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    <td>
                                        <span class="badge {{ $kind === 'laravel' ? 'badge-accent' : ($kind === 'node' ? 'badge-blue' : 'badge-neutral') }}">{{ $this->appKindLabel($app) }}</span>
                                    </td>
                                    <td class="text-soft whitespace-nowrap">
                                        @if($kind === 'node')
                                            Node {{ $app['node_version'] ?: 'default' }}
                                        @else
                                            PHP {{ $app['php'] ?: '—' }}
                                            @if($app['octane'])
                                                <span class="badge badge-mono ml-1" title="{{ $this->runtimeLabel($app['octane'], $app['octane_port']) }}">Octane</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $kind === 'laravel' ? $this->engineLabel($app['engine']) : '—' }}</td>
                                    <td class="text-muted font-mono text-xs">{{ $app['branch'] ?: '—' }}</td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @if($app['suspended'] ?? false)
                                                <span class="badge badge-amber">Suspended</span>
                                            @else
                                                <span class="badge badge-green">Live</span>
                                            @endif
                                            @if($app['force_https'] ?? false)
                                                <span class="badge" title="HTTP → HTTPS redirect"><x-cipi::icon name="lock" class="h-3 w-3" /> HTTPS</span>
                                            @endif
                                            @if($app['basic_auth'] ?? false)
                                                <span class="badge" title="HTTP basic auth"><x-cipi::icon name="key" class="h-3 w-3" /> Auth</span>
                                            @endif
                                            @if(!empty($app['redirect']['enabled']))
                                                <span class="badge badge-blue" title="Redirects to {{ $app['redirect']['to'] ?? '' }}">Redirect</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-actions">
                                            @if($kind !== 'custom' || $app['repository'] !== '')
                                                <button type="button" wire:click="deploy('{{ $app['app'] }}')" class="btn btn-ghost btn-sm" title="Deploy {{ $app['app'] }}"><x-cipi::icon name="rocket" /> Deploy</button>
                                            @endif
                                            <a href="{{ $url }}" class="btn btn-secondary btn-sm">Manage</a>
                                            <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                                <button type="button" class="btn btn-ghost btn-icon btn-sm" x-on:click="open = !open" aria-label="More actions for {{ $app['app'] }}"><x-cipi::icon name="more" /></button>
                                                <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="dropdown-item"><x-cipi::icon name="external" /> Open site</a>
                                                    <a href="{{ $url }}&tab=logs" class="dropdown-item"><x-cipi::icon name="document" /> Logs</a>
                                                    <button type="button" class="dropdown-item" x-on:click="open = false"
                                                            wire:click="toggleSuspend('{{ $app['app'] }}', {{ ($app['suspended'] ?? false) ? 'true' : 'false' }})"
                                                            wire:confirm="{{ ($app['suspended'] ?? false) ? 'Bring '.$app['app'].' back online?' : 'Take '.$app['app'].' offline with an HTTP 503 maintenance page?' }}">
                                                        <x-cipi::icon :name="($app['suspended'] ?? false) ? 'play' : 'pause'" /> {{ ($app['suspended'] ?? false) ? 'Unsuspend' : 'Suspend' }}
                                                    </button>
                                                    <div class="dropdown-divider"></div>
                                                    <button type="button" class="dropdown-item danger" wire:click="confirmDeleteApp('{{ $app['app'] }}')" x-on:click="open = false"><x-cipi::icon name="trash" /> Delete app</button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="text-xs text-subtle mt-3">{{ count($visibleApps) }} of {{ count($apps) }} apps</p>
        @endif
    @endif

    {{-- Create modal --}}
    @if($showCreateModal)
        @php $preset = $this->nodePreset(); @endphp
        <x-cipi::modal title="Create app" close="closeCreate" size="lg" :subtitle="'On '.($server?->name ?? 'this server').' — Cipi provisions the Linux user, vhost, PHP pool or Node runtime, and deploy key.'">
            <form wire:submit="createApp">
                <div class="modal-body space-y-5">
                    <div>
                        <span class="label">App type</span>
                        <div class="type-picker" role="radiogroup">
                            @foreach([
                                'laravel' => ['Laravel', 'PHP-FPM or Octane, database, queue worker, scheduler'],
                                'node' => ['Node', 'Next, Nuxt, SvelteKit, Astro, Remix or Vite — SPA, static or SSR'],
                                'custom' => ['Custom PHP', 'WordPress or any PHP site — Git or SFTP, your docroot'],
                            ] as $kind => [$label, $hint])
                                <button type="button" role="radio" aria-checked="{{ $appKind === $kind ? 'true' : 'false' }}" wire:click="setAppKind('{{ $kind }}')" class="type-option {{ $appKind === $kind ? 'active' : '' }}">
                                    <strong>{{ $label }}</strong>
                                    <span>{{ $hint }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="app-user">App name (Linux user)</label>
                            <input id="app-user" type="text" wire:model="user" placeholder="myapp" autocomplete="off" autofocus>
                            @error('user') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="app-domain">Primary domain</label>
                            <input id="app-domain" type="text" wire:model="domain" placeholder="app.example.com or *.example.com" autocomplete="off">
                            @error('domain') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label for="app-repo">Repository <span class="text-subtle font-normal">{{ $appKind === 'custom' ? '(optional — empty for SFTP only)' : '(Git SSH URL)' }}</span></label>
                            <input id="app-repo" type="text" wire:model="repository" placeholder="git@github.com:org/repo.git" autocomplete="off">
                            @error('repository') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="app-branch">Branch</label>
                            <input id="app-branch" type="text" wire:model="branch" placeholder="main" autocomplete="off">
                            @error('branch') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if($appKind === 'node')
                        @if($nodeUnsupported)
                            <x-cipi::alert type="warn">Node apps need API 1.31+ / Cipi 5.4+ and the <code>node-view</code> ability. You can still submit — the server answers if it cannot.</x-cipi::alert>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label for="node-framework">Framework</label>
                                <select id="node-framework" wire:model.live="nodeFramework">
                                    <option value="">None (set commands)</option>
                                    @foreach($presets as $key => $p)
                                        <option value="{{ $key }}">{{ $p['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="node-mode">Mode</label>
                                <select id="node-mode" wire:model.live="nodeMode">
                                    <option value="spa">SPA — client-side routing</option>
                                    <option value="static">Static — prebuilt files</option>
                                    <option value="ssr">SSR — Node process</option>
                                </select>
                                @error('nodeMode') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="node-version">Node version</label>
                                <select id="node-version" wire:model="nodeVersion">
                                    <option value="">Server default</option>
                                    @foreach($nodeRuntimes as $runtime)
                                        <option value="{{ $runtime['major'] }}">Node {{ $runtime['major'] }}{{ !empty($runtime['version']) ? ' ('.$runtime['version'].')' : '' }}{{ !empty($runtime['default']) ? ' · default' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="node-build">Build command</label>
                                <input id="node-build" type="text" wire:model="nodeBuild" class="font-mono" placeholder="{{ $preset['build'] ?: 'npm run build' }}">
                            </div>
                            @if($nodeMode === 'ssr')
                                <div>
                                    <label for="node-start">Start command</label>
                                    <input id="node-start" type="text" wire:model="nodeStart" class="font-mono" placeholder="{{ $preset['start'] ?: 'npm run start' }}">
                                    <p class="field-hint">Cipi sets <code>PORT</code> and <code>HOST</code>.</p>
                                </div>
                                <div>
                                    <label for="node-health">Health path</label>
                                    <input id="node-health" type="text" wire:model="nodeHealthPath" class="font-mono" placeholder="/">
                                    <p class="field-hint">Checked before each blue/green switch.</p>
                                    @error('nodeHealthPath') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label for="node-output">Output directory</label>
                                    <input id="node-output" type="text" wire:model="nodeOutput" class="font-mono" placeholder="{{ $preset['output'] ?: 'dist' }}">
                                </div>
                            @endif
                        </div>
                        <p class="field-hint">Empty fields use the framework defaults shown as placeholders.</p>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="app-php">PHP version</label>
                                <select id="app-php" wire:model="php">
                                    @foreach($phpVersions as $ver)
                                        <option value="{{ $ver }}">PHP {{ $ver }}{{ $ver === $defaultPhpVersion ? ' · default' : '' }}</option>
                                    @endforeach
                                </select>
                                <p class="field-hint">Versions installed on this server · <a href="{{ route('cipi-gui.server-manage') }}?tab=php" class="text-link">install another</a></p>
                                @error('php') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            @if($appKind === 'laravel' && count($availableEngines) > 0)
                                <div>
                                    <label for="app-engine">Database engine</label>
                                    <select id="app-engine" wire:model="engine">
                                        @foreach($availableEngines as $item)
                                            <option value="{{ $item['engine'] }}">{{ $this->engineLabel($item['engine']) }}{{ !empty($item['default']) ? ' · default' : '' }}{{ !empty($item['port']) ? ' · :'.$item['port'] : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('engine') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @elseif($appKind === 'custom')
                                <div>
                                    <label for="app-docroot">Document root</label>
                                    <input id="app-docroot" type="text" wire:model="docroot" class="font-mono" placeholder="(home root) or public">
                                    @error('docroot') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                        @if($appKind === 'laravel')
                            <label class="check-card">
                                <input type="checkbox" wire:model="octane">
                                <span>
                                    <span class="check-card-title">Laravel Octane (FrankenPHP)</span>
                                    <span class="check-card-text">Nginx proxies to a long-lived Octane worker instead of PHP-FPM. Needs <code>laravel/octane</code> in the repo and more RAM. Cipi 5.0+.</span>
                                </span>
                            </label>
                        @endif
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeCreate" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="createApp">
                        <x-cipi::icon name="plus" /> Create {{ $appKind === 'custom' ? 'custom app' : ucfirst($appKind).' app' }}
                    </button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @if($deleteAppName !== '')
        <x-cipi::modal title="Delete app" close="cancelDeleteApp">
            <form wire:submit="deleteApp">
                <div class="modal-body space-y-4">
                    <x-cipi::alert type="danger" title="This cannot be undone">
                        Deleting <strong>{{ $deleteAppName }}</strong> removes its Linux user and home, vhost, PHP pool or Node process, workers, and its database.
                    </x-cipi::alert>
                    <div>
                        <label for="delete-confirm">Type <code>{{ $deleteAppName }}</code> to confirm</label>
                        <input id="delete-confirm" type="text" wire:model="deleteConfirmation" autocomplete="off" autofocus>
                        @error('deleteConfirmation') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="cancelDeleteApp" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger-solid"><x-cipi::icon name="trash" /> Delete app</button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
