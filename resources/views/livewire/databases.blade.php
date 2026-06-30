<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-white">Databases</h2>
            <p class="text-sm text-surface-400 mt-1">MySQL databases on the selected server</p>
        </div>
        @if($servers->isNotEmpty())
            <button wire:click="openCreate" class="btn btn-primary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Database
            </button>
        @endif
    </div>

    @include('cipi-gui::partials.server-selector')

    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    @if($lastCredentials)
        <div class="card border-emerald-700 bg-emerald-900/20 mb-4">
            <p class="text-sm text-emerald-400 font-medium mb-2">Database credentials (save now):</p>
            <pre class="font-mono text-sm text-white whitespace-pre-wrap">{{ json_encode($lastCredentials, JSON_PRETTY_PRINT) }}</pre>
            <button wire:click="$set('lastCredentials', null)" class="btn btn-ghost btn-sm mt-2">Dismiss</button>
        </div>
    @endif

    @if($servers->isEmpty())
        <div class="card text-center py-12">
            <a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Add Server</a>
        </div>
    @elseif($loading)
        <div class="card flex items-center justify-center py-12 gap-3">
            <div class="spinner spinner-lg"></div>
            <span class="text-surface-400">Loading databases...</span>
        </div>
    @elseif(empty($databases))
        <div class="card text-center py-12">
            <p class="text-surface-400 mb-4">No databases on this server.</p>
            <button wire:click="openCreate" class="btn btn-primary">Create database</button>
        </div>
    @else
        <div class="card p-0 overflow-hidden">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Size</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($databases as $db)
                        <tr>
                            <td class="font-medium text-white font-mono">{{ $db['name'] }}</td>
                            <td class="text-surface-400">{{ $db['size'] ?? '—' }}</td>
                            <td>
                                <div class="flex gap-1 justify-end">
                                    <button wire:click="backupDatabase('{{ $db['name'] }}')" class="btn btn-ghost btn-sm">Backup</button>
                                    <button wire:click="openRestore('{{ $db['name'] }}')" class="btn btn-ghost btn-sm">Restore</button>
                                    <button wire:click="regeneratePassword('{{ $db['name'] }}')" wire:confirm="Regenerate password for {{ $db['name'] }}?" class="btn btn-ghost btn-sm">New Password</button>
                                    <button wire:click="deleteDatabase('{{ $db['name'] }}')" wire:confirm="Permanently delete {{ $db['name'] }}?" class="btn btn-ghost btn-sm text-red-400">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($showCreateModal)
        <div class="modal-overlay" wire:click.self="$set('showCreateModal', false)">
            <div class="modal-content">
                <div class="p-6 border-b border-surface-800">
                    <h3 class="text-lg font-semibold text-white">Create Database</h3>
                </div>
                <form wire:submit="createDatabase" class="p-6 space-y-4">
                    <div>
                        <label>Database name</label>
                        <input type="text" wire:model="dbName" placeholder="mydb">
                        @error('dbName') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if($showRestoreModal)
        <div class="modal-overlay" wire:click.self="$set('showRestoreModal', false)">
            <div class="modal-content">
                <div class="p-6 border-b border-surface-800">
                    <h3 class="text-lg font-semibold text-white">Restore {{ $restoreDbName }}</h3>
                </div>
                <form wire:submit="restoreDatabase" class="p-6 space-y-4">
                    <div>
                        <label>Backup file path on server</label>
                        <input type="text" wire:model="restoreFile" placeholder="/home/cipi/backups/mydb_2026.sql.gz">
                        @error('restoreFile') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="$set('showRestoreModal', false)" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Restore</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
