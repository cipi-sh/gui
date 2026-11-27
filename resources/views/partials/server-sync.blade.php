{{-- Server → Transfer: this server's apps sent to another server (cipi sync). The host
     component uses CipiGui\Livewire\Concerns\ManagesSync. --}}
@php
    $syncPeers = $this->syncPeers();
    $syncPeer = $syncPeers->firstWhere('id', $syncPeerId);
    [$syncState, $syncVerdict] = \CipiGui\Support\Sync::verdict($syncCheck, $syncPeer !== null);
    $syncAll = $syncScope !== 'chosen';
    // cipi sync does not recreate Node apps yet: listed, not sent.
    $syncMovable = \CipiGui\Support\Sync::movable($syncSourceApps);
    $syncNodeApps = count($syncSourceApps) - count($syncMovable);
    $syncChosen = $syncAll ? $syncMovable : array_values(array_intersect($syncApps, $syncMovable));
    $syncTarget = $syncPeer?->name ?? (trim($syncHost) !== '' ? trim($syncHost) : 'the target');
    $syncChecked = $syncState === 'ok' && ($syncCheck['host'] ?? null) === trim($syncHost) && (int) ($syncCheck['port'] ?? 0) === (int) $syncPort;
    $syncReady = $syncChecked && $syncChosen !== [];
@endphp
@if($syncUnsupported === 'ability')
    <x-cipi::alert type="warn" title="This token cannot move apps">
        The tab needs the <code>sync-manage</code> ability. It is as strong as <code>ssh-manage</code> — whoever holds it can have the server send its apps, databases included, to a host of their choosing — so existing tokens do not get it by themselves: recreate the token from <a href="{{ route('cipi-gui.servers') }}" class="text-link">Connections</a>.
    </x-cipi::alert>
@elseif($syncUnsupported === 'server')
    <x-cipi::alert type="warn" title="Transfers from the panel need a newer server">
        Run <code>cipi self-update &amp;&amp; cipi api update</code> on the server. Until then, over SSH: <code>cipi sync push --host=&lt;target&gt; --import</code>.
    </x-cipi::alert>
