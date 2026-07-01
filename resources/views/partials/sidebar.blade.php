<aside class="cipi-gui-sidebar hidden md:flex w-64 flex-col border-r">
    <div class="flex h-16 items-center gap-3.5 px-4 border-b">
        @include('cipi-gui::partials.logo')
        <span class="text-sm font-semibold tracking-tight">Cipi</span>
    </div>

    <nav class="flex-1 p-3 space-y-0.5">
        @include('cipi-gui::partials.nav-links')
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
