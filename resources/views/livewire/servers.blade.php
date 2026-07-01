<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-white">Servers</h2>
            <p class="text-sm text-surface-400 mt-1">Connect and manage your Cipi servers via API token</p>
        </div>
        <button wire:click="openAdd" class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add Server
        </button>
    </div>

    @if($success)
        <div class="card border-emerald-700 bg-emerald-900/20 mb-4 text-sm text-emerald-400">{{ $success }}</div>
    @endif
    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    @if($servers->isEmpty())
        <div class="card text-center py-12">
            <svg class="h-12 w-12 mx-auto text-surface-600 mb-4" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 17.25v-.228a4.5 4.5 0 0 0-.12-1.03l-2.268-9.64a3.375 3.375 0 0 0-3.285-2.602H7.923a3.375 3.375 0 0 0-3.285 2.602l-2.268 9.64a4.5 4.5 0 0 0-.12 1.03v.228m19.5 0a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3m19.5 0a3 3 0 0 0-3-3H5.25a3 3 0 0 0-3 3m16.5 0h.008v.008h-.008v-.008Zm-3 0h.008v.008h-.008v-.008Z" /></svg>
            <p class="text-surface-400 mb-4">No servers yet. Add your first Cipi server to get started.</p>
            <button wire:click="openAdd" class="btn btn-primary">Add Server</button>
        </div>
    @else
        <div class="card p-0 overflow-hidden">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>URL</th>
                            <th>IP</th>
                            <th>Status</th>
                            <th>Last connected</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($servers as $server)
                            <tr>
                                <td class="font-medium text-white">{{ $server->name }}</td>
                                <td class="text-surface-400 text-sm">{{ $server->url }}</td>
                                <td class="text-surface-400 text-sm font-mono">{{ $server->ip ?? '—' }}</td>
                                <td>
                                    @if(!$server->is_active)
                                        <span class="badge badge-gray">Disabled</span>
                                    @elseif($server->last_error)
                                        <span class="badge badge-red" title="{{ $server->last_error }}">Error</span>
                                    @else
                                        <span class="badge badge-green">Active</span>
                                    @endif
                                </td>
                                <td class="text-sm text-surface-400">{{ $server->last_connected_at?->diffForHumans() ?? 'Never' }}</td>
                                <td>
                                    <div class="flex gap-1 justify-end">
                                        <button wire:click="testConnection({{ $server->id }})" class="btn btn-ghost btn-sm" @if($testing) disabled @endif>Test</button>
                                        <button wire:click="deleteServer({{ $server->id }})" wire:confirm="Remove this server?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($showAddModal)
        <div class="modal-overlay" wire:click.self="closeAdd">
            <div class="modal-content">
                <div class="p-6 border-b border-surface-800">
                    <h3 class="text-lg font-semibold text-white">Add Server</h3>
                </div>
                <form wire:submit.prevent="addServer" novalidate class="p-6 space-y-4">
                    <div>
                        <label>Name</label>
                        <input type="text" wire:model="name" placeholder="production" autocomplete="off">
                        @error('name') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-surface-500 mt-1">Letters, numbers, hyphens and underscores only.</p>
                    </div>
                    <div>
                        <label>Server URL</label>
                        <input type="text" wire:model="url" placeholder="https://vps.example.com" autocomplete="off" inputmode="url">
                        @error('url') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label>IP Address <span class="text-surface-500 font-normal">(optional)</span></label>
                        <input type="text" wire:model="ip" placeholder="203.0.113.10" autocomplete="off" inputmode="decimal">
                        @error('ip') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        <p class="text-xs text-surface-500 mt-1">Auto-detected from URL when possible. Override if needed.</p>
                    </div>
                    <div>
                        <label>API Token</label>
                        <input type="password" wire:model="token" placeholder="Token from cipi api token create" autocomplete="off">
                        @error('token') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <p class="text-xs text-surface-500">Requires <code class="text-link">cipi api</code> enabled on the target server. Create a token with all required abilities.</p>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeAdd" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addServer">
                            <span wire:loading.remove wire:target="addServer">Add Server</span>
                            <span wire:loading wire:target="addServer" class="inline-flex items-center gap-2">
                                <span class="spinner"></span> Saving…
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
