<header class="cipi-gui-header flex h-16 items-center justify-between border-b px-4 sm:px-6">
    <div class="flex items-center gap-3 min-w-0">
        <button type="button"
                class="btn btn-ghost btn-sm md:hidden"
                @click="mobileNavOpen = true"
                aria-label="Open menu">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
        </button>
        <div class="flex items-center gap-2.5 md:hidden min-w-0">
            @include('cipi-gui::partials.logo')
            <span class="text-sm font-semibold truncate">Cipi</span>
        </div>
    </div>
    <div class="hidden md:block"></div>
    <div class="flex items-center gap-3">
        @include('cipi-gui::partials.theme-toggle')
        <span class="text-xs text-surface-400 hidden sm:block">{{ auth()->user()->name ?? auth()->user()->email }}</span>
    </div>
</header>
