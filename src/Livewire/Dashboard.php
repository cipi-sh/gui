<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public ?int $selectedServerId = null;

    /** @var array<int, array> */
    public array $serverStatuses = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->selectedServerId = session('cipi_gui_server_id');
        $this->loadStatuses();
    }

    public function selectServer(?int $id): void
    {
        $this->selectedServerId = $id;
        session(['cipi_gui_server_id' => $id]);
    }

    public function refresh(): void
    {
        $this->loadStatuses();
    }

    protected function loadStatuses(): void
    {
        $this->error = null;
        $this->serverStatuses = [];

        $servers = CipiServer::where('is_active', true)->orderBy('name')->get();

        foreach ($servers as $server) {
            try {
                $status = CipiApiClient::for($server)->getStatus();
                $this->syncIpFromStatus($server, $status);
                $this->serverStatuses[$server->id] = [
                    'server' => $server->fresh(),
                    'status' => $status,
                    'error' => null,
                ];
            } catch (CipiApiException $e) {
                $this->serverStatuses[$server->id] = [
                    'server' => $server,
                    'status' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    /** @param  array<string, mixed>  $status */
    protected function syncIpFromStatus(CipiServer $server, array $status): void
    {
        $ip = $status['system']['ip'] ?? $status['system']['ipv4'] ?? null;

        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        if ($server->ip !== $ip) {
            $server->update(['ip' => $ip]);
        }
    }

    public function render()
    {
        $servers = CipiServer::orderBy('name')->get();

        return view('cipi-gui::livewire.dashboard', [
            'servers' => $servers,
        ]);
    }
}
