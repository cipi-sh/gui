@php
    /** @var \Illuminate\Support\Collection<int, \CipiGui\Models\CipiServer> $switcherServers */
    $switcherServers = \CipiGui\Models\CipiServer::query()->where('is_active', true)->orderBy('name')->get();
    $currentServerId = (int) session('cipi_gui_server_id');
    $currentServer = $switcherServers->firstWhere('id', $currentServerId) ?? $switcherServers->first();
@endphp
@if($currentServer)
    <div class="server-switch" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <button type="button" class="server-switch-btn" x-on:click="open = !open" :aria-expanded="open" aria-haspopup="listbox" title="Switch server">
            <span class="dot {{ $currentServer->last_error ? 'dot-red' : 'dot-green' }}"></span>
            <span class="text-subtle hidden sm:inline">Server</span>
            <span class="font-semibold truncate max-w-xs">{{ $currentServer->name }}</span>
            <x-cipi::icon name="chevron-up-down" class="h-4 w-4 text-subtle" />
        </button>
        <div class="server-switch-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
            <p class="dropdown-label">Switch server</p>
            <form method="POST" action="{{ route('cipi-gui.servers.switch') }}">
                @csrf
                @foreach($switcherServers as $s)
                    <button type="submit" name="server_id" value="{{ $s->id }}" class="server-switch-item {{ $s->id === $currentServer->id ? 'current' : '' }}">
                        <span class="dot {{ $s->last_error ? 'dot-red' : 'dot-green' }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-strong">{{ $s->name }}</span>
                            <span class="block text-xs text-muted truncate">{{ $s->host }}</span>
                        </span>
                        @if($s->id === $currentServer->id)
                            <x-cipi::icon name="check" class="h-4 w-4 text-accent" />
                        @endif
                    </button>
                @endforeach
            </form>
            <div class="dropdown-divider"></div>
            <a href="{{ route('cipi-gui.servers') }}" class="dropdown-item"><x-cipi::icon name="plus" /> Add or manage connections</a>
        </div>
    </div>
@else
    <a href="{{ route('cipi-gui.servers') }}" class="btn btn-secondary btn-sm"><x-cipi::icon name="plus" /> Connect a server</a>
@endif
