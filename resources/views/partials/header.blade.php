<header class="flex h-16 items-center justify-between border-b border-surface-800 bg-surface-900/50 px-4 sm:px-6">
    <div class="flex items-center gap-3 min-w-0 md:hidden">
        <span class="font-semibold text-white">Cipi GUI</span>
    </div>
    <div class="hidden md:block"></div>
    <div class="flex items-center gap-3">
        <span class="text-sm text-surface-400">{{ auth()->user()->name ?? auth()->user()->email }}</span>
    </div>
</header>
