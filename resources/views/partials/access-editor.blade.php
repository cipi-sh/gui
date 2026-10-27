{{-- Role and grants of one account: shared by the invite and the edit dialogs on Team. Binds editorRole, serverLevels and appGrants. --}}
<div class="space-y-4">
    <div>
        <span class="label">Role</span>
        <div class="type-picker" role="radiogroup">
            <button type="button" role="radio" aria-checked="{{ $editorRole === 'user' ? 'true' : 'false' }}" wire:click="$set('editorRole', 'user')" class="type-option {{ $editorRole === 'user' ? 'active' : '' }}" @disabled($lockRole ?? false)>
                <strong>User</strong>
                <span>Only the servers and apps picked below</span>
            </button>
            <button type="button" role="radio" aria-checked="{{ $editorRole === 'admin' ? 'true' : 'false' }}" wire:click="$set('editorRole', 'admin')" class="type-option {{ $editorRole === 'admin' ? 'active' : '' }}" @disabled($lockRole ?? false)>
                <strong>Admin</strong>
                <span>Every server and app, connections, API tokens, the team and the panel settings</span>
            </button>
        </div>
        @error('editorRole') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    @if($editorRole === 'user')
        @if($servers->isEmpty())
            <x-cipi::alert type="info">No server is connected yet — a user gets access once servers exist. Admins can connect them under Connections.</x-cipi::alert>
        @else
            <p class="text-xs text-muted">
                On a server, <strong class="text-strong">View</strong> opens its page read-only and lists its apps and databases; <strong class="text-strong">Manage</strong> is everything, apps included. Without a level, tick abilities on single apps.
            </p>
            @foreach($servers as $s)
                @php $level = $serverLevels[$s->id] ?? ''; @endphp
                <div class="card p-4" wire:key="access-server-{{ $s->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-strong">{{ $s->name }}</p>
                            <p class="text-xs text-muted truncate">{{ $s->host }}</p>
                        </div>
                        <select wire:model.live="serverLevels.{{ $s->id }}" class="input-sm w-auto" aria-label="Access to {{ $s->name }}">
                            <option value="">No access</option>
                            @foreach($levels as $key => [$label, $hint])
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($level === 'manage')
                        <p class="text-xs text-muted mt-2">Everything on this server, every app included.</p>
                    @else
                        <div class="mt-3">
                            @if(! $appsLoaded)
                                <div class="skeleton h-6 w-full"></div>
                            @elseif(isset($serverAppErrors[$s->id]))
                                <p class="text-xs text-danger">Apps could not be listed: {{ $serverAppErrors[$s->id] }}</p>
                            @elseif(empty($serverApps[$s->id]))
                                <p class="text-xs text-muted">No apps on this server yet.</p>
                            @else
                                <p class="text-xs text-subtle mb-1">Per app{{ $level === 'view' ? ' — on top of viewing every app' : '' }}</p>
                                @foreach($serverApps[$s->id] as $app)
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 border-t" wire:key="access-app-{{ $s->id }}-{{ $app['app'] }}">
                                        <span class="font-mono text-sm text-strong" style="min-width: 7rem;">{{ $app['app'] }}</span>
                                        <span class="text-xs text-muted truncate" style="max-width: 14rem;">{{ $app['domain'] }}</span>
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 sm:ml-auto">
                                            @foreach($abilities as $ability => [$label, $hint])
                                                <label class="check text-xs" title="{{ $hint }}">
                                                    <input type="checkbox" wire:model="appGrants.{{ $s->id }}.{{ $app['app'] }}" value="{{ $ability }}"> {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
            <details class="text-xs text-muted">
                <summary class="cursor-pointer">What each ability allows</summary>
                <dl class="kv mt-2">
                    @foreach($abilities as $ability => [$label, $hint])
                        <dt>{{ $label }}</dt><dd>{{ $hint }}</dd>
                    @endforeach
                </dl>
            </details>
        @endif
    @endif
</div>
