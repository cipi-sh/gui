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
            // A page left open across an update of the panel still carries an app without `user`.
            $appUser = (string) ($app['user'] ?? '');
        @endphp

        <x-cipi::page-header :title="$app['app']">
            <x-slot:eyebrow>
                <a href="{{ route('cipi-gui.apps') }}">Apps</a>
                <x-cipi::icon name="chevron-right" class="h-3 w-3" />
                @if($server)<a href="{{ route('cipi-gui.apps', ['server' => $server->id]) }}" title="Apps on {{ $server->name }}">{{ $server->name }}</a>@endif
            </x-slot:eyebrow>
            <x-slot:meta>
                <span class="flex flex-wrap items-center gap-2 mt-1">
                    @if($app['suspended'] ?? false)
                        <span class="badge badge-amber"><x-cipi::icon name="pause" class="h-3 w-3" /> Suspended</span>
                    @else
                        <span class="badge badge-green"><span class="dot dot-green" style="width:.4rem;height:.4rem;box-shadow:none"></span> Live</span>
                    @endif
                    <span class="badge {{ $this->envBadgeClass($app['env']) }}" title="Environment" data-app-env="{{ $app['env'] }}">{{ $app['env'] }}</span>
                    <span class="badge {{ $isLaravel ? 'badge-accent' : ($isNode ? 'badge-blue' : 'badge-neutral') }}">{{ $this->appKindLabel($app) }}</span>
                    <span class="badge">{{ $this->appRuntimeLabel($app) }}</span>
                    @if($app['force_https'] ?? false)<span class="badge"><x-cipi::icon name="lock" class="h-3 w-3" /> HTTPS</span>@endif
                    <a href="{{ $this->siteUrl() }}" target="_blank" rel="noopener" class="text-sm text-soft inline-flex items-center gap-1 ml-1">{{ $app['domain'] }} <x-cipi::icon name="external" class="h-3.5 w-3.5" /></a>
                    @if($server)
                        <a href="{{ route('cipi-gui.server-manage', ['serverId' => $server->id]) }}" class="text-sm text-soft inline-flex items-center gap-1 ml-1" title="Open the server this app runs on" data-server-link><x-cipi::icon name="server" class="h-3.5 w-3.5" /> {{ $server->name }}</a>
                        @if($serverIp)<x-cipi::copyable :value="$serverIp" label="Copy server IP" class="font-mono text-xs text-soft" />@endif
                    @endif
                </span>
            </x-slot:meta>
            <x-slot:actions>
                @if(! $isNode && ! $isLaravel && $app['repository'] === '')
                    <span class="text-xs text-muted">SFTP-only app</span>
                @elseif($can['deploy'] ?? false)
                    <button type="button" wire:click="deploy" @disabled($this->deployActive()) class="btn btn-primary" @if($this->deployActive()) title="A deploy is already running" @endif><x-cipi::icon name="rocket" /> Deploy</button>
                @endif
                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <button type="button" class="btn btn-secondary" x-on:click="open = !open" :aria-expanded="open">Actions <x-cipi::icon name="chevron-down" class="h-3.5 w-3.5" /></button>
                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                        <a href="{{ $this->siteUrl() }}" target="_blank" rel="noopener" class="dropdown-item"><x-cipi::icon name="external" /> Open site</a>
                        @if($server)
                            <a href="{{ route('cipi-gui.server-manage', ['serverId' => $server->id]) }}" class="dropdown-item"><x-cipi::icon name="server" /> Server: {{ $server->name }}</a>
                        @endif
                        @if($can['edit'] ?? false)
                            @if($isSsr)
                                <button type="button" class="dropdown-item" wire:click="restartNode" data-confirm="Blue/green restart {{ $app['app'] }}? The new process must pass its health path before traffic switches." x-on:click="open = false"><x-cipi::icon name="refresh" /> Restart Node process</button>
                            @endif
                            <button type="button" class="dropdown-item" wire:click="fixPermissions" data-confirm="Restore ownership and modes of /home/{{ $app['app'] }}?" x-on:click="open = false"><x-cipi::icon name="wrench" /> Fix permissions</button>
                            @if($app['suspended'] ?? false)
                                <button type="button" class="dropdown-item" wire:click="unsuspendApp" x-on:click="open = false"><x-cipi::icon name="play" /> Unsuspend</button>
                            @else
                                <button type="button" class="dropdown-item" wire:click="suspendApp" data-confirm="Take {{ $app['app'] }} offline with an HTTP 503 maintenance page?" x-on:click="open = false"><x-cipi::icon name="pause" /> Suspend (maintenance page)</button>
                            @endif
                        @endif
                        @if($can['delete'] ?? false)
                            <div class="dropdown-divider"></div>
                            <button type="button" class="dropdown-item danger" wire:click="confirmDeleteApp" x-on:click="open = false"><x-cipi::icon name="trash" /> Delete app</button>
                        @endif
                    </div>
                </div>
            </x-slot:actions>
        </x-cipi::page-header>

        {{-- Deploy in progress — whoever started it (API 1.37+ / Cipi 5.8.4+). Polled fast while a run is on, slowly otherwise;
             the two spans have different keys so a changed interval re-registers the poll. --}}
        @unless($deployStatusUnsupported)
            @if($this->deployActive())
                <span wire:key="deploy-poll-fast" wire:poll.3s="pollDeployStatus" aria-hidden="true"></span>
            @else
                <span wire:key="deploy-poll-slow" wire:poll.10s="pollDeployStatus" aria-hidden="true"></span>
            @endif
        @endunless
        @if($banner = $this->deployBanner())
            <div class="alert alert-{{ $banner['variant'] }} mb-4" role="status" data-deploy-status="{{ $banner['state'] }}">
                @if($banner['state'] === 'interrupted')
                    <x-cipi::icon name="warning" class="shrink-0" />
                @else
                    <div class="spinner shrink-0" aria-hidden="true"></div>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="alert-title flex flex-wrap items-center gap-2">
                        <span>{{ $banner['title'] }}</span>
                        @if($banner['elapsed'] !== null && $banner['state'] === 'running')
                            <span class="badge badge-neutral tabular-nums" title="Running for"
                                  x-data="{ start: Date.now() - {{ (int) $banner['elapsed'] }} * 1000, now: Date.now() }"
                                  x-init="setInterval(() => now = Date.now(), 1000)"
                                  x-text="(s => s < 60 ? s + 's' : Math.floor(s / 60) + 'm' + String(s % 60).padStart(2, '0') + 's')(Math.max(0, Math.round((now - start) / 1000)))">{{ \CipiGui\Support\DeployLog::format($banner['elapsed']) }}</span>
                        @endif
                        @if($banner['branch'] !== '')<span class="badge"><x-cipi::icon name="branch" class="h-3 w-3" /> {{ $banner['branch'] }}</span>@endif
                    </p>
                    <p class="text-sm">{{ trim($banner['detail'].' '.$banner['started_text']) }}</p>
                    @if($deployTail !== [])
                        <details class="mt-2" x-data="{ open: {{ $banner['state'] === 'running' ? 'true' : 'false' }} }" x-bind:open="open" x-on:toggle="open = $event.target.open">
                            <summary class="text-xs text-muted cursor-pointer select-none">Last lines of the deploy log</summary>
                            <div class="mt-2">@include('cipi-gui::partials.terminal', ['lines' => $deployTail, 'title' => 'deploy.log', 'subtitle' => $banner['state'] === 'running' ? 'live' : null])</div>
                        </details>
                    @endif
                    @if($banner['state'] === 'interrupted' && ($can['deploy'] ?? false))
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="unlockDeploy" data-confirm="Remove the deploy lock? Only do this if no deploy is running." class="btn btn-secondary btn-sm"><x-cipi::icon name="unlock" /> Unlock</button>
                            <button type="button" wire:click="setTab('deploy')" class="btn btn-ghost btn-sm">Deploy tab</button>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- A commit whose automatic deploy failed (Cipi 5.13.0): the webhook skips it
             until a new push arrives; the queue behind it is free. --}}
        @php $pausedCommit = $this->deployActive() ? null : $this->pausedCommit(); @endphp
        @if($pausedCommit !== null)
            <div class="alert alert-warn mb-4" role="status" data-deploy-paused>
                <x-cipi::icon name="pause" class="h-5 w-5 shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="alert-title">Automatic deploys paused on commit <code>{{ substr($pausedCommit, 0, 12) }}</code></p>
                    <p class="text-sm">Its deploy failed, so the webhook does not try the same commit again. The next push deploys as usual; a deploy started here that succeeds resumes them too.</p>
                    @if($can['deploy'] ?? false)
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="deploy" class="btn btn-secondary btn-sm"><x-cipi::icon name="rocket" /> Deploy now</button>
                            <button type="button" wire:click="unlockDeploy" data-confirm="Let the webhook deploy commit {{ substr($pausedCommit, 0, 12) }} again?" class="btn btn-ghost btn-sm"><x-cipi::icon name="play" /> Resume automatic deploys</button>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @unless($can['edit'] ?? false)
            <x-cipi::alert type="info" class="mb-4">Read-only: your access lets you look at this app{{ ($can['deploy'] ?? false) ? ' and deploy it' : '' }}, not change it.</x-cipi::alert>
        @endunless

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
                        <dt>Environment</dt>
                        <dd><span class="badge {{ $this->envBadgeClass($app['env']) }}">{{ $app['env'] }}</span> <span class="text-xs text-muted">· deploys read <code>cipi-{{ $app['env'] }}.yml</code>, then <code>cipi.yml</code></span></dd>
                        <dt>Domains</dt>
                        <dd>
                            {{ $app['domain'] }}
                            @foreach($aliases as $alias)<br><span class="text-muted">{{ $alias }}</span>@endforeach
                        </dd>
                        {{-- Where to connect and as whom: the app's Linux user, the address of its server. --}}
                        <dt>User</dt>
                        <dd>
                            @if($appUser !== '')
                                <x-cipi::copyable :value="$appUser" label="Copy user" class="font-mono text-xs" data-app-user /> <span class="text-xs text-muted">· SSH and SFTP</span>
                            @else
                                —
                            @endif
                        </dd>
                        <dt>Server IP</dt>
                        <dd>
                            @if($serverIp)
                                <x-cipi::copyable :value="$serverIp" label="Copy server IP" class="font-mono text-xs" data-server-ip />@if($server) <span class="text-xs text-muted">· {{ $server->name }}</span>@endif
                            @else
                                <span class="text-muted" data-server-ip-unknown>Not known yet</span>
                                <span class="text-xs text-muted">· read from the server's status when the dashboard loads, or typed under @if($isAdmin)<a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>@else Connections by an admin @endif</span>
                            @endif
                        </dd>
                        @if($sshCommand)
                            <dt>SSH</dt>
                            <dd><x-cipi::copyable :value="$sshCommand" label="Copy SSH command" class="font-mono text-xs" data-ssh-command /></dd>
                        @endif
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
                            <div>
                                <label for="edit-env">Environment</label>
                                <input id="edit-env" type="text" wire:model="editEnv" list="edit-env-options" class="font-mono" placeholder="production" maxlength="{{ $envMaxLength }}" autocomplete="off" autocapitalize="none" spellcheck="false">
                                <datalist id="edit-env-options">
                                    @foreach($envSuggestions as $suggestion)<option value="{{ $suggestion }}"></option>@endforeach
                                </datalist>
                                <p class="field-hint">production, staging, development, testing or your own word — lowercase letters, digits, <code>-</code>, <code>_</code>. Picks <code>cipi-&lt;environment&gt;.yml</code> over <code>cipi.yml</code> on deploys.</p>
                                @error('editEnv') <p class="field-error">{{ $message }}</p> @enderror
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
                                    @php
                                        // The version the app runs on stays selectable even when it is not
                                        // one of those on offer: a legacy one, or one the list does not have.
                                        $currentPhp = (string) ($app['php'] ?? '');
                                        $extraPhp = array_values(array_unique(array_filter([$currentPhp, $editPhp], fn ($v) => $v !== '' && ! in_array($v, $phpVersions, true))));
                                        $appOnLegacyPhp = in_array($currentPhp, $legacyPhpVersions, true);
                                    @endphp
                                    <select id="edit-php" wire:model="editPhp">
                                        @foreach($extraPhp as $ver)
                                            <option value="{{ $ver }}">PHP {{ $ver }} {{ in_array($ver, $legacyPhpVersions, true) ? '(legacy)' : '(not installed?)' }}</option>
                                        @endforeach
                                        @foreach($phpVersions as $ver)
                                            <option value="{{ $ver }}">PHP {{ $ver }}</option>
                                        @endforeach
                                    </select>
                                    <p class="field-hint">
                                        @if($phpListUnsupported) PHP list unavailable — showing local hints. @else Installed on this server · <a href="{{ route('cipi-gui.server-manage') }}?tab=php" class="text-link">manage PHP</a> @endif
                                    </p>
                                    @if($appOnLegacyPhp)
                                        <p class="field-hint" data-php-legacy>PHP {{ $currentPhp }} is a legacy version. The app keeps running on it, but a server with Deployer 8 refuses to deploy it, and once moved to a current version it cannot go back.</p>
                                    @endif
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
                            <p class="card-subtitle">Probed every 5 minutes, and right after every deploy — the deploy's verdict then says whether the new release answers. Three failures in a row send the <code>health_fail</code> notification.</p>
                        </div>
                        @if($healthEnabled)
                            @php $hs = $health['state'] ?? null; @endphp
                            <span class="badge {{ $hs === 'ok' ? 'badge-green' : ($hs === 'fail' ? 'badge-red' : '') }}">{{ $hs ? strtoupper($hs) : 'Pending' }}@if(($health['failcount'] ?? 0) > 0) · {{ $health['failcount'] }} fails @endif</span>
                        @endif
                    </div>
                    @if($healthUnsupported)
                        <p class="text-muted">Healthchecks need an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>health-view</code> / <code>health-manage</code> abilities.</p>
                    @else
                        <form wire:submit="saveHealth" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-start">
                            <div class="sm:col-span-3">
                                <label for="health-url">URL</label>
                                <input id="health-url" type="text" wire:model="healthUrl" placeholder="https://{{ $app['domain'] }}/up">
                                @error('healthUrl') <p class="field-error">{{ $message }}</p> @enderror
                                <p class="field-hint">Laravel answers <code>/up</code> only with the health route on (<code>bootstrap/app.php</code>: <code>health: '/up'</code>); without it the check gets a 404.</p>
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
                                    <button type="button" wire:click="disableHealth" data-confirm="Disable the healthcheck for this app?" class="btn btn-ghost btn-sm danger">Disable</button>
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
                            <p class="text-muted">Needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>search-view</code> ability.</p>
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
                                <button type="button" wire:click="disableSearch" data-confirm="Disable Meilisearch for this app? The previous SCOUT_DRIVER is restored." class="btn btn-ghost btn-sm danger">Disable search</button>
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
                                <button type="button" wire:click="restartNode" data-confirm="Blue/green restart {{ $app['app'] }}?" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Restart</button>
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
                                <button type="button" wire:click="removeAlias(@js($alias))" data-confirm="Remove alias {{ $alias }}?" class="btn btn-ghost btn-sm danger">Remove</button>
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
                        <p class="text-muted">Needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>www-manage</code> ability.</p>
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
                                <button wire:click="wwwClear" data-confirm="Serve both hostnames without a redirect?" class="btn btn-ghost btn-sm danger">Clear redirect</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

        {{-- ═══ Routing ═══ --}}
        @elseif($activeTab === 'routing')
            @if($routingUnsupported)
                <x-cipi::alert type="warn" title="Routing needs a newer server">
                    Redirects and proxies need an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>), plus the abilities
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
                            <div class="flex flex-wrap items-start gap-4">
                                <div class="w-48">
                                    <label for="redirect-code">Status</label>
                                    <select id="redirect-code" wire:model="redirectCode">
                                        @foreach(['301' => '301 permanent', '302' => '302 temporary', '307' => '307 temporary', '308' => '308 permanent'] as $code => $label)
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="check field-action"><input type="checkbox" wire:model="redirectKeepPath"> Keep path and query</label>
                            </div>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary btn-sm">Save redirect</button>
                                @if($appRedirect)
                                    <button type="button" wire:click="toggleAppRedirect" class="btn btn-secondary btn-sm">{{ !empty($appRedirect['enabled']) ? 'Disable' : 'Enable' }}</button>
                                    <button type="button" wire:click="removeAppRedirect" data-confirm="Remove the whole-app redirect?" class="btn btn-ghost btn-sm danger">Remove</button>
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
                                        <button type="button" wire:click="removePathRedirect(@js($rule['from'] ?? ''))" data-confirm="Remove this redirect?" class="btn btn-ghost btn-sm danger">Remove</button>
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
                            <div class="flex flex-wrap items-start gap-4">
                                <div class="w-32">
                                    <label for="pr-code">Status</label>
                                    <select id="pr-code" wire:model="pathRedirectCode">
                                        @foreach(['301', '302', '307', '308'] as $code)
                                            <option value="{{ $code }}">{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <label class="check field-action"><input type="checkbox" wire:model="pathRedirectKeepPath"> Keep path</label>
                                <button type="submit" class="btn btn-secondary field-action ml-auto">Add redirect</button>
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
                                                <td class="text-right"><button type="button" wire:click="removeProxy(@js($proxy['prefix'] ?? ''))" data-confirm="Remove this proxy?" class="btn btn-ghost btn-sm danger">Remove</button></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <form wire:submit="addProxy" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-start">
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

                    {{-- The app's own nginx directives (cipi/api 1.44, Cipi 5.14.0). nginx configuration
                         sits next to every other app's: the card is for whoever manages the server. --}}
                    @if($canManageNginx)
                        <div class="card lg:col-span-2" data-nginx-directives>
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title">Nginx directives</h2>
                                    <p class="card-subtitle">Headers, caching, limits, rewrites, <code>location</code> blocks of this app's own. Cipi keeps them and includes them in the app's server block, so they survive every change of the vhost. Tested with <code>nginx -t</code> before nginx reloads: a refused text changes nothing.</p>
                                </div>
                                @if($nginxLoaded && trim($nginxSaved) !== '')
                                    <span class="badge {{ $nginxActive ? 'badge-green' : 'badge-amber' }}">{{ $nginxActive ? 'Live' : 'Saved — not in the vhost now' }}</span>
                                @endif
                            </div>
                            @if($nginxUnavailable)
                                <p class="text-muted" data-nginx-unavailable>{{ $nginxUnavailable }}</p>
                            @elseif(! $nginxLoaded)
                                <div class="skeleton h-24 w-full"></div>
                            @else
                                <form wire:submit="saveNginx">
                                    <label for="nginx-config" class="sr-only">Nginx directives of {{ $app['app'] }}</label>
                                    <textarea id="nginx-config" wire:model="nginxConfig" class="font-mono text-xs" rows="{{ min(24, max(8, substr_count($nginxConfig, "\n") + 3)) }}"
                                              spellcheck="false" autocomplete="off" autocapitalize="none"
                                              placeholder="client_max_body_size 512M;&#10;&#10;location ^~ /downloads/ {&#10;    add_header Cache-Control &quot;public, max-age=31536000, immutable&quot;;&#10;}"></textarea>
                                    @if($nginxReasons !== [])
                                        <x-cipi::alert type="danger" title="Refused — nothing changed on the server" class="mt-3" data-nginx-refused>
                                            <ul class="space-y-1 mt-1">
                                                @foreach($nginxReasons as $reason)
                                                    <li class="font-mono text-xs">{{ $reason }}</li>
                                                @endforeach
                                            </ul>
                                        </x-cipi::alert>
                                    @endif
                                    <p class="field-hint">From the panel a subset of nginx's language is accepted: no <code>access_log</code> or <code>error_log</code> path, <code>include</code>, <code>listen</code>, <code>server_name</code>, <code>*_pass</code> or certificates; <code>root</code> and <code>alias</code> under <code>/home/{{ $app['app'] }}/</code>. Reverse proxies and redirects are the cards above. The whole language is <code>cipi nginx edit {{ $app['app'] }}</code> on the server.</p>
                                    <div class="btn-group mt-3">
                                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveNginx">Save and reload nginx</button>
                                        @if(trim($nginxSaved) !== '')
                                            <button type="button" wire:click="resetNginx" data-confirm="Remove the nginx directives of {{ $app['app'] }}?" class="btn btn-ghost btn-sm danger">Remove</button>
                                        @endif
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

        {{-- ═══ Deploy ═══ --}}
        {{-- ═══ Page cache: nginx answers with the page PHP made (Cipi 5.17.0) ═══ --}}
        @elseif($activeTab === 'cache')
            @php
                $pc = $pageCache ?? [];
                $pcOn = (bool) ($pc['enabled'] ?? false);
                $pcLive = $pcOn && (bool) ($pc['active'] ?? false);
                $pcKind = ($pc['kind'] ?? null) === 'proxy' ? 'proxy_cache (Octane)' : 'fastcgi_cache (PHP-FPM)';
                $pcClear = \CipiGui\Support\PageCache::clearCommand($pageCache, $app['app']);
                $pcMayClear = ($can['deploy'] ?? false) || ($can['edit'] ?? false);
                $ttlChoices = \CipiGui\Support\PageCache::TTL_CHOICES;
                $sizeChoices = \CipiGui\Support\PageCache::SIZE_CHOICES;
                // What goes in a recipe of one's own: built here, so that no Blade directive has to read these parentheses.
                $pcRecipe = [
                    '// deploy.php (Deployer) — after the new release is live',
                    "after('deploy:symlink', function () {",
                    "    run('".$pcClear."');",
                    '});',
                    '',
                    '# a shell script, or a CI job over SSH',
                    'ssh '.$app['app'].chr(64).($serverIp ?: 'your-server')." '".$pcClear."'",
                ];
                $pcWordpress = [
                    '# as the app\'s user, in its WordPress folder',
                    'cd ~/htdocs',
                    'wp plugin install '.\CipiGui\Support\PageCache::WP_PLUGIN_ZIP.' --activate',
                    'wp cipi-cache status',
                ];
                $pcPhp = [
                    'composer require cipi/cache',
                    '',
                    '// the pages a change touches, once at the end of the request',
                    "CipiCache::purge('/blog/hello-world/', '/blog/*');",
                    "CipiCache::purgeRoute('posts.show', \$post);",
                ];
                $pcSome = $pcClear." /blog/hello-world/ '/blog/*'";
                $pcCheck = 'curl -sI '.$this->siteUrl().' | grep -i x-cache-status';
                // The app's own paths: shown when the server gives them.
                $pcHasExclude = $pageCacheLoaded && array_key_exists('exclude', $pc) && ($pc['supported'] ?? true);
                $pcExclude = \CipiGui\Support\PageCache::excludes($pageCache);
                $pcExcludeHint = implode("\n", ['/cart*', '/checkout*', '/my-account*', '/feed']);
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4" data-page-cache>
                <div class="lg:col-span-3 space-y-4">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="bolt" /> Page cache</h2>
                                <p class="card-subtitle">nginx keeps the page PHP made and answers the next visitors with it — for a page that is kept, PHP does not run at all.</p>
                            </div>
                            @if($pageCacheLoaded)
                                <span class="badge {{ $pcLive ? 'badge-green' : ($pcOn ? 'badge-amber' : 'badge-gray') }}" data-page-cache-state>{{ $pcLive ? 'On' : ($pcOn ? 'On — not in the vhost now' : 'Off') }}</span>
                            @endif
                        </div>
                        @if($pageCacheUnavailable)
                            <p class="text-muted" data-page-cache-unavailable>{{ $pageCacheUnavailable }}</p>
                        @elseif(! $pageCacheLoaded)
                            <div class="skeleton h-24 w-full"></div>
                        @elseif(! ($pc['supported'] ?? true))
                            <p class="text-muted">Not available for a Node app: its pages come from its own server.</p>
                        @else
                            @if($pcOn)
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                    <div class="card p-4 bg-raised"><p class="stat-label">Pages kept now</p><p class="stat-value-sm tabular-nums mt-1" data-page-cache-files>{{ number_format((int) ($pc['files'] ?? 0)) }}</p></div>
                                    <div class="card p-4 bg-raised"><p class="stat-label">On disk</p><p class="stat-value-sm tabular-nums mt-1">{{ \CipiGui\Support\PageCache::bytes((int) ($pc['bytes'] ?? 0)) }} <span class="stat-meta">of {{ \CipiGui\Support\PageCache::sizeLabel((string) ($pc['size'] ?? '')) }}</span></p></div>
                                    <div class="card p-4 bg-raised"><p class="stat-label">Kept for</p><p class="stat-value-sm mt-1">{{ \CipiGui\Support\PageCache::ttlLabel((string) ($pc['ttl'] ?? '')) }}</p></div>
                                </div>
                                @if(! $pcLive)
                                    <x-cipi::alert type="warn" class="mb-4">The cache is saved but not in the app's vhost right now — a suspended app answers with its maintenance page. It is back when the app is.</x-cipi::alert>
                                @endif
                            @else
                                <p class="text-sm text-muted mb-4">Off: every request runs PHP. Turn it on for a WordPress site, a blog, a catalogue, a landing page — any app whose pages are the same for everyone who is not signed in.</p>
                            @endif

                            @if($can['edit'] ?? false)
                                <form wire:submit="savePageCache" class="space-y-4">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label for="cache-ttl">Keep a page for</label>
                                            <select id="cache-ttl" wire:model="cacheTtl">
                                                @unless(array_key_exists($cacheTtl, $ttlChoices))
                                                    <option value="{{ $cacheTtl }}">{{ \CipiGui\Support\PageCache::ttlLabel($cacheTtl) }}</option>
                                                @endunless
                                                @foreach($ttlChoices as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('cacheTtl') <p class="field-error">{{ $message }}</p> @enderror
                                            <p class="field-hint">For pages that say nothing themselves: a <code>Cache-Control: max-age</code> of the app wins. Any other time: <code>cipi cache {{ $app['app'] }} ttl 45m</code>.</p>
                                        </div>
                                        <div>
                                            <label for="cache-size">Use up to</label>
                                            <select id="cache-size" wire:model="cacheSize">
                                                @unless(array_key_exists($cacheSize, $sizeChoices))
                                                    <option value="{{ $cacheSize }}">{{ \CipiGui\Support\PageCache::sizeLabel($cacheSize) }}</option>
                                                @endunless
                                                @foreach($sizeChoices as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('cacheSize') <p class="field-error">{{ $message }}</p> @enderror
                                            <p class="field-hint">Of the server's disk. Past it nginx drops the pages asked for least.</p>
                                        </div>
                                    </div>
                                    <label class="check"><input type="checkbox" wire:model="cacheClearOnDeploy"> Empty it after every deploy and rollback</label>
                                    @if($pageCacheRefused)
                                        <x-cipi::alert type="danger" title="Refused — nothing changed on the server" data-page-cache-refused>
                                            <span class="font-mono text-xs break-words">{{ $pageCacheRefused }}</span>
                                        </x-cipi::alert>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="savePageCache" data-page-cache-save>
                                            <x-cipi::icon name="{{ $pcOn ? 'check' : 'bolt' }}" /> {{ $pcOn ? 'Save' : 'Turn the page cache on' }}
                                        </button>
                                        @if($pcOn)
                                            <button type="button" wire:click="clearPageCache" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="clearPageCache" data-page-cache-clear><x-cipi::icon name="trash" /> Empty the cache now</button>
                                            <button type="button" wire:click="disablePageCache" data-confirm="Turn the page cache of {{ $app['app'] }} off? Its pages are deleted and every request runs PHP again." class="btn btn-ghost btn-sm danger" data-page-cache-off>Turn off</button>
                                        @endif
                                    </div>
                                </form>
                            @else
                                @if($pcOn && $pcMayClear)
                                    <button type="button" wire:click="clearPageCache" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="clearPageCache" data-page-cache-clear><x-cipi::icon name="trash" /> Empty the cache now</button>
                                @endif
                                <p class="field-hint">Turning the page cache on or off needs the Edit ability on this app{{ $pcMayClear ? '; emptying it comes with Deploy' : '' }}.</p>
                            @endif
                        @endif
                    </div>

                    @if($pcHasExclude)
                        <div class="card" data-page-cache-exclude>
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="eye-off" /> Paths that are never cached</h2>
                                    <p class="card-subtitle">Of this app, on top of the rules for every app: a page that is different for each visitor with no cookie saying so, a feed, a page with a counter.</p>
                                </div>
                                <span class="badge badge-gray" data-page-cache-exclude-count>{{ count($pcExclude) === 0 ? 'None' : count($pcExclude) }}</span>
                            </div>
                            @if($can['edit'] ?? false)
                                <form wire:submit="saveCacheExclude" class="space-y-3">
                                    <div>
                                        <label for="cache-exclude">One path per line</label>
                                        <textarea id="cache-exclude" wire:model="cacheExclude" rows="5" class="font-mono text-sm" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="{{ $pcExcludeHint }}"></textarea>
                                        @error('cacheExclude') <p class="field-error">{{ $message }}</p> @enderror
                                        <p class="field-hint"><code>/cart*</code> is everything that starts so — <code>*</code> stands for anything. Without it, the path itself: <code>/feed</code> is <code>/feed</code> and <code>/feed/</code>, whatever its query string. Upper and lower case are the same. Up to {{ \CipiGui\Support\PageCache::EXCLUDE_MAX }}.</p>
                                    </div>
                                    @if($cacheExcludeRefused)
                                        <x-cipi::alert type="danger" title="Refused — nothing changed on the server" data-page-cache-exclude-refused>
                                            <span class="font-mono text-xs break-words">{{ $cacheExcludeRefused }}</span>
                                        </x-cipi::alert>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveCacheExclude" data-page-cache-exclude-save><x-cipi::icon name="check" /> Save paths</button>
                                        @unless($pcOn)
                                            <span class="text-xs text-muted">The cache is off: the list is kept for when it is turned on.</span>
                                        @endunless
                                    </div>
                                </form>
                            @elseif($pcExclude === [])
                                <p class="text-sm text-muted">None: only the rules for every app apply.</p>
                            @else
                                <ul class="space-y-1">
                                    @foreach($pcExclude as $path)
                                        <li><code>{{ $path }}</code></li>
                                    @endforeach
                                </ul>
                                <p class="field-hint">Changing the list needs the Edit ability on this app.</p>
                            @endif
                            <p class="field-hint mt-3">On the server: <code>cipi cache {{ $app['app'] }} exclude add '/cart*'</code>.</p>
                        </div>
                    @endif

                    @if($pcOn && $pcMayClear)
                        <div class="card" data-page-cache-purge-some>
                            <div class="card-header">
                                <div>
                                    <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="trash" /> Purge some pages</h2>
                                    <p class="card-subtitle">Only those pages: the rest of the cache stays warm.</p>
                                </div>
                            </div>
                            <form wire:submit="purgePageCache" class="space-y-3">
                                <div>
                                    <label for="cache-purge">One page per line</label>
                                    <textarea id="cache-purge" wire:model="cachePurge" rows="4" class="font-mono text-sm" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="/blog/hello-world/&#10;/category/news/*&#10;/"></textarea>
                                    @error('cachePurge') <p class="field-error">{{ $message }}</p> @enderror
                                    <p class="field-hint"><code>/blog/hello-world/</code> is that page on every domain of the app, over http and https, with its <code>?utm_…</code> variants. <code>/category/news/*</code> is every page under it — its pagination and its feed too. A full URL is that page on that domain only. Up to {{ \CipiGui\Support\PageCache::PURGE_MAX }}.</p>
                                </div>
                                @if($cachePurgeRefused)
                                    <x-cipi::alert type="danger" title="Refused — nothing was purged" data-page-cache-purge-refused>
                                        <span class="font-mono text-xs break-words">{{ $cachePurgeRefused }}</span>
                                    </x-cipi::alert>
                                @endif
                                <button type="submit" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="purgePageCache" data-page-cache-purge-save><x-cipi::icon name="trash" /> Purge these pages</button>
                            </form>
                            <p class="field-hint mt-3">On the server: <code>cipi cache {{ $app['app'] }} purge /blog/hello-world/</code>.</p>
                        </div>
                    @endif

                    <div class="card" data-page-cache-purge>
                        <div class="card-header">
                            <div>
                                <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="refresh" /> When the content changes</h2>
                                <p class="card-subtitle">A cached page is the old page until it expires or the cache is emptied.</p>
                            </div>
                        </div>
                        <dl class="kv mb-4">
                            <dt>A Cipi deploy</dt>
                            <dd>
                                @if(! $pageCacheLoaded || ($pc['clear_on_deploy'] ?? true))
                                    Nothing to add: Cipi empties the cache by itself after every successful deploy and rollback — <code>cipi deploy</code>, the Git webhook, the Deploy button here.
                                @else
                                    <span class="text-warn">Not emptied</span> — "Empty it after every deploy" is off for this app: the old pages stay until they expire.
                                @endif
                            </dd>
                            <dt>From this page</dt><dd><em>Empty the cache now</em>, above — or <code>cipi cache {{ $app['app'] }} clear</code> on the server.</dd>
                            <dt>Anything else</dt><dd>One command, as the app's own user (no password asked, and it can empty this app's cache only) — every page, or the ones named:</dd>
                        </dl>
                        <x-cipi::secret label="Command" :value="$pcClear" />
                        <div class="mt-2"><x-cipi::secret label="Some pages" :value="$pcSome" /></div>
                        <p class="stat-label mt-5 mb-2">In a deploy recipe of your own</p>
                        @include('cipi-gui::partials.terminal', ['lines' => $pcRecipe, 'title' => 'deploy recipe', 'autoScroll' => false])
                        <div class="mt-5" data-page-cache-wordpress>
                            <p class="stat-label mb-1">WordPress: the Cipi Cache plugin</p>
                            <p class="text-sm text-soft mb-2">Purges the pages a change touches — the post, its categories and tags, its author, its archives, the home page, the feeds — when a post is published, updated or deleted, a comment approved, a menu or the theme changed. <a href="{{ \CipiGui\Support\PageCache::WP_PLUGIN_REPO }}" target="_blank" rel="noopener" class="text-link">cipi-sh/cache-wp <x-cipi::icon name="external" /></a></p>
                            @include('cipi-gui::partials.terminal', ['lines' => $pcWordpress, 'title' => 'install', 'autoScroll' => false])
                            <p class="field-hint">Or download <a href="{{ \CipiGui\Support\PageCache::WP_PLUGIN_ZIP }}" class="text-link">cipi-cache.zip</a> and upload it under Plugins → Add New. Then Tools → Cipi Cache → <em>Check the connection</em>.</p>
                        </div>
                        <div class="mt-5" data-page-cache-php>
                            <p class="stat-label mb-1">Laravel and any PHP app: cipi/cache</p>
                            <p class="text-sm text-soft mb-2">Purge by path, by prefix or by route from the code that changes the content, and mark which routes nginx may keep. <a href="{{ \CipiGui\Support\PageCache::PHP_PACKAGE_REPO }}" target="_blank" rel="noopener" class="text-link">cipi-sh/cache-php <x-cipi::icon name="external" /></a></p>
                            @include('cipi-gui::partials.terminal', ['lines' => $pcPhp, 'title' => 'composer', 'autoScroll' => false])
                        </div>
                        <p class="field-hint">Or leave it to the time above: with pages kept {{ $pageCacheLoaded ? \CipiGui\Support\PageCache::ttlLabel((string) ($pc['ttl'] ?? '10m')) : '10 minutes' }}, an edit shows up by itself within that time.</p>
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-4">
                    <div class="card" data-page-cache-explained>
                        <div class="card-header"><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="light-bulb" /> Not OPcache — the other cache</h2></div>
                        <div class="space-y-3 text-sm text-soft">
                            <p><strong class="text-strong">OPcache is on already</strong>, on every PHP of this server, and there is nothing to turn on: it keeps PHP's compiled code in memory, so PHP does not read and parse its files at every request.</p>
                            <p>But with OPcache <strong class="text-strong">PHP still runs</strong>: the framework boots, WordPress loads its plugins and theme, the database is asked, and the same page is built again — for every visitor.</p>
                            <p><strong class="text-strong">The page cache keeps the page itself.</strong> nginx answers the next visitors from its disk, and PHP does not start. The two add up: OPcache makes the first build of a page quicker, the page cache makes the others free.</p>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">What you get</h2></div>
                        <ul class="space-y-2 text-sm text-soft" style="list-style:disc;padding-left:1.1rem;">
                            <li><strong class="text-strong">WordPress, WooCommerce, blogs, catalogues, landing pages:</strong> a page comes back in about a millisecond instead of a few hundred.</li>
                            <li><strong class="text-strong">Traffic peaks:</strong> a newsletter, an ad, a post that goes around — a small server holds what would have filled its PHP workers.</li>
                            <li><strong class="text-strong">A safety net:</strong> while PHP or the database is down or slow, nginx keeps answering with the page it has.</li>
                            <li><strong class="text-strong">Nothing to install in the app:</strong> no cache plugin, no change to the code.</li>
                        </ul>
                    </div>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">What is never cached</h2></div>
                        <ul class="space-y-2 text-sm text-soft" style="list-style:disc;padding-left:1.1rem;">
                            <li>Signed-in visitors and carts: WordPress and WooCommerce cookies, PHP and Laravel sessions.</li>
                            <li>Anything but GET and HEAD — a form, a checkout, an API write.</li>
                            <li><code>/wp-admin</code>, <code>wp-login.php</code>, <code>/wp-json</code>, <code>xmlrpc.php</code>, and a request with an <code>Authorization</code> header.</li>
                            <li>A URL with a query string — searches, filters, previews — unless it only carries tracking parameters (<code>utm_*</code>, <code>fbclid</code>, <code>gclid</code>).</li>
                            <li>Whatever the app marks <code>private</code> or <code>no-cache</code>, or answers with a cookie.</li>
                            @if($pcHasExclude)
                                <li>The paths this app adds itself, in <em>Paths that are never cached</em> — {{ count($pcExclude) === 0 ? 'none for now' : count($pcExclude).' now' }}.</li>
                            @endif
                        </ul>
                        @if($isLaravel)
                            <x-cipi::alert type="info" class="mt-4" title="A Laravel app chooses its pages">
                                Laravel marks every response <code>no-cache, private</code>, and a route of the <code>web</code> group starts a session: nothing is cached until a route says so. Give the pages that are the same for everyone a route without session and cookies, and <code>-&gt;middleware('cache.headers:public;max_age=600')</code>.
                            </x-cipi::alert>
                        @endif
                        <p class="field-hint mt-4">Every answer says what happened in the <code>X-Cache-Status</code> header — <code>HIT</code>, <code>MISS</code>, <code>BYPASS</code>, <code>EXPIRED</code>, <code>STALE</code>:</p>
                        <x-cipi::secret label="Check" :value="$pcCheck" />
                    </div>
                </div>
            </div>

        {{-- ═══ Octane: the PHP that answers requests, the PHP that runs the rest (Cipi 5.17.0) ═══ --}}
        @elseif($activeTab === 'octane')
            @php
                $oc = $octane ?? [];
                $ocState = (string) ($oc['state'] ?? '');
                $ocMinor = $this->octaneBuildMinor();
                $ocAvailable = array_values(array_filter((array) ($oc['available_php'] ?? []), 'is_string'));
                $ocFix = is_array($oc['fix'] ?? null) ? $oc['fix'] : null;
                $ocFixVersion = $ocFix && preg_match('/^cipi frankenphp php \S+ (\d\.\d)$/', (string) ($ocFix['command'] ?? ''), $m) ? $m[1] : null;
                $ocStates = [
                    'ok' => ['badge-green', 'One PHP'],
                    'mismatch' => ['badge-amber', 'Two PHP versions'],
                    'vulnerable' => ['badge-red', 'Build to update'],
                    'unpinned' => ['badge-gray', 'Not pinned yet'],
                    'missing' => ['badge-red', 'Build missing'],
                ];
            @endphp
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4" data-octane>
                <div class="lg:col-span-3 space-y-4">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="cpu" /> Octane</h2>
                                <p class="card-subtitle">The app stays in memory and FrankenPHP answers its requests. An Octane app runs two PHP — this page keeps them on one.</p>
                            </div>
                            @if($octaneLoaded && isset($ocStates[$ocState]))
                                <span class="badge {{ $ocStates[$ocState][0] }}" data-octane-state>{{ $ocStates[$ocState][1] }}</span>
                            @endif
                        </div>
                        @if($octaneUnavailable)
                            <p class="text-muted" data-octane-unavailable>{{ $octaneUnavailable }}</p>
                        @elseif(! $octaneLoaded)
                            <div class="skeleton h-24 w-full"></div>
                        @else
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                                <div class="card p-4 bg-raised">
                                    <p class="stat-label">Answers the requests</p>
                                    <p class="stat-value-sm mt-1" data-octane-request-php>PHP {{ $oc['octane_php'] ?? '—' }}</p>
                                    <p class="stat-meta mt-1">Built into FrankenPHP {{ $oc['frankenphp'] ?? '—' }}@if(!empty($oc['build'])) · <span class="font-mono">{{ $oc['build'] }}</span>@endif</p>
                                </div>
                                <div class="card p-4 bg-raised">
                                    <p class="stat-label">Runs the rest</p>
                                    <p class="stat-value-sm mt-1" data-octane-cli-php>PHP {{ $oc['php'] ?? $app['php'] }}</p>
                                    <p class="stat-meta mt-1">Composer, artisan, cron, queue workers · <span class="font-mono">/usr/bin/php{{ $oc['php'] ?? $app['php'] }}</span></p>
                                </div>
                            </div>

                            @if($ocState === 'mismatch')
                                <x-cipi::alert type="warn" title="Two PHP versions in one app" class="mb-4" data-octane-mismatch>
                                    Requests are answered on PHP {{ $oc['octane_php'] ?? '?' }}, while Composer, artisan, cron and the workers run PHP {{ $oc['php'] ?? '?' }}: a package installed for one may not load on the other.
                                    @if($ocFix && !empty($ocFix['why'])) <span class="text-muted">{{ $ocFix['why'] }}.</span>@endif
                                    @if(($can['edit'] ?? false) && $ocFixVersion)
                                        <p class="mt-3"><button type="button" wire:click="switchOctanePhp('{{ $ocFixVersion }}')" data-confirm="Put {{ $app['app'] }} on PHP {{ $ocFixVersion }}? Octane restarts and is checked; the previous build comes back if it does not answer." class="btn btn-secondary btn-sm" data-octane-fix><x-cipi::icon name="wrench" /> Put everything on PHP {{ $ocFixVersion }}</button></p>
                                    @elseif($ocFix && !empty($ocFix['command']))
                                        <p class="mt-2">On the server: <code>{{ $ocFix['command'] }}</code></p>
                                    @endif
                                </x-cipi::alert>
                            @elseif($ocState === 'vulnerable')
                                <x-cipi::alert type="danger" title="The PHP inside this build has security fixes waiting" class="mb-4">
                                    apt never patches the PHP built into FrankenPHP: a newer build does. <em>Update the build</em>, below, moves the app to the newest one on PHP {{ $ocMinor ?? ($oc['php'] ?? '') }}.
                                </x-cipi::alert>
                            @elseif($ocState === 'unpinned')
                                <x-cipi::alert type="info" class="mb-4">The app runs the FrankenPHP binary it downloaded at its first deploy. Choosing a PHP version, or updating the build, pins one Cipi keeps and checks.</x-cipi::alert>
                            @elseif($ocState === 'missing')
                                <x-cipi::alert type="danger" class="mb-4">The build this app is pinned to is not on the server any more. Choose a PHP version below to fetch one.</x-cipi::alert>
                            @endif
                            @if($oc['restart_pending'] ?? false)
                                <x-cipi::alert type="warn" class="mb-4" data-octane-restart-pending>Octane has not been restarted since its build changed: it still runs the previous one.</x-cipi::alert>
                            @endif

                            @if($can['edit'] ?? false)
                                <div class="space-y-3" data-octane-php-form>
                                    <div class="flex flex-wrap items-start gap-3">
                                        <div class="w-56">
                                            <label for="octane-php">PHP version</label>
                                            <select id="octane-php" wire:model="octanePhpTarget">
                                                @foreach($phpVersions as $version)
                                                    <option value="{{ $version }}">PHP {{ $version }}@if($version === ($oc['php'] ?? null) && $version === $ocMinor) — current @elseif(in_array($version, $ocAvailable, true)) — build on this server @endif</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button type="button" wire:click="switchOctanePhp" class="btn btn-primary field-action" data-confirm="Put {{ $app['app'] }} on the PHP version chosen? Octane restarts and is checked; the previous build comes back if it does not answer." data-octane-switch><x-cipi::icon name="arrow-path" /> Switch PHP</button>
                                    </div>
                                    @error('octanePhpTarget') <p class="field-error">{{ $message }}</p> @enderror
                                    <p class="field-hint">By its number, as for any other app. Octane first: the FrankenPHP build that carries that PHP — fetched from FrankenPHP's releases when this server has none — is started and has to answer; when it does not, the previous build comes back and nothing else changes. Then Composer, artisan, cron and the workers move. Versions listed are the ones installed on this server (<a href="{{ route('cipi-gui.server-manage', ['serverId' => $server?->id, 'tab' => 'php']) }}" class="text-link">Server → PHP</a>).</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 mt-5 pt-4 border-t">
                                    <button type="button" wire:click="restartOctane" data-confirm="Restart Octane of {{ $app['app'] }}? Requests wait a moment while it comes back." class="btn btn-secondary btn-sm" data-octane-restart><x-cipi::icon name="refresh" /> Restart Octane</button>
                                    <button type="button" wire:click="upgradeOctaneBuild" data-confirm="Move {{ $app['app'] }} to the newest FrankenPHP build on PHP {{ $ocMinor ?? ($oc['php'] ?? '') }}? Octane restarts and is checked." class="btn btn-secondary btn-sm" data-octane-upgrade><x-cipi::icon name="download" /> Update the build</button>
                                </div>
                                <p class="field-hint"><em>Update the build</em> takes the newest FrankenPHP on the PHP the app has — security fixes of that PHP, never another version. Another version is <em>Switch PHP</em>.</p>
                            @else
                                <p class="field-hint">Switching PHP and restarting Octane need the Edit ability on this app.</p>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-4">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title flex items-center gap-2"><x-cipi::icon name="light-bulb" /> Why two PHP</h2></div>
                        <div class="space-y-3 text-sm text-soft">
                            <p>FrankenPHP is one binary with PHP built in: <strong class="text-strong">a release carries one PHP version</strong>, and apt never updates it. So Cipi keeps a build per Octane app, pinned — each app has its own, and updating one never moves another.</p>
                            <p>Everything that is not a web request — <code>composer install</code> at a deploy, <code>artisan</code>, the scheduler, the queue workers — runs the server's PHP, the one apt keeps patched.</p>
                            <p>The two should be the same version. They drift when a deploy downloads the newest FrankenPHP and it carries a newer PHP than the app was created with; this page puts them back on one.</p>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">On the server</h2></div>
                        <dl class="kv">
                            <dt>What it runs</dt><dd><code>cipi frankenphp php {{ $app['app'] }}</code></dd>
                            <dt>Switch PHP</dt><dd><code>cipi frankenphp php {{ $app['app'] }} 8.5</code></dd>
                            <dt>Update the build</dt><dd><code>cipi frankenphp upgrade {{ $app['app'] }}</code></dd>
                            <dt>Restart</dt><dd><code>cipi frankenphp restart {{ $app['app'] }}</code></dd>
                            <dt>Log</dt><dd><code>/var/log/cipi-apps/{{ $app['app'] }}/octane.log</code> — the Logs tab</dd>
                        </dl>
                    </div>
                </div>
            </div>

        @elseif($activeTab === 'deploy')
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Deploy</h2></div>
                    <p class="text-sm text-muted mb-4">Zero-downtime release from <code>{{ $app['branch'] ?: 'main' }}</code>. Pushes deploy automatically through the webhook.</p>
                    @if($can['deploy'] ?? false)
                        @php $deployBusy = $this->deployActive(); @endphp
                        <div class="space-y-2">
                            <button wire:click="deploy" @disabled($deployBusy) class="btn btn-primary w-full"><x-cipi::icon name="rocket" /> Deploy now</button>
                            <button wire:click="rollback" @disabled($deployBusy) data-confirm="Roll back to the previous release?" class="btn btn-secondary w-full"><x-cipi::icon name="rollback" /> Roll back</button>
                            @if($this->pausedCommit() !== null)
                                <button wire:click="unlockDeploy" data-confirm="Resume automatic deploys (and remove a stuck lock, if any)? Only do this if no deploy is running." class="btn btn-ghost w-full"><x-cipi::icon name="play" /> Resume automatic deploys</button>
                            @else
                                <button wire:click="unlockDeploy" data-confirm="Remove the deploy lock? Only do this if no deploy is running." class="btn btn-ghost w-full"><x-cipi::icon name="unlock" /> Unlock a stuck deploy</button>
                            @endif
                        </div>
                        @if($deployBusy)
                            <p class="text-xs text-muted mt-2" data-deploy-busy>A deploy is already running — Deployer holds the lock until it ends, and the page follows it above.</p>
                        @endif
                    @else
                        <p class="text-xs text-muted">Deploying needs the Deploy ability on this app.</p>
                    @endif
                    @if($auditLoaded && ! $auditUnsupported)
                        @php $times = $this->deployTimes(); @endphp
                        <div class="mt-5 pt-4 border-t">
                            <p class="font-medium text-strong mb-1 flex items-center gap-1.5"><x-cipi::icon name="clock" class="h-4 w-4 text-muted" /> Deploy time</p>
                            @if($times)
                                <p class="text-sm text-soft">Last deploy took <strong class="text-strong tabular-nums">{{ \CipiGui\Support\DeployLog::format($times['last']) }}</strong>@if($times['count'] > 1) · <span class="tabular-nums">{{ \CipiGui\Support\DeployLog::format($times['average']) }}</span> on average over {{ $times['count'] }} {{ \Illuminate\Support\Str::plural('deploy', $times['count']) }}@endif.</p>
                                <p class="text-xs text-muted mt-1">From the deploy log — the Took column in the audit has every deploy.</p>
                            @elseif(! empty($auditRecords))
                                <p class="text-xs text-muted">No timing yet: it shows here from the next deploy, once the server writes how long each deploy took to the deploy log.</p>
                            @else
                                <p class="text-xs text-muted">Shown once a deploy has run.</p>
                            @endif
                        </div>
                    @endif
                    @if($app['repository'] !== '' && ($can['edit'] ?? false))
                        <div class="mt-5 pt-4 border-t">
                            <p class="font-medium text-strong mb-1">Git webhook</p>
                            <p class="text-xs text-muted mb-3">Recreate the GitHub/GitLab hook, or rotate <code>CIPI_WEBHOOK_TOKEN</code>.</p>
                            <div class="btn-group">
                                <button type="button" wire:click="recreateWebhook(false)" data-confirm="Recreate the provider webhook?" class="btn btn-secondary btn-sm">Recreate</button>
                                <button type="button" wire:click="recreateWebhook(true)" data-confirm="Rotate the webhook secret? The old one stops working immediately." class="btn btn-ghost btn-sm">Rotate secret</button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="card card-flush lg:col-span-2">
                    <div class="card-header p-5 mb-0">
                        <div>
                            <h2 class="card-title">Deploy audit</h2>
                            <p class="card-subtitle">Hash-chained ledger of every deploy — who or what started it, and from where. Times in {{ \CipiGui\Support\Clock::label() }} (<a href="{{ route('cipi-gui.settings') }}">your time zone</a>).</p>
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
                        <div class="px-5 pb-5"><p class="text-muted">The audit needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>deploy-manage</code> ability.</p></div>
                    @elseif(! $auditLoaded)
                        <div class="px-5 pb-5"><div class="skeleton h-24 w-full"></div></div>
                    @elseif(empty($auditRecords) && ! $this->deployBanner())
                        <div class="px-5 pb-5"><p class="text-muted">No records in this period. The ledger starts with the first deploy the server recorded.</p></div>
                    @else
                        <div class="table-scroll max-h-panel overflow-y-auto">
                            <table class="table-compact">
                                <thead>
                                    <tr><th>#</th><th>When</th><th>Event</th><th>Release</th><th class="text-right" title="From the deploy log">Took</th><th>Origin</th><th title="Who started it: the login user root established, or the actor the app's own code reported (webhook pusher, MCP client)">By</th>@if($can['logs'] ?? false)<th class="text-right"><span class="sr-only">Log</span></th>@endif</tr>
                                </thead>
                                <tbody>
                                    @if($live = $this->deployBanner())
                                        {{-- The run in flight (or left interrupted): the ledger gets its record when it ends. --}}
                                        <tr wire:key="audit-live" data-deploy-live="{{ $live['state'] }}">
                                            <td class="font-mono text-2xs text-subtle">—</td>
                                            <td class="whitespace-nowrap">
                                                <span class="text-strong">{{ $live['started']?->diffForHumans() ?? 'now' }}</span>
                                                <span class="block text-2xs text-subtle">{{ $live['started']?->format('Y-m-d H:i') ?? '' }}</span>
                                            </td>
                                            <td><span class="badge {{ $live['state'] === 'interrupted' ? 'badge-amber' : 'badge-accent' }}">{{ match ($live['state']) { 'queued' => 'queued', 'interrupted' => 'interrupted', default => 'in progress' } }}</span></td>
                                            <td class="font-mono text-xs whitespace-nowrap">{{ $live['from_release'] !== '' ? $live['from_release'].' → …' : '…' }}</td>
                                            <td class="text-right tabular-nums text-xs text-soft">{{ $live['elapsed'] !== null && $live['state'] === 'running' ? \CipiGui\Support\DeployLog::format($live['elapsed']) : '—' }}</td>
                                            <td class="text-xs"><span class="badge badge-neutral">{{ $live['trigger'] ?? '—' }}</span></td>
                                            <td class="text-xs">—</td>
                                            @if($can['logs'] ?? false)<td></td>@endif
                                        </tr>
                                    @endif
                                    @foreach($auditRecords as $record)
                                        @php
                                            $event = (string) ($record['event'] ?? '');
                                            $ts = \CipiGui\Support\Clock::local($record['ts'] ?? null);
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
                                                {{-- The commit's title, read from the app's clone (Cipi 5.14.0+): whoever pushes writes it. --}}
                                                @php $subject = is_string($record['subject'] ?? null) ? trim($record['subject']) : ''; @endphp
                                                @if($subject !== '')<span class="block font-sans text-xs text-soft truncate commit-subject" title="{{ $subject }}" data-commit-subject>{{ $subject }}</span>@endif
                                            </td>
                                            <td class="text-right tabular-nums text-xs {{ isset($auditTook[$loop->index]) ? 'text-soft' : 'text-subtle' }}">{{ isset($auditTook[$loop->index]) ? \CipiGui\Support\DeployLog::format($auditTook[$loop->index]) : '—' }}</td>
                                            <td class="text-xs"><span class="badge badge-neutral">{{ $record['origin'] ?? '—' }}</span>@if(!empty($record['trigger']))<span class="text-subtle"> {{ $record['trigger'] }}</span>@endif</td>
                                            @php
                                                $operator = trim((string) ($record['operator'] ?? ''));
                                                $claimedActor = trim((string) ($claimed['actor'] ?? ''));
                                                $claimedSource = trim((string) ($claimed['source'] ?? ''));
                                            @endphp
                                            <td class="text-xs">
                                                @if($operator !== '')
                                                    <span class="text-strong">{{ $operator }}</span>
                                                @elseif($claimedActor !== '')
                                                    <span class="text-strong" title="Reported by the app's own code ({{ $claimedSource !== '' ? $claimedSource : 'cipi/agent' }}), not checked by the server">{{ $claimedActor }}</span>
                                                @else
                                                    —
                                                @endif
                                                @if(!empty($record['ip']))<span class="block font-mono text-2xs text-subtle">{{ $record['ip'] }}</span>@endif
                                                @if($operator !== '' && $claimedActor !== '')<span class="block text-2xs text-subtle" title="{{ json_encode($claimed) }}">{{ $claimedActor }} ({{ $claimedSource !== '' ? $claimedSource : 'claimed' }})</span>
                                                @elseif($claimedActor !== '' && ($claimedSource !== '' || !empty($claimed['ip'])))<span class="block text-2xs text-subtle" title="{{ json_encode($claimed) }}">via {{ $claimedSource !== '' ? $claimedSource : 'the app' }}@if(!empty($claimed['ip'])) · <span class="font-mono">{{ $claimed['ip'] }}</span>@endif</span>@endif
                                            </td>
                                            @if($can['logs'] ?? false)
                                                <td class="text-right"><button type="button" wire:click="showAuditLog({{ $loop->index }})" class="btn btn-ghost btn-icon btn-sm" title="This deploy's lines of the deploy log" aria-label="Log of this deploy"><x-cipi::icon name="terminal" class="h-3.5 w-3.5" /></button></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if($isLaravel || $isNode)
                    <div class="card lg:col-span-3" data-deploy-pipeline="{{ $isNode ? 'node' : 'laravel' }}">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Deploy pipeline</h2>
                                <p class="card-subtitle">Structured Deployer options. Saving regenerates <code>deploy.php</code> from Cipi's template.</p>
                            </div>
                        </div>
                        @if($deployConfigUnsupported)
                            <p class="text-muted">Needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>apps-deploy-config</code> ability.</p>
                        @elseif(! $deployConfigLoaded)
                            <div class="skeleton h-20 w-full"></div>
                        @else
                            {{-- How long a step may run: the same two numbers for every app with releases. --}}
                            @php
                                $dcMinutes = fn ($s) => is_numeric($s) && (int) $s > 0
                                    ? ((int) $s % 60 === 0 ? ((int) $s / 60).' min' : intdiv((int) $s, 60).' min '.((int) $s % 60).' s')
                                    : '';
                            @endphp
                            @if($isNode)
                            <form wire:submit="saveDeployConfig" class="space-y-4" data-deploy-pipeline-form="node">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="dc-keep">Releases to keep</label>
                                        <input id="dc-keep" type="number" wire:model="dcKeepReleases" min="1" max="20">
                                        @error('dcKeepReleases') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    @if($dcTimeouts)
                                        <div>
                                            <label for="dc-build-timeout">Install and build time limit <span class="text-subtle font-normal">(seconds)</span></label>
                                            <input id="dc-build-timeout" type="number" wire:model="dcBuildTimeout" min="60" max="3600" step="1" data-dc-build-timeout>
                                            <p class="field-hint">{{ $dcMinutes($dcBuildTimeout) }} each, for installing the packages and for the build. 60 to 3600; 1800 unless changed.</p>
                                            @error('dcBuildTimeout') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="dc-step-timeout">Every other step <span class="text-subtle font-normal">(seconds)</span></label>
                                            <input id="dc-step-timeout" type="number" wire:model="dcStepTimeout" min="60" max="1800" step="1" data-dc-step-timeout>
                                            <p class="field-hint">{{ $dcMinutes($dcStepTimeout) }} per command: the clone, the switch. 60 to 1800; 300 unless changed.</p>
                                            @error('dcStepTimeout') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                    @endif
                                </div>
                                <label class="check-card" style="max-width:22rem;">
                                    <input type="checkbox" wire:model="dcPredeploySnapshot">
                                    <span><span class="check-card-title font-mono text-xs">DB snapshot</span><span class="check-card-text">Dump the app's database before each deploy</span></span>
                                </label>
                                <p class="field-hint">The build command, the start command and the output folder of a Node app are on its <strong>Node</strong> tab.</p>
                                <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save pipeline</button></div>
                            </form>
                            @else
                            <form wire:submit="saveDeployConfig" class="space-y-4">
                                {{-- queue:restart and horizon:terminate are both on by default, and an app runs
                                     either queue workers or Horizon: say which of the two hooks does something here. --}}
                                @php $runsHorizon = array_key_exists('horizon', $app) ? (bool) $app['horizon'] : null; @endphp
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
                                    @foreach([
                                        'dcMigrate' => ['migrate', 'Run migrations'],
                                        'dcOptimize' => ['optimize', 'Cache config & routes'],
                                        'dcStorageLink' => ['storage:link', 'Public storage link'],
                                        'dcQueueRestart' => ['queue:restart', $runsHorizon ? 'Reload Horizon\'s workers' : 'Reload workers'],
                                        'dcHorizonTerminate' => ['horizon:terminate', $runsHorizon === false ? 'Skipped: no Horizon here' : 'Restart Horizon'],
                                        'dcPredeploySnapshot' => ['DB snapshot', 'Dump before migrating'],
                                    ] as $prop => [$title, $text])
                                        <label class="check-card">
                                            <input type="checkbox" wire:model="{{ $prop }}">
                                            <span><span class="check-card-title font-mono text-xs">{{ $title }}</span><span class="check-card-text">{{ $text }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="field-hint" data-worker-hooks="{{ $runsHorizon === null ? 'unknown' : ($runsHorizon ? 'horizon' : 'workers') }}">
                                    @if($runsHorizon)
                                        This app runs <strong>Horizon</strong>: <code>horizon:terminate</code> is the hook that matters here. <code>queue:restart</code> only asks Horizon's workers to reload — harmless, and it can be turned off.
                                    @elseif($runsHorizon === false)
                                        This app does not run Horizon: <code>horizon:terminate</code> is skipped on every deploy — it runs only where <code>laravel/horizon</code> is installed — so leaving it on changes nothing, and <code>queue:restart</code> is the hook that matters here.
                                    @else
                                        <code>queue:restart</code> and <code>horizon:terminate</code> can both stay on: <code>horizon:terminate</code> runs only where <code>laravel/horizon</code> is installed and is skipped everywhere else.
                                    @endif
                                    Either way Cipi restarts the app's workers after each deploy.
                                </p>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="dc-keep">Releases to keep</label>
                                        <input id="dc-keep" type="number" wire:model="dcKeepReleases" min="1" max="20">
                                        @error('dcKeepReleases') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="dc-node">Asset build</label>
                                        <input id="dc-node" type="text" wire:model="dcNodeBuild" placeholder="npm ci && npm run build — empty to skip" class="font-mono" maxlength="{{ \CipiGui\Support\BuildCommand::MAX_LENGTH }}">
                                        @error('dcNodeBuild') <p class="field-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                                @if($dcTimeouts)
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" data-dc-timeouts>
                                        <div>
                                            <label for="dc-build-timeout">Asset build time limit <span class="text-subtle font-normal">(seconds)</span></label>
                                            <input id="dc-build-timeout" type="number" wire:model="dcBuildTimeout" min="60" max="3600" step="1" data-dc-build-timeout>
                                            <p class="field-hint">{{ $dcMinutes($dcBuildTimeout) }}. How long the asset build may run before the deploy is stopped. 60 to 3600; 1800 unless changed.</p>
                                            @error('dcBuildTimeout') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="dc-step-timeout">Every other step <span class="text-subtle font-normal">(seconds)</span></label>
                                            <input id="dc-step-timeout" type="number" wire:model="dcStepTimeout" min="60" max="1800" step="1" data-dc-step-timeout>
                                            <p class="field-hint">{{ $dcMinutes($dcStepTimeout) }} per command: the clone, Composer, the migrations. 60 to 1800; 300 unless changed.</p>
                                            @error('dcStepTimeout') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                @endif
                                <div>
                                    <label for="dc-extra">Extra Artisan commands <span class="text-subtle font-normal">(one per line, after migrations)</span></label>
                                    <textarea id="dc-extra" wire:model="dcExtraArtisan" rows="3" class="font-mono" placeholder="scout:sync-index-settings"></textarea>
                                </div>
                                <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save pipeline</button></div>
                            </form>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

        {{-- ═══ cipi.yml ═══ --}}
        @elseif($activeTab === 'yml')
            @if($ymlUnsupported === 'ability')
                <x-cipi::alert type="warn" title="This token cannot read cipi.yml">
                    The tab needs the <code>yml-view</code> ability, and <code>yml-manage</code> to apply the file and turn auto-apply on or off.
                    Existing tokens do not get them automatically: recreate the token from <a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>.
                </x-cipi::alert>
            @elseif($ymlUnsupported === 'server')
                <x-cipi::alert type="warn" title="cipi.yml needs a newer server">
                    Requires an up-to-date Cipi — run <code>cipi self-update &amp;&amp; cipi api update</code> on the server, then give the token
                    <code>yml-view</code> and <code>yml-manage</code>. Until then: <code>cipi yml plan {{ $app['app'] }}</code> and <code>cipi yml apply {{ $app['app'] }}</code> over SSH.
                    @if($ymlUnsupportedDetail)
                        <p class="mt-2">The server said: <code>{{ $ymlUnsupportedDetail }}</code></p>
                    @endif
                </x-cipi::alert>
            @elseif($yml === null)
                <div class="card"><div class="skeleton h-24 w-full"></div></div>
            @else
                @php
                    $ymlFile = $yml['file'];
                    $ymlChanges = count($yml['changes']);
                    [$ymlBadge, $ymlState] = match (true) {
                        $ymlFile === null => ['badge-gray', 'No file'],
                        $yml['valid'] === false => ['badge-red', 'Invalid'],
                        $yml['blockers'] !== [] => ['badge-red', 'Blocked'],
                        $yml['in_sync'] === true => ['badge-green', 'In sync'],
                        default => ['badge-amber', $ymlChanges.' '.\Illuminate\Support\Str::plural('change', $ymlChanges).' to apply'],
                    };
                    $ymlCanApply = $this->ymlCanApply();
                    $nodeExtra = $isNode ? ', and Node mode, version, build and start command' : '';
                    // The server names the environment it planned for (API 1.36+); older ones do not, so the app's label is used.
                    $ymlEnv = $yml['env'] ?? $app['env'];
                    $ymlFiles = \CipiGui\Support\AppEnv::ymlFiles($ymlEnv);
                @endphp

                @if($ymlLastApply)
                    <div class="space-y-3 mb-4" data-yml-last-apply>
                        @include('cipi-gui::partials.yml-apply-result', ['ymlResult' => $ymlLastApply])
                    </div>
                @endif

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="card lg:col-span-2">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="document" /> cipi.yml</h2>
                                <p class="card-subtitle">The server settings the repository asks for — aliases, PHP, limits, workers, the deploy branch (<code>deploy.branch</code>) and recipe, crons, backups — compared with what this server has now.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="badge {{ $ymlBadge }}" data-yml-state>{{ $ymlState }}</span>
                                <button type="button" wire:click="loadYml" class="btn btn-ghost btn-icon btn-sm" aria-label="Refresh plan"><x-cipi::icon name="refresh" /></button>
                            </div>
                        </div>

                        <dl class="kv mb-4">
                            <dt>Environment</dt>
                            <dd data-yml-env="{{ $ymlEnv }}">
                                <span class="badge {{ $this->envBadgeClass($ymlEnv) }}">{{ $ymlEnv }}</span>
                                <span class="text-xs text-muted">· reads <code>{{ $ymlFiles[0] }}</code> first, then <code>{{ $ymlFiles[1] }}</code></span>
                            </dd>
                            <dt>File</dt>
                            <dd>
                                @if($ymlFile !== null)
                                    <span class="font-mono text-xs">{{ $ymlFile }}</span>
                                @else
                                    <span class="text-muted">No cipi.yml in the current release</span>
                                @endif
                            </dd>
                            @if($ymlFile !== null)
                                <dt>Valid</dt><dd>{{ $yml['valid'] === false ? 'No — see the errors below' : 'Yes' }}</dd>
                            @endif
                        </dl>

                        @if($ymlFile === null)
                            <p class="text-sm text-muted">
                                Commit a <code>cipi.yml</code> — or a <code>{{ $ymlFiles[0] }}</code> for this environment only — at the root of the repository and deploy. To start from what this server already has, run
                                <code>cipi yml generate {{ $app['app'] }} &gt; cipi.yml</code> on the server.
                            </p>
                        @else
                            <div class="space-y-3">
                                @if($yml['valid'] === false)
                                    <x-cipi::alert type="danger" title="cipi.yml is not valid — fix it in the repository and deploy" data-yml-errors>
                                        <ul class="mt-1 space-y-1 font-mono text-xs" style="list-style:disc;padding-left:1.1rem;">
                                            @foreach($yml['errors'] as $message)<li>{{ $message }}</li>@endforeach
                                        </ul>
                                    </x-cipi::alert>
                                @endif

                                @if($yml['blockers'])
                                    <x-cipi::alert type="danger" title="Apply is blocked until these are resolved" data-yml-blockers>
                                        <ul class="mt-1 space-y-1" style="list-style:disc;padding-left:1.1rem;">
                                            @foreach($yml['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach
                                        </ul>
                                    </x-cipi::alert>
                                @endif

                                @if($yml['warnings'])
                                    <x-cipi::alert type="warn" title="Warnings">
                                        <ul class="mt-1 space-y-1" style="list-style:disc;padding-left:1.1rem;">
                                            @foreach($yml['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                                        </ul>
                                    </x-cipi::alert>
                                @endif
                            </div>

                            @if($yml['valid'] !== false)
                                <div class="mt-4">
                                    <p class="stat-label mb-2">Plan</p>
                                    @if($yml['in_sync'] === true)
                                        <p class="text-sm text-success flex items-center gap-2" data-yml-in-sync><x-cipi::icon name="check-circle" /> In sync — the server already matches cipi.yml.</p>
                                    @elseif($yml['changes'] === [])
                                        <p class="text-sm text-muted">No changes to list.</p>
                                    @else
                                        <ul data-yml-changes>
                                            @foreach($yml['changes'] as $change)
                                                <li class="list-row" wire:key="yml-change-{{ $loop->index }}">
                                                    <span class="text-sm min-w-0 flex-1 break-words">{{ $change['summary'] }}</span>
                                                    @if($change['kind'] !== '')<span class="badge badge-mono">{{ $change['kind'] }}</span>@endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif

                            @if($yml['notes'])
                                <ul class="mt-4 space-y-1 text-xs text-muted" data-yml-notes>
                                    @foreach($yml['notes'] as $note)<li class="whitespace-pre-wrap">{{ $note }}</li>@endforeach
                                </ul>
                            @endif
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3 mt-5 pt-4 border-t">
                            <p class="text-xs text-subtle">
                                @if($ymlCanApply)
                                    Runs <code>cipi yml apply {{ $app['app'] }}</code> on the server.
                                @elseif($ymlFile === null)
                                    Nothing to apply without a cipi.yml.
                                @elseif($yml['valid'] === false)
                                    Fix the errors before applying.
                                @elseif($yml['blockers'])
                                    Resolve the blockers before applying.
                                @else
                                    Nothing to apply.
                                @endif
                            </p>
                            <button type="button" wire:click="applyYml" class="btn btn-primary" @disabled(! $ymlCanApply) wire:loading.attr="disabled" wire:target="applyYml"><x-cipi::icon name="play" /> Apply now</button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2 class="card-title">Auto-apply</h2>
                                <p class="card-subtitle">Apply cipi.yml after every successful deploy — from the CLI, the panel or the Git webhook.</p>
                            </div>
                            <span class="badge {{ $yml['auto'] ? 'badge-green' : 'badge-gray' }}" data-yml-auto>{{ $yml['auto'] ? 'On' : 'Off' }}</span>
                        </div>
                        <p class="text-sm text-muted mb-4">
                            While it is on, anyone who can commit to the repository can change this app's aliases, PHP settings, limits, workers, deploy branch and recipe, scheduled commands, databases and backup schedule{{ $nodeExtra }}.
                        </p>
                        @if($yml['auto'] && $ymlSudoers === false)
                            <x-cipi::alert type="warn" class="mb-4">
                                The sudo rule that lets webhook deploys apply the file is missing — run <code>cipi yml auto {{ $app['app'] }} on</code> on the server.
                            </x-cipi::alert>
                        @endif
                        @if($yml['auto'])
                            <button type="button" wire:click="disableYmlAuto" data-confirm="Turn off auto-apply for {{ $app['app'] }}? Deploys keep the server settings as they are; cipi.yml is applied only when you apply it." class="btn btn-secondary">Turn off</button>
                        @else
                            <button type="button" wire:click="enableYmlAuto" data-confirm="Turn on auto-apply for {{ $app['app'] }}? cipi.yml will be applied after every successful deploy (CLI, panel and Git webhook): anyone who can commit to the repository can then change this app's aliases, PHP settings, limits, workers, deploy branch and recipe, scheduled commands, databases and backup schedule{{ $nodeExtra }}." class="btn btn-primary">Turn on</button>
                        @endif
                    </div>
                </div>
            @endif

        {{-- ═══ Environment ═══ --}}
        @elseif($activeTab === 'env')
            <div class="card" x-data="{ filter: '' }">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Environment</h2>
                        <p class="card-subtitle"><code>shared/.env</code> — changes apply on the next deploy or <code>config:cache</code>.</p>
                    </div>
                    @if($envLoaded)
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="search-input"><x-cipi::icon name="search" /><input type="search" x-model="filter" placeholder="Filter keys…" class="input-sm" aria-label="Filter variables"></div>
                            <button type="button" wire:click="openEnvText('paste')" class="btn btn-secondary btn-sm" title="Paste a block of KEY=value lines: they are split into the list"><x-cipi::icon name="plus" /> Paste variables</button>
                            <button type="button" wire:click="openEnvText('edit')" class="btn btn-secondary btn-sm" title="The whole list as text, to edit by hand"><x-cipi::icon name="code" /> Edit as text</button>
                            <button wire:click="loadEnv" class="btn btn-ghost btn-icon btn-sm" aria-label="Reload"><x-cipi::icon name="refresh" /></button>
                        </div>
                    @endif
                </div>

                @if($envUnsupported)
                    <p class="text-muted">.env editing needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>apps-env</code> ability.</p>
                @elseif(! $envLoaded)
                    <div class="skeleton h-40 w-full"></div>
                @else
                    @php
                        $changes = $this->envChangeCount();
                        $orderChanged = $this->envOrderChanged();
                        $envLast = count($envRows) - 1;
                    @endphp
                    {{-- The .env as text: a block pasted from a README or a chat is split into the
                         list (paste), or the whole list is edited by hand (edit). Applying changes
                         the rows only; Save sends them, as it does for a row edited one by one. --}}
                    @if($envTextMode)
                        @php $envTextEdit = $envTextMode === 'edit'; @endphp
                        <form wire:submit="applyEnvText" class="mb-4" data-env-text="{{ $envTextMode }}">
                            <label for="env-text">{{ $envTextEdit ? 'The list as text' : 'Paste variables' }}</label>
                            <textarea id="env-text" wire:model="envText" class="font-mono text-xs" rows="{{ $envTextEdit ? min(24, max(8, count($envRows) + 2)) : 8 }}"
                                      spellcheck="false" autocomplete="off" autocapitalize="none" x-init="$nextTick(() => $el.focus())"
                                      placeholder="APP_NAME=&quot;My app&quot;&#10;MAIL_MAILER=smtp&#10;MAIL_HOST=smtp.example.com"></textarea>
                            @error('envText') <p class="field-error">{{ $message }}</p> @enderror
                            @if($envTextEdit)
                                <p class="field-hint">One <code>KEY=value</code> per line, every value in clear. What you apply replaces the list: a line you take out removes that key, a new line adds one, the order of the lines becomes the order of the file. Comments are not shown here and stay in the file with their keys.</p>
                            @else
                                <p class="field-hint">One <code>KEY=value</code> per line — quotes, <code>export</code>, comments and blank lines are understood. Keys already in the list take the new value, new ones are added at the end, nothing is removed.</p>
                            @endif
                            <div class="btn-group mt-3">
                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="applyEnvText">{{ $envTextEdit ? 'Apply to the list' : 'Add to the list' }}</button>
                                <button type="button" wire:click="closeEnvText" class="btn btn-ghost btn-sm">Cancel</button>
                            </div>
                        </form>
                    @endif
                    @if($envTextMode !== 'edit')
                    {{-- Drag a row by its handle (or focus the handle and use the arrow keys) to
                         reorder the file; comments above a key move with it. Off while filtering.
                         The server is told keys, never positions, and every row is keyed by its
                         position too: a row that changes place is drawn again, so its wire:model
                         and wire:click point at its new place (Livewire does not re-read a
                         wire: attribute changed by a morph — the values followed the old rows). --}}
                    <div class="space-y-2 max-h-panel overflow-y-auto pr-1"
                         x-data="{ from: null, fromKey: null, over: null,
                                   drop(toKey) { const k = this.fromKey; this.from = null; this.fromKey = null; this.over = null;
                                                 if (k === null || k === toKey) return;
                                                 $wire.moveEnvKey(k, toKey); },
                                   regrip(key, target) { $wire.moveEnvKey(key, target).then(() => requestAnimationFrame(() =>
                                                 document.querySelector('[data-env-grip=&quot;' + key + '&quot;]')?.focus())); } }">
                        @forelse($envRows as $index => $row)
                            @php
                                $key = $row['key'];
                                $isNew = ! array_key_exists($key, $envOriginal);
                                $isChanged = ! $isNew && $envOriginal[$key] !== $row['value'];
                                $secret = $this->isSensitiveKey($key);
                            @endphp
                            <div class="env-row is-sortable {{ $isNew ? 'is-new' : ($isChanged ? 'is-changed' : '') }}" wire:key="env-{{ $index }}-{{ $key }}"
                                 x-show="!filter || @js(strtolower($key)).includes(filter.toLowerCase())"
                                 x-data="{ reveal: {{ $secret ? 'false' : 'true' }} }"
                                 x-bind:class="{ 'is-dragging': from === {{ $index }}, 'is-drop-target': over === {{ $index }} && from !== {{ $index }}, 'is-drop-after': over === {{ $index }} && from !== null && from < {{ $index }} }"
                                 x-on:dragstart="from = {{ $index }}; fromKey = @js($key); $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', @js($key))"
                                 x-on:dragover.prevent="if (from !== null) { over = {{ $index }}; $event.dataTransfer.dropEffect = 'move' }"
                                 x-on:drop.prevent="drop(@js($key))"
                                 x-on:dragend="from = null; fromKey = null; over = null; $el.draggable = false">
                                {{-- A span, not a <button>: Firefox starts no drag from inside a button. --}}
                                <span class="env-grip" role="button" data-env-grip="{{ $key }}"
                                      aria-label="Move {{ $key }} — drag, or use the arrow keys" title="Drag to reorder · ↑/↓ with the keyboard"
                                      x-bind:tabindex="filter === '' ? 0 : -1" x-bind:aria-disabled="filter !== ''"
                                      x-on:pointerdown="if (filter === '') $el.closest('.env-row').draggable = true"
                                      x-on:pointerup="$el.closest('.env-row').draggable = false"
                                      @if($index > 0) x-on:keydown.arrow-up.prevent="regrip(@js($key), @js($envRows[$index - 1]['key']))" @endif
                                      @if($index < $envLast) x-on:keydown.arrow-down.prevent="regrip(@js($key), @js($envRows[$index + 1]['key']))" @endif
                                ><x-cipi::icon name="grip" stroke="3" /></span>
                                <input type="text" value="{{ $key }}" class="font-mono input-sm" readonly tabindex="-1" aria-label="Key">
                                <div class="relative">
                                    <input :type="reveal ? 'text' : 'password'" wire:model.live.debounce.400ms="envRows.{{ $index }}.value" class="font-mono input-sm" autocomplete="off" aria-label="Value of {{ $key }}" style="{{ $secret ? 'padding-right:2.25rem' : '' }}">
                                    @if($secret)
                                        <button type="button" class="absolute text-subtle" style="right:.5rem;top:50%;transform:translateY(-50%)" x-on:click="reveal = !reveal" :aria-label="reveal ? 'Hide value' : 'Show value'">
                                            <x-cipi::icon name="eye" x-show="!reveal" /><x-cipi::icon name="eye-off" x-show="reveal" x-cloak />
                                        </button>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeEnvKey(@js($key))" class="btn btn-ghost btn-icon btn-sm danger" aria-label="Remove {{ $key }}"><x-cipi::icon name="trash" /></button>
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
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                        <p class="text-xs {{ $changes ? 'text-accent' : 'text-subtle' }}">{{ $changes ? $changes.' unsaved '.\Illuminate\Support\Str::plural('change', $changes).($orderChanged ? ' · new order' : '') : count($envRows).' variables · drag ⠿ to reorder · sensitive values are hidden' }}</p>
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
                    <p class="text-muted">Needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>apps-auth</code> ability.</p>
                @elseif(! $authJsonLoaded)
                    <div class="skeleton h-40 w-full"></div>
                @else
                    <textarea wire:model="authJsonContent" rows="14" class="font-mono text-sm" spellcheck="false" aria-label="auth.json"></textarea>
                    @error('authJsonContent') <p class="field-error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                        <p class="text-xs text-subtle">{{ $authJsonExists ? 'Saving replaces the whole file.' : 'Edit the template, then create the file.' }}</p>
                        <div class="btn-group">
                            @if($authJsonExists)
                                <button wire:click="deleteAuthJson" data-confirm="Delete shared/auth.json for this app?" class="btn btn-ghost danger">Delete</button>
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
                @include('cipi-gui::partials.preset-list', ['kind' => 'artisan', 'presets' => $artisanPresets, 'use' => 'useArtisanPreset', 'canEdit' => $canEditPresets, 'editing' => $presetEditing === 'artisan', 'custom' => $presetsCustom['artisan']])
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
                    <p class="text-muted">Needs an up-to-date Cipi on the server (<code>cipi self-update &amp;&amp; cipi api update</code>) and the <code>apps-run</code> ability.</p>
                @else
                    @include('cipi-gui::partials.preset-list', ['kind' => 'run', 'presets' => $runPresets, 'use' => 'useRunPreset', 'canEdit' => $canEditPresets, 'editing' => $presetEditing === 'run', 'custom' => $presetsCustom['run']])
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
                    @if($basicAuthAgent !== null)
                        @php $agentPublic = (bool) ($basicAuthAgent['public'] ?? false); $agentProtected = ($basicAuthAgent['mode'] ?? 'public') === 'protected'; @endphp
                        <div class="card p-4 mb-4" data-basicauth-agent>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-strong">cipi/agent routes</p>
                                    <p class="text-xs text-muted mt-1">
                                        @if($agentPublic)
                                            <span class="font-mono">{{ $basicAuthAgent['prefix'] ?? '/cipi/' }}</span> stays reachable without the password: the Git webhook, the health endpoint and MCP check their own tokens, and a Git provider cannot send this password.
                                        @elseif($agentProtected)
                                            Behind basic auth too: Git webhooks and MCP clients get 401 until they can send the password.
                                        @else
                                            Left out of basic auth once the live release has <code>cipi/agent</code> — checked again after every deploy.
                                        @endif
                                    </p>
                                </div>
                                <span class="badge {{ $agentPublic ? 'badge-blue' : 'badge-gray' }}">{{ $agentPublic ? 'Public' : ($agentProtected ? 'Protected' : 'No agent') }}</span>
                            </div>
                            <div class="mt-3">
                                @if($agentProtected)
                                    <button type="button" wire:click="setBasicAuthAgent('public')" class="btn btn-secondary btn-sm">Leave the agent out of basic auth</button>
                                @else
                                    <button type="button" wire:click="setBasicAuthAgent('protected')" data-confirm="Git webhooks and MCP clients will get 401. Keep the agent behind basic auth?" class="btn btn-ghost btn-sm">Protect the agent too</button>
                                @endif
                            </div>
                        </div>
                    @endif
                    <button wire:click="disableBasicAuth" data-confirm="Remove the password prompt from this site?" class="btn btn-danger">Disable basic auth</button>
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

        {{-- ═══ Metrics ═══ --}}
        @elseif($activeTab === 'metrics')
            @livewire('cipi-gui.metrics', ['serverId' => $serverId, 'app' => $appName], key('metrics-'.$appName))

        {{-- ═══ Logs ═══ --}}
        @elseif($activeTab === 'logs')
            @livewire('cipi-gui.log-viewer', [
                'app' => $appName,
                'serverId' => $serverId,
                'isCustomApp' => (bool) ($app['custom'] ?? false),
            ], key('logs-'.$appName))
        @endif

        @include('cipi-gui::partials.job-overlay')

        @if($auditLogIndex !== null)
            @php
                $logRecord = $auditRecords[$auditLogIndex] ?? [];
                $logTs = \CipiGui\Support\Clock::local($logRecord['ts'] ?? null);
            @endphp
            <x-cipi::modal title="Deploy log — release {{ $logRecord['release'] ?? '?' }}" subtitle="{{ ucfirst((string) ($logRecord['event'] ?? 'deploy')) }}{{ $logTs ? ' · '.$logTs->format('Y-m-d H:i') : '' }}{{ !empty($logRecord['origin']) ? ' · '.$logRecord['origin'] : '' }}" close="closeAuditLog" size="lg" data-audit-log>
                <div class="modal-body space-y-3">
                    @if($auditLogMissing)
                        <x-cipi::alert type="info" title="Not in the part of the log the panel reads">
                            This deploy is older than the last 2000 lines of the deploy log. On the server: <code>cipi deploy {{ $app['app'] }} --log=5000</code>.
                        </x-cipi::alert>
                    @else
                        <p class="text-xs text-muted">From the start banner of this deploy to the post-deploy steps, cipi.yml apply and healthcheck verdict that followed it.</p>
                        @include('cipi-gui::partials.terminal', ['lines' => $auditLogLines, 'title' => 'deploy.log', 'tall' => true, 'autoScroll' => false])
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="closeAuditLog">Close</button>
                </div>
            </x-cipi::modal>
        @endif

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
