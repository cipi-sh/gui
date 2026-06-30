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
            <a href="{{ route('cipi-gui.apps') }}" class="text-sm text-surface-400 hover:text-brand-400">&larr; Back to apps</a>
            <div class="flex items-center justify-between mt-2">
                <div>
                    <h2 class="text-2xl font-semibold text-white">{{ $app['app'] }}</h2>
                    <p class="text-sm text-surface-400">{{ $app['domain'] }}</p>
                </div>
                <div class="flex gap-2">
                    @if($app['suspended'] ?? false)
                        <button wire:click="unsuspend" class="btn btn-primary btn-sm">Unsuspend</button>
                    @else
                        <button wire:click="suspend" wire:confirm="Take this app offline?" class="btn btn-secondary btn-sm">Suspend</button>
                    @endif
                    <button wire:click="deploy" class="btn btn-primary btn-sm">Deploy</button>
                </div>
            </div>
        </div>

        @include('cipi-gui::partials.server-selector')

        {{-- Tabs --}}
        <div class="flex gap-1 mb-6 border-b border-surface-800 pb-px">
            @foreach(['overview' => 'Overview', 'aliases' => 'Aliases & SSL', 'deploy' => 'Deploy', 'basicauth' => 'Basic Auth', 'logs' => 'Logs'] as $tab => $label)
                <button wire:click="setTab('{{ $tab }}')"
                        class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors {{ $activeTab === $tab ? 'bg-surface-800 text-white' : 'text-surface-400 hover:text-surface-200' }}">
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
                        <div class="flex justify-between"><dt class="text-surface-400">PHP</dt><dd class="text-white">{{ $app['php'] }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Branch</dt><dd class="text-white">{{ $app['branch'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Repository</dt><dd class="text-white truncate max-w-xs">{{ $app['repository'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Created</dt><dd class="text-white">{{ $app['created_at'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Status</dt>
                            <dd>@if($app['suspended'] ?? false)<span class="badge badge-amber">Suspended</span>@else<span class="badge badge-green">Active</span>@endif</dd>
                        </div>
                    </dl>
                </div>

                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Edit App</h3>
                    <form wire:submit="saveApp" class="space-y-3">
                        <div>
                            <label>PHP Version</label>
                            <select wire:model="editPhp">
                                @foreach($phpVersions as $v)
                                    <option value="{{ $v }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>Branch</label>
                            <input type="text" wire:model="editBranch">
                        </div>
                        <div>
                            <label>Repository</label>
                            <input type="text" wire:model="editRepository">
                        </div>
                        <div>
                            <label>Primary Domain</label>
                            <input type="text" wire:model="editDomain">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                    </form>
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
                    <p class="text-sm text-surface-400 mb-4">Install a Let's Encrypt certificate for this app and its aliases.</p>
                    <button wire:click="installSsl" class="btn btn-primary">Install SSL</button>
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
                    <div class="mt-4 p-3 rounded-lg bg-amber-600/10 border border-amber-600/30">
                        <p class="text-sm text-amber-400">Auto-generated password (save it now):</p>
                        <code class="font-mono text-white">{{ $generatedPassword }}</code>
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'logs')
            @livewire('cipi-gui.log-viewer', ['app' => $appName, 'serverId' => $serverId], key('logs-'.$appName))
        @endif

        @include('cipi-gui::partials.job-overlay')
    @endif
</div>
