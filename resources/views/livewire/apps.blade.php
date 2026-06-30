<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-white">Apps</h2>
            <p class="text-sm text-surface-400 mt-1">Manage Laravel and custom applications</p>
        </div>
        @if($servers->isNotEmpty())
            <button wire:click="openCreate" class="btn btn-primary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New App
            </button>
        @endif
    </div>

    @include('cipi-gui::partials.server-selector')

    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    @if($servers->isEmpty())
        <div class="card text-center py-12">
            <p class="text-surface-400 mb-4">Add a server first to manage apps.</p>
            <a href="{{ route('cipi-gui.servers') }}" class="btn btn-primary">Add Server</a>
        </div>
    @elseif($loading)
        <div class="card flex items-center justify-center py-12 gap-3">
            <div class="spinner spinner-lg"></div>
            <span class="text-surface-400">Loading apps...</span>
        </div>
    @elseif(empty($apps))
        <div class="card text-center py-12">
            <p class="text-surface-400 mb-4">No apps on this server yet.</p>
            <button wire:click="openCreate" class="btn btn-primary">Create first app</button>
        </div>
    @else
        <div class="card p-0 overflow-hidden">
            <table>
                <thead>
                    <tr>
                        <th>App</th>
                        <th>Domain</th>
                        <th>PHP</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($apps as $app)
                        <tr>
                            <td>
                                <a href="{{ route('cipi-gui.apps.show', $app['app']) }}" class="font-medium text-brand-400">{{ $app['app'] }}</a>
                            </td>
                            <td class="text-surface-300">{{ $app['domain'] }}</td>
                            <td><span class="badge badge-blue">{{ $app['php'] }}</span></td>
                            <td class="text-surface-400 text-sm">{{ $app['branch'] ?? '—' }}</td>
                            <td>
                                @if($app['suspended'] ?? false)
                                    <span class="badge badge-amber">Suspended</span>
                                @else
                                    <span class="badge badge-green">Active</span>
                                @endif
                                @if($app['basic_auth'] ?? false)
                                    <span class="badge badge-gray ml-1">Auth</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('cipi-gui.apps.show', $app['app']) }}" class="btn btn-ghost btn-sm">Manage</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Create modal --}}
    @if($showCreateModal)
        <div class="modal-overlay" wire:click.self="$set('showCreateModal', false)">
            <div class="modal-content">
                <div class="p-6 border-b border-surface-800">
                    <h3 class="text-lg font-semibold text-white">Create App</h3>
                </div>
                <form wire:submit="createApp" class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label>Username</label>
                            <input type="text" wire:model="user" placeholder="myapp">
                            @error('user') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label>Domain</label>
                            <input type="text" wire:model="domain" placeholder="myapp.example.com">
                            @error('domain') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="custom" id="custom">
                        <label for="custom" style="margin:0;font-weight:400;">Custom app (non-Laravel)</label>
                    </div>

                    @if(!$custom)
                        <div>
                            <label>Repository (Git SSH URL)</label>
                            <input type="text" wire:model="repository" placeholder="git@github.com:user/repo.git">
                            @error('repository') <p class="text-sm text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label>Branch</label>
                                <input type="text" wire:model="branch">
                            </div>
                            <div>
                                <label>PHP Version</label>
                                <select wire:model="php">
                                    @foreach($phpVersions as $v)
                                        <option value="{{ $v }}">{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @else
                        <div>
                            <label>Repository (optional — leave empty for SFTP-only)</label>
                            <input type="text" wire:model="repository" placeholder="git@github.com:user/repo.git">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label>Docroot</label>
                                <input type="text" wire:model="docroot" placeholder="public">
                            </div>
                            <div>
                                <label>PHP Version</label>
                                <select wire:model="php">
                                    @foreach($phpVersions as $v)
                                        <option value="{{ $v }}">{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 pt-4">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @include('cipi-gui::partials.job-overlay')
</div>
