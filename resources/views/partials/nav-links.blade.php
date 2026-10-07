@php
    $close = isset($closeMobileNav) ? '@click="'.$closeMobileNav.'"' : '';
    $links = [
        ['section' => 'Overview'],
        ['route' => 'cipi-gui.dashboard', 'match' => 'cipi-gui.dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'],
        ['section' => 'Current server'],
        ['route' => 'cipi-gui.apps', 'match' => 'cipi-gui.apps*', 'icon' => 'apps', 'label' => 'Apps'],
        ['route' => 'cipi-gui.databases', 'match' => 'cipi-gui.databases', 'icon' => 'database', 'label' => 'Databases'],
        ['route' => 'cipi-gui.server-manage', 'match' => 'cipi-gui.server-manage', 'icon' => 'server', 'label' => 'Server'],
        ['section' => 'Panel'],
        ['route' => 'cipi-gui.servers', 'match' => 'cipi-gui.servers', 'icon' => 'servers', 'label' => 'Connections'],
        ['route' => 'cipi-gui.settings', 'match' => 'cipi-gui.settings', 'icon' => 'settings', 'label' => 'Settings'],
    ];
@endphp
@foreach($links as $link)
    @if(isset($link['section']))
        <p class="nav-section">{{ $link['section'] }}</p>
    @else
        <a href="{{ route($link['route']) }}" class="nav-link {{ request()->routeIs($link['match']) ? 'active' : '' }}"
           @if(request()->routeIs($link['match'])) aria-current="page" @endif
           @if(isset($closeMobileNav)) x-on:click="{{ $closeMobileNav }}" @endif>
            <x-cipi::icon :name="$link['icon']" />
            {{ $link['label'] }}
        </a>
    @endif
@endforeach
