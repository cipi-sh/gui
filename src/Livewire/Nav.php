<?php

namespace CipiGui\Livewire;

use CipiGui\Services\Access;
use CipiGui\Support\Panel;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The navigation of the sidebar and of the drawer on phones. In fleet view it
 * has an entry per server, so it is a component that re-renders when the
 * connections change (`servers-changed`, from the Connections page) instead of
 * a partial that only a page reload would refresh.
 */
class Nav extends Component
{
    /** The drawer on phones: tapping a link closes it. */
    public bool $mobile = false;

    /**
     * The page this nav was rendered on, kept from mount(): in a later Livewire
     * update, request() is the update request, not the page.
     */
    public string $routeName = '';

    public int $routeServerId = 0;

    public function mount(bool $mobile = false): void
    {
        $this->mobile = $mobile;

        $route = request()->route();
        $this->routeName = (string) ($route?->getName() ?? '');
        $this->routeServerId = (int) ($route?->parameter('serverId') ?? 0);
    }

    #[On('servers-changed')]
    public function refresh(): void
    {
        // Re-rendering is all it takes: links() reads the servers again.
    }

    /**
     * Sections and links, top to bottom. A link has `href`, `active`, `icon`
     * and `label`, plus an optional `title`, `dot` (a server's state) or `meta`.
     *
     * @return list<array<string, mixed>>
     */
    public function links(): array
    {
        $user = auth()->user();
        $fleet = Panel::fleetView();
        $sessionServerId = (int) session('cipi_gui_server_id');
        $onServerPage = $this->routeName === 'cipi-gui.server-manage';

        $links = [];
        if ($fleet) {
            $servers = Access::serversWithLevel($user);
            $links[] = ['section' => 'Fleet'];
            $links[] = $this->link('cipi-gui.dashboard', 'dashboard', 'Dashboard');
            $links[] = $this->link('cipi-gui.apps', 'apps', 'Apps', 'cipi-gui.apps*');
            $links[] = $this->link('cipi-gui.databases', 'database', 'Databases');
            if ($servers->isNotEmpty()) {
                $links[] = ['section' => $servers->count() === 1 ? 'Server' : 'Servers'];
                if (Panel::serverNav() === 'sidebar') {
                    foreach ($servers as $server) {
                        $links[] = [
                            'href' => route('cipi-gui.server-manage', ['serverId' => $server->id]),
                            'active' => $onServerPage && ($this->routeServerId === $server->id || ($this->routeServerId === 0 && $sessionServerId === $server->id)),
                            'icon' => 'server', 'label' => $server->name, 'title' => $server->host,
                            'dot' => $server->last_error ? 'dot-red' : 'dot-green',
                        ];
                    }
                } else {
                    $current = $servers->firstWhere('id', $sessionServerId) ?? $servers->first();
                    $links[] = ['href' => route('cipi-gui.server-manage', ['serverId' => $current->id]), 'active' => $onServerPage, 'icon' => 'server', 'label' => 'Server', 'meta' => $current->name];
                }
            }
        } else {
            $links[] = ['section' => 'Overview'];
            $links[] = $this->link('cipi-gui.dashboard', 'dashboard', 'Dashboard');
            $links[] = ['section' => 'Current server'];
            $links[] = $this->link('cipi-gui.apps', 'apps', 'Apps', 'cipi-gui.apps*');
            $links[] = $this->link('cipi-gui.databases', 'database', 'Databases');
            if (Access::serversWithLevel($user)->isNotEmpty()) {
                $links[] = $this->link('cipi-gui.server-manage', 'server', 'Server');
            }
        }

        $links[] = ['section' => 'Panel'];
        if (Access::isAdmin($user)) {
            $links[] = $this->link('cipi-gui.servers', 'servers', 'Connections');
            $links[] = $this->link('cipi-gui.team', 'user', 'Team');
        }
        $links[] = $this->link('cipi-gui.settings', 'settings', 'Settings');

        return $links;
    }

    /** @return array{href: string, active: bool, icon: string, label: string} */
    private function link(string $route, string $icon, string $label, ?string $match = null): array
    {
        return [
            'href' => route($route),
            'active' => Str::is($match ?? $route, $this->routeName),
            'icon' => $icon,
            'label' => $label,
        ];
    }

    public function render()
    {
        return view('cipi-gui::livewire.nav', ['links' => $this->links()]);
    }
}
