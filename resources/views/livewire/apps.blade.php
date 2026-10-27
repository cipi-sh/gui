<div wire:init="loadApps">
    <x-cipi::page-header title="Apps">
        <x-slot:meta>
            @if($scopeServer)
                Laravel, Node and custom PHP apps on <strong class="text-strong">{{ $scopeServer->name }}</strong>.
                @if($multiServer)<button type="button" wire:click="setScope(0)" class="text-link">Show every server</button>@endif
                @if(! $fleet && $servers->count() === 1 && \CipiGui\Services\Access::visibleServers(auth()->user())->count() > 1) <span class="text-subtle">Switch server from the header.</span>@endif
            @elseif($multiServer)
                Laravel, Node and custom PHP apps on every connected server.
            @elseif($servers->isNotEmpty())
                Laravel, Node and custom PHP apps on <strong class="text-strong">{{ $servers->first()->name }}</strong>.
            @endif
        </x-slot:meta>
        @if($servers->isNotEmpty())
            <x-slot:actions>
                <button type="button" wire:click="loadApps" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="loadApps">
                    <x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="loadApps" /> Refresh
                </button>
                @if($canCreate)
                    <button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> New app</button>
                @endif
            </x-slot:actions>
        @endif
    </x-cipi::page-header>

    @if($servers->isEmpty())
        <div class="card">
            @if(\CipiGui\Services\Access::isAdmin(auth()->user()))
                <x-cipi::empty icon="servers" title="No server connected">
                    Connect a Cipi server first; its apps will show up here.
                    <x-slot:actions><a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Connect a server</a></x-slot:actions>
                </x-cipi::empty>
            @else
                <x-cipi::empty icon="lock" title="No access yet">An admin of this panel has not given you a server or an app yet.</x-cipi::empty>
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
                    <button type="button" wire:click="loadApps" class="btn btn-secondary btn-sm">Retry</button>
                    <a href="{{ route('cipi-gui.servers') }}" class="btn btn-ghost btn-sm">Check connections</a>
                </div>
            </x-cipi::alert>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex flex-wrap items-center gap-2">
                @if($multiServer)
                    <div class="segmented" role="tablist" aria-label="Filter by server">
                        <button type="button" role="tab" aria-selected="{{ $scopeId === 0 ? 'true' : 'false' }}" wire:click="setScope(0)" class="segmented-item {{ $scopeId === 0 ? 'active' : '' }}">
                            All servers<span class="count">{{ count($apps) }}</span>
                        </button>
                        @foreach($servers as $s)
                            <button type="button" role="tab" aria-selected="{{ $scopeId === $s->id ? 'true' : 'false' }}" wire:click="setScope({{ $s->id }})" class="segmented-item {{ $scopeId === $s->id ? 'active' : '' }}" title="{{ $s->host }}">
                                <span class="dot {{ isset($this->serverErrors[$s->id]) ? 'dot-red' : 'dot-green' }}" style="width:.4rem;height:.4rem;box-shadow:none;margin-right:.4rem;vertical-align:middle"></span>{{ $s->name }}<span class="count">{{ $serverCounts[$s->id] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <div class="segmented" role="tablist" aria-label="Filter by type">
                    @foreach(['all' => 'All', 'laravel' => 'Laravel', 'node' => 'Node', 'custom' => 'Custom'] as $kind => $label)
                        <button type="button" role="tab" aria-selected="{{ $kindFilter === $kind ? 'true' : 'false' }}" wire:click="setKindFilter('{{ $kind }}')" class="segmented-item {{ $kindFilter === $kind ? 'active' : '' }}">
                            {{ $label }}<span class="count">{{ $counts[$kind] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="search-input w-full sm:w-auto" style="min-width: 16rem;">
                <x-cipi::icon name="search" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ $multiServer && $scopeId === 0 ? 'Search name, domain, alias, server…' : 'Search name, domain, alias…' }}" aria-label="Search apps">
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
        @elseif($scopedCount === 0 && ($scopeId !== 0 ? isset($serverErrors[$scopeId]) : ($apps === [] && $serverErrors !== [])))
            <div class="card">
                <x-cipi::empty icon="warning" :title="$scopeServer ? 'Apps on '.$scopeServer->name.' could not be loaded' : 'Apps could not be loaded'">
                    The server did not answer — the reason is above.
                    <x-slot:actions><button type="button" wire:click="loadApps" class="btn btn-primary">Try again</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @elseif($scopedCount === 0)
            <div class="card">
                <x-cipi::empty icon="apps" :title="$scopeServer ? 'No apps on '.$scopeServer->name.' yet' : 'No apps yet'">
                    @if($canCreate)
                        Create a Laravel app (FPM or Octane), a Node app (SPA, static or SSR) or a custom PHP site.
                        Cipi provisions the user, vhost, database, workers and deploy key.
                        <x-slot:actions><button type="button" wire:click="openCreate" class="btn btn-primary"><x-cipi::icon name="plus" /> Create the first app</button></x-slot:actions>
                    @else
                        Nothing to show for your access on {{ $scopeServer?->name ?? 'these servers' }}.
                    @endif
                </x-cipi::empty>
            </div>
        @elseif(empty($visibleApps))
            <div class="card">
                <x-cipi::empty icon="search" title="No apps match">
                    Nothing matches{{ $search !== '' ? ' “'.$search.'”' : '' }}{{ $kindFilter !== 'all' ? ' in '.ucfirst($kindFilter).' apps' : '' }}{{ $scopeServer ? ' on '.$scopeServer->name : '' }}.
                    <x-slot:actions><button type="button" class="btn btn-secondary" wire:click="clearFilters">Clear filters</button></x-slot:actions>
                </x-cipi::empty>
            </div>
        @else
            {{-- Phones: compact cards --}}
            <div class="space-y-2 md:hidden">
                @foreach($visibleApps as $app)
                    @php $kind = $this->appKind($app); @endphp
                    <a href="{{ route('cipi-gui.apps.show', ['name' => $app['app'], 'server' => $app['server_id']]) }}" class="card card-hover p-4" wire:key="app-card-{{ $app['server_id'] }}-{{ $app['app'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-strong">{{ $app['app'] }}</p>
                                <p class="text-xs text-muted truncate">{{ $app['domain'] }}</p>
                                @if($multiServer && $scopeId === 0)
                                    <p class="text-xs text-subtle truncate mt-0.5 flex items-center gap-1"><x-cipi::icon name="server" class="h-3 w-3" /> {{ $app['server_name'] }}</p>
                                @endif
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
                                @if($multiServer && $scopeId === 0)<th>Server</th>@endif
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
                                    $url = route('cipi-gui.apps.show', ['name' => $app['app'], 'server' => $app['server_id']]);
                                    $siteUrl = (($app['force_https'] ?? false) ? 'https://' : 'http://').ltrim(str_replace('*.', '', $app['domain']), '.');
                                @endphp
                                <tr wire:key="app-{{ $app['server_id'] }}-{{ $app['app'] }}">
                                    <td>
                                        <a href="{{ $url }}" class="row-link">{{ $app['app'] }}</a>
                                        <p class="text-xs text-muted flex items-center gap-1 mt-0.5">
                                            <span class="truncate" style="max-width: 18rem;">{{ $app['domain'] }}</span>
                                            @if(count($app['aliases']) > 0)
                                                <span class="text-subtle">+{{ count($app['aliases']) }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    @if($multiServer && $scopeId === 0)
                                        <td class="whitespace-nowrap">
                                            <button type="button" wire:click="setScope({{ $app['server_id'] }})" class="badge badge-neutral" title="Only the apps on {{ $app['server_name'] }}"><x-cipi::icon name="server" class="h-3 w-3" /> {{ $app['server_name'] }}</button>
                                        </td>
                                    @endif
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
                                            @php $can = $app['can'] ?? []; @endphp
                                            @if(($kind !== 'custom' || $app['repository'] !== '') && ($can['deploy'] ?? false))
                                                <button type="button" wire:click="deploy('{{ $app['app'] }}', {{ $app['server_id'] }})" class="btn btn-ghost btn-sm" title="Deploy {{ $app['app'] }}{{ $multiServer ? ' on '.$app['server_name'] : '' }}"><x-cipi::icon name="rocket" /> Deploy</button>
                                            @endif
                                            <a href="{{ $url }}" class="btn btn-secondary btn-sm">{{ ($can['edit'] ?? false) ? 'Manage' : 'Open' }}</a>
                                            <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                                <button type="button" class="btn btn-ghost btn-icon btn-sm" x-on:click="open = !open" aria-label="More actions for {{ $app['app'] }}"><x-cipi::icon name="more" /></button>
                                                <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="dropdown-item"><x-cipi::icon name="external" /> Open site</a>
                                                    @if($can['logs'] ?? false)
                                                        <a href="{{ $url }}&tab=logs" class="dropdown-item"><x-cipi::icon name="document" /> Logs</a>
                                                    @endif
                                                    @if($can['edit'] ?? false)
                                                        <button type="button" class="dropdown-item" x-on:click="open = false"
                                                                wire:click="toggleSuspend('{{ $app['app'] }}', {{ ($app['suspended'] ?? false) ? 'true' : 'false' }}, {{ $app['server_id'] }})"
                                                                wire:confirm="{{ ($app['suspended'] ?? false) ? 'Bring '.$app['app'].' back online?' : 'Take '.$app['app'].' offline with an HTTP 503 maintenance page?' }}">
                                                            <x-cipi::icon :name="($app['suspended'] ?? false) ? 'play' : 'pause'" /> {{ ($app['suspended'] ?? false) ? 'Unsuspend' : 'Suspend' }}
                                                        </button>
                                                    @endif
                                                    @if($can['delete'] ?? false)
                                                        <div class="dropdown-divider"></div>
                                                        <button type="button" class="dropdown-item danger" wire:click="confirmDeleteApp('{{ $app['app'] }}', {{ $app['server_id'] }})" x-on:click="open = false"><x-cipi::icon name="trash" /> Delete app</button>
                                                    @endif
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
            <p class="text-xs text-subtle mt-3">
                {{ count($visibleApps) }} of {{ $scopedCount }} apps
                @if($scopeServer) on {{ $scopeServer->name }} @elseif($multiServer) on {{ $servers->count() }} servers @endif
            </p>
        @endif
    @endif

    {{-- Create modal --}}
    @if($showCreateModal)
        @php $preset = $this->nodePreset(); @endphp
        <x-cipi::modal title="Create app" close="closeCreate" size="lg" persistent :subtitle="'On '.($createServer?->name ?? 'this server').' — Cipi provisions the Linux user, vhost, PHP pool or Node runtime, and deploy key.'">
            <form wire:submit="createApp">
                <div class="modal-body space-y-5">
                    @if($createDraft)
                        <x-cipi::alert type="info">
                            Picked up where you left off — nothing typed here is lost when the dialog closes.
                            <button type="button" wire:click="clearCreateForm" class="text-link ml-1">Clear the form</button>
                        </x-cipi::alert>
                    @endif

                    @if($createServers->count() > 1)
                        <div>
                            <label for="app-server">Server</label>
                            <select id="app-server" wire:model.live="createServerId">
                                @foreach($createServers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->host }}</option>
                                @endforeach
                            </select>
                            <p class="field-hint">PHP versions, database engines and Node runtimes below are the ones installed there.</p>
                        </div>
                    @endif

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
                                <p class="field-hint">Versions installed on {{ $createServer?->name ?? 'this server' }} · <a href="{{ route('cipi-gui.server-manage', ['serverId' => $createServerId ?: null]) }}?tab=php" class="text-link">install another</a></p>
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
                            <div>
                                <label for="app-asset-build">Build command <span class="text-subtle font-normal">(optional)</span></label>
                                <input id="app-asset-build" type="text" wire:model="assetBuild" class="font-mono" placeholder="{{ $buildPlaceholder }}" maxlength="{{ $buildMaxLength }}" autocomplete="off" spellcheck="false">
                                <p class="field-hint">Front-end assets: runs on every deploy after <code>composer install</code>, before the release goes live. Starts with npm, npx, yarn, pnpm, bun or node; <code>&amp;&amp;</code> and <code>;</code> are allowed, pipes and redirects are not. Empty to skip. Cipi 5.6+ (older servers ignore it: set it later under Deploy → Deploy pipeline).</p>
                                @error('assetBuild') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
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
                        Deleting <strong>{{ $deleteAppName }}</strong>@if($deleteServerName !== '' && $multiServer) on <strong>{{ $deleteServerName }}</strong>@endif removes its Linux user and home, vhost, PHP pool or Node process, workers, and its database.
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
