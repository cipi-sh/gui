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

    /** @var array<int, array{engine: string, status?: string, port?: int|null, default?: bool}> */
    public array $availableEngines = [];

    public ?string $defaultEngine = null;

    public bool $enginesUnsupported = false;

    public bool $loading = true;

    public bool $showCreateModal = false;

    public string $dbName = '';

    public string $dbEngine = '';

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
        $this->availableEngines = [];
        $this->defaultEngine = null;
        $this->enginesUnsupported = false;

        if (! $this->currentServer()) {
            $this->loading = false;

            return;
        }

        try {
            $this->loadAvailableEngines();
            $this->databases = $this->client()->listDatabases();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    protected function loadAvailableEngines(): void
    {
        try {
            $data = $this->client()->listDatabaseEngines();
            $engines = $data['engines'] ?? [];
            if (! is_array($engines)) {
                $this->enginesUnsupported = true;

                return;
            }

            $this->availableEngines = array_values(array_filter(
                $engines,
                fn ($item) => is_array($item)
                    && is_string($item['engine'] ?? null)
                    && in_array($item['status'] ?? '', ['installed', 'running'], true),
            ));

            $default = $data['default'] ?? null;
            if (is_string($default) && $default !== '') {
                $this->defaultEngine = $default;
            } else {
                foreach ($this->availableEngines as $item) {
                    if (! empty($item['default'])) {
                        $this->defaultEngine = (string) $item['engine'];
                        break;
                    }
                }
            }

            if ($this->defaultEngine === null && isset($this->availableEngines[0]['engine'])) {
                $this->defaultEngine = (string) $this->availableEngines[0]['engine'];
            }
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [404, 403, 501], true)) {
                $this->enginesUnsupported = true;
                $this->availableEngines = [];

                return;
            }

            throw $e;
        }
    }

    public function openCreate(): void
    {
        $this->reset(['dbName', 'error']);
        $this->dbEngine = $this->defaultEngine ?? '';
        $this->showCreateModal = true;
    }

    public function createDatabase(): void
    {
        $rules = [
            'dbName' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
        ];

        if ($this->availableEngines !== []) {
            $allowed = implode(',', array_column($this->availableEngines, 'engine'));
            $rules['dbEngine'] = ['required', 'in:'.$allowed];
        }

        $this->validate($rules);

        try {
            $engine = $this->dbEngine !== '' ? $this->dbEngine : null;
            $response = $this->client()->createDatabase($this->dbName, $engine);
            $this->showCreateModal = false;
            $this->dispatchJob($response, 'Database creation');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function regeneratePassword(string $name, string $engine = ''): void
    {
        try {
            $response = $this->client()->regenerateDbPassword(
                $name,
                $engine !== '' ? $engine : null,
            );
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
