@php
    $user = auth()->user();
    $displayName = $user->name ?? $user->email ?? 'Admin';
    $initials = collect(preg_split('/\s+/', trim((string) $displayName)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp
<header class="cipi-gui-header">
    <div class="flex items-center gap-2 min-w-0">
        <button type="button" class="btn btn-ghost btn-icon md:hidden" x-on:click="mobileNavOpen = true" aria-label="Open menu">
            <x-cipi::icon name="menu" class="h-5 w-5" />
        </button>
        @include('cipi-gui::partials.server-switcher')
    </div>

    <div class="flex items-center gap-2">
        @include('cipi-gui::partials.theme-toggle')

        <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button type="button" class="flex items-center gap-2 btn btn-ghost px-2" x-on:click="open = !open" :aria-expanded="open" aria-haspopup="menu">
                <span class="avatar">{{ $initials ?: 'A' }}</span>
                <span class="hidden sm:block text-sm text-soft truncate max-w-xs">{{ $displayName }}</span>
                <x-cipi::icon name="chevron-down" class="h-3.5 w-3.5 text-subtle" />
            </button>
            <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu">
                <p class="dropdown-label truncate">{{ $user->email ?? '' }}</p>
                <a href="{{ route('cipi-gui.settings') }}" class="dropdown-item" role="menuitem"><x-cipi::icon name="settings" /> Settings</a>
                <a href="https://cipi.sh/docs/gui" target="_blank" rel="noopener" class="dropdown-item" role="menuitem"><x-cipi::icon name="document" /> Documentation</a>
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('cipi-gui.logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item" role="menuitem"><x-cipi::icon name="logout" /> Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
