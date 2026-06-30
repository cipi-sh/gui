<div>
    <div class="mb-6">
        <h2 class="text-2xl font-semibold text-white">Servers</h2>
        <p class="text-sm text-surface-400 mt-1">Connect and manage your Cipi servers via API token</p>
    </div>

    @if($success)
        <div class="card border-emerald-700 bg-emerald-900/20 mb-4 text-sm text-emerald-400">{{ $success }}</div>
    @endif
    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="card">
                <h3 class="font-semibold text-white mb-4">Connected Servers</h3>
                @if($servers->isEmpty())
                    <p class="text-sm text-surface-400">No servers yet. Add one using the form.</p>
                @else
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>URL</th>
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
                                                <button wire:click="selectServer({{ $server->id }})" class="btn btn-ghost btn-sm">Use</button>
                                                <button wire:click="testConnection({{ $server->id }})" class="btn btn-ghost btn-sm" @if($testing) disabled @endif>Test</button>
                                                <button wire:click="toggleActive({{ $server->id }})" class="btn btn-ghost btn-sm">{{ $server->is_active ? 'Disable' : 'Enable' }}</button>
                                                <button wire:click="deleteServer({{ $server->id }})" wire:confirm="Remove this server?" class="btn btn-ghost btn-sm text-red-400">Remove</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div>
            <div class="card">
                <h3 class="font-semibold text-white mb-4">Add Server</h3>
                <form wire:submit="addServer" class="space-y-4">
                    <div>
                        <label>Name</label>
                        <input type="text" wire:model="name" placeholder="production">
                        @error('name') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label>Server URL</label>
                        <input type="url" wire:model="url" placeholder="https://vps.example.com">
                        @error('url') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label>API Token</label>
                        <input type="password" wire:model="token" placeholder="Bearer token from cipi api token create">
                        @error('token') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <p class="text-xs text-surface-500">Requires <code class="text-brand-400">cipi api</code> enabled on the target server. Create a token with all required abilities.</p>
                    <button type="submit" class="btn btn-primary w-full">Add Server</button>
                </form>
            </div>
        </div>
    </div>
</div>
