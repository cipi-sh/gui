<?php

namespace CipiGui\Livewire;

use CipiGui\Services\Access;
use CipiGui\Support\Panel;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The bar at the top of every page: the server switcher (fleet view off) and
 * the user menu. A component of its own, outside the page, so that it follows
 * what it shows without a reload: connections added, edited, removed, enabled
 * or disabled on the Connections page, a test that changed a server's state
 * (the dot), and the name saved on Settings → Profile.
 */
class Header extends Component
{
    #[On('servers-changed')]
    #[On('profile-changed')]
    public function refresh(): void
    {
        // Re-rendering is all it takes: render() reads the servers and the user again.
    }

    public function render()
    {
        $user = auth()->user();
        $displayName = (string) ($user->name ?? $user->email ?? 'Admin');
        $initials = collect(preg_split('/\s+/', trim($displayName)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');

        $fleetView = Panel::fleetView();
        $switcherServers = $fleetView ? collect() : Access::visibleServers($user);
        $currentServerId = (int) session('cipi_gui_server_id');

        return view('cipi-gui::livewire.header', [
            'user' => $user,
            'isAdmin' => Access::isAdmin($user),
            'displayName' => $displayName,
            'initials' => $initials,
            'fleetView' => $fleetView,
            'switcherServers' => $switcherServers,
            'currentServer' => $switcherServers->firstWhere('id', $currentServerId) ?? $switcherServers->first(),
        ]);
    }
}
