<header class="cipi-gui-header flex h-16 items-center justify-between border-b px-4 sm:px-6">
    <div class="flex items-center gap-3 min-w-0 md:hidden">
        @include('cipi-gui::partials.logo')
        <span class="text-sm font-semibold">Cipi</span>
    </div>
    <div class="hidden md:block"></div>
    <div class="flex items-center gap-3">
        @include('cipi-gui::partials.theme-toggle')
        <span class="text-xs text-surface-400 hidden sm:block">{{ auth()->user()->name ?? auth()->user()->email }}</span>
    </div>
</header>
