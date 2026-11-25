@php
    use CipiGui\Support\Firewall as F;
    $ufw = (array) ($firewall['ufw'] ?? []);
    $f2b = (array) ($firewall['fail2ban'] ?? []);
    $cs = (array) ($firewall['crowdsec'] ?? []);
    $zt = (array) ($firewall['zero_trust'] ?? []);
    $protected = (array) ($firewall['protected_ports'] ?? []);
    $allRules = array_values((array) ($ufw['rules'] ?? []));
@endphp
<div wire:init="init" data-firewall>
    @if(! $loaded)
        <div class="card" aria-busy="true" data-firewall-loading>
            <div class="space-y-3">
                <div class="skeleton h-4 w-40"></div>
                <div class="skeleton h-12 w-full"></div>
                <div class="skeleton h-12 w-full"></div>
                <div class="skeleton h-4 w-1/2"></div>
            </div>
            <p class="sr-only">Loading the firewall from the server…</p>
        </div>
    @elseif($unsupported)
        <x-cipi::alert type="warn" title="The firewall is not available on this server" data-firewall-unsupported>
            It needs <strong>Cipi 5.14.0</strong> and <strong>cipi/api 1.44.0</strong> on the server — <code>cipi self-update &amp;&amp; cipi api update</code> —
            and a token with <code>firewall-view</code> and <code>firewall-manage</code> (recreate it from <a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>).
            @if($unsupportedReason !== '')
                <p class="mt-2">The server answered: <span class="font-mono text-xs break-words">{{ $unsupportedReason }}</span></p>
            @endif
            <p class="mt-3"><button type="button" wire:click="load" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Check again</button></p>
        </x-cipi::alert>
    @elseif($error !== null && $firewall === [])
        <x-cipi::alert type="danger" title="The firewall could not be read" data-tab-error>
            <span class="font-mono text-xs break-words">{{ $error }}</span>
            <p class="mt-3"><button type="button" wire:click="load" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Try again</button></p>
        </x-cipi::alert>
    @else
        <div class="space-y-4">
            {{-- ── At a glance ──────────────────────────────────────── --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-firewall-summary>
                <div class="card">
                    <p class="stat-label">UFW</p>
                    <p class="stat-value-sm">
                        @if(empty($ufw['installed'])) <span class="badge badge-gray">Not installed</span>
                        @elseif(! empty($ufw['active'])) <span class="badge badge-green">Active</span>
                        @else <span class="badge badge-red">Inactive</span> @endif
                    </p>
                    <p class="text-xs text-muted mt-1">Default: {{ $ufw['default_incoming'] ?? '?' }} incoming · {{ count($allRules) }} {{ \Illuminate\Support\Str::plural('rule', count($allRules)) }}</p>
                </div>
                <div class="card">
                    <p class="stat-label">Always open</p>
                    <p class="stat-value-sm font-mono text-sm">{{ $protected ? implode(', ', $protected) : '—' }}</p>
                    <p class="text-xs text-muted mt-1">SSH, HTTP, HTTPS: how the server is reached</p>
                </div>
                <div class="card">
                    <p class="stat-label">fail2ban</p>
                    <p class="stat-value-sm">
                        @if(! empty($f2b['running'])) <span class="badge badge-green">Running</span>
                        @elseif(! empty($f2b['installed'])) <span class="badge badge-red">Stopped</span>
                        @else <span class="badge badge-gray">Not installed</span> @endif
                    </p>
                    <p class="text-xs text-muted mt-1">{{ $f2b['attempts'] ?? '?' }} failed SSH logins → ban {{ F::duration($f2b['bantime'] ?? null) }}</p>
                </div>
                <div class="card">
                    <p class="stat-label">CrowdSec</p>
                    <p class="stat-value-sm">
                        @if(! empty($cs['running'])) <span class="badge badge-green">Running</span>
                        @elseif(! empty($cs['installed'])) <span class="badge badge-red">Stopped</span>
                        @else <span class="badge badge-gray">Off</span> @endif
                    </p>
                    <p class="text-xs text-muted mt-1">{{ count($bans) }} {{ \Illuminate\Support\Str::plural('address', count($bans)) }} banned now</p>
                </div>
            </div>

            @if(! empty($zt['lock_http']) || ! empty($zt['lock_ssh']))
                <x-cipi::alert type="info" data-firewall-zt>
                    Zero Trust keeps {{ ! empty($zt['lock_http']) ? '80/443 to Cloudflare' : '' }}{{ ! empty($zt['lock_http']) && ! empty($zt['lock_ssh']) ? ' and ' : '' }}{{ ! empty($zt['lock_ssh']) ? 'SSH closed' : '' }}:
                    opening {{ ! empty($zt['lock_http']) ? 'them' : 'it' }} to everyone from here is refused. Unlock on the server with <code>cipi zt unlock http</code> / <code>cipi zt ssh unlock</code>.
                </x-cipi::alert>
            @endif
            @if(! empty($ufw['installed']) && empty($ufw['active']))
                <x-cipi::alert type="danger" title="UFW is not active">Rules are kept, but nothing is filtered. Turn it on at the server: <code>ufw enable</code>.</x-cipi::alert>
            @endif

            {{-- ── UFW rules ────────────────────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card card-flush lg:col-span-2" data-firewall-rules>
                    <div class="card-header p-5 mb-0">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="shield" /> Rules</h2>
                            <p class="card-subtitle">UFW, in the order it reads them; everything not allowed is {{ $ufw['default_incoming'] ?? 'denied' }}. A rule kept by a Cipi feature, or that keeps the server reachable, is changed with that feature, not here.</p>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="table-compact">
                            <thead><tr><th>Port</th><th>Action</th><th>From</th><th>IP</th><th class="text-right"></th></tr></thead>
                            <tbody>
                                @foreach($allRules as $i => $rule)
                                    @continue(($rule['kind'] ?? '') === 'block')
                                    <tr wire:key="fw-rule-{{ $i }}-{{ md5(json_encode($rule)) }}">
                                        <td class="font-mono text-strong">
                                            {{ F::portLabel($rule) }}
                                            @if(($rule['app'] ?? '') !== '')<span class="block text-2xs text-subtle font-sans">profile {{ $rule['app'] }}</span>@endif
                                        </td>
                                        <td>
                                            <span class="badge {{ ($rule['action'] ?? '') === 'allow' ? 'badge-green' : (($rule['action'] ?? '') === 'limit' ? 'badge-amber' : 'badge-red') }}">{{ ucfirst((string) ($rule['action'] ?? '?')) }}</span>
                                            @if(($rule['direction'] ?? 'in') === 'out')<span class="badge badge-gray ml-1">out</span>@endif
                                        </td>
                                        <td class="font-mono text-xs">{{ F::fromLabel($rule) }}@if(($rule['interface'] ?? '') !== '')<span class="text-subtle"> on {{ $rule['interface'] }}</span>@endif</td>
                                        <td class="text-xs text-muted whitespace-nowrap">{{ F::familyLabel($rule['family'] ?? null) }}</td>
                                        <td class="text-right whitespace-nowrap">
                                            @if(! empty($rule['protected']))
                                                <span class="badge badge-accent" title="Keeps the server reachable: closed only with Zero Trust (cipi zt lock), at the server">Kept open</span>
                                            @elseif($managed = F::managedLabel($rule['managed_by'] ?? null))
                                                <span class="badge badge-neutral" title="{{ $rule['comment'] ?? '' }}">{{ $managed }}</span>
                                            @elseif($canManage && ! empty($rule['removable']))
                                                <button type="button" wire:click="removeRule({{ $i }})" data-confirm="Remove the rule {{ ucfirst((string) ($rule['action'] ?? '')) }} {{ F::portLabel($rule) }} from {{ F::fromLabel($rule) }}?" class="btn btn-ghost btn-sm danger" wire:loading.attr="disabled" wire:target="removeRule">Remove</button>
                                            @elseif(($rule['kind'] ?? '') === 'other')
                                                <span class="text-2xs text-subtle" title="Made at the server with ufw: changed there">ufw</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @if($sections['rules'] === [])
                                    <tr><td colspan="5" class="text-muted">No rules: with the default policy, nothing comes in.</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" data-firewall-open>
                    <div class="card-header"><div><h2 class="card-title">Open a port</h2><p class="card-subtitle">To everyone, or to one address or range only.</p></div></div>
                    @if($canManage)
                        <form wire:submit="addRule" class="space-y-3">
                            <div class="grid grid-cols-3 gap-2">
                                <div class="col-span-2">
                                    <label for="fw-port">Port or range</label>
                                    <input id="fw-port" type="text" wire:model="rulePort" placeholder="8080 or 8000:8100" class="font-mono" inputmode="numeric" autocomplete="off">
                                </div>
                                <div>
                                    <label for="fw-proto">Protocol</label>
                                    <select id="fw-proto" wire:model="ruleProto"><option value="tcp">TCP</option><option value="udp">UDP</option></select>
                                </div>
                            </div>
                            @error('rulePort') <p class="field-error">{{ $message }}</p> @enderror
                            @error('ruleProto') <p class="field-error">{{ $message }}</p> @enderror
                            <div>
                                <label for="fw-from">From <span class="text-subtle font-normal">(optional)</span></label>
                                <input id="fw-from" type="text" wire:model="ruleFrom" placeholder="Everyone — or 203.0.113.10, 10.0.0.0/8" class="font-mono" autocomplete="off">
                                @error('ruleFrom') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="addRule">Open port</button>
                        </form>
                        <p class="field-hint mt-3">A database or a cache port opened to everyone is reachable from the whole Internet: prefer an address.</p>
                    @else
                        <p class="text-muted">Changing the firewall needs Manage access to this server.</p>
                    @endif
                </div>
            </div>

            {{-- ── Blocked addresses ────────────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card card-flush lg:col-span-2" data-firewall-blocks>
                    <div class="card-header p-5 mb-0">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="lock" /> Blocked addresses</h2>
                            <p class="card-subtitle">No port of the server answers them, whatever the rules above allow. Until unblocked — unlike a ban.</p>
                        </div>
                    </div>
                    <ul class="px-5 pb-5">
                        @forelse($sections['blocks'] as $rule)
                            <li class="list-row" wire:key="fw-block-{{ $rule['from'] }}">
                                <span class="font-mono text-strong">{{ $rule['from'] }} <span class="text-2xs text-subtle font-sans">{{ F::familyLabel($rule['family'] ?? null) }}</span></span>
                                @if($canManage && ! empty($rule['removable']))
                                    <button type="button" wire:click="unblock(@js($rule['from']))" data-confirm="Unblock {{ $rule['from'] }}?" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="unblock">Unblock</button>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted">No address is blocked.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="card">
                    <div class="card-header"><h2 class="card-title">Block an address</h2></div>
                    @if($canManage)
                        <form wire:submit="blockAddress" class="space-y-3">
                            <input type="text" wire:model="blockAddress" placeholder="198.51.100.23 or 198.51.100.0/24" class="font-mono" aria-label="IP or CIDR" autocomplete="off">
                            @error('blockAddress') <p class="field-error">{{ $message }}</p> @enderror
                            <button type="submit" class="btn btn-danger w-full" wire:loading.attr="disabled" wire:target="blockAddress">Block</button>
                        </form>
                        <p class="field-hint mt-3">The server refuses to block the address this panel calls it from.</p>
                    @else
                        <p class="text-muted">Blocking needs Manage access to this server.</p>
                    @endif
                </div>
            </div>

            {{-- ── Bans: fail2ban + CrowdSec ────────────────────────── --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="card card-flush lg:col-span-2" data-firewall-bans>
                    <div class="card-header p-5 mb-0">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="warning" /> Banned now</h2>
                            <p class="card-subtitle">Banned for a while by fail2ban (failed SSH logins, repeat offenders){{ ! empty($cs['running']) ? ' and CrowdSec (its scenarios)' : '' }}. Unban lifts every ban of the address.</p>
                        </div>
                        <span class="badge {{ count($bans) ? 'badge-amber' : 'badge-green' }}">{{ count($bans) }}</span>
                    </div>
                    <div class="table-scroll">
                        <table class="table-compact">
                            <thead><tr><th>Address</th><th>By</th><th>Reason</th><th class="text-right"></th></tr></thead>
                            <tbody>
                                @forelse($bans as $ban)
                                    <tr wire:key="fw-ban-{{ $ban['ip'] }}">
                                        <td class="font-mono text-strong">{{ $ban['ip'] }}</td>
                                        <td class="text-xs">{{ implode(', ', $ban['by']) }}</td>
                                        <td class="text-xs text-muted">{{ $ban['reason'] ?: '—' }}@if($ban['duration'] !== '')<span class="block text-2xs text-subtle">{{ $ban['duration'] }} left</span>@endif</td>
                                        <td class="text-right">
                                            @if($canManage)
                                                <button type="button" wire:click="unban(@js($ban['ip']))" data-confirm="Unban {{ $ban['ip'] }}?" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="unban">Unban</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">Nobody is banned right now.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" data-firewall-attempts>
                    <div class="card-header"><div><h2 class="card-title">SSH login attempts</h2><p class="card-subtitle">Failed SSH logins, within {{ F::duration($f2b['findtime'] ?? null) }}, before fail2ban bans the address for {{ F::duration($f2b['bantime'] ?? null) }} — longer for repeat offenders.</p></div></div>
                    @if($canManage && ! empty($f2b['installed']))
                        <form wire:submit="saveAttempts" class="space-y-3">
                            <div class="flex items-start gap-2">
                                <div class="flex-1">
                                    <label for="fw-attempts">Failed logins → ban</label>
                                    <input id="fw-attempts" type="number" min="1" max="100" wire:model="attempts" class="font-mono">
                                    @error('attempts') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <button type="submit" class="btn btn-primary field-action" wire:loading.attr="disabled" wire:target="saveAttempts">Save</button>
                            </div>
                        </form>
                        <p class="field-hint mt-3">
                            Default {{ $f2b['attempts_default'] ?? 3 }}.
                            @if(($f2b['attempts_custom'] ?? null) !== null)
                                <button type="button" wire:click="defaultAttempts" class="text-link">Back to the default</button>
                            @endif
                        </p>
                        @if(! empty($f2b['attempts_pending']))
                            <x-cipi::alert type="warn" class="mt-3">Saved as {{ $f2b['attempts_custom'] }}, but fail2ban still runs with {{ $f2b['attempts'] }}: restart it from the Services tab.</x-cipi::alert>
                        @endif
                    @elseif(empty($f2b['installed']))
                        <p class="text-muted">fail2ban is not installed on this server.</p>
                    @else
                        <p class="text-2xl font-semibold text-strong">{{ $f2b['attempts'] ?? '—' }}</p>
                    @endif
                    @if(! empty($f2b['installed']) && empty($f2b['running']))
                        <x-cipi::alert type="danger" class="mt-3">fail2ban is not running: nobody is being banned. Restart it from the Services tab.</x-cipi::alert>
                    @endif
                </div>
            </div>

            {{-- ── CrowdSec ─────────────────────────────────────────── --}}
            <div class="card" data-firewall-crowdsec>
                <div class="card-header">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="signal" /> CrowdSec</h2>
                        <p class="card-subtitle">Optional, next to fail2ban: reads the SSH and nginx logs, bans what its scenarios and the community recognise, through a firewall bouncer. Not a WAF.</p>
                    </div>
                    @if(! empty($cs['installed']))
                        <span class="badge {{ ! empty($cs['running']) ? 'badge-green' : 'badge-red' }}">{{ ! empty($cs['running']) ? 'Running' : 'Stopped' }}</span>
                    @else
                        <span class="badge badge-gray">Off</span>
                    @endif
                </div>

                @if(empty($cs['installed']))
                    @if(! empty($cs['enable_blockers']))
                        <x-cipi::alert type="warn" class="mb-4" title="This server cannot take CrowdSec now" data-crowdsec-blockers>
                            <ul class="space-y-1">@foreach($cs['enable_blockers'] as $why)<li>{{ $why }}</li>@endforeach</ul>
                        </x-cipi::alert>
                    @endif
                    <p class="text-sm text-soft mb-4">Enabling installs the engine and a firewall bouncer, allowlists private networks, Let's Encrypt, the GitHub/GitLab/Bitbucket webhooks and this panel's address, and starts a rescue listener whose one-shot link is mailed to the server's notification address. It takes a few minutes and about {{ $cs['ram_required_mb'] ?? 512 }} MB of RAM ({{ $cs['ram_available_mb'] ?? '?' }} MB free).</p>
                    @if($canManage)
                        <button type="button" wire:click="enableCrowdsec" data-confirm="Install and enable CrowdSec on this server? It takes a few minutes." class="btn btn-primary" @disabled(! empty($cs['enable_blockers']) || $jobRunning)><x-cipi::icon name="power" /> Enable CrowdSec</button>
                    @endif
                @else
                    <dl class="kv mb-4">
                        <dt>Bouncer</dt><dd>{{ $cs['bouncer'] ?? '—' }}@if(($cs['mode'] ?? '') !== '') <span class="text-muted">· {{ $cs['mode'] }}</span>@endif</dd>
                        <dt>Decisions</dt><dd>{{ count((array) ($cs['decisions'] ?? [])) }} — in <em>Banned now</em> above</dd>
                        <dt>Rescue</dt><dd>{{ $cs['rescue'] ?? '—' }}@if(! empty($cs['rescue_port'])) <span class="text-muted">· port {{ $cs['rescue_port'] }}</span>@endif <span class="block text-xs text-muted">Break-glass, at the server only: <code>cipi crowdsec rescue token</code></span></dd>
                    </dl>
                    @if(! empty($cs['running']) && ($cs['bouncer'] ?? '') !== 'running')
                        <x-cipi::alert type="danger" class="mb-4">The engine is up but the bouncer is not: its decisions are not enforced. Restart <code>crowdsec-firewall-bouncer</code> from the Services tab.</x-cipi::alert>
                    @endif

                    <p class="label">Never banned <span class="text-subtle font-normal">— besides localhost, private networks, Let's Encrypt and the Git webhooks</span></p>
                    <ul class="mb-4">
                        @forelse((array) ($cs['allowlist'] ?? []) as $net)
                            <li class="list-row" wire:key="cs-allow-{{ $net }}">
                                <span class="font-mono text-strong">{{ $net }}</span>
                                @if($canManage)
                                    <button type="button" wire:click="crowdsecUnallow(@js($net))" data-confirm="Let CrowdSec ban {{ $net }} again?" class="btn btn-ghost btn-sm danger" wire:loading.attr="disabled" wire:target="crowdsecUnallow">Remove</button>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted text-sm">No address of your own yet.</li>
                        @endforelse
                    </ul>
                    @if($canManage)
                        <form wire:submit="crowdsecAllow" class="flex flex-wrap items-start gap-2">
                            <div class="flex-1" style="min-width: 14rem;">
                                <input type="text" wire:model="allowAddress" placeholder="Your office: 203.0.113.0/24" class="font-mono" aria-label="IP or CIDR CrowdSec never bans" autocomplete="off">
                                @error('allowAddress') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="crowdsecAllow">Never ban it</button>
                        </form>
                        <div class="flex justify-end mt-4">
                            <button type="button" wire:click="disableCrowdsec" data-confirm="Disable CrowdSec? Its firewall rules are flushed and its packages removed; fail2ban stays." class="btn btn-ghost danger" @disabled($jobRunning)>Disable CrowdSec</button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
