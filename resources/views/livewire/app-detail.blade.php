<div>
    @if($loading)
        <div class="flex items-center justify-center py-24 gap-3">
            <div class="spinner spinner-lg"></div>
            <span class="text-surface-400">Loading app...</span>
        </div>
    @elseif($error && !$app)
        <div class="card border-red-800 bg-red-900/20 text-red-400">{{ $error }}</div>
        <a href="{{ route('cipi-gui.apps') }}" class="btn btn-secondary mt-4">Back to apps</a>
    @elseif($app)
        <div class="mb-6">
            <a href="{{ route('cipi-gui.apps') }}" class="text-sm text-surface-400 hover:text-link">&larr; Back to apps</a>
            <div class="flex items-center justify-between mt-2">
                <div>
                    <h2 class="text-2xl font-semibold text-white">{{ $app['app'] }}</h2>
                    <p class="text-sm text-surface-400">
                        {{ $app['domain'] }}
                        · {{ $this->appKindLabel($app) }}
                        @if($app['suspended'] ?? false)
                            <span class="badge badge-gray ml-1">Suspended</span>
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($app['suspended'] ?? false)
                        <button wire:click="unsuspendApp" class="btn btn-primary btn-sm">Unsuspend</button>
                    @else
                        <button wire:click="suspendApp" wire:confirm="Take this app offline with an HTTP 503 maintenance page?" class="btn btn-secondary btn-sm">Suspend</button>
                    @endif
                    <button wire:click="deploy" class="btn btn-primary btn-sm">Deploy</button>
                    <button wire:click="confirmDeleteApp" class="btn btn-danger btn-sm">Delete</button>
                </div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex flex-wrap gap-1 mb-6">
            @foreach($tabs as $tab => $label)
                <button wire:click="setTab('{{ $tab }}')"
                        class="tab-btn {{ $activeTab === $tab ? 'active' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($error)
            <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
        @endif

        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">App Details</h3>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between"><dt class="text-surface-400">Server</dt><dd class="text-white">{{ $server?->name ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Type</dt><dd class="text-white">{{ $this->appKindLabel($app) }}</dd></div>
                        @if($this->isNodeApp($app))
                            <div class="flex justify-between"><dt class="text-surface-400">Node</dt><dd class="text-white">{{ $app['node_version'] ?: 'server default' }}</dd></div>
                        @else
                            <div class="flex justify-between"><dt class="text-surface-400">PHP</dt><dd class="text-white">{{ $app['php'] }}</dd></div>
                            <div class="flex justify-between">
                                <dt class="text-surface-400">Runtime</dt>
                                <dd class="text-white">
                                    @if($app['octane'] ?? null)
                                        <span class="badge badge-neutral">Octane</span>
                                        <span class="text-surface-400 text-xs ml-1">{{ $app['octane'] }}{{ isset($app['octane_port']) && $app['octane_port'] ? ' :'.$app['octane_port'] : '' }}</span>
                                    @else
                                        PHP-FPM
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if($this->isLaravelApp($app))
                            <div class="flex justify-between"><dt class="text-surface-400">Database</dt><dd class="text-white">{{ $this->engineLabel($app['engine'] ?? null) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-surface-400">Branch</dt><dd class="text-white">{{ $app['branch'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Repository</dt><dd class="text-white truncate max-w-xs">{{ $app['repository'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">WWW redirect</dt><dd class="text-white">{{ $this->wwwRedirectLabel($app['www_redirect'] ?? null) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Force HTTPS</dt><dd class="text-white">{{ ($app['force_https'] ?? false) ? 'Yes' : 'No' }}</dd></div>
                        @if(!empty($app['redirect']['to']))
                            <div class="flex justify-between"><dt class="text-surface-400">App redirect</dt><dd class="text-white truncate max-w-xs">{{ ($app['redirect']['enabled'] ?? false) ? 'On' : 'Off' }} → {{ $app['redirect']['to'] }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-surface-400">Created</dt><dd class="text-white">{{ $app['created_at'] ?? '—' }}</dd></div>
                    </dl>
                </div>

                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Edit App</h3>
                    <form wire:submit="saveApp" class="space-y-3">
                        @if($this->isNodeApp($app))
                            <div>
                                <label>Node mode</label>
                                <select wire:model="editNodeMode">
                                    <option value="spa">SPA</option>
                                    <option value="static">Static</option>
                                    <option value="ssr">SSR</option>
                                </select>
                            </div>
                            <div>
                                <label>Node version</label>
                                @if(count($nodeRuntimes) > 0)
                                    <select wire:model="editNodeVersion">
                                        <option value="">Server default</option>
                                        @foreach($nodeRuntimes as $runtime)
                                            <option value="{{ $runtime['major'] }}">
                                                {{ $runtime['major'] }}
                                                @if(!empty($runtime['version'])) ({{ $runtime['version'] }}) @endif
                                                @if(!empty($runtime['default'])) — default @endif
                                            </option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="text" wire:model="editNodeVersion" placeholder="22" autocomplete="off">
                                @endif
                            </div>
                            <div>
                                <label>Build command</label>
                                <input type="text" wire:model="editBuild" class="font-mono text-sm" placeholder="npm run build">
                            </div>
                            @if($editNodeMode === 'ssr')
                                <div>
                                    <label>Start command</label>
                                    <input type="text" wire:model="editStart" class="font-mono text-sm" placeholder="npm run start">
                                </div>
                                <div>
                                    <label>Health path</label>
                                    <input type="text" wire:model="editHealthPath" class="font-mono text-sm" placeholder="/">
                                </div>
                            @else
                                <div>
                                    <label>Output directory</label>
                                    <input type="text" wire:model="editOutput" class="font-mono text-sm" placeholder="dist">
                                </div>
                            @endif
                        @else
                            <div>
                                <label>PHP Version</label>
                                @if(count($phpVersions) > 0)
                                    <select wire:model="editPhp">
                                        @foreach($phpVersions as $ver)
                                            <option value="{{ $ver }}">{{ $ver }}</option>
                                        @endforeach
                                        @if($editPhp !== '' && !in_array($editPhp, $phpVersions, true))
                                            <option value="{{ $editPhp }}">{{ $editPhp }} (current — not installed?)</option>
                                        @endif
                                    </select>
                                @else
                                    <input type="text" wire:model="editPhp" placeholder="e.g. 8.4" autocomplete="off">
                                @endif
                                <p class="text-xs text-surface-500 mt-1">
                                    @if($phpListUnsupported)
                                        PHP list API unavailable — using local hints. Install versions from Server → Manage.
                                    @else
                                        Only versions installed on this server are listed.
                                        <a href="{{ route('cipi-gui.server-manage') }}" class="text-link">Manage PHP</a>
                                    @endif
                                </p>
                                @error('editPhp') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            @if($this->isLaravelApp($app))
                                <div>
                                    <label>Pin Node version (optional)</label>
                                    @if(count($nodeRuntimes) > 0)
                                        <select wire:model="editNodeVersion">
                                            <option value="">Server default</option>
                                            @foreach($nodeRuntimes as $runtime)
                                                <option value="{{ $runtime['major'] }}">{{ $runtime['major'] }}@if(!empty($runtime['default'])) — default @endif</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="text" wire:model="editNodeVersion" placeholder="22" autocomplete="off">
                                    @endif
                                    <p class="text-xs text-surface-500 mt-1">Pins frontend builds to a Node major. Empty = follow server default.</p>
                                </div>
                            @endif
                        @endif
                        <div>
                            <label>Branch</label>
                            <input type="text" wire:model="editBranch">
                        </div>
                        <div>
                            <label>Repository</label>
                            <input type="text" wire:model="editRepository">
                            <p class="text-xs text-surface-500 mt-1">Changing the repository recreates the deploy key and webhook. Unchanged values are not sent.</p>
                        </div>
                        <div>
                            <label>Primary Domain</label>
                            <input type="text" wire:model="editDomain" placeholder="app.example.com or *.example.com">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                    </form>

                    @if(!($app['custom'] ?? false) && !empty($app['repository']))
                        <div class="mt-6 pt-4 border-t border-surface-800">
                            <h4 class="font-medium text-white mb-2">Deploy webhook</h4>
                            <p class="text-xs text-surface-500 mb-3">Recreate the GitHub/GitLab webhook, or rotate <code class="text-surface-300">CIPI_WEBHOOK_TOKEN</code> in <code class="text-surface-300">shared/.env</code>.</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="recreateWebhook(false)" wire:confirm="Recreate the provider webhook for this app?" class="btn btn-secondary btn-sm">Recreate webhook</button>
                                <button type="button" wire:click="recreateWebhook(true)" wire:confirm="Rotate CIPI_WEBHOOK_TOKEN and recreate the webhook? The old secret will stop working." class="btn btn-ghost btn-sm">Recreate + rotate secret</button>
                            </div>
                        </div>
                    @endif

                    <div class="mt-6 pt-4 border-t border-surface-800">
                        <h4 class="font-medium text-white mb-2">Maintenance</h4>
                        <p class="text-xs text-surface-500 mb-3">Restore the Cipi permission model, or blue/green restart an SSR Node process.</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="fixPermissions" wire:confirm="Restore the app home permission model?" class="btn btn-secondary btn-sm">Fix permissions</button>
                            @if($this->isNodeApp($app) && ($app['node_mode'] ?? '') === 'ssr')
                                <button type="button" wire:click="restartNode" wire:confirm="Blue/green restart this Node SSR app?" class="btn btn-secondary btn-sm">Restart Node</button>
                            @endif
                        </div>
                    </div>
                </div>

                @if($this->isLaravelApp($app))
                    <div class="card md:col-span-2">
                        <h3 class="font-semibold text-white mb-2">Search (Meilisearch / Scout)</h3>
                        @if($searchUnsupported)
                            <p class="text-sm text-surface-400">Search API unavailable (API 1.31+ / Cipi ≥ 5.2.2, abilities <code class="text-surface-300">search-view</code> / <code class="text-surface-300">search-manage</code>). Engine install stays on the host CLI.</p>
                        @elseif($searchStatus === null)
                            <button wire:click="loadSearchStatus" class="btn btn-secondary btn-sm">Load search status</button>
                        @else
                            <p class="text-sm text-surface-400 mb-3">
                                Meilisearch:
                                @if(!empty($searchStatus['installed']))
                                    <span class="text-emerald-400">installed</span>
                                    @if(!empty($searchStatus['running'])) · running @endif
                                    @if(!empty($searchStatus['version'])) · {{ $searchStatus['version'] }} @endif
                                    @if(!empty($searchStatus['health'])) · {{ $searchStatus['health'] }} @endif
                                @else
                                    <span class="text-amber-400">not installed</span> on this server
                                    (<code class="text-surface-300">cipi search install</code> on the host)
                                @endif
                            </p>
                            @if($this->searchEnabledForApp())
                                <p class="text-sm text-emerald-400 mb-3">This app is search-enabled.</p>
                                <button type="button" wire:click="disableSearch" wire:confirm="Disable Meilisearch/Scout for this app?" class="btn btn-ghost btn-sm text-red-400">Disable search</button>
                            @else
                                <button type="button" wire:click="enableSearch" @if(empty($searchStatus['installed']) || empty($searchStatus['running'])) disabled @endif class="btn btn-primary btn-sm">Enable search</button>
                            @endif
                        @endif
                    </div>
                @endif

                <div class="card md:col-span-2">
                    <h3 class="font-semibold text-white mb-2">HTTP healthcheck</h3>
                    @if($healthUnsupported)
                        <p class="text-sm text-surface-400">Healthcheck API unavailable (API 1.16+ / Cipi ≥ 5.0.7, abilities <code class="text-surface-300">health-view</code> / <code class="text-surface-300">health-manage</code>).</p>
                    @else
                        <p class="text-xs text-surface-500 mb-4">
                            Probed every 5 minutes. After 3 consecutive failures Cipi emails trigger
                            <code class="text-surface-300">health_fail</code> (requires SMTP on
                            <a href="{{ route('cipi-gui.server-manage') }}" class="text-link">Manage → Email</a>).
                            @if($healthEnabled)
                                Current:
                                <span class="text-surface-300">{{ $health['state'] ?? 'pending' }}</span>
                                · fails {{ $health['failcount'] ?? 0 }}
                            @endif
                        </p>
                        <form wire:submit="saveHealth" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                            <div class="md:col-span-2">
                                <label>URL</label>
                                <input type="text" wire:model="healthUrl" placeholder="https://{{ $app['domain'] }}/up">
                                @error('healthUrl') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>Expect HTTP</label>
                                <input type="number" wire:model="healthExpect" min="100" max="599">
                                @error('healthExpect') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-3 flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">{{ $healthEnabled ? 'Update healthcheck' : 'Enable healthcheck' }}</button>
                                @if($healthEnabled)
                                    <button type="button" wire:click="runHealthCheck" class="btn btn-secondary btn-sm">Check now</button>
                                    <button type="button" wire:click="disableHealth" wire:confirm="Disable healthcheck for this app?" class="btn btn-ghost btn-sm text-red-400">Disable</button>
                                @endif
                            </div>
                        </form>
                        @if($healthCheckResult)
                            <p class="text-sm mt-3 {{ ($healthCheckResult['ok'] ?? false) ? 'text-emerald-400' : 'text-red-400' }}">
                                Last check: got {{ $healthCheckResult['got'] ?? '?' }}, expected {{ $healthCheckResult['expect'] ?? '?' }}
                                ← {{ $healthCheckResult['url'] ?? '' }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'aliases')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Domain Aliases</h3>
                    @if(empty($aliases))
                        <p class="text-sm text-surface-400">No aliases configured.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach($aliases as $alias)
                                <li class="flex items-center justify-between py-2 border-b border-surface-800">
                                    <span class="text-sm text-surface-200">{{ $alias }}</span>
                                    <button wire:click="removeAlias('{{ $alias }}')" wire:confirm="Remove alias {{ $alias }}?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <form wire:submit="addAlias" class="mt-4 flex gap-2">
                        <input type="text" wire:model="newAlias" placeholder="www.example.com" class="flex-1">
                        <button type="submit" class="btn btn-primary btn-sm">Add</button>
                    </form>
                </div>

                <div class="card">
                    <h3 class="font-semibold text-white mb-4">SSL Certificate</h3>
                    <p class="text-sm text-surface-400 mb-4">Install a Let's Encrypt certificate for this app and its aliases, or re-apply the HTTP → HTTPS redirect without new issuance.</p>
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="installSsl" class="btn btn-primary">Install SSL</button>
                        <button wire:click="forceSsl" class="btn btn-secondary">Force HTTPS</button>
                    </div>
                    @if($app['force_https'] ?? false)
                        <p class="text-sm text-emerald-400 mt-3">Force HTTPS is active.</p>
                    @endif
                </div>

                <div class="card md:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-white">WWW / Apex Redirects</h3>
                        <button wire:click="loadWwwStatus" class="btn btn-ghost btn-sm">Refresh</button>
                    </div>

                    @if($wwwUnsupported)
                        <p class="text-sm text-surface-400">WWW redirects require Cipi 4.8+ and API 1.12+ with the <code class="text-surface-300">www-manage</code> token ability.</p>
                    @elseif($wwwStatus === null)
                        <button wire:click="loadWwwStatus" class="btn btn-secondary btn-sm">Load status</button>
                    @else
                        <table class="mb-4">
                            <tbody>
                                <tr>
                                    <th scope="row">Primary</th>
                                    <td class="font-mono text-white break-all">{{ $wwwStatus['primary'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Apex</th>
                                    <td class="font-mono text-white break-all">{{ $wwwStatus['apex'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">WWW</th>
                                    <td class="font-mono text-white break-all">{{ $wwwStatus['www'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Redirect</th>
                                    <td class="text-white">{{ $this->wwwRedirectLabel($wwwStatus['redirect'] ?? null) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="flex flex-wrap gap-2">
                            <button wire:click="wwwAdd" class="btn btn-secondary btn-sm">Add counterpart alias</button>
                            <button wire:click="wwwForceToRoot" class="btn btn-secondary btn-sm">Force www → apex</button>
                            <button wire:click="wwwForceFromRoot" class="btn btn-secondary btn-sm">Force apex → www</button>
                            <button wire:click="wwwClear" wire:confirm="Clear www/apex redirect?" class="btn btn-ghost btn-sm text-red-400">Clear redirect</button>
                        </div>
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'routing')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @if($routingUnsupported)
                    <div class="card md:col-span-2 text-sm text-surface-400">
                        Redirects and proxies require API 1.31+ / Cipi ≥ 5.3.1 (sudoers ≥ 5.4.1) and token abilities
                        <code class="text-surface-300">redirects-view</code>, <code class="text-surface-300">redirects-manage</code>,
                        <code class="text-surface-300">proxies-view</code>, <code class="text-surface-300">proxies-manage</code>.
                    </div>
                @elseif(! $routingLoaded)
                    <div class="card md:col-span-2">
                        <button wire:click="loadRouting" class="btn btn-secondary btn-sm">Load routing</button>
                    </div>
                @else
                    <div class="card">
                        <h3 class="font-semibold text-white mb-2">Whole-app redirect</h3>
                        <p class="text-xs text-surface-500 mb-4">Every hostname of this app redirects in one hop. App-served targets are refused as loops.</p>
                        @if($appRedirect)
                            <p class="text-sm mb-3">
                                Status:
                                @if(!empty($appRedirect['enabled']))
                                    <span class="text-emerald-400">enabled</span>
                                @else
                                    <span class="text-amber-400">saved, disabled</span>
                                @endif
                                · {{ $appRedirect['code'] ?? 301 }}
                                · keep path {{ !empty($appRedirect['keep_path']) ? 'yes' : 'no' }}
                            </p>
                        @endif
                        <form wire:submit="saveAppRedirect" class="space-y-3">
                            <div>
                                <label>Target URL</label>
                                <input type="text" wire:model="redirectTo" placeholder="https://new.example.com">
                                @error('redirectTo') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label>Code</label>
                                    <select wire:model="redirectCode">
                                        <option value="301">301</option>
                                        <option value="302">302</option>
                                        <option value="307">307</option>
                                        <option value="308">308</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="flex items-center gap-2 text-sm text-surface-300">
                                        <input type="checkbox" wire:model="redirectKeepPath"> Keep path
                                    </label>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Save redirect</button>
                                @if($appRedirect)
                                    <button type="button" wire:click="toggleAppRedirect" class="btn btn-secondary btn-sm">{{ !empty($appRedirect['enabled']) ? 'Disable' : 'Enable' }}</button>
                                    <button type="button" wire:click="removeAppRedirect" wire:confirm="Remove the whole-app redirect?" class="btn btn-ghost btn-sm text-red-400">Unset</button>
                                @endif
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <h3 class="font-semibold text-white mb-2">Path redirects</h3>
                        <p class="text-xs text-surface-500 mb-4">A <code class="text-surface-300">from</code> ending in <code class="text-surface-300">/</code> is a prefix match.</p>
                        @if(empty($pathRedirects))
                            <p class="text-sm text-surface-400 mb-3">No path redirects.</p>
                        @else
                            <ul class="space-y-2 mb-4">
                                @foreach($pathRedirects as $rule)
                                    <li class="flex items-start justify-between gap-3 py-2 border-b border-surface-800 text-sm">
                                        <div class="min-w-0">
                                            <span class="font-mono text-white break-all">{{ $rule['from'] ?? '' }}</span>
                                            <span class="text-surface-500"> → </span>
                                            <span class="font-mono text-surface-300 break-all">{{ $rule['to'] ?? '' }}</span>
                                            <span class="text-xs text-surface-500 ml-1">{{ $rule['code'] ?? 301 }}{{ !empty($rule['keep_path']) ? ' · keep path' : '' }}</span>
                                        </div>
                                        <button type="button" wire:click="removePathRedirect(@js($rule['from'] ?? ''))" wire:confirm="Remove this path redirect?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form wire:submit="addPathRedirect" class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label>From</label>
                                    <input type="text" wire:model="pathRedirectFrom" placeholder="/blog/" class="font-mono text-sm">
                                    @error('pathRedirectFrom') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label>To</label>
                                    <input type="text" wire:model="pathRedirectTo" placeholder="https://blog.example.com/" class="font-mono text-sm">
                                    @error('pathRedirectTo') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label>Code</label>
                                    <select wire:model="pathRedirectCode">
                                        <option value="301">301</option>
                                        <option value="302">302</option>
                                        <option value="307">307</option>
                                        <option value="308">308</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="flex items-center gap-2 text-sm text-surface-300">
                                        <input type="checkbox" wire:model="pathRedirectKeepPath"> Keep path
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Add path redirect</button>
                        </form>
                    </div>

                    <div class="card md:col-span-2">
                        <h3 class="font-semibold text-white mb-2">Prefix reverse proxies</h3>
                        <p class="text-xs text-surface-500 mb-4">Loopback guard is always enforced (no force). Upstreams on ports Cipi already uses are refused.</p>
                        @if(empty($proxies))
                            <p class="text-sm text-surface-400 mb-3">No proxies.</p>
                        @else
                            <ul class="space-y-2 mb-4">
                                @foreach($proxies as $proxy)
                                    <li class="flex items-start justify-between gap-3 py-2 border-b border-surface-800 text-sm">
                                        <div class="min-w-0">
                                            <span class="font-mono text-white">{{ $proxy['prefix'] ?? '' }}</span>
                                            <span class="text-surface-500"> → </span>
                                            <span class="font-mono text-surface-300 break-all">{{ $proxy['upstream'] ?? '' }}</span>
                                            <span class="text-xs text-surface-500 ml-1">
                                                timeout {{ $proxy['timeout'] ?? 60 }}s
                                                @if(!empty($proxy['strip_prefix'])) · strip prefix @endif
                                                @if(!empty($proxy['preserve_host'])) · preserve host @endif
                                                @if(!array_key_exists('buffering', $proxy) || $proxy['buffering']) · buffering @endif
                                            </span>
                                        </div>
                                        <button type="button" wire:click="removeProxy(@js($proxy['prefix'] ?? ''))" wire:confirm="Remove this proxy?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form wire:submit="addProxy" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label>Prefix</label>
                                <input type="text" wire:model="proxyPrefix" placeholder="/api/" class="font-mono text-sm">
                                @error('proxyPrefix') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>Upstream</label>
                                <input type="text" wire:model="proxyUpstream" placeholder="http://127.0.0.1:3000" class="font-mono text-sm">
                                @error('proxyUpstream') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>Timeout (seconds)</label>
                                <input type="number" wire:model="proxyTimeout" min="1" max="3600">
                            </div>
                            <div class="flex flex-wrap items-end gap-4 pb-2">
                                <label class="flex items-center gap-2 text-sm text-surface-300"><input type="checkbox" wire:model="proxyStripPrefix"> Strip prefix</label>
                                <label class="flex items-center gap-2 text-sm text-surface-300"><input type="checkbox" wire:model="proxyPreserveHost"> Preserve host</label>
                                <label class="flex items-center gap-2 text-sm text-surface-300"><input type="checkbox" wire:model="proxyBuffering"> Buffering</label>
                            </div>
                            <div class="md:col-span-2">
                                <button type="submit" class="btn btn-primary btn-sm">Add proxy</button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'deploy')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Deploy Actions</h3>
                    <div class="flex flex-wrap gap-2">
                        <button wire:click="deploy" class="btn btn-primary">Deploy Now</button>
                        <button wire:click="rollback" wire:confirm="Rollback to previous release?" class="btn btn-secondary">Rollback</button>
                        <button wire:click="unlockDeploy" class="btn btn-secondary">Unlock Stuck Deploy</button>
                    </div>
                </div>

                <div class="card">
                    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                        <h3 class="font-semibold text-white">Deploy audit</h3>
                        <div class="flex gap-2 items-center">
                            <input type="number" wire:model="auditDays" min="1" max="3650" class="w-20" title="Days">
                            <button type="button" wire:click="loadDeployAudit" class="btn btn-ghost btn-sm">{{ $auditLoaded ? 'Refresh' : 'Load' }}</button>
                        </div>
                    </div>
                    @if($auditUnsupported)
                        <p class="text-sm text-surface-400">Deploy audit requires API 1.31+ / Cipi ≥ 5.4.0 and <code class="text-surface-300">deploy-manage</code>.</p>
                    @elseif(! $auditLoaded)
                        <p class="text-sm text-surface-400">Load the hash-chained ledger for this app.</p>
                    @elseif(empty($auditRecords))
                        <p class="text-sm text-surface-400">No ledger records yet (empty until the first deploy after Cipi 5.4.0).</p>
                    @else
                        <div class="max-h-80 overflow-y-auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Event</th>
                                        <th>Release</th>
                                        <th>Origin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($auditRecords as $record)
                                        <tr>
                                            <td class="text-xs text-surface-400 whitespace-nowrap">{{ $record['ts'] ?? '—' }}</td>
                                            <td class="text-white text-sm">{{ $record['event'] ?? '—' }}</td>
                                            <td class="font-mono text-xs text-surface-300">{{ $record['release'] ?? '—' }}@if(!empty($record['commit'])) <span class="text-surface-500">{{ substr((string) $record['commit'], 0, 8) }}</span> @endif</td>
                                            <td class="text-xs text-surface-400">{{ $record['origin'] ?? '—' }}@if(!empty($record['operator'])) · {{ $record['operator'] }} @endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="card lg:col-span-2">
                    <h3 class="font-semibold text-white mb-2">Deploy config</h3>
                    <p class="text-xs text-surface-500 mb-4">Structured Deployer options. Saving regenerates <code class="text-surface-300">deploy.php</code> from the template (not a raw PHP upload).</p>
                    @if($deployConfigUnsupported)
                        <p class="text-sm text-surface-400">Deploy config is unavailable (custom apps, missing <code class="text-surface-300">apps-deploy-config</code>, or API older than 1.14).</p>
                    @elseif(! $deployConfigLoaded)
                        <button type="button" wire:click="loadDeployConfig" class="btn btn-secondary btn-sm">Load deploy config</button>
                    @else
                        <form wire:submit="saveDeployConfig" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div>
                                    <label>Keep releases</label>
                                    <input type="number" wire:model="dcKeepReleases" min="1" max="20">
                                </div>
                                <div>
                                    <label>Node build</label>
                                    <input type="text" wire:model="dcNodeBuild" placeholder="npm ci && npm run build" class="font-mono text-sm">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-4 text-sm text-surface-300">
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcMigrate"> migrate</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcOptimize"> optimize</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcStorageLink"> storage:link</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcQueueRestart"> queue:restart</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcHorizonTerminate"> horizon:terminate</label>
                                <label class="flex items-center gap-2"><input type="checkbox" wire:model="dcPredeploySnapshot"> predeploy snapshot</label>
                            </div>
                            <div>
                                <label>Extra artisan (one per line)</label>
                                <textarea wire:model="dcExtraArtisan" rows="3" class="font-mono text-sm" placeholder="config:cache"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Save deploy config</button>
                        </form>
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'env')
            <div class="card">
                <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                    <div>
                        <h3 class="font-semibold text-white">Environment (.env)</h3>
                        <p class="text-sm text-surface-400 mt-1">View and edit key/value pairs. Requires API 1.14+ and <code class="text-surface-300">apps-env</code>.</p>
                    </div>
                    <button wire:click="loadEnv" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="loadEnv,saveEnv">Refresh</button>
                </div>

                @if($envUnsupported)
                    <p class="text-sm text-surface-400">.env management is unavailable (custom apps, missing ability, or API older than 1.14).</p>
                @elseif(! $envLoaded)
                    <button wire:click="loadEnv" class="btn btn-secondary btn-sm">Load .env</button>
                @else
                    <div class="space-y-2 mb-4 max-h-[28rem] overflow-y-auto pr-1">
                        @forelse($envRows as $index => $row)
                            <div class="grid grid-cols-1 sm:grid-cols-[minmax(10rem,14rem)_1fr_auto] gap-2 items-start" wire:key="env-row-{{ $index }}-{{ $row['key'] }}">
                                <input type="text" wire:model="envRows.{{ $index }}.key" class="font-mono text-sm" placeholder="KEY" autocomplete="off">
                                <input type="text" wire:model="envRows.{{ $index }}.value" class="font-mono text-sm" placeholder="value" autocomplete="off">
                                <button type="button" wire:click="removeEnvRow({{ $index }})" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                            </div>
                        @empty
                            <p class="text-sm text-surface-400">No variables found.</p>
                        @endforelse
                    </div>

                    <form wire:submit="addEnvRow" class="grid grid-cols-1 sm:grid-cols-[minmax(10rem,14rem)_1fr_auto] gap-2 mb-4">
                        <input type="text" wire:model="envNewKey" class="font-mono text-sm" placeholder="NEW_KEY" autocomplete="off">
                        <input type="text" wire:model="envNewValue" class="font-mono text-sm" placeholder="value" autocomplete="off">
                        <button type="submit" class="btn btn-secondary btn-sm">Add</button>
                    </form>

                    <button wire:click="saveEnv" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveEnv">
                        <span wire:loading.remove wire:target="saveEnv">Save .env</span>
                        <span wire:loading wire:target="saveEnv">Saving…</span>
                    </button>
                @endif
            </div>

        @elseif($activeTab === 'authjson')
            <div class="card max-w-3xl">
                <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                    <div>
                        <h3 class="font-semibold text-white">Composer auth.json</h3>
                        <p class="text-sm text-surface-400 mt-1">Shared credentials for private Composer repos. Distinct from HTTP Basic Auth. Requires <code class="text-surface-300">apps-auth</code>.</p>
                    </div>
                    <button wire:click="loadAuthJson" class="btn btn-ghost btn-sm">Refresh</button>
                </div>

                @if($authJsonUnsupported)
                    <p class="text-sm text-surface-400">auth.json management requires API 1.14+ with the <code class="text-surface-300">apps-auth</code> token ability.</p>
                @elseif(! $authJsonLoaded)
                    <button wire:click="loadAuthJson" class="btn btn-secondary btn-sm">Load auth.json</button>
                @else
                    @if(! $authJsonExists)
                        <p class="text-sm text-surface-400 mb-3">No shared auth.json yet. Edit the draft below and create it, or create the default file.</p>
                    @endif
                    <div class="mb-3">
                        <label class="text-sm text-surface-400">JSON document</label>
                        <textarea wire:model="authJsonContent" rows="16" class="font-mono text-sm w-full mt-1" spellcheck="false"></textarea>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        @if(! $authJsonExists)
                            <label class="flex items-center gap-2 text-sm text-surface-300">
                                <input type="checkbox" wire:model="authJsonForce" class="rounded border-surface-700">
                                Force overwrite
                            </label>
                            <button wire:click="createAuthJson" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="createAuthJson">Create auth.json</button>
                        @else
                            <button wire:click="saveAuthJson" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveAuthJson">Save</button>
                            <button wire:click="deleteAuthJson" wire:confirm="Delete shared auth.json for this app?" class="btn btn-danger btn-sm">Delete</button>
                        @endif
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'artisan')
            <div class="card max-w-2xl">
                <h3 class="font-semibold text-white mb-1">Artisan</h3>
                <p class="text-sm text-surface-400 mb-4">Run Artisan on this Laravel app (async job). Requires <code class="text-surface-300">apps-artisan</code>. Interactive commands like <code class="text-surface-300">tinker</code> are blocked.</p>

                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($artisanPresets as $preset)
                        <button type="button"
                                wire:click="runArtisanPreset(@js($preset['command']))"
                                class="btn btn-secondary btn-sm font-mono">
                            {{ $preset['label'] }}
                        </button>
                    @endforeach
                </div>

                <form wire:submit="runArtisan" class="flex flex-col sm:flex-row gap-2">
                    <div class="flex-1 flex items-center gap-2">
                        <span class="text-sm text-surface-500 font-mono shrink-0">artisan</span>
                        <input type="text" wire:model="artisanCommand" class="font-mono text-sm flex-1" placeholder="migrate --force" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" wire:confirm="Run this Artisan command?">Run</button>
                </form>
            </div>

        @elseif($activeTab === 'run')
            <div class="card max-w-2xl">
                <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                    <div>
                        <h3 class="font-semibold text-white">App commands</h3>
                        <p class="text-sm text-surface-400 mt-1">Whitelisted non-interactive commands (composer, npm, …). Requires <code class="text-surface-300">apps-run</code> and Cipi CLI ≥ 5.0.3.</p>
                    </div>
                    <button wire:click="loadRunCommands" class="btn btn-ghost btn-sm">Refresh whitelist</button>
                </div>

                @if($runUnsupported)
                    <p class="text-sm text-surface-400">App run requires API 1.14+ with the <code class="text-surface-300">apps-run</code> token ability.</p>
                @else
                    <div class="flex flex-wrap gap-2 mb-4">
                        @foreach($runPresets as $preset)
                            <button type="button"
                                    wire:click="runAppPreset(@js($preset['command']))"
                                    wire:confirm="Run: {{ $preset['command'] }}?"
                                    class="btn btn-secondary btn-sm font-mono">
                                {{ $preset['label'] }}
                            </button>
                        @endforeach
                    </div>

                    <form wire:submit="runAppCommand" class="flex flex-col sm:flex-row gap-2 mb-4">
                        <input type="text" wire:model="runCommand" class="font-mono text-sm flex-1" placeholder="composer install --no-dev --no-interaction" autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-sm" wire:confirm="Run this command on the app?">Run</button>
                    </form>

                    @if($runLoaded && ! empty($runAllowedCommands))
                        <details class="text-sm">
                            <summary class="cursor-pointer text-surface-400 hover:text-surface-200">Allowed binaries ({{ count($runAllowedCommands) }})</summary>
                            <p class="mt-2 font-mono text-xs text-surface-400 leading-relaxed">{{ implode(', ', $runAllowedCommands) }}</p>
                            @if(! empty($runNotes))
                                <ul class="mt-2 space-y-1 text-xs text-surface-500" style="list-style:disc;padding-left:1.25rem;">
                                    @foreach($runNotes as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                    @elseif(! $runLoaded)
                        <button wire:click="loadRunCommands" class="btn btn-ghost btn-sm">Load whitelist</button>
                    @endif
                @endif
            </div>

        @elseif($activeTab === 'basicauth')
            <div class="card max-w-lg">
                <h3 class="font-semibold text-white mb-4">HTTP Basic Auth</h3>
                @if($basicAuth === null)
                    <button wire:click="loadBasicAuth" class="btn btn-secondary btn-sm">Load status</button>
                @elseif($basicAuth['enabled'] ?? false)
                    <p class="text-sm text-emerald-400 mb-2">Basic auth is enabled.</p>
                    <p class="text-sm text-surface-400 mb-4">Users: {{ implode(', ', $basicAuth['users'] ?? []) }}</p>
                    <button wire:click="disableBasicAuth" class="btn btn-danger btn-sm">Disable</button>
                @else
                    <form wire:submit="enableBasicAuth" class="space-y-3">
                        <div>
                            <label>Username</label>
                            <input type="text" wire:model="basicAuthUser">
                        </div>
                        <div>
                            <label>Password (leave empty to auto-generate)</label>
                            <input type="password" wire:model="basicAuthPassword">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Enable Basic Auth</button>
                    </form>
                @endif

                @if($generatedPassword)
                    <div class="mt-4 p-3 rounded-lg border border-amber-600/30 bg-amber-600/10">
                        <p class="text-sm text-amber-400">Auto-generated password (save it now):</p>
                        <code>{{ $generatedPassword }}</code>
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'logs')
            @livewire('cipi-gui.log-viewer', [
                'app' => $appName,
                'serverId' => $serverId,
                'isCustomApp' => (bool) ($app['custom'] ?? false),
            ], key('logs-'.$appName))
        @endif

        @include('cipi-gui::partials.job-overlay')

        @if($showDeleteModal)
            <div class="modal-overlay" wire:click.self="cancelDeleteApp">
                <div class="modal-content">
                    <div class="p-6 border-b border-surface-800">
                        <h3 class="text-lg font-semibold text-white">Delete app</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <p class="text-sm text-surface-300">
                            Permanently delete <span class="font-mono text-white">{{ $appName }}</span>?
                            This removes the app, its web config, and files from the server. This action cannot be undone.
                        </p>
                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="cancelDeleteApp" class="btn btn-secondary">Cancel</button>
                            <button type="button" wire:click="deleteApp" class="btn btn-danger">Delete app</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
