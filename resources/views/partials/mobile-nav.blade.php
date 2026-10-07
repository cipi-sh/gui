<div x-show="mobileNavOpen" x-cloak
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="mobile-nav-overlay md:hidden"
     x-on:click="mobileNavOpen = false"
     x-on:keydown.escape.window="mobileNavOpen = false">
    <aside class="mobile-nav-drawer" x-on:click.stop x-show="mobileNavOpen"
           x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
        <div class="sidebar-brand">
            @include('cipi-gui::partials.logo')
            <span class="brand-name">Cipi</span>
            <button type="button" class="btn btn-ghost btn-icon btn-sm ml-auto" x-on:click="mobileNavOpen = false" aria-label="Close menu">
                <x-cipi::icon name="x" />
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto px-3 pb-4">
            @include('cipi-gui::partials.nav-links', ['closeMobileNav' => 'mobileNavOpen = false'])
        </nav>
        <div class="border-t p-3">
            <form method="POST" action="{{ route('cipi-gui.logout') }}">
                @csrf
                <button type="submit" class="nav-link nav-button"><x-cipi::icon name="logout" /> Sign out</button>
            </form>
        </div>
    </aside>
</div>
