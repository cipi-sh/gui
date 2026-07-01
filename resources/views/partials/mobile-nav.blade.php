<div x-show="mobileNavOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="mobile-nav-overlay md:hidden"
     @click="mobileNavOpen = false"
     @keydown.escape.window="mobileNavOpen = false">
    <aside class="mobile-nav-drawer"
           @click.stop
           x-show="mobileNavOpen"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full">
        <div class="flex h-16 items-center justify-between px-4 border-b">
            <div class="flex items-center gap-3.5">
                @include('cipi-gui::partials.logo')
                <span class="text-sm font-semibold tracking-tight">Cipi</span>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="mobileNavOpen = false" aria-label="Close menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <nav class="flex-1 p-3 space-y-0.5 overflow-y-auto">
            @include('cipi-gui::partials.nav-links', ['closeMobileNav' => 'mobileNavOpen = false'])
        </nav>

        <div class="border-t p-3">
            <form method="POST" action="{{ route('cipi-gui.logout') }}">
                @csrf
                <button type="submit" class="nav-link w-full text-left" style="border:none;background:none;cursor:pointer;width:100%;">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                    Sign out
                </button>
            </form>
        </div>
    </aside>
</div>
