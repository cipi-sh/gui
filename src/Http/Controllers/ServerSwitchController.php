<?php

namespace CipiGui\Http\Controllers;

use CipiGui\Models\CipiServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Global "current server" switch used by the header dropdown.
 *
 * Pages scoped to a server (apps, databases, server) re-read the session, so
 * the user lands on the same page for the new server. Pages that only make
 * sense for the old server (an app detail, /server/{id}) fall back to their
 * listing so nobody is left looking at a 404.
 */
class ServerSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer'],
        ]);

        $server = CipiServer::query()
            ->where('is_active', true)
            ->findOrFail($validated['server_id']);

        $request->session()->put('cipi_gui_server_id', $server->id);
        $request->session()->flash('cipi_gui_flash', [
            'type' => 'info',
            'message' => "Switched to {$server->name}",
        ]);

        return redirect()->to($this->destination($request, $server));
    }

    private function destination(Request $request, CipiServer $server): string
    {
        $previous = url()->previous();

        try {
            $route = Route::getRoutes()->match(Request::create($previous));
        } catch (\Throwable) {
            return route('cipi-gui.dashboard');
        }

        return match ($route->getName()) {
            'cipi-gui.apps.show' => route('cipi-gui.apps'),
            'cipi-gui.server-manage' => route('cipi-gui.server-manage', ['serverId' => $server->id]),
            'cipi-gui.apps', 'cipi-gui.databases', 'cipi-gui.dashboard', 'cipi-gui.servers', 'cipi-gui.settings' => $previous,
            default => route('cipi-gui.dashboard'),
        };
    }
}
