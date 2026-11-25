{{-- Role and grants of one account: shared by the invite and the edit dialogs on Team. Binds editorRole, serverLevels and appGrants. --}}
<div class="space-y-4">
    <div>
        <span class="label">Role</span>
        <div class="type-picker role-picker" role="radiogroup">
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
                    <div class="access-server-head">
                        <div class="min-w-0">
                            <p class="font-semibold text-strong">{{ $s->name }}</p>
                            <p class="text-xs text-muted truncate">{{ $s->host }}</p>
                        </div>
                        <select wire:model.live="serverLevels.{{ $s->id }}" class="input-sm" aria-label="Access to {{ $s->name }}">
                            <option value="">No access</option>
                            @foreach($levels as $key => [$label, $hint])
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($level === 'manage')
                        <p class="text-xs text-muted mt-3">Everything on this server, every app included.</p>
                    @elseif(! $appsLoaded)
                        <div class="skeleton h-6 w-full mt-3"></div>
                    @elseif(isset($serverAppErrors[$s->id]))
                        <p class="text-xs text-danger mt-3">Apps could not be listed: {{ $serverAppErrors[$s->id] }}</p>
                    @elseif(empty($serverApps[$s->id]))
                        <p class="text-xs text-muted mt-3">No apps on this server yet.</p>
                    @else
                        <p class="access-apps-title">Per app{{ $level === 'view' ? ' — on top of viewing every app' : '' }}</p>
                        <ul class="access-apps">
                            @foreach($serverApps[$s->id] as $app)
                                <li class="access-app" wire:key="access-app-{{ $s->id }}-{{ $app['app'] }}">
                                    <div class="access-app-id">
                                        <span class="access-app-name">{{ $app['app'] }}</span>
                                        @if($app['domain'] !== '')<span class="access-app-domain" title="{{ $app['domain'] }}">{{ $app['domain'] }}</span>@endif
                                    </div>
                                    <div class="access-abilities" role="group" aria-label="Abilities on {{ $app['app'] }}">
                                        @foreach($abilities as $ability => [$label, $hint])
                                            <label class="access-ability" title="{{ $hint }}">
                                                <input type="checkbox" wire:model="appGrants.{{ $s->id }}.{{ $app['app'] }}" value="{{ $ability }}">
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </li>
                            @endforeach
                        </ul>
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
