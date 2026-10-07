<div>
    @if($loading && ! $app)
        <div class="skeleton h-4 w-32 mb-3"></div>
        <div class="skeleton h-8 w-64 mb-6"></div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4"><div class="card"><div class="skeleton h-40 w-full"></div></div><div class="card"><div class="skeleton h-40 w-full"></div></div></div>
    @elseif(! $app)
        <x-cipi::page-header :title="$appName">
            <x-slot:eyebrow><a href="{{ route('cipi-gui.apps') }}">← Apps</a></x-slot:eyebrow>
        </x-cipi::page-header>
        <div class="card">
            <x-cipi::empty icon="warning" title="App not available">
                {{ rtrim($error ?? 'The app could not be loaded', '.') }}. It may live on another server — switch server from the header.
                <x-slot:actions><a href="{{ route('cipi-gui.apps') }}" class="btn btn-secondary">Back to apps</a></x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        @php
            $kind = $this->appKind($app);
            $isNode = $kind === 'node';
            $isLaravel = $kind === 'laravel';
            $isSsr = $isNode && ($app['node_mode'] ?? '') === 'ssr';
            $repoUrl = $this->repositoryUrl();
        @endphp

        <x-cipi::page-header :title="$app['app']">
            <x-slot:eyebrow>
                <a href="{{ route('cipi-gui.apps') }}">Apps</a>
                <x-cipi::icon name="chevron-right" class="h-3 w-3" />
                <span>{{ $server?->name }}</span>
            </x-slot:eyebrow>
            <x-slot:meta>
                <span class="flex flex-wrap items-center gap-2 mt-1">
                    @if($app['suspended'] ?? false)
                        <span class="badge badge-amber"><x-cipi::icon name="pause" class="h-3 w-3" /> Suspended</span>
                    @else
                        <span class="badge badge-green"><span class="dot dot-green" style="width:.4rem;height:.4rem;box-shadow:none"></span> Live</span>
                    @endif
                    <span class="badge {{ $isLaravel ? 'badge-accent' : ($isNode ? 'badge-blue' : 'badge-neutral') }}">{{ $this->appKindLabel($app) }}</span>
                    <span class="badge">{{ $this->appRuntimeLabel($app) }}</span>
                    @if($app['force_https'] ?? false)<span class="badge"><x-cipi::icon name="lock" class="h-3 w-3" /> HTTPS</span>@endif
                    <a href="{{ $this->siteUrl() }}" target="_blank" rel="noopener" class="text-sm text-soft inline-flex items-center gap-1 ml-1">{{ $app['domain'] }} <x-cipi::icon name="external" class="h-3.5 w-3.5" /></a>
                </span>
            </x-slot:meta>
            <x-slot:actions>
                @if(! $isNode && ! $isLaravel && $app['repository'] === '')
                    <span class="text-xs text-muted">SFTP-only app</span>
                @else
                    <button type="button" wire:click="deploy" class="btn btn-primary"><x-cipi::icon name="rocket" /> Deploy</button>
                @endif
                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <button type="button" class="btn btn-secondary" x-on:click="open = !open" :aria-expanded="open">Actions <x-cipi::icon name="chevron-down" class="h-3.5 w-3.5" /></button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                        <a href="{{ $this->siteUrl() }}" target="_blank" rel="noopener" class="dropdown-item"><x-cipi::icon name="external" /> Open site</a>
                        @if($isSsr)
                            <button type="button" class="dropdown-item" wire:click="restartNode" wire:confirm="Blue/green restart {{ $app['app'] }}? The new process must pass its health path before traffic switches." x-on:click="open = false"><x-cipi::icon name="refresh" /> Restart Node process</button>
                        @endif
                        <button type="button" class="dropdown-item" wire:click="fixPermissions" wire:confirm="Restore ownership and modes of /home/{{ $app['app'] }}?" x-on:click="open = false"><x-cipi::icon name="wrench" /> Fix permissions</button>
                        @if($app['suspended'] ?? false)
                            <button type="button" class="dropdown-item" wire:click="unsuspendApp" x-on:click="open = false"><x-cipi::icon name="play" /> Unsuspend</button>
                        @else
                            <button type="button" class="dropdown-item" wire:click="suspendApp" wire:confirm="Take {{ $app['app'] }} offline with an HTTP 503 maintenance page?" x-on:click="open = false"><x-cipi::icon name="pause" /> Suspend (maintenance page)</button>
                        @endif
                        <div class="dropdown-divider"></div>
                        <button type="button" class="dropdown-item danger" wire:click="confirmDeleteApp" x-on:click="open = false"><x-cipi::icon name="trash" /> Delete app</button>
                    </div>
                </div>
            </x-slot:actions>
        </x-cipi::page-header>

        <nav class="tabs" aria-label="App sections">
            @foreach($tabs as $tab => $label)
                <button type="button" wire:click="setTab('{{ $tab }}')" class="tab-btn {{ $activeTab === $tab ? 'active' : '' }}" @if($activeTab === $tab) aria-current="page" @endif>
                    {{ $label }}
                    @if($tab === 'domains' && count($aliases))<span class="count">{{ count($aliases) + 1 }}</span>@endif
                    @if($tab === 'routing' && (count($app['redirects']) + count($app['proxies'])))<span class="count">{{ count($app['redirects']) + count($app['proxies']) }}</span>@endif
                </button>
            @endforeach
        </nav>

        @if($error)
            <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
        @endif

        {{-- ═══ Overview ═══ --}}
        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <div class="card lg:col-span-2">
                    <div class="card-header"><h2 class="card-title">Details</h2></div>
                    <dl class="kv">
                        <dt>Type</dt><dd>{{ $this->appKindLabel($app) }}@if($isNode && !empty($nodeInfo['framework'])) · {{ ucfirst($nodeInfo['framework']) }}@endif</dd>
                        <dt>Runtime</dt>
                        <dd>
                            @if($isNode)
                                Node {{ $app['node_version'] ?: 'server default' }}
                            @else
                                PHP {{ $app['php'] }} · {{ $this->runtimeLabel($app['octane'], $app['octane_port']) }}
                            @endif
                        </dd>
                        @if($isLaravel)
                            <dt>Database</dt><dd>{{ $this->engineLabel($app['engine']) }} · <span class="font-mono">{{ $app['app'] }}</span></dd>
                            @if($app['node_version'])
                                <dt>Asset build</dt><dd>Node {{ $app['node_version'] }} (pinned)</dd>
                            @endif
                        @endif
                        <dt>Repository</dt>
                        <dd>
                            @if($app['repository'] !== '')
                                @if($repoUrl)
                                    <a href="{{ $repoUrl }}" target="_blank" rel="noopener" class="text-link font-mono text-xs">{{ $app['repository'] }}</a>
                                @else
                                    <span class="font-mono text-xs">{{ $app['repository'] }}</span>
                                @endif
                            @else
                                <span class="text-muted">None — SFTP uploads</span>
                            @endif
                        </dd>
                        <dt>Branch</dt><dd class="font-mono text-xs">{{ $app['branch'] ?: '—' }}</dd>
                        <dt>Domains</dt>
                        <dd>
                            {{ $app['domain'] }}
                            @foreach($aliases as $alias)<br><span class="text-muted">{{ $alias }}</span>@endforeach
                        </dd>
                        <dt>Home</dt><dd class="font-mono text-xs">/home/{{ $app['app'] }}</dd>
                        <dt>Created</dt><dd>{{ $app['created_at'] ?: '—' }}</dd>
                    </dl>
                </div>

                <div class="card lg:col-span-3">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">Configuration</h2>
                            <p class="card-subtitle">Only changed fields are sent. Changing the repository recreates the deploy key and webhook.</p>
                        </div>
                    </div>
                    <form wire:submit="saveApp" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label for="edit-domain">Primary domain</label>
                                <input id="edit-domain" type="text" wire:model="editDomain" placeholder="app.example.com or *.example.com">
                                @error('editDomain') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="edit-repo">Repository</label>
                                <input id="edit-repo" type="text" wire:model="editRepository" class="font-mono" placeholder="git@github.com:org/repo.git">
                            </div>
                            <div>
                                <label for="edit-branch">Branch</label>
                                <input id="edit-branch" type="text" wire:model="editBranch" class="font-mono">
                            </div>
                            @if($isNode)
                                <div>
                                    <label for="edit-mode">Mode</label>
                                    <select id="edit-mode" wire:model.live="editNodeMode">
                                        <option value="spa">SPA</option>
                                        <option value="static">Static</option>
                                        <option value="ssr">SSR</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="edit-node">Node version</label>
                                    <select id="edit-node" wire:model="editNodeVersion">
                                        <option value="">Server default</option>
                                        @foreach($nodeRuntimes as $runtime)
                                            <option value="{{ $runtime['major'] }}">Node {{ $runtime['major'] }}{{ !empty($runtime['version']) ? ' ('.$runtime['version'].')' : '' }}{{ !empty($runtime['default']) ? ' · default' : '' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="edit-build">Build command</label>
                                    <input id="edit-build" type="text" wire:model="editBuild" class="font-mono" placeholder="npm run build">
                                </div>
                                @if($editNodeMode === 'ssr')
                                    <div>
                                        <label for="edit-start">Start command</label>
                                        <input id="edit-start" type="text" wire:model="editStart" class="font-mono" placeholder="npm run start">
                                    </div>
                                    <div>
                                        <label for="edit-health">Health path</label>
                                        <input id="edit-health" type="text" wire:model="editHealthPath" class="font-mono" placeholder="/">
                                    </div>
                                @else
                                    <div>
                                        <label for="edit-output">Output directory</label>
                                        <input id="edit-output" type="text" wire:model="editOutput" class="font-mono" placeholder="dist">
                                    </div>
                                @endif
                            @else
                                <div>
                                    <label for="edit-php">PHP version</label>
                                    <select id="edit-php" wire:model="editPhp">
                                        @foreach($phpVersions as $ver)
                                            <option value="{{ $ver }}">PHP {{ $ver }}</option>
                                        @endforeach
                                        @if($editPhp !== '' && ! in_array($editPhp, $phpVersions, true))
                                            <option value="{{ $editPhp }}">PHP {{ $editPhp }} (not installed?)</option>
                                        @endif
                                    </select>
                                    <p class="field-hint">
                                        @if($phpListUnsupported) PHP list unavailable — showing local hints. @else Installed on this server · <a href="{{ route('cipi-gui.server-manage') }}?tab=php" class="text-link">manage PHP</a> @endif
                                    </p>
                                    @error('editPhp') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                @if($isLaravel)
                                    <div>
                                        <label for="edit-node-pin">Node for asset builds</label>
                                        <select id="edit-node-pin" wire:model="editNodeVersion">
                                            <option value="">Server default</option>
                                            @foreach($nodeRuntimes as $runtime)
                                                <option value="{{ $runtime['major'] }}">Node {{ $runtime['major'] }}{{ !empty($runtime['default']) ? ' · default' : '' }}</option>
                                            @endforeach
                                        </select>
                                        <p class="field-hint">Pins <code>npm run build</code> during deploys to a Node major.</p>
                                    </div>
                                @endif
                            @endif
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveApp">Save changes</button>
                        </div>
                    </form>
                </div>

                <div class="card lg:col-span-3">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="heart" /> HTTP healthcheck</h2>
                            <p class="card-subtitle">Probed every 5 minutes. Three failures in a row send the <code>health_fail</code> notification.</p>
                        </div>
                        @if($healthEnabled)
                            @php $hs = $health['state'] ?? null; @endphp
                            <span class="badge {{ $hs === 'ok' ? 'badge-green' : ($hs === 'fail' ? 'badge-red' : '') }}">{{ $hs ? strtoupper($hs) : 'Pending' }}@if(($health['failcount'] ?? 0) > 0) · {{ $health['failcount'] }} fails @endif</span>
                        @endif
                    </div>
                    @if($healthUnsupported)
                        <p class="text-muted">Healthchecks need API 1.16+ / Cipi 5.0.7+ and the <code>health-view</code> / <code>health-manage</code> abilities.</p>
                    @else
                        <form wire:submit="saveHealth" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                            <div class="sm:col-span-3">
                                <label for="health-url">URL</label>
                                <input id="health-url" type="text" wire:model="healthUrl" placeholder="https://{{ $app['domain'] }}/up">
                                @error('healthUrl') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="health-expect">Expected status</label>
                                <input id="health-expect" type="number" wire:model="healthExpect" min="100" max="599">
                                @error('healthExpect') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-full flex flex-wrap items-center gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">{{ $healthEnabled ? 'Update' : 'Enable healthcheck' }}</button>
                                @if($healthEnabled)
                                    <button type="button" wire:click="runHealthCheck" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="runHealthCheck">Check now</button>
                                    <button type="button" wire:click="disableHealth" wire:confirm="Disable the healthcheck for this app?" class="btn btn-ghost btn-sm danger">Disable</button>
                                @endif
                                @if($healthCheckResult)
                                    <span class="text-sm ml-auto {{ ($healthCheckResult['ok'] ?? false) ? 'text-success' : 'text-danger' }}">
                                        Got {{ $healthCheckResult['got'] ?? '?' }} · expected {{ $healthCheckResult['expect'] ?? '?' }}
                                    </span>
                                @endif
                            </div>
                        </form>
                    @endif
                </div>

                @if($isLaravel)
                    <div class="card lg:col-span-2">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="search" /> Search</h2>
                                <p class="card-subtitle">Meilisearch for Laravel Scout, with a key scoped to this app.</p>
                            </div>
                            @if($searchStatus !== null && $this->searchEnabledForApp())
                                <span class="badge badge-green">Enabled</span>
                            @endif
                        </div>
                        @if($searchUnsupported)
                            <p class="text-muted">Needs API 1.31+ / Cipi 5.2.2+ and the <code>search-view</code> ability.</p>
                        @elseif($searchStatus === null)
                            <button wire:click="loadSearchStatus" class="btn btn-secondary btn-sm">Load search status</button>
                        @elseif(empty($searchStatus['installed']))
                            <p class="text-muted">Meilisearch is not installed on this server. Install it on the host with <code>cipi search install</code>.</p>
                        @else
                            <p class="text-sm text-muted mb-3">
                                Meilisearch {{ $searchStatus['version'] ?? '' }} · {{ !empty($searchStatus['running']) ? 'running' : 'stopped' }}
                                @if($this->searchEnabledForApp() && !empty($searchStatus['apps'][$appName]['prefix'])) · indexes <code>{{ $searchStatus['apps'][$appName]['prefix'] }}*</code>@endif
                            </p>
                            @if($this->searchEnabledForApp())
                                <button type="button" wire:click="disableSearch" wire:confirm="Disable Meilisearch for this app? The previous SCOUT_DRIVER is restored." class="btn btn-ghost btn-sm danger">Disable search</button>
                            @else
                                <button type="button" wire:click="enableSearch" @disabled(empty($searchStatus['running'])) class="btn btn-primary btn-sm">Enable search</button>
                            @endif
                        @endif
                    </div>
                @elseif($isNode && $nodeInfo)
                    <div class="card lg:col-span-2">
                        <div class="card-header">
                            <h2 class="card-title">Node runtime</h2>
                            @if($isSsr)
                                <button type="button" wire:click="restartNode" wire:confirm="Blue/green restart {{ $app['app'] }}?" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Restart</button>
                            @endif
                        </div>
                        <dl class="kv">
                            <dt>Mode</dt><dd>{{ $this->nodeModeLabel($nodeInfo['mode'] ?? null) }}</dd>
                            <dt>Version</dt><dd>Node {{ $nodeInfo['version'] ?? 'default' }}</dd>
                            <dt>Build</dt><dd class="font-mono text-xs">{{ $nodeInfo['build'] ?? '—' }}</dd>
                            @if($isSsr)
                                <dt>Start</dt><dd class="font-mono text-xs">{{ $nodeInfo['start'] ?? '—' }}</dd>
                                <dt>Health path</dt><dd class="font-mono text-xs">{{ $nodeInfo['health_path'] ?? '/' }}</dd>
                            @else
                                <dt>Output</dt><dd class="font-mono text-xs">{{ $nodeInfo['output'] ?? 'dist' }}</dd>
                            @endif
                        </dl>
                    </div>
                @endif
            </div>

        {{-- ═══ Domains & SSL ═══ --}}
        @elseif($activeTab === 'domains')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">Domains</h2>
                            <p class="card-subtitle">The primary domain plus aliases served by the same vhost.</p>
                        </div>
                    </div>
                    <ul>
                        <li class="list-row">
                            <span class="font-medium">{{ $app['domain'] }}</span>
                            <span class="badge badge-accent">Primary</span>
                        </li>
                        @foreach($aliases as $alias)
                            <li class="list-row" wire:key="alias-{{ $alias }}">
                                <span>{{ $alias }}</span>
                                <button type="button" wire:click="removeAlias(@js($alias))" wire:confirm="Remove alias {{ $alias }}?" class="btn btn-ghost btn-sm danger">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                    <form wire:submit="addAlias" class="mt-4">
                        <label for="new-alias">Add alias</label>
                        <div class="flex gap-2">
                            <input id="new-alias" type="text" wire:model="newAlias" placeholder="www.example.com" class="flex-1" autocomplete="off">
                            <button type="submit" class="btn btn-secondary">Add</button>
                        </div>
                        @error('newAlias') <p class="field-error">{{ $message }}</p> @enderror
                        <p class="field-hint">Re-run the certificate after adding hostnames.</p>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="shield" /> SSL certificate</h2>
                            <p class="card-subtitle">Let's Encrypt for the primary domain and every alias, renewed automatically.</p>
                        </div>
                        @if($app['force_https'] ?? false)
                            <span class="badge badge-green"><x-cipi::icon name="lock" class="h-3 w-3" /> HTTPS forced</span>
                        @else
                            <span class="badge badge-amber">HTTP allowed</span>
                        @endif
                    </div>
                    <div class="btn-group">
                        <button type="button" wire:click="installSsl" class="btn btn-primary"><x-cipi::icon name="shield" /> Issue / renew certificate</button>
                        <button type="button" wire:click="forceSsl" class="btn btn-secondary">Re-apply HTTPS redirect</button>
                    </div>
                    <p class="field-hint mt-3">Wildcard (<code>*.domain</code>) certificates need DNS-01 with a Cloudflare token — run <code>cipi ssl install {{ $app['app'] }} --dns=cloudflare --wildcard</code> on the host.</p>
                </div>

                <div class="card lg:col-span-2">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">www ↔ apex</h2>
                            <p class="card-subtitle">Serve both hostnames, or make one canonical with a 301.</p>
                        </div>
                        <button wire:click="loadWwwStatus" class="btn btn-ghost btn-sm"><x-cipi::icon name="refresh" /> Refresh</button>
                    </div>
                    @if($wwwUnsupported)
                        <p class="text-muted">Needs Cipi 4.8+ / API 1.12+ and the <code>www-manage</code> ability.</p>
                    @elseif($wwwStatus === null)
                        <div class="skeleton h-12 w-full"></div>
                    @else
                        @php $mode = $wwwStatus['redirect'] ?? null; @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                            <div class="card p-4 bg-raised"><p class="stat-label">Apex</p><p class="font-mono text-sm mt-1 break-words">{{ $wwwStatus['apex'] ?? '—' }}</p></div>
                            <div class="card p-4 bg-raised"><p class="stat-label">www</p><p class="font-mono text-sm mt-1 break-words">{{ $wwwStatus['www'] ?? '—' }}</p></div>
                            <div class="card p-4 bg-raised"><p class="stat-label">Canonical</p><p class="text-sm mt-1">{{ $this->wwwRedirectLabel($mode) }}</p></div>
                        </div>
                        <div class="btn-group">
                            <button wire:click="wwwAdd" class="btn btn-secondary btn-sm">Add the other hostname as alias</button>
                            <button wire:click="wwwForceToRoot" class="btn btn-sm {{ $mode === 'to-root' ? 'btn-primary' : 'btn-secondary' }}">www → apex</button>
                            <button wire:click="wwwForceFromRoot" class="btn btn-sm {{ $mode === 'from-root' ? 'btn-primary' : 'btn-secondary' }}">apex → www</button>
                            @if($mode)
                                <button wire:click="wwwClear" wire:confirm="Serve both hostnames without a redirect?" class="btn btn-ghost btn-sm danger">Clear redirect</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

        {{-- ═══ Routing ═══ --}}
        @elseif($activeTab === 'routing')
            @if($routingUnsupported)
                <x-cipi::alert type="warn" title="Routing needs a newer server">
                    Redirects and proxies need API 1.31+ and Cipi 5.4.1+ (run <code>cipi self-update</code>), plus the abilities
                    <code>redirects-view</code>, <code>redirects-manage</code>, <code>proxies-view</code>, <code>proxies-manage</code>.
                </x-cipi::alert>
            @elseif(! $routingLoaded)
                <div class="card"><div class="skeleton h-24 w-full"></div></div>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Whole-app redirect</h2>
                                <p class="card-subtitle">Every hostname redirects in one hop — handy for moved or retired sites. ACME challenges stay reachable.</p>
                            </div>
                            @if($appRedirect)
                                <span class="badge {{ !empty($appRedirect['enabled']) ? 'badge-blue' : 'badge-gray' }}">{{ !empty($appRedirect['enabled']) ? 'Active' : 'Saved · off' }}</span>
                            @endif
                        </div>
                        <form wire:submit="saveAppRedirect" class="space-y-3">
                            <div>
                                <label for="redirect-to">Target URL</label>
                                <input id="redirect-to" type="text" wire:model="redirectTo" placeholder="https://new.example.com">
                                @error('redirectTo') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-wrap items-end gap-4">
                                <div class="w-48">
                                    <label for="redirect-code">Status</label>
                                    <select id="redirect-code" wire:model="redirectCode">
                                        @foreach(['301' => '301 permanent', '302' => '302 temporary', '307' => '307 temporary', '308' => '308 permanent'] as $code => $label)
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="check pb-2"><input type="checkbox" wire:model="redirectKeepPath"> Keep path and query</label>
                            </div>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary btn-sm">Save redirect</button>
                                @if($appRedirect)
                                    <button type="button" wire:click="toggleAppRedirect" class="btn btn-secondary btn-sm">{{ !empty($appRedirect['enabled']) ? 'Disable' : 'Enable' }}</button>
                                    <button type="button" wire:click="removeAppRedirect" wire:confirm="Remove the whole-app redirect?" class="btn btn-ghost btn-sm danger">Remove</button>
                                @endif
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Path redirects</h2>
                                <p class="card-subtitle">A source ending in <code>/</code> matches the whole prefix.</p>
                            </div>
                        </div>
                        @if(empty($pathRedirects))
                            <p class="text-muted mb-4">No path redirects.</p>
                        @else
                            <ul class="mb-4">
                                @foreach($pathRedirects as $rule)
                                    <li class="list-row" wire:key="pr-{{ md5($rule['from'] ?? '') }}">
                                        <div class="min-w-0 flex-1">
                                            <p class="font-mono text-xs break-words"><span class="text-strong">{{ $rule['from'] ?? '' }}</span> <span class="text-subtle">→</span> {{ $rule['to'] ?? '' }}</p>
                                            <p class="text-2xs text-subtle mt-0.5">{{ $rule['code'] ?? 301 }}{{ !empty($rule['keep_path']) ? ' · keeps path' : '' }}</p>
                                        </div>
                                        <button type="button" wire:click="removePathRedirect(@js($rule['from'] ?? ''))" wire:confirm="Remove this redirect?" class="btn btn-ghost btn-sm danger">Remove</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form wire:submit="addPathRedirect" class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="pr-from">From</label>
                                    <input id="pr-from" type="text" wire:model="pathRedirectFrom" placeholder="/blog/" class="font-mono">
                                    @error('pathRedirectFrom') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="pr-to">To</label>
                                    <input id="pr-to" type="text" wire:model="pathRedirectTo" placeholder="https://blog.example.com/" class="font-mono">
                                    @error('pathRedirectTo') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="flex flex-wrap items-end gap-4">
                                <div class="w-32">
                                    <label for="pr-code">Status</label>
                                    <select id="pr-code" wire:model="pathRedirectCode">
                                        @foreach(['301', '302', '307', '308'] as $code)
                                            <option value="{{ $code }}">{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="check pb-2"><input type="checkbox" wire:model="pathRedirectKeepPath"> Keep path</label>
                                <button type="submit" class="btn btn-secondary btn-sm ml-auto mb-1">Add redirect</button>
                            </div>
                        </form>
                    </div>

                    <div class="card lg:col-span-2">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Prefix reverse proxies</h2>
                                <p class="card-subtitle">Send a path prefix to another service. Ports Cipi already uses (SSH, databases, Valkey, Meilisearch, other apps) are refused.</p>
                            </div>
                        </div>
                        @if(empty($proxies))
                            <p class="text-muted mb-4">No proxies.</p>
                        @else
                            <div class="table-scroll mb-4">
                                <table class="table-compact table-plain">
                                    <thead><tr><th>Prefix</th><th>Upstream</th><th>Options</th><th></th></tr></thead>
                                    <tbody>
                                        @foreach($proxies as $proxy)
                                            <tr wire:key="px-{{ md5($proxy['prefix'] ?? '') }}">
                                                <td class="font-mono text-xs text-strong">{{ $proxy['prefix'] ?? '' }}</td>
                                                <td class="font-mono text-xs">{{ $proxy['upstream'] ?? '' }}</td>
                                                <td>
                                                    <div class="flex flex-wrap gap-1">
                                                        <span class="badge">{{ $proxy['timeout'] ?? 60 }}s</span>
                                                        @if(!empty($proxy['strip_prefix']))<span class="badge">strip prefix</span>@endif
                                                        @if(!empty($proxy['preserve_host']))<span class="badge">preserve host</span>@endif
                                                        @if(!array_key_exists('buffering', $proxy) || $proxy['buffering'])<span class="badge">buffered</span>@else<span class="badge">streaming</span>@endif
                                                    </div>
                                                </td>
                                                <td class="text-right"><button type="button" wire:click="removeProxy(@js($proxy['prefix'] ?? ''))" wire:confirm="Remove this proxy?" class="btn btn-ghost btn-sm danger">Remove</button></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <form wire:submit="addProxy" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                            <div>
                                <label for="px-prefix">Prefix</label>
                                <input id="px-prefix" type="text" wire:model="proxyPrefix" placeholder="/api/" class="font-mono">
                                @error('proxyPrefix') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label for="px-upstream">Upstream</label>
                                <input id="px-upstream" type="text" wire:model="proxyUpstream" placeholder="http://127.0.0.1:3000" class="font-mono">
                                @error('proxyUpstream') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="px-timeout">Timeout (s)</label>
                                <input id="px-timeout" type="number" wire:model="proxyTimeout" min="1" max="3600">
                            </div>
                            <div class="col-span-full flex flex-wrap items-center gap-4">
                                <label class="check"><input type="checkbox" wire:model="proxyStripPrefix"> Strip prefix</label>
                                <label class="check"><input type="checkbox" wire:model="proxyPreserveHost"> Preserve Host header</label>
                                <label class="check"><input type="checkbox" wire:model="proxyBuffering"> Buffering</label>
                                <button type="submit" class="btn btn-secondary btn-sm ml-auto">Add proxy</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

        {{-- ═══ Deploy ═══ --}}
        @elseif($activeTab === 'deploy')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Deploy</h2></div>
                    <p class="text-sm text-muted mb-4">Zero-downtime release from <code>{{ $app['branch'] ?: 'main' }}</code>. Pushes deploy automatically through the webhook.</p>
                    <div class="space-y-2">
                        <button wire:click="deploy" class="btn btn-primary w-full"><x-cipi::icon name="rocket" /> Deploy now</button>
                        <button wire:click="rollback" wire:confirm="Roll back to the previous release?" class="btn btn-secondary w-full"><x-cipi::icon name="rollback" /> Roll back</button>
                        <button wire:click="unlockDeploy" wire:confirm="Remove the deploy lock? Only do this if no deploy is running." class="btn btn-ghost w-full"><x-cipi::icon name="unlock" /> Unlock a stuck deploy</button>
                    </div>
                    @if($app['repository'] !== '')
                        <div class="mt-5 pt-4 border-t">
                            <p class="font-medium text-strong mb-1">Git webhook</p>
                            <p class="text-xs text-muted mb-3">Recreate the GitHub/GitLab hook, or rotate <code>CIPI_WEBHOOK_TOKEN</code>.</p>
                            <div class="btn-group">
                                <button type="button" wire:click="recreateWebhook(false)" wire:confirm="Recreate the provider webhook?" class="btn btn-secondary btn-sm">Recreate</button>
                                <button type="button" wire:click="recreateWebhook(true)" wire:confirm="Rotate the webhook secret? The old one stops working immediately." class="btn btn-ghost btn-sm">Rotate secret</button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="card card-flush lg:col-span-2">
                    <div class="card-header p-5 mb-0">
                        <div>
                            <h2 class="card-title">Deploy audit</h2>
                            <p class="card-subtitle">Hash-chained ledger of every deploy — who or what started it, and from where.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <select wire:model.live="auditDays" class="input-sm w-auto" aria-label="Period">
                                @foreach(['7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '1 year'] as $d => $label)
                                    <option value="{{ $d }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="loadDeployAudit" class="btn btn-ghost btn-icon btn-sm" aria-label="Refresh audit"><x-cipi::icon name="refresh" /></button>
                        </div>
                    </div>
                    @if($auditUnsupported)
                        <div class="px-5 pb-5"><p class="text-muted">The audit needs API 1.31+ / Cipi 5.4.0+ and the <code>deploy-manage</code> ability.</p></div>
                    @elseif(! $auditLoaded)
                        <div class="px-5 pb-5"><div class="skeleton h-24 w-full"></div></div>
                    @elseif(empty($auditRecords))
                        <div class="px-5 pb-5"><p class="text-muted">No records in this period. The ledger starts with the first deploy after Cipi 5.4.0.</p></div>
                    @else
                        <div class="table-scroll max-h-panel overflow-y-auto">
                            <table class="table-compact">
                                <thead>
                                    <tr><th>#</th><th>When</th><th>Event</th><th>Release</th><th>Origin</th><th>By</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($auditRecords as $record)
                                        @php
                                            $event = (string) ($record['event'] ?? '');
                                            $ts = isset($record['ts']) ? \Illuminate\Support\Carbon::parse($record['ts']) : null;
                                            $claimed = is_array($record['claimed'] ?? null) ? array_filter($record['claimed']) : [];
                                        @endphp
                                        <tr wire:key="audit-{{ $record['seq'] ?? $loop->index }}">
                                            <td class="font-mono text-2xs text-subtle">{{ $record['seq'] ?? '' }}</td>
                                            <td class="whitespace-nowrap">
                                                <span class="text-strong" title="{{ $record['ts'] ?? '' }}">{{ $ts?->diffForHumans() ?? '—' }}</span>
                                                <span class="block text-2xs text-subtle">{{ $ts?->format('Y-m-d H:i') }}</span>
                                            </td>
                                            <td><span class="badge {{ match ($event) { 'published' => 'badge-green', 'failed' => 'badge-red', 'rollback' => 'badge-amber', default => '' } }}">{{ $event ?: '—' }}</span></td>
                                            <td class="font-mono text-xs whitespace-nowrap">
                                                {{ $record['release'] ?? '—' }}
                                                @if(!empty($record['commit']))<span class="text-subtle"> · {{ substr((string) $record['commit'], 0, 7) }}</span>@endif
                                            </td>
                                            <td class="text-xs"><span class="badge badge-neutral">{{ $record['origin'] ?? '—' }}</span>@if(!empty($record['trigger']))<span class="text-subtle"> {{ $record['trigger'] }}</span>@endif</td>
                                            <td class="text-xs">
                                                {{ $record['operator'] ?? '—' }}
                                                @if(!empty($record['ip']))<span class="block font-mono text-2xs text-subtle">{{ $record['ip'] }}</span>@endif
                                                @if($claimed)<span class="block text-2xs text-subtle" title="{{ json_encode($claimed) }}">{{ $claimed['note'] ?? ('claimed by '.($claimed['by'] ?? '?')) }}</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if($isLaravel)
                    <div class="card lg:col-span-3">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Deploy pipeline</h2>
                                <p class="card-subtitle">Structured Deployer options. Saving regenerates <code>deploy.php</code> from Cipi's template.</p>
                            </div>
                        </div>
                        @if($deployConfigUnsupported)
                            <p class="text-muted">Needs API 1.14+ / Cipi 5.0.3+ and the <code>apps-deploy-config</code> ability.</p>
                        @elseif(! $deployConfigLoaded)
                            <div class="skeleton h-20 w-full"></div>
                        @else
                            <form wire:submit="saveDeployConfig" class="space-y-4">
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                                    @foreach([
                                        'dcMigrate' => ['migrate', 'Run migrations'],
                                        'dcOptimize' => ['optimize', 'Cache config & routes'],
                                        'dcStorageLink' => ['storage:link', 'Public storage link'],
                                        'dcQueueRestart' => ['queue:restart', 'Reload workers'],
                                        'dcHorizonTerminate' => ['horizon:terminate', 'Restart Horizon'],
                                        'dcPredeploySnapshot' => ['DB snapshot', 'Dump before migrating'],
                                    ] as $prop => [$title, $text])
                                        <label class="check-card">
                                            <input type="checkbox" wire:model="{{ $prop }}">
                                            <span><span class="check-card-title font-mono text-xs">{{ $title }}</span><span class="check-card-text">{{ $text }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="dc-keep">Releases to keep</label>
                                        <input id="dc-keep" type="number" wire:model="dcKeepReleases" min="1" max="20">
                                        @error('dcKeepReleases') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="dc-node">Asset build</label>
                                        <input id="dc-node" type="text" wire:model="dcNodeBuild" placeholder="npm ci && npm run build — empty to skip" class="font-mono">
                                    </div>
                                </div>
                                <div>
                                    <label for="dc-extra">Extra Artisan commands <span class="text-subtle font-normal">(one per line, after migrations)</span></label>
                                    <textarea id="dc-extra" wire:model="dcExtraArtisan" rows="3" class="font-mono" placeholder="scout:sync-index-settings"></textarea>
                                </div>
                                <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save pipeline</button></div>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

        {{-- ═══ Environment ═══ --}}
        @elseif($activeTab === 'env')
            <div class="card" x-data="{ filter: '' }">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Environment</h2>
                        <p class="card-subtitle"><code>shared/.env</code> — changes apply on the next deploy or <code>config:cache</code>.</p>
                    </div>
                    @if($envLoaded)
                        <div class="flex items-center gap-2">
                            <div class="search-input"><x-cipi::icon name="search" /><input type="search" x-model="filter" placeholder="Filter keys…" class="input-sm" aria-label="Filter variables"></div>
                            <button wire:click="loadEnv" class="btn btn-ghost btn-icon btn-sm" aria-label="Reload"><x-cipi::icon name="refresh" /></button>
                        </div>
                    @endif
                </div>

                @if($envUnsupported)
                    <p class="text-muted">.env editing needs API 1.14+ / Cipi 5.0.3+ and the <code>apps-env</code> ability.</p>
                @elseif(! $envLoaded)
                    <div class="skeleton h-40 w-full"></div>
                @else
                    @php $changes = $this->envChangeCount(); @endphp
                    <div class="space-y-2 max-h-panel overflow-y-auto pr-1">
                        @forelse($envRows as $index => $row)
                            @php
                                $key = $row['key'];
                                $isNew = ! array_key_exists($key, $envOriginal);
                                $isChanged = ! $isNew && $envOriginal[$key] !== $row['value'];
                                $secret = $this->isSensitiveKey($key);
                            @endphp
                            <div class="env-row {{ $isNew ? 'is-new' : ($isChanged ? 'is-changed' : '') }}" wire:key="env-{{ $key }}"
                                 x-show="!filter || @js(strtolower($key)).includes(filter.toLowerCase())"
                                 x-data="{ reveal: {{ $secret ? 'false' : 'true' }} }">
                                <input type="text" value="{{ $key }}" class="font-mono input-sm" readonly tabindex="-1" aria-label="Key">
                                <div class="relative">
                                    <input :type="reveal ? 'text' : 'password'" wire:model.live.debounce.400ms="envRows.{{ $index }}.value" class="font-mono input-sm" autocomplete="off" aria-label="Value of {{ $key }}" style="{{ $secret ? 'padding-right:2.25rem' : '' }}">
                                    @if($secret)
                                        <button type="button" class="absolute text-subtle" style="right:.5rem;top:50%;transform:translateY(-50%)" x-on:click="reveal = !reveal" :aria-label="reveal ? 'Hide value' : 'Show value'">
                                            <x-cipi::icon name="eye" x-show="!reveal" /><x-cipi::icon name="eye-off" x-show="reveal" x-cloak />
                                        </button>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeEnvRow({{ $index }})" class="btn btn-ghost btn-icon btn-sm danger" aria-label="Remove {{ $key }}"><x-cipi::icon name="trash" /></button>
                            </div>
                        @empty
                            <p class="text-muted">The file is empty.</p>
                        @endforelse
                    </div>

                    <form wire:submit="addEnvRow" class="env-row mt-4 pt-4 border-t">
                        <input type="text" wire:model="envNewKey" class="font-mono input-sm" placeholder="NEW_KEY" autocomplete="off" aria-label="New key">
                        <input type="text" wire:model="envNewValue" class="font-mono input-sm" placeholder="value" autocomplete="off" aria-label="New value">
                        <button type="submit" class="btn btn-secondary btn-sm"><x-cipi::icon name="plus" /> Add</button>
                    </form>
                    @error('envNewKey') <p class="field-error">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                        <p class="text-xs {{ $changes ? 'text-accent' : 'text-subtle' }}">{{ $changes ? $changes.' unsaved '.\Illuminate\Support\Str::plural('change', $changes) : count($envRows).' variables · sensitive values are hidden' }}</p>
                        <div class="btn-group">
                            @if($changes)
                                <button type="button" wire:click="resetEnv" class="btn btn-ghost">Discard</button>
                            @endif
                            <button type="button" wire:click="saveEnv" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveEnv">Save .env</button>
                        </div>
                    </div>
                @endif
            </div>

        {{-- ═══ Composer auth.json ═══ --}}
        @elseif($activeTab === 'authjson')
            <div class="card max-w-3xl">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Composer auth.json</h2>
                        <p class="card-subtitle">Credentials for private Composer repositories (Nova, Spark, Satis…), shared across releases. Not the same as HTTP basic auth.</p>
                    </div>
                    @if($authJsonLoaded)
                        <span class="badge {{ $authJsonExists ? 'badge-green' : 'badge-gray' }}">{{ $authJsonExists ? 'shared/auth.json' : 'Not created' }}</span>
                    @endif
                </div>
                @if($authJsonUnsupported)
                    <p class="text-muted">Needs API 1.14+ and the <code>apps-auth</code> ability.</p>
                @elseif(! $authJsonLoaded)
                    <div class="skeleton h-40 w-full"></div>
                @else
                    <textarea wire:model="authJsonContent" rows="14" class="font-mono text-sm" spellcheck="false" aria-label="auth.json"></textarea>
                    @error('authJsonContent') <p class="field-error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                        <p class="text-xs text-subtle">{{ $authJsonExists ? 'Saving replaces the whole file.' : 'Edit the template, then create the file.' }}</p>
                        <div class="btn-group">
                            @if($authJsonExists)
                                <button wire:click="deleteAuthJson" wire:confirm="Delete shared/auth.json for this app?" class="btn btn-ghost danger">Delete</button>
                            @endif
                            <button wire:click="saveAuthJson" class="btn btn-primary">{{ $authJsonExists ? 'Save' : 'Create auth.json' }}</button>
                        </div>
                    </div>
                @endif
            </div>

        {{-- ═══ Artisan ═══ --}}
        @elseif($activeTab === 'artisan')
            <div class="card max-w-3xl">
                <div class="card-header">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="terminal" /> Artisan</h2>
                        <p class="card-subtitle">Runs as the app user in the current release. Interactive commands like <code>tinker</code> are refused.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-4">
                    @foreach($artisanPresets as $preset)
                        <button type="button" wire:click="useArtisanPreset(@js($preset))" class="btn btn-secondary btn-xs font-mono">{{ $preset }}</button>
                    @endforeach
                </div>
                <form wire:submit="runArtisan">
                    <div class="input-group">
                        <span class="input-addon">php artisan</span>
                        <input type="text" wire:model="artisanCommand" class="font-mono flex-1" placeholder="migrate --force" autocomplete="off" aria-label="Artisan command">
                        <button type="submit" class="btn btn-primary"><x-cipi::icon name="play" /> Run</button>
                    </div>
                    @error('artisanCommand') <p class="field-error">{{ $message }}</p> @enderror
                </form>
            </div>

        {{-- ═══ Commands ═══ --}}
        @elseif($activeTab === 'run')
            <div class="card max-w-3xl">
                <div class="card-header">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="code" /> Commands</h2>
                        <p class="card-subtitle">Whitelisted, non-interactive commands in <code>/home/{{ $app['app'] }}</code> — composer, npm, git, file tools.</p>
                    </div>
                </div>
                @if($runUnsupported)
                    <p class="text-muted">Needs API 1.14+ / Cipi 5.0.3+ and the <code>apps-run</code> ability.</p>
                @else
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        @foreach($runPresets as $preset)
                            <button type="button" wire:click="useRunPreset(@js($preset))" class="btn btn-secondary btn-xs font-mono">{{ $preset }}</button>
                        @endforeach
                    </div>
                    <form wire:submit="runAppCommand">
                        <div class="input-group">
                            <span class="input-addon">$</span>
                            <input type="text" wire:model="runCommand" class="font-mono flex-1" placeholder="composer install --no-dev --no-interaction" autocomplete="off" aria-label="Command">
                            <button type="submit" class="btn btn-primary"><x-cipi::icon name="play" /> Run</button>
                        </div>
                        @error('runCommand') <p class="field-error">{{ $message }}</p> @enderror
                    </form>
                    @if($runLoaded && $runAllowedCommands)
                        <details class="mt-4 text-sm">
                            <summary class="cursor-pointer text-muted">Allowed programs ({{ count($runAllowedCommands) }})</summary>
                            <div class="flex flex-wrap gap-1 mt-3">
                                @foreach($runAllowedCommands as $cmd)<span class="badge badge-mono">{{ $cmd }}</span>@endforeach
                            </div>
                            @if($runNotes)
                                <ul class="mt-3 space-y-1 text-xs text-muted" style="list-style:disc;padding-left:1.1rem;">
                                    @foreach($runNotes as $note)<li>{{ $note }}</li>@endforeach
                                </ul>
                            @endif
                        </details>
                    @endif
                @endif
            </div>

        {{-- ═══ Access (basic auth) ═══ --}}
        @elseif($activeTab === 'access')
            <div class="card max-w-xl">
                <div class="card-header">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="key" /> HTTP basic auth</h2>
                        <p class="card-subtitle">Put a password in front of the whole site — staging, previews, launches.</p>
                    </div>
                    @if($basicAuth !== null)
                        <span class="badge {{ ($basicAuth['enabled'] ?? false) ? 'badge-green' : 'badge-gray' }}">{{ ($basicAuth['enabled'] ?? false) ? 'Enabled' : 'Off' }}</span>
                    @endif
                </div>
                @if($basicAuth === null)
                    <div class="skeleton h-20 w-full"></div>
                @elseif($basicAuth['enabled'] ?? false)
                    <p class="text-sm text-muted mb-4">Users: <span class="font-mono text-strong">{{ implode(', ', $basicAuth['users'] ?? []) ?: '—' }}</span></p>
                    @if($generatedPassword)
                        <div class="card p-4 mb-4" style="border-color: var(--accent-line);">
                            <p class="text-xs text-muted mb-1">Generated password — shown once</p>
                            <x-cipi::secret label="Password" :value="$generatedPassword" />
                        </div>
                    @endif
                    <button wire:click="disableBasicAuth" wire:confirm="Remove the password prompt from this site?" class="btn btn-danger">Disable basic auth</button>
                @else
                    <form wire:submit="enableBasicAuth" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="ba-user">Username</label>
                                <input id="ba-user" type="text" wire:model="basicAuthUser" autocomplete="off">
                                @error('basicAuthUser') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="ba-pass">Password</label>
                                <input id="ba-pass" type="password" wire:model="basicAuthPassword" placeholder="Leave empty to generate" autocomplete="new-password">
                                @error('basicAuthPassword') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Enable basic auth</button>
                    </form>
                @endif
            </div>

        {{-- ═══ Logs ═══ --}}
        @elseif($activeTab === 'logs')
            @livewire('cipi-gui.log-viewer', [
                'app' => $appName,
                'serverId' => $serverId,
                'isCustomApp' => (bool) ($app['custom'] ?? false),
            ], key('logs-'.$appName))
        @endif

        @include('cipi-gui::partials.job-overlay')

        @if($showDeleteModal)
            <x-cipi::modal title="Delete app" close="cancelDeleteApp">
                <form wire:submit="deleteApp">
                    <div class="modal-body space-y-4">
                        <x-cipi::alert type="danger" title="This cannot be undone">
                            Deleting <strong>{{ $appName }}</strong> removes its Linux user and home, vhost, PHP pool or Node process, workers and database.
                        </x-cipi::alert>
                        <div>
                            <label for="delete-confirm">Type <code>{{ $appName }}</code> to confirm</label>
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
    @endif
</div>
