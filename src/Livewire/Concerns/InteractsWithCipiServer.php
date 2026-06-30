<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;

trait InteractsWithCipiServer
{
    public ?int $serverId = null;

    public ?string $error = null;

    protected function currentServer(): ?CipiServer
    {
        $id = $this->serverId ?? session('cipi_gui_server_id');

        if (! $id) {
            return null;
        }

        return CipiServer::where('is_active', true)->find($id);
    }

    protected function client(): CipiApiClient
    {
        $server = $this->currentServer();

        if (! $server) {
            throw new CipiApiException('No server selected. Add a server first.', 400);
        }

        return CipiApiClient::for($server);
    }

    protected function handleApiError(CipiApiException $e): void
    {
        $this->error = $e->getMessage();
        $this->dispatch('notify', type: 'error', message: $e->getMessage());
    }

    protected function ensureServerSelected(): void
    {
        if ($this->serverId) {
            return;
        }

        $fromSession = session('cipi_gui_server_id');
        if ($fromSession && CipiServer::where('is_active', true)->where('id', $fromSession)->exists()) {
            $this->serverId = (int) $fromSession;

            return;
        }

        $first = CipiServer::where('is_active', true)->orderBy('name')->first();
        if ($first) {
            $this->serverId = $first->id;
            session(['cipi_gui_server_id' => $first->id]);
        }
    }
}
