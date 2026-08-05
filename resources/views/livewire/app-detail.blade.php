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
                    <p class="text-sm text-surface-400">{{ $app['domain'] }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
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
                        @if(!($app['custom'] ?? false))
                            <div class="flex justify-between"><dt class="text-surface-400">Database</dt><dd class="text-white">{{ $this->engineLabel($app['engine'] ?? null) }}</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-surface-400">Branch</dt><dd class="text-white">{{ $app['branch'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Repository</dt><dd class="text-white truncate max-w-xs">{{ $app['repository'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">WWW redirect</dt><dd class="text-white">{{ $this->wwwRedirectLabel($app['www_redirect'] ?? null) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Force HTTPS</dt><dd class="text-white">{{ ($app['force_https'] ?? false) ? 'Yes' : 'No' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Created</dt><dd class="text-white">{{ $app['created_at'] ?? '—' }}</dd></div>
                    </dl>
                </div>

                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Edit App</h3>
                    <form wire:submit="saveApp" class="space-y-3">
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
                            <input type="text" wire:model="editDomain">
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
                </div>

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

        @elseif($activeTab === 'deploy')
            <div class="card max-w-lg">
                <h3 class="font-semibold text-white mb-4">Deploy Actions</h3>
                <div class="flex flex-wrap gap-2">
                    <button wire:click="deploy" class="btn btn-primary">Deploy Now</button>
                    <button wire:click="rollback" wire:confirm="Rollback to previous release?" class="btn btn-secondary">Rollback</button>
                    <button wire:click="unlockDeploy" class="btn btn-secondary">Unlock Stuck Deploy</button>
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
