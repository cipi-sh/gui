<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Services\CipiApiException;
use Livewire\Component;

class LogViewer extends Component
{
    use InteractsWithCipiServer;

    public string $appName;

    public string $logType = 'all';

    public int $page = 1;

    public int $perPage = 100;

    /** @var array<int, array> */
    public array $files = [];

    /** @var array<int, string> */
    public array $availableTypes = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public bool $loading = true;

    public bool $autoRefresh = false;

    public function mount(string $app, ?int $serverId = null): void
    {
        $this->appName = $app;
        $this->serverId = $serverId ?? session('cipi_gui_server_id');
        $this->loadLogs();
    }

    public function updatedLogType(): void
    {
        $this->page = 1;
        $this->loadLogs();
    }

    public function updatedAutoRefresh(): void
    {
        if ($this->autoRefresh) {
            $this->page = 1;
        }
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->loadLogs();
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->loadLogs();
        }
    }

    public function refresh(): void
    {
        $this->loadLogs();
    }

    public function polledRefresh(): void
    {
        if ($this->autoRefresh) {
            $this->loadLogs();
        }
    }

    public function loadLogs(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $data = $this->client()->appLogs($this->appName, [
                'type' => $this->logType,
                'page' => $this->page,
                'per_page' => $this->perPage,
            ]);

            $this->files = $data['files'] ?? [];
            $this->availableTypes = $data['available_types'] ?? [];
            $this->warnings = $data['warnings'] ?? [];
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
            $this->files = [];
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('cipi-gui::livewire.log-viewer');
    }
}
