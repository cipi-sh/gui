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
                                <td class="font-mono text-xs text-muted">{{ $server->ip ?? '—' }}</td>
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

    <div class="card mt-6">
        <div class="card-header">
            <div>
                <h2 class="card-title">Create a token on the server</h2>
                <p class="card-subtitle">Run this on each Cipi server (as root). It grants the {{ $abilityCount }} abilities the panel uses with API 1.31.</p>
            </div>
            <x-cipi::copy :value="$tokenCommand" label="Copy command" />
        </div>
        @include('cipi-gui::partials.terminal', ['lines' => ['$ cipi api', '$ '.$tokenCommand], 'title' => 'root@your-server', 'autoScroll' => false])
        <p class="field-hint">Restrict who can call the API with <code>cipi api ip-whitelist</code> — and include this panel's IP.</p>
    </div>

    @if($showFormModal)
        <x-cipi::modal :title="$editingId ? 'Edit connection' : 'Add server'" close="closeForm"
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
                            <label for="server-ip">IP address <span class="text-subtle font-normal">(optional)</span></label>
                            <input id="server-ip" type="text" wire:model="ip" placeholder="Detected automatically" autocomplete="off" inputmode="decimal">
                            @error('ip') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="server-url">API URL</label>
                        <input id="server-url" type="url" wire:model="url" placeholder="https://vps.example.com" autocomplete="off">
                        <p class="field-hint">The host that serves <code>cipi api</code> — the panel calls <code>{url}/api/…</code>.</p>
                        @error('url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="server-token">API token</label>
                        <input id="server-token" type="password" wire:model="token" placeholder="{{ $editingId ? 'Unchanged' : 'Paste the token from cipi api token create' }}" autocomplete="new-password">
                        @error('token') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
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
                <p class="text-soft">Remove <strong class="text-strong">{{ $deleting->name }}</strong> from this panel? The server, its apps and the API token on it are not touched — revoke the token there with <code>cipi api token revoke</code> if you no longer need it.</p>
            </div>
            <div class="modal-footer">
                <button type="button" wire:click="cancelDelete" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteServer" class="btn btn-danger-solid">Remove</button>
            </div>
        </x-cipi::modal>
    @endif
</div>
