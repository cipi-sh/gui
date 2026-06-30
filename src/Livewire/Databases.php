<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Databases')]
class Databases extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /** @var array<int, array> */
    public array $databases = [];

    public bool $loading = true;

    public bool $showCreateModal = false;

    public string $dbName = '';

    public bool $showRestoreModal = false;

    public string $restoreDbName = '';

    public string $restoreFile = '';

    public ?array $lastCredentials = null;

    public function mount(): void
    {
        $this->ensureServerSelected();
        $this->loadDatabases();
    }

    public function updatedServerId(): void
    {
        session(['cipi_gui_server_id' => $this->serverId]);
        $this->loadDatabases();
    }

    public function loadDatabases(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->databases = [];

        if (! $this->currentServer()) {
            $this->loading = false;

            return;
        }

        try {
            $this->databases = $this->client()->listDatabases();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    public function openCreate(): void
    {
        $this->reset(['dbName', 'error']);
        $this->showCreateModal = true;
    }

    public function createDatabase(): void
    {
        $this->validate([
            'dbName' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
        ]);

        try {
            $response = $this->client()->createDatabase($this->dbName);
            $this->showCreateModal = false;
            $this->dispatchJob($response, 'Database creation');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteDatabase(string $name): void
    {
        try {
            $response = $this->client()->deleteDatabase($name);
            $this->dispatchJob($response, "Delete database {$name}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function backupDatabase(string $name): void
    {
        try {
            $response = $this->client()->backupDatabase($name);
            $this->dispatchJob($response, "Backup {$name}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function openRestore(string $name): void
    {
        $this->restoreDbName = $name;
        $this->restoreFile = '';
        $this->showRestoreModal = true;
    }

    public function restoreDatabase(): void
    {
        $this->validate([
            'restoreFile' => ['required', 'string', 'max:512'],
        ]);

        try {
            $response = $this->client()->restoreDatabase($this->restoreDbName, $this->restoreFile);
            $this->showRestoreModal = false;
            $this->dispatchJob($response, "Restore {$this->restoreDbName}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function regeneratePassword(string $name): void
    {
        try {
            $response = $this->client()->regenerateDbPassword($name);
            $this->dispatchJob($response, "Regenerate password for {$name}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        if (isset($data['result']['password'])) {
            $this->lastCredentials = $data['result'];
        }
        $this->loadDatabases();
    }

    public function render()
    {
        return view('cipi-gui::livewire.databases', [
            'servers' => CipiServer::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