@else
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" data-sync>
        <div class="card lg:col-span-2">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Send apps to another server</h2>
                    <p class="card-subtitle">A copy of the apps of <strong>{{ $server->name }}</strong> — settings, <code>.env</code>, deploy recipe, and their data when asked — made on another Cipi server. Nothing is removed or stopped here.</p>
                </div>
            </div>
            @if(! $canManage)
                <p class="text-muted">Moving apps needs Manage access to this server.</p>
            @elseif($syncMovable === [])
                <p class="text-muted">This server has no apps to send.@if($syncNodeApps) Its Node apps are not moved by a transfer: create them on the other server — they deploy from Git.@endif</p>
            @else
                <form wire:submit="checkSync" class="space-y-6">
                    {{-- 1. What --}}
                    <section data-sync-step="what">
                        <h3 class="text-sm font-semibold mb-2">1 · What</h3>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <label class="check-card"><input type="radio" wire:model.live="syncScope" value="all"><span><span class="check-card-title">Every app</span><span class="check-card-text">All {{ count($syncMovable) }} of this server</span></span></label>
                            <label class="check-card"><input type="radio" wire:model.live="syncScope" value="chosen"><span><span class="check-card-title">Chosen apps</span><span class="check-card-text">Tick them below</span></span></label>
                        </div>
                        @unless($syncAll)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" data-sync-apps>
                                @foreach($syncSourceApps as $item)
                                    @if($item['kind'] === 'node')
                                        <label class="check-card" style="opacity:.6;cursor:default;">
                                            <input type="checkbox" disabled>
                                            <span><span class="check-card-title font-mono text-xs">{{ $item['app'] }}</span><span class="check-card-text">{{ $item['domain'] }} · Node app: not moved</span></span>
                                        </label>
                                    @else
                                        <label class="check-card">
                                            <input type="checkbox" wire:model.live="syncApps" value="{{ $item['app'] }}">
                                            <span><span class="check-card-title font-mono text-xs">{{ $item['app'] }}</span><span class="check-card-text">{{ $item['domain'] }} · {{ $item['kind'] }}</span></span>
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                            @error('syncApps') <p class="field-error">{{ $message }}</p> @enderror
                        @endunless
                        @if($syncNodeApps)
                            <p class="field-hint" data-sync-node>{{ $syncNodeApps === 1 ? '1 Node app of this server is' : $syncNodeApps.' Node apps of this server are' }} not moved: a transfer does not recreate Node apps yet. Create {{ $syncNodeApps === 1 ? 'it' : 'them' }} on the target — the code comes from Git with the first deploy.</p>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                            <label class="check-card"><input type="checkbox" wire:model.live="syncWithDb"><span><span class="check-card-title">Databases</span><span class="check-card-text">Dumped here, restored there — replacing the database of an app that is already on the target</span></span></label>
                            <label class="check-card"><input type="checkbox" wire:model.live="syncWithStorage"><span><span class="check-card-title">Uploaded files</span><span class="check-card-text"><code>shared/storage</code> of each app: can be large</span></span></label>
                        </div>
                    </section>

                    {{-- 2. Where --}}
                    <section data-sync-step="where">
                        <h3 class="text-sm font-semibold mb-2">2 · Where</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                            <div class="sm:col-span-2">
                                <label for="sync-peer">Target server</label>
                                <select id="sync-peer" wire:model.live="syncPeerId">
                                    @foreach($syncPeers as $peer)
                                        <option value="{{ $peer->id }}">{{ $peer->name }}</option>
                                    @endforeach
                                    <option value="0">A server that is not in this panel…</option>
                                </select>
                            </div>
                            <div>
                                <label for="sync-host">SSH address</label>
                                <input id="sync-host" type="text" wire:model.blur="syncHost" class="font-mono" placeholder="203.0.113.9" autocomplete="off">
                            </div>
                            <div>
                                <label for="sync-port">SSH port</label>
                                <input id="sync-port" type="number" wire:model.blur="syncPort" min="1" max="65535">
                            </div>
                        </div>
                        @error('syncHost') <p class="field-error">{{ $message }}</p> @enderror
                        @error('syncPort') <p class="field-error">{{ $message }}</p> @enderror
                        <p class="field-hint">The address <strong>this server</strong> reaches the target on over SSH — its public IP, or a private or mesh address when the two share a network. The target needs Cipi and room for the apps.</p>
                        @if($syncPeer === null && !empty($syncKey['key']))
                            <div class="mt-3" data-sync-key>
                                <p class="field-hint">A server that is not in this panel has to be told to trust this one. On the target, as root: <code>cipi sync trust</code>, and paste this key —</p>
                                <x-cipi::copyable :value="$syncKey['key']" label="Copy the key" class="font-mono text-xs break-all" />
                                <p class="field-hint">Fingerprint <code>{{ $syncKey['fingerprint'] ?? '' }}</code>. To take it back later: <code>cipi sync untrust --key="…"</code> there.</p>
                            </div>
                        @elseif($syncPeer !== null)
                            <p class="field-hint mt-2" data-sync-managed>{{ $syncPeer->name }} is in this panel: checking the connection adds this server's key to it, and the key is taken out again when the transfer ends — while this page is open to see it end. <button type="button" wire:click="forgetSyncTrust" class="text-link" data-sync-forget>Remove the key from {{ $syncPeer->name }} now</button></p>
                            <label class="check-card mt-2" style="max-width:34rem;"><input type="checkbox" wire:model="syncKeepTrust"><span><span class="check-card-title">Keep this server trusted on {{ $syncPeer->name }}</span><span class="check-card-text">For transfers that will be repeated. Trusted means: this server's <code>cipi</code> user can sign in there.</span></span></label>
                        @endif
                        <div class="flex items-center gap-3 mt-3">
                            <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="checkSync"><x-cipi::icon name="bolt" /> Check the connection</button>
                            <span class="text-xs text-muted" wire:loading wire:target="checkSync">Asking {{ $server->name }} to reach the target…</span>
                        </div>
                        @if($syncNote)
                            <x-cipi::alert type="info" class="mt-3">{{ $syncNote }}</x-cipi::alert>
                        @endif
                        @if($syncState !== 'none')
                            <x-cipi::alert type="{{ $syncState === 'ok' ? 'success' : ($syncState === 'error' ? 'danger' : 'warn') }}" class="mt-3" data-sync-verdict="{{ $syncState }}">
                                {{ $syncVerdict }}
                            </x-cipi::alert>
                        @endif
                    </section>

                    {{-- 3. Encryption, and go --}}
                    <section data-sync-step="go">
                        <h3 class="text-sm font-semibold mb-2">3 · Encryption and transfer</h3>
                        <p class="field-hint" data-sync-encryption>
                            The apps travel as <strong>one archive, encrypted (AES-256) on this server</strong> before it leaves, over SSH. Its passphrase is made by the server for this one transfer, handed to the target's import over the same SSH connection, and then gone: it is never shown, never stored, and never passes through this panel. The archive is removed from both servers when the import ends.
                        </p>
                        <div class="flex justify-end mt-3">
                            <button type="button" wire:click="startSync" class="btn btn-primary" data-sync-start="{{ $syncReady ? 'ready' : 'wait' }}" @disabled(! $syncReady)
                                    data-confirm="{{ \CipiGui\Support\Sync::summary($syncAll, $syncChosen, count($syncMovable), $syncWithDb, $syncWithStorage, $syncTarget) }}"
                                    data-confirm-label="Start the transfer">
                                <x-cipi::icon name="upload" /> Start the transfer
                            </button>
                        </div>
                        @unless($syncReady)
                            <p class="field-hint text-right">{{ $syncChecked ? 'Choose at least one app.' : 'Check the connection first.' }}</p>
                        @endunless
                    </section>
                </form>
                @if($syncTrustedPeerId !== 0 && ($trustedPeer = $syncPeers->firstWhere('id', $syncTrustedPeerId)))
                    <div class="flex items-center justify-between gap-3 mt-4 pt-4" style="border-top:1px solid var(--border);" data-sync-trusted>
                        <p class="text-xs text-muted">This server's key is trusted on <strong>{{ $trustedPeer->name }}</strong> since the last check.</p>
                        <button type="button" wire:click="forgetSyncTrust" class="btn btn-ghost btn-xs">Remove it now</button>
                    </div>
                @endif
            @endif
        </div>

        <div class="space-y-4">
            <div class="card" data-sync-receive>
                <div class="card-header"><h2 class="card-title">Bring apps here</h2></div>
                <p class="text-muted text-sm">To host on <strong>{{ $server->name }}</strong> an app that lives on another server, start from the server that has it: it is the one that exports and sends.</p>
                @forelse($syncPeers as $peer)
                    <a href="{{ route('cipi-gui.server-manage', ['serverId' => $peer->id, 'tab' => 'sync', 'to' => $server->id]) }}" class="btn btn-secondary btn-sm w-full mt-2" style="justify-content:space-between;">
                        <span>From {{ $peer->name }}</span><x-cipi::icon name="chevron-right" />
                    </a>
                @empty
                    <p class="field-hint mt-2">No other server in this panel. From a server that is not in it, over SSH there: <code>cipi sync push --host={{ $server->ip ?: '&lt;this server&gt;' }} --import</code>, after <code>cipi sync trust</code> here.</p>
                @endforelse
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">What a transfer does</h2></div>
                <ul class="text-sm text-muted space-y-2" style="list-style:disc;padding-left:1.1rem;">
                    <li>Apps that are <strong>not</strong> on the target are created there: user, PHP, Nginx, workers, crons, deploy key.</li>
                    <li>Laravel and custom apps travel. Node apps do not yet.</li>
                    <li>Apps that <strong>are</strong> there are updated from this server's.</li>
                    <li>Domains keep pointing where DNS says: nothing changes for visitors until you move the records.</li>
                    <li>Certificates are issued on the target once DNS points at it.</li>
                    <li>This server keeps everything. Delete the apps here when the target has taken over.</li>
                </ul>
            </div>
        </div>
    </div>
@endif
