<div>
    <x-cipi::page-header title="Connections" subtitle="Cipi servers this panel manages through the REST API. Tokens are encrypted at rest.">
        <x-slot:actions>
            <button type="button" wire:click="openAdd" class="btn btn-primary"><x-cipi::icon name="plus" /> Add server</button>
        </x-slot:actions>
    </x-cipi::page-header>

    @if($servers->isEmpty())
        <div class="card">
            <x-cipi::empty icon="servers" title="Connect your first server">
                Every Cipi server you manage needs the REST API (cipi api) and a token. The panel never stores
                root credentials — only that token, encrypted.
                <x-slot:actions>
                    <button type="button" wire:click="openAdd" class="btn btn-primary"><x-cipi::icon name="plus" /> Add server</button>
                </x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        <div class="card card-flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th title="The machine's own name (hostname): what a notification calls the server">Hostname</th>
                            <th>IP</th>
                            <th>Status</th>
                            <th>Last contact</th>
                            <th>Token</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($servers as $server)
                            @php $test = $testResults[$server->id] ?? null; @endphp
                            <tr wire:key="server-row-{{ $server->id }}" class="{{ $server->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <p class="font-semibold text-strong">{{ $server->name }}</p>
                                    <p class="text-xs text-muted">{{ $server->base_url }}</p>
                                </td>
                                <td data-server-hostname>
                                    @if($server->hostname)
                                        <span class="badge badge-accent badge-mono" title="The machine's own name: what a notification calls this server">{{ $server->hostname }}</span>
                                    @else
                                        <span class="text-xs text-subtle" title="Read at the next connection test">—</span>
                                    @endif
                                </td>
                                <td class="font-mono text-xs text-muted">@if($server->ip)<x-cipi::copyable :value="$server->ip" label="Copy IP" />@else — @endif</td>
                                <td>
                                    @if(! $server->is_active)
                                        <span class="badge badge-gray">Disabled</span>
                                    @elseif($test && ! $test['ok'])
                                        <span class="badge badge-red" title="{{ $test['message'] }}">Failed</span>
                                    @elseif($test && $test['ok'])
                                        <span class="badge badge-green">Connected{{ !empty($test['cipi']) ? ' · Cipi '.$test['cipi'] : '' }}</span>
                                    @elseif($server->last_error)
                                        <span class="badge badge-red" title="{{ $server->last_error }}">Error</span>
                                    @else
                                        <span class="badge badge-green">Active</span>
                                    @endif
                                    @if(($test && ! $test['ok']) || (! $test && $server->last_error && $server->is_active))
                                        <p class="text-xs text-danger mt-1 max-w-xs truncate" title="{{ $test['message'] ?? $server->last_error }}">{{ $test['message'] ?? $server->last_error }}</p>
                                    @endif
                                </td>
                                <td class="text-xs text-muted whitespace-nowrap">{{ $server->last_connected_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="font-mono text-xs text-subtle">{{ $server->token_hint ?? 'unreadable' }}</td>
                                <td>
                                    <div class="btn-actions">
                                        <button type="button" wire:click="testConnection({{ $server->id }})" wire:loading.attr="disabled" wire:target="testConnection({{ $server->id }})" class="btn btn-ghost btn-sm">
                                            <span wire:loading.remove wire:target="testConnection({{ $server->id }})">Test</span>
                                            <span wire:loading wire:target="testConnection({{ $server->id }})" class="spinner" style="width:.75rem;height:.75rem"></span>
                                        </button>
                                        @if($server->is_active)
                                            <a href="{{ route('cipi-gui.server-manage', ['serverId' => $server->id]) }}" class="btn btn-ghost btn-sm">Manage</a>
                                        @endif
                                        <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                            <button type="button" class="btn btn-ghost btn-icon btn-sm" x-on:click="open = !open" aria-label="More actions"><x-cipi::icon name="more" /></button>
                                            <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                                <button type="button" class="dropdown-item" wire:click="openEdit({{ $server->id }})" x-on:click="open = false"><x-cipi::icon name="pencil" /> Edit or rotate token</button>
                                                <button type="button" class="dropdown-item" wire:click="toggleActive({{ $server->id }})" x-on:click="open = false"><x-cipi::icon name="power" /> {{ $server->is_active ? 'Disable' : 'Enable' }}</button>
                                                <div class="dropdown-divider"></div>
                                                <button type="button" class="dropdown-item danger" wire:click="confirmDelete({{ $server->id }})" x-on:click="open = false"><x-cipi::icon name="trash" /> Remove connection</button>
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
    @endif

    @if($servers->contains(fn ($s) => $s->machine_name !== null))
        <p class="field-hint mt-3" data-hostname-hint><strong>Hostname</strong> is the machine's own name — what a notification says (<code>Server: {{ $servers->first(fn ($s) => $s->machine_name !== null)->hostname }}</code>) unless the server is told the name it has here: <a href="{{ route('cipi-gui.server-manage', ['serverId' => $servers->first(fn ($s) => $s->machine_name !== null)->id, 'tab' => 'smtp']) }}" class="text-link">Notifications</a> → <em>Written as</em>, or <code>sudo cipi notification server-name &lt;name&gt;</code> on the server.</p>
    @endif

    <div class="card mt-6">
        <div class="card-header">
            <div>
                <h2 class="card-title">Create a token on the server</h2>
                <p class="card-subtitle">Run this on each Cipi server, logged in as <code>cipi</code>. It grants every ability the panel uses. <code>sudo cipi api token create --name=gui --full-access</code> grants them all too, and the ones the API adds later.</p>
            </div>
            <x-cipi::copy :value="$tokenCommand" label="Copy command" />
        </div>
        @include('cipi-gui::partials.terminal', ['lines' => ['$ '.$tokenCommand], 'title' => 'cipi@your-server', 'autoScroll' => false, 'stripPrompt' => true])
        <p class="field-hint">Needs the API on that server (<code>sudo cipi api &lt;domain&gt;</code>). Restrict who can call the API with <code>sudo cipi api ip-whitelist</code> and keep this panel on it.@if($panelIp) It calls from <x-cipi::copyable :value="$panelIp" label="Copy IP" /> — <code>sudo cipi api ip-whitelist add {{ $panelIp }}</code> when you restrict a list that is still open.@endif When a list is already restricted, a successful connection test adds the panel's address by itself.</p>
    </div>

    @if($showFormModal)
        <x-cipi::modal :title="$editingId ? 'Edit connection' : 'Add server'" close="closeForm" persistent
                       :subtitle="$editingId ? 'Leave the token empty to keep the current one.' : 'The token is tested right after saving.'">
            <form wire:submit="save" novalidate>
                <div class="modal-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="server-name">Name</label>
                            <input id="server-name" type="text" wire:model="name" placeholder="production" autocomplete="off" autofocus>
                            @error('name') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="server-ip">Server IP <span class="text-subtle font-normal">(optional)</span></label>
                            <input id="server-ip" type="text" wire:model="ip" placeholder="Detected automatically" autocomplete="off" inputmode="decimal">
                            <p class="field-hint">Shown in the list and on the dashboard; read from the server when empty. Not an access rule: the API's IP whitelist is on the server page, and this panel's IP is added there automatically.</p>
                            @error('ip') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="server-url">API URL</label>
                        <input id="server-url" type="url" wire:model="url" placeholder="https://vps.example.com" autocomplete="off">
                        <p class="field-hint">The host that serves <code>sudo cipi api</code> — the panel calls <code>{url}/api/…</code>.</p>
                        @error('url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="server-token">API token</label>
                        <input id="server-token" type="password" wire:model="token" placeholder="{{ $editingId ? 'Unchanged' : 'Paste the token from cipi api token create' }}" autocomplete="new-password">
                        @error('token') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <details class="rounded-lg" @if($accessClientId !== '' || $errors->has('accessClientId') || $errors->has('accessClientSecret')) open @endif data-server-cf-access>
                        <summary class="text-sm text-soft cursor-pointer">Behind Cloudflare Access <span class="text-subtle">(optional)</span></summary>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            <div>
                                <label for="server-cf-id">CF-Access-Client-Id</label>
                                <input id="server-cf-id" type="text" wire:model="accessClientId" placeholder="1a2b3c….access" autocomplete="off" class="font-mono text-xs">
                                @error('accessClientId') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="server-cf-secret">CF-Access-Client-Secret</label>
                                <input id="server-cf-secret" type="password" wire:model="accessClientSecret" placeholder="{{ $editingId && $accessClientId !== '' ? 'Unchanged' : 'Shown once by the CLI' }}" autocomplete="new-password">
                                @error('accessClientSecret') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <p class="field-hint">When the server's API sits behind Cloudflare Access, the panel sends a service token with every call. On the server: <code>sudo cipi zt hostname add --api</code>, <code>sudo cipi zt access enable --api --allow=none</code>, <code>sudo cipi zt service-token create panel --for=api</code> — it prints both values once.</p>
                        @if($editingId && $accessClientId !== '')
                            <label class="flex items-center gap-2 text-sm mt-2"><input type="checkbox" wire:model="accessClear"> Remove the service token from this connection</label>
                        @endif
                    </details>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Add & test' }}</span>
                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><span class="spinner"></span> Testing…</span>
                    </button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @if($deleting)
        <x-cipi::modal title="Remove connection" close="cancelDelete">
            <div class="modal-body">
                <p class="text-soft">Remove <strong class="text-strong">{{ $deleting->name }}</strong> from this panel? The server, its apps and the API token on it are not touched — revoke the token there with <code>sudo cipi api token revoke</code> if you no longer need it.</p>
            </div>
            <div class="modal-footer">
                <button type="button" wire:click="cancelDelete" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteServer" class="btn btn-danger-solid">Remove</button>
            </div>
        </x-cipi::modal>
    @endif
</div>
