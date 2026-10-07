<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Databases')]
class Databases extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /** @var array<int, array{name: string, size: ?string, engine: ?string, user: ?string}> */
    public array $databases = [];

    /** @var array<int, array{engine: string, status?: string, port?: int|null, default?: bool}> */
    public array $engines = [];

    public ?string $defaultEngine = null;

    public bool $enginesUnsupported = false;

    public bool $loading = true;

    #[Url(as: 'engine', except: 'all')]
    public string $engineFilter = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showCreateModal = false;

    public string $dbName = '';

    public string $dbEngine = '';

    // Restore
    public ?string $restoreName = null;

    public ?string $restoreEngine = null;

    public string $restoreFile = '';

    public string $restoreConfirmation = '';

    /** Pending job context so the backup file can be remembered for restore. */
    public ?string $pendingBackup = null;

    public function mount(): void
    {
        $this->ensureServerSelected();
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
            $this->loadEngines();
            $this->databases = $this->client()->listDatabases();
            usort($this->databases, fn ($a, $b) => strcmp($a['name'], $b['name']));
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    protected function loadEngines(): void
    {
        $this->engines = [];
        $this->defaultEngine = null;
        $this->enginesUnsupported = false;

        try {
            $data = $this->client()->listDatabaseEngines();
            $engines = is_array($data['engines'] ?? null) ? $data['engines'] : [];

            $this->engines = array_values(array_filter(
                $engines,
                fn ($item) => is_array($item) && is_string($item['engine'] ?? null) && in_array($item['status'] ?? '', ['installed', 'running'], true),
            ));

            $default = $data['default'] ?? null;
            $this->defaultEngine = is_string($default) && $default !== '' ? $default : null;
            foreach ($this->engines as $item) {
                if ($this->defaultEngine === null && ! empty($item['default'])) {
                    $this->defaultEngine = (string) $item['engine'];
                }
            }
            $this->defaultEngine ??= $this->engines[0]['engine'] ?? null;
        } catch (CipiApiException $e) {
            if (! $this->isUnsupported($e)) {
                throw $e;
            }
            $this->enginesUnsupported = true;
        }
    }

    public function setEngineFilter(string $engine): void
    {
        $this->engineFilter = $engine;
    }

    public function openCreate(): void
    {
        $this->reset(['dbName']);
        $this->resetErrorBag();
        $this->dbEngine = $this->defaultEngine ?? '';
        $this->showCreateModal = true;
    }

    public function closeCreate(): void
    {
        $this->showCreateModal = false;
    }

    public function createDatabase(): void
    {
        $rules = ['dbName' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/']];

        if ($this->engines !== []) {
            $rules['dbEngine'] = ['required', 'in:'.implode(',', array_column($this->engines, 'engine'))];
        }

        $this->validate($rules, ['dbName.regex' => 'Use 3–32 lowercase letters or digits, starting with a letter (same rule as app names).']);

        $name = $this->dbName;
        $engine = $this->dbEngine !== '' ? $this->dbEngine : null;

        if ($this->startJob("Create database {$name}", fn (CipiApiClient $api) => $api->createDatabase($name, $engine))) {
            $this->showCreateModal = false;
        }
    }

    public function backup(string $name, string $engine = ''): void
    {
        $this->pendingBackup = $name;
        $this->startJob("Back up {$name}", fn (CipiApiClient $api) => $api->backupDatabase($name, $engine !== '' ? $engine : null));
    }

    public function openRestore(string $name, string $engine = ''): void
    {
        $this->resetErrorBag();
        $this->restoreName = $name;
        $this->restoreEngine = $engine !== '' ? $engine : null;
        $this->restoreFile = $this->rememberedBackups()[$name] ?? '/home/cipi/backups/';
        $this->restoreConfirmation = '';
    }

    public function closeRestore(): void
    {
        $this->restoreName = null;
    }

    public function restore(): void
    {
        $this->validate([
            'restoreFile' => ['required', 'string', 'max:512', 'regex:#^/[\w./-]+\.sql\.gz$#'],
            'restoreConfirmation' => ['required', 'in:'.$this->restoreName],
        ], [
            'restoreFile.regex' => 'Use an absolute path to a .sql.gz dump on the server.',
            'restoreConfirmation.in' => 'Type the database name to confirm.',
        ]);

        $name = (string) $this->restoreName;
        $file = $this->restoreFile;
        $engine = $this->restoreEngine;

        if ($this->startJob("Restore {$name}", fn (CipiApiClient $api) => $api->restoreDatabase($name, $file, $engine))) {
            $this->restoreName = null;
        }
    }

    public function regeneratePassword(string $name, string $engine = ''): void
    {
        $this->startJob("New password for {$name}", fn (CipiApiClient $api) => $api->regenerateDbPassword($name, $engine !== '' ? $engine : null));
    }

    /** @return array<string, string> last backup file per database on this server (this session) */
    protected function rememberedBackups(): array
    {
        return (array) (session('cipi_gui_db_backups', [])[$this->serverId] ?? []);
    }

    protected function onJobCompleted(array $data): void
    {
        $file = $data['result']['file'] ?? null;
        if ($this->pendingBackup && is_string($file) && $file !== '') {
            $all = session('cipi_gui_db_backups', []);
            $all[$this->serverId][$this->pendingBackup] = $file;
            session(['cipi_gui_db_backups' => $all]);
        }
        $this->pendingBackup = null;

        $this->loadDatabases();
    }

    protected function onJobFailed(array $data): void
    {
        $this->pendingBackup = null;
    }

    public function render()
    {
        $counts = ['all' => count($this->databases)];
        foreach ($this->databases as $db) {
            $engine = $db['engine'] ?? 'mariadb';
            $counts[$engine] = ($counts[$engine] ?? 0) + 1;
        }

        $needle = mb_strtolower(trim($this->search));
        $visible = array_values(array_filter($this->databases, fn (array $db) => ($this->engineFilter === 'all' || ($db['engine'] ?? 'mariadb') === $this->engineFilter)
            && ($needle === '' || str_contains(mb_strtolower($db['name'].' '.($db['user'] ?? '')), $needle))));

        $server = $this->currentServer();

        return view('cipi-gui::livewire.databases', [
            'server' => $server,
            'visible' => $visible,
            'counts' => $counts,
            'backups' => $this->rememberedBackups(),
        ])->title($server ? 'Databases · '.$server->name : 'Databases');
    }
}
