<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold text-white">Server</h2>
            <p class="text-sm text-surface-400 mt-1">
                Manage PHP, Node, packages, monitor, Zero Trust, and API access
                @if($server)
                    on <span class="text-surface-200">{{ $server->name }}</span>
                @endif
            </p>
        </div>
        <button type="button" wire:click="loadAll" class="btn btn-secondary btn-sm">Refresh</button>
    </div>

    @if(!$server)
        <div class="card text-surface-400">
            Add a server first, then come back here.
            <a href="{{ route('cipi-gui.servers') }}" class="text-link ml-1">Servers</a>
        </div>
    @elseif($unsupported)
        <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
            This server’s API is older than 1.15 / Cipi CLI &lt; 5.0.6, or the token is missing management abilities
            (<code>php-*</code>, <code>ssh-*</code>,
            <code>services-*</code>, <code>smtp-*</code>).
            Newer tabs (Node, packages, monitor, Zero Trust, IP whitelist) need API 1.31+ / Cipi ≥ 5.4.1.
            Run <code>cipi self-update</code> on the host and create a token with the updated abilities.
        </div>
    @elseif($loading)
        <div class="flex items-center justify-center py-24 gap-3">
            <div class="spinner spinner-lg"></div>
            <span class="text-surface-400">Loading…</span>
        </div>
    @else
        @if($error)
            <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
        @endif

        <div class="flex flex-wrap gap-1 mb-6">
            @foreach($tabs as $tab => $label)
                <button type="button" wire:click="setTab('{{ $tab }}')"
                        class="tab-btn {{ $activeTab === $tab ? 'active' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($activeTab === 'php')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Installed PHP</h3>
                    @if(empty($phpData['versions']))
                        <p class="text-sm text-surface-400">No PHP versions detected.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach($phpData['versions'] as $row)
                                <li class="py-2 border-b border-surface-800">
                                    <span class="text-white font-medium">PHP {{ $row['version'] }}</span>
                                    <span class="text-xs text-surface-500 ml-2">{{ $row['status'] ?? '' }} · {{ $row['apps'] ?? 0 }} apps</span>
                                    @if(!empty($row['default']) || ($phpData['default'] ?? null) === ($row['version'] ?? null))
                                        <span class="badge badge-neutral ml-2">system default</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Install PHP</h3>
                    <form wire:submit="installPhp" class="space-y-3">
                        <div>
                            <label>Version</label>
                            <select wire:model="phpInstallVersion">
                                @foreach(($phpData['installable'] ?? ['8.3','8.4','8.5']) as $ver)
                                    <option value="{{ $ver }}">{{ $ver }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Install</button>
                    </form>
                    <p class="text-xs text-surface-500 mt-3">Installation runs as a background job and may take several minutes.</p>
                </div>
            </div>

        @elseif($activeTab === 'engines')
            <div class="card">
                <h3 class="font-semibold text-white mb-4">Database engines</h3>
                <p class="text-sm text-surface-400 mb-4">All supported engines. Only <span class="text-surface-300">installed</span> ones appear under Databases and in create forms.</p>
                @if(empty($enginesData['engines']))
                    <p class="text-sm text-surface-400">Could not load engines.</p>
                @else
                    <ul class="space-y-3 mb-6">
                        @foreach($enginesData['engines'] as $engine)
                            @php
                                $engineStatus = $engine['status'] ?? '';
                                $engineIsReady = in_array($engineStatus, ['installed', 'running'], true);
                            @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2 border-b border-surface-800">
                                <div>
                                    <span class="text-white font-medium">{{ $engine['engine'] }}</span>
                                    @if($engineIsReady)
                                        <span class="badge badge-green ml-2">installed</span>
                                    @else
                                        <span class="badge badge-neutral ml-2">not installed</span>
                                    @endif
                                    @if(!empty($engine['default']) || ($enginesData['default'] ?? null) === ($engine['engine'] ?? null))
                                        <span class="badge badge-neutral ml-2">default</span>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    @if(!$engineIsReady)
                                        <button type="button" wire:click="installEngine('{{ $engine['engine'] }}')" class="btn btn-primary btn-sm">Install</button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

        @elseif($activeTab === 'ssh')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Authorized keys (cipi user)</h3>
                    @if(empty($sshKeys))
                        <p class="text-sm text-surface-400">No keys configured.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach($sshKeys as $key)
                                <li class="py-2 border-b border-surface-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-white text-sm font-medium">{{ $key['comment'] }}</div>
                                            <div class="text-xs text-surface-500 mt-1">{{ $key['type'] }} · {{ $key['fingerprint'] }}</div>
                                            @if(!empty($key['current_session']))
                                                <span class="badge badge-neutral mt-1">current session</span>
                                            @endif
                                        </div>
                                        <button type="button"
                                                wire:click="removeSshKey({{ (int) $key['id'] }})"
                                                wire:confirm="Remove SSH key #{{ $key['id'] }}?"
                                                class="btn btn-ghost btn-sm text-red-400"
                                                @if(!empty($key['current_session'])) disabled @endif>
                                            Remove
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Add key</h3>
                    <form wire:submit="addSshKey" class="space-y-3">
                        <div>
                            <label>Public key</label>
                            <textarea wire:model="sshKey" rows="4" placeholder="ssh-ed25519 AAAA… comment" class="font-mono text-xs"></textarea>
                            @error('sshKey') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add key</button>
                    </form>
                </div>
            </div>

        @elseif($activeTab === 'services')
            <div class="card">
                <h3 class="font-semibold text-white mb-4">Services</h3>
                @if(empty($services))
                    <p class="text-sm text-surface-400">No services returned.</p>
                @else
                    <ul class="space-y-2">
                        @foreach($services as $svc)
                            <li class="flex items-center justify-between gap-3 py-2 border-b border-surface-800">
                                <div>
                                    <span class="text-white font-medium">{{ $svc['name'] }}</span>
                                    <span class="text-xs text-surface-500 ml-2">{{ $svc['status'] }}</span>
                                    @if(!empty($svc['since']))
                                        <span class="text-xs text-surface-600 ml-2">since {{ $svc['since'] }}</span>
                                    @endif
                                </div>
                                <button type="button"
                                        wire:click="restartService('{{ $svc['name'] }}')"
                                        wire:confirm="Restart {{ $svc['name'] }}?"
                                        class="btn btn-secondary btn-sm"
                                        @if(($svc['status'] ?? '') === 'not_installed') disabled @endif>
                                    Restart
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

        @elseif($activeTab === 'smtp')
            @if($smtpUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    SMTP API unavailable (needs API 1.16+ / Cipi ≥ 5.0.7 and token abilities
                    <code>smtp-view</code>, <code>smtp-manage</code>).
                </div>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="card">
                        <h3 class="font-semibold text-white mb-2">SMTP configuration</h3>
                        <p class="text-xs text-surface-500 mb-4">
                            Server-wide email for Cipi notifications (deploys, healthchecks, security, …).
                            @if(!empty($smtp['configured']))
                                Status:
                                @if(!empty($smtp['enabled']))
                                    <span class="text-emerald-400">enabled</span>
                                @else
                                    <span class="text-amber-400">disabled</span>
                                @endif
                            @else
                                <span class="text-surface-400">Not configured yet</span>
                            @endif
                        </p>
                        <form wire:submit="saveSmtp" class="space-y-3">
                            <div>
                                <label>Host</label>
                                <input type="text" wire:model="smtpHost" placeholder="smtp.example.com" autocomplete="off">
                                @error('smtpHost') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label>Port</label>
                                    <input type="number" wire:model="smtpPort" min="1" max="65535">
                                    @error('smtpPort') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-end pb-2 gap-4">
                                    <label class="flex items-center gap-2 text-sm text-surface-300">
                                        <input type="checkbox" wire:model="smtpTls"> TLS
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-surface-300">
                                        <input type="checkbox" wire:model="smtpEnabled"> Enabled
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label>Username</label>
                                <input type="text" wire:model="smtpUser" autocomplete="off">
                                @error('smtpUser') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>Password</label>
                                <input type="password" wire:model="smtpPassword" autocomplete="new-password" placeholder="{{ !empty($smtp['configured']) ? 'Enter to update' : '' }}">
                                @error('smtpPassword') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>From</label>
                                <input type="email" wire:model="smtpFrom" placeholder="noreply@example.com">
                                @error('smtpFrom') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label>To (recipient)</label>
                                <input type="email" wire:model="smtpTo" placeholder="ops@example.com">
                                @error('smtpTo') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <label class="flex items-center gap-2 text-sm text-surface-300">
                                <input type="checkbox" wire:model="smtpSendTest"> Send test email after save
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm">Save SMTP</button>
                        </form>
                    </div>
                    <div class="card">
                        <h3 class="font-semibold text-white mb-4">Actions</h3>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($smtp['configured']))
                                @if(!empty($smtp['enabled']))
                                    <button type="button" wire:click="disableSmtp" class="btn btn-secondary btn-sm">Disable</button>
                                @else
                                    <button type="button" wire:click="enableSmtp" class="btn btn-primary btn-sm">Enable</button>
                                @endif
                                <button type="button" wire:click="testSmtp" class="btn btn-secondary btn-sm">Send test</button>
                                <button type="button" wire:click="deleteSmtp" wire:confirm="Remove SMTP configuration from this server?" class="btn btn-ghost btn-sm text-red-400">Delete config</button>
                            @else
                                <p class="text-sm text-surface-400">Save a configuration first, then you can enable/disable or send tests.</p>
                            @endif
                        </div>
                        <p class="text-xs text-surface-500 mt-4">
                            Per-event filters stay on the server via <code class="text-surface-300">cipi notifications</code>.
                            Healthcheck failures use trigger <code class="text-surface-300">health_fail</code>.
                        </p>
                    </div>
                </div>
            @endif

        @elseif($activeTab === 'node')
            @if($nodeUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    Node runtimes require API 1.31+ / Cipi ≥ 5.4.0 (sudoers ≥ 5.4.1) and <code>node-view</code>.
                    Installing runtimes and changing the default stay on the host CLI (<code>cipi node</code>).
                </div>
            @else
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Installed Node runtimes</h3>
                    @if(empty($nodeRuntimes))
                        <p class="text-sm text-surface-400">No Node runtimes returned.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach($nodeRuntimes as $runtime)
                                <li class="py-2 border-b border-surface-800">
                                    <span class="text-white font-medium">Node {{ $runtime['major'] }}</span>
                                    @if(!empty($runtime['version']))
                                        <span class="text-xs text-surface-500 ml-2">{{ $runtime['version'] }}</span>
                                    @endif
                                    @if(!empty($runtime['default']))
                                        <span class="badge badge-neutral ml-2">server default</span>
                                    @endif
                                    @if(!empty($runtime['apps']))
                                        <p class="text-xs text-surface-500 mt-1">Apps: {{ implode(', ', $runtime['apps']) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="text-xs text-surface-500 mt-4">Read-only. Install or switch the default with <code class="text-surface-300">cipi node</code> on the host.</p>
                </div>
            @endif

        @elseif($activeTab === 'search')
            @if($searchUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    Search status requires API 1.31+ / Cipi ≥ 5.2.2 and <code>search-view</code>.
                    Installing Meilisearch stays on the host CLI.
                </div>
            @else
                <div class="card">
                    <h3 class="font-semibold text-white mb-2">Meilisearch</h3>
                    <p class="text-sm text-surface-400 mb-4">
                        @if(!empty($search['installed']))
                            <span class="text-emerald-400">installed</span>
                            @if(!empty($search['running'])) · running @else · not running @endif
                            @if(!empty($search['version'])) · {{ $search['version'] }} @endif
                            @if(!empty($search['health'])) · {{ $search['health'] }} @endif
                            @if(!empty($search['data_size'])) · {{ $search['data_size'] }} @endif
                            @if(!empty($search['host']))
                                · {{ $search['host'] }}:{{ $search['port'] ?? '' }}
                            @endif
                        @else
                            Not installed. Run <code class="text-surface-300">cipi search install</code> on the host, then enable per Laravel app.
                        @endif
                    </p>
                    @php $searchApps = is_array($search['apps'] ?? null) ? $search['apps'] : []; @endphp
                    @if($searchApps === [])
                        <p class="text-sm text-surface-400">No apps have search enabled.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach($searchApps as $name => $meta)
                                <li class="py-2 border-b border-surface-800 text-sm">
                                    <a href="{{ route('cipi-gui.apps.show', $name) }}" class="text-link font-medium">{{ $name }}</a>
                                    @if(is_array($meta) && !empty($meta['prefix']))
                                        <span class="text-xs text-surface-500 ml-2">index {{ $meta['prefix'] }}*</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

        @elseif($activeTab === 'packages')
            @if($packagesUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    Package catalog requires API 1.31+ / Cipi ≥ 5.2.2 and <code>packages-view</code>. Install/remove stay on the host CLI.
                </div>
            @else
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Optional host packages</h3>
                    @if(empty($packages))
                        <p class="text-sm text-surface-400">No packages returned.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach($packages as $pkg)
                                <li class="py-2 border-b border-surface-800">
                                    <div class="flex items-center gap-2">
                                        <span class="text-white font-medium">{{ $pkg['id'] ?? '—' }}</span>
                                        @if(!empty($pkg['installed']))
                                            <span class="badge badge-green">installed</span>
                                        @elseif(!empty($pkg['partial']))
                                            <span class="badge badge-neutral">partial</span>
                                        @else
                                            <span class="badge badge-gray">not installed</span>
                                        @endif
                                    </div>
                                    @if(!empty($pkg['description']))
                                        <p class="text-sm text-surface-400 mt-1">{{ $pkg['description'] }}</p>
                                    @endif
                                    @if(!empty($pkg['packages']))
                                        <p class="text-xs text-surface-500 mt-1 font-mono">{{ implode(', ', $pkg['packages']) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

        @elseif($activeTab === 'monitor')
            @if($monitorUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    Monitor requires API 1.31+ / Cipi ≥ 5.3.0 and <code>monitor-view</code>. Thresholds stay on the host CLI.
                </div>
            @else
                <div class="card">
                    <h3 class="font-semibold text-white mb-2">System monitor</h3>
                    <p class="text-xs text-surface-500 mb-4">
                        Cron every 5 minutes, edge-triggered alerts.
                        @if(isset($monitor['reminder_minutes']))
                            Reminder every {{ $monitor['reminder_minutes'] }} minutes.
                        @endif
                    </p>
                    @php $checks = is_array($monitor['checks'] ?? null) ? $monitor['checks'] : []; @endphp
                    @if($checks === [])
                        <p class="text-sm text-surface-400">No checks returned.</p>
                    @else
                        <ul class="space-y-3">
                            @foreach($checks as $check)
                                <li class="py-2 border-b border-surface-800">
                                    <div class="flex items-center gap-2">
                                        <span class="text-white font-medium">{{ $check['check'] ?? '—' }}</span>
                                        @php $state = $check['state'] ?? null; @endphp
                                        @if($state === 'ok')
                                            <span class="badge badge-green">ok</span>
                                        @elseif($state)
                                            <span class="badge badge-red">{{ $state }}</span>
                                        @else
                                            <span class="badge badge-gray">pending</span>
                                        @endif
                                    </div>
                                    @if(!empty($check['last_alert']))
                                        <p class="text-xs text-surface-500 mt-1">Last alert {{ date('Y-m-d H:i', (int) $check['last_alert']) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

        @elseif($activeTab === 'zt')
            @if($ztUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    Zero Trust status requires API 1.31+ / Cipi ≥ 5.3.0 and <code>zt-view</code>. Mutations stay on the host CLI.
                </div>
            @else
                <div class="card">
                    <h3 class="font-semibold text-white mb-4">Cloudflare Zero Trust</h3>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between"><dt class="text-surface-400">Enabled</dt><dd class="text-white">{{ !empty($zt['enabled']) ? 'Yes' : 'No' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">cloudflared</dt><dd class="text-white">{{ $zt['cloudflared'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Tunnel</dt><dd class="text-white">{{ $zt['tunnel'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Real IP</dt><dd class="text-white">{{ $zt['real_ip'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Lock HTTP</dt><dd class="text-white">{{ $zt['lock_http'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">Lock SSH</dt><dd class="text-white">{{ $zt['lock_ssh'] ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-surface-400">SSH hostname</dt><dd class="text-white">{{ $zt['ssh_hostname'] ?? '—' }}</dd></div>
                    </dl>
                    @if(!empty($zt['raw']))
                        <pre class="mt-4 p-3 rounded-lg bg-black/30 text-xs text-surface-400 overflow-x-auto whitespace-pre-wrap">{{ $zt['raw'] }}</pre>
                    @endif
                </div>
            @endif

        @elseif($activeTab === 'ip')
            @if($ipWhitelistUnsupported)
                <div class="card border-amber-600/30 bg-amber-600/10 text-amber-400 text-sm">
                    IP whitelist requires API 1.15+ / Cipi ≥ 5.0.8 and abilities <code>ip-whitelist-view</code> / <code>ip-whitelist-manage</code>.
                </div>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="card">
                        <h3 class="font-semibold text-white mb-2">API client IP whitelist</h3>
                        <p class="text-xs text-surface-500 mb-4">
                            Restricts <code class="text-surface-300">/api</code> and <code class="text-surface-300">/mcp</code>.
                            @if(!empty($ipWhitelist['allow_all']))
                                Currently <span class="text-emerald-400">allow all</span> (<code>*</code>).
                            @else
                                Restricted to the listed IPs/CIDRs.
                            @endif
                            @if(!empty($ipWhitelist['client_ip']))
                                This GUI is calling as <code class="text-surface-300">{{ $ipWhitelist['client_ip'] }}</code>.
                            @endif
                        </p>
                        @php $entries = is_array($ipWhitelist['entries'] ?? null) ? $ipWhitelist['entries'] : []; @endphp
                        @if($entries === [])
                            <p class="text-sm text-surface-400">No entries.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach($entries as $entry)
                                    <li class="flex items-center justify-between gap-3 py-2 border-b border-surface-800">
                                        <span class="font-mono text-sm text-white">{{ $entry }}</span>
                                        @if($entry !== '*')
                                            <button type="button" wire:click="removeIpWhitelistEntry(@js($entry))" wire:confirm="Remove {{ $entry }} from the API whitelist?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="card">
                        <h3 class="font-semibold text-white mb-2">Add IP or CIDR</h3>
                        <p class="text-xs text-amber-400 mb-3">Restricting without this GUI host’s IP will lock the panel out of the API.</p>
                        <form wire:submit="addIpWhitelistEntry" class="space-y-3">
                            <div>
                                <label>IP / CIDR</label>
                                <input type="text" wire:model="ipWhitelistEntry" placeholder="203.0.113.10 or 10.0.0.0/8" class="font-mono text-sm">
                                @error('ipWhitelistEntry') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Add</button>
                        </form>
                        @if(empty($ipWhitelist['allow_all']))
                            <button type="button" wire:click="allowAllIpWhitelist" wire:confirm="Allow all client IPs to call the API?" class="btn btn-ghost btn-sm mt-4">Allow all</button>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
