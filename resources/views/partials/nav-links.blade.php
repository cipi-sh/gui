@php
    use CipiGui\Services\Access;
    use CipiGui\Support\Panel;

    $navUser = auth()->user();
    $navAdmin = Access::isAdmin($navUser);
    $navFleet = Panel::fleetView();
    $navServers = $navFleet ? Access::serversWithLevel($navUser) : collect();
    $navSessionServerId = (int) session('cipi_gui_server_id');
    $navRouteServerId = (int) (request()->route('serverId') ?? 0);
    $onServerPage = request()->routeIs('cipi-gui.server-manage');

    $links = [];
    if ($navFleet) {
        $links[] = ['section' => 'Fleet'];
        $links[] = ['route' => 'cipi-gui.dashboard', 'match' => 'cipi-gui.dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'];
        $links[] = ['route' => 'cipi-gui.apps', 'match' => 'cipi-gui.apps*', 'icon' => 'apps', 'label' => 'Apps'];
        $links[] = ['route' => 'cipi-gui.databases', 'match' => 'cipi-gui.databases', 'icon' => 'database', 'label' => 'Databases'];
        if ($navServers->isNotEmpty()) {
            $links[] = ['section' => $navServers->count() === 1 ? 'Server' : 'Servers'];
            if (Panel::serverNav() === 'sidebar') {
                foreach ($navServers as $navServer) {
                    $links[] = [
                        'href' => route('cipi-gui.server-manage', ['serverId' => $navServer->id]),
                        'active' => $onServerPage && ($navRouteServerId === $navServer->id || ($navRouteServerId === 0 && $navSessionServerId === $navServer->id)),
                        'icon' => 'server', 'label' => $navServer->name, 'title' => $navServer->host,
                        'dot' => $navServer->last_error ? 'dot-red' : 'dot-green',
                    ];
                }
            } else {
                $navCurrent = $navServers->firstWhere('id', $navSessionServerId) ?? $navServers->first();
                $links[] = ['href' => route('cipi-gui.server-manage', ['serverId' => $navCurrent->id]), 'active' => $onServerPage, 'icon' => 'server', 'label' => 'Server', 'meta' => $navCurrent->name];
            }
        }
    } else {
        $links[] = ['section' => 'Overview'];
        $links[] = ['route' => 'cipi-gui.dashboard', 'match' => 'cipi-gui.dashboard', 'icon' => 'dashboard', 'label' => 'Dashboard'];
        $links[] = ['section' => 'Current server'];
        $links[] = ['route' => 'cipi-gui.apps', 'match' => 'cipi-gui.apps*', 'icon' => 'apps', 'label' => 'Apps'];
        $links[] = ['route' => 'cipi-gui.databases', 'match' => 'cipi-gui.databases', 'icon' => 'database', 'label' => 'Databases'];
        if (Access::serversWithLevel($navUser)->isNotEmpty()) {
            $links[] = ['route' => 'cipi-gui.server-manage', 'match' => 'cipi-gui.server-manage', 'icon' => 'server', 'label' => 'Server'];
        }
    }
    $links[] = ['section' => 'Panel'];
    if ($navAdmin) {
        $links[] = ['route' => 'cipi-gui.servers', 'match' => 'cipi-gui.servers', 'icon' => 'servers', 'label' => 'Connections'];
        $links[] = ['route' => 'cipi-gui.team', 'match' => 'cipi-gui.team', 'icon' => 'user', 'label' => 'Team'];
    }
    $links[] = ['route' => 'cipi-gui.settings', 'match' => 'cipi-gui.settings', 'icon' => 'settings', 'label' => 'Settings'];
@endphp
@foreach($links as $link)
    @if(isset($link['section']))
        <p class="nav-section">{{ $link['section'] }}</p>
    @else
        @php
            $href = $link['href'] ?? route($link['route']);
            $active = $link['active'] ?? request()->routeIs($link['match']);
        @endphp
        <a href="{{ $href }}" class="nav-link {{ $active ? 'active' : '' }}" @if(isset($link['title'])) title="{{ $link['title'] }}" @endif
           @if($active) aria-current="page" @endif
           @if(isset($closeMobileNav)) x-on:click="{{ $closeMobileNav }}" @endif>
            <x-cipi::icon :name="$link['icon']" />
            <span class="truncate">{{ $link['label'] }}</span>
            @if(isset($link['dot']))<span class="dot {{ $link['dot'] }} ml-auto" style="width:.4rem;height:.4rem;box-shadow:none"></span>@endif
            @if(isset($link['meta']))<span class="nav-meta truncate">{{ $link['meta'] }}</span>@endif
        </a>
    @endif
@endforeach
