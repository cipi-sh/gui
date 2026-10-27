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

        $server = \CipiGui\Services\Access::visibleServers($request->user())->firstWhere('id', (int) $validated['server_id']);
        abort_unless($server, 404);

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
            'cipi-gui.apps.show' => route('cipi-gui.apps', ['server' => $server->id]),
            'cipi-gui.server-manage' => route('cipi-gui.server-manage', ['serverId' => $server->id]),
            // The apps list is fleet-wide; when it was narrowed to the old server, narrow it to the new one.
            'cipi-gui.apps' => $this->withServerParam($previous, $server),
            'cipi-gui.databases', 'cipi-gui.dashboard', 'cipi-gui.servers', 'cipi-gui.settings' => $previous,
            default => route('cipi-gui.dashboard'),
        };
    }

    private function withServerParam(string $url, CipiServer $server): string
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (! array_key_exists('server', $query)) {
            return $url;
        }

        $query['server'] = $server->id;

        return strtok($url, '?').'?'.http_build_query($query);
    }
}
