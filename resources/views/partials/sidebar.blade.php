<aside class="cipi-gui-sidebar hidden md:flex" aria-label="Main navigation">
  <div class="sidebar-inner">
    <div class="sidebar-brand">
        <a href="{{ route('cipi-gui.dashboard') }}" class="flex items-center gap-3 text-strong">
            @include('cipi-gui::partials.logo')
            <span class="brand-name">Cipi</span>
        </a>
        <span class="brand-tag ml-auto">panel</span>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 pb-4">
        @include('cipi-gui::partials.nav-links')
    </nav>

    <div class="border-t px-4 py-3 text-2xs text-subtle flex items-center justify-between gap-2">
        <span>cipi/gui {{ \CipiGui\Support\Theme::VERSION }}</span>
        <a href="https://cipi.sh/docs/gui" target="_blank" rel="noopener" class="text-subtle">Docs ↗</a>
    </div>
  </div>
</aside>
