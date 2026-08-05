<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Apps')]
class Apps extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /** @var array<int, array> */
    public array $apps = [];

    public bool $loading = true;

    public bool $showCreateModal = false;

    public bool $showDeleteModal = false;

    public string $deleteAppName = '';

    public string $user = '';

    public string $domain = '';

    public string $repository = '';

    public string $branch = 'main';

    public string $php = '8.5';

    public bool $custom = false;

    public string $docroot = '';

    public string $engine = '';

    public bool $octane = false;

    /** @var array<int, array{engine: string, status?: string, port?: int|null, default?: bool}> */
    public array $availableEngines = [];

    /** @var list<string> */
    public array $installedPhpVersions = [];

    public function mount(): void
    {
        $this->ensureServerSelected();
        $this->loadApps();
    }

    public function updatedServerId(): void
    {
        session(['cipi_gui_server_id' => $this->serverId]);
        $this->loadApps();
    }

    public function loadApps(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->apps = [];

        $server = $this->currentServer();
        if (! $server) {
            $this->loading = false;

            return;
        }

        try {
            $this->apps = array_map(
                fn (array $app) => $this->normalizeApp($app),
                $this->client()->listApps(),
            );
            $this->apps = $this->applySessionAppPatches($this->apps);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    public function openCreate(): void
    {
        $this->reset(['user', 'domain', 'repository', 'branch', 'docroot', 'engine', 'error']);
        $this->custom = false;
        $this->octane = false;
        $this->loadAvailableEngines();
        $this->loadInstalledPhpVersions();
        $this->php = in_array('8.5', $this->installedPhpVersions, true)
            ? '8.5'
            : ($this->installedPhpVersions[0] ?? '8.5');
        $this->showCreateModal = true;
    }

    protected function loadInstalledPhpVersions(): void
    {
        $this->installedPhpVersions = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);

        if (! $this->currentServer()) {
            return;
        }

        try {
            $data = $this->client()->listPhp();
            $versions = [];
            foreach ($data['versions'] ?? [] as $row) {
                if (is_array($row) && ! empty($row['version'])) {
                    $versions[] = (string) $row['version'];
                }
            }
            if ($versions !== []) {
                $this->installedPhpVersions = $versions;
            }
        } catch (CipiApiException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 501], true)) {
                // Keep config fallback; don't block create modal.
            }
        }
    }

    public function updatedCustom(): void
    {
        if ($this->custom) {
            $this->engine = '';
            $this->octane = false;
        } elseif ($this->engine === '' && $this->availableEngines !== []) {
            $this->engine = $this->defaultEngine();
        }
    }

    protected function loadAvailableEngines(): void
    {
        $this->availableEngines = [];
        $this->engine = '';

        if (! $this->currentServer()) {
            return;
        }

        try {
            $data = $this->client()->listDatabaseEngines();
            $engines = $data['engines'] ?? [];
            if (! is_array($engines)) {
                return;
            }

            $this->availableEngines = array_values(array_filter(
                $engines,
                fn ($item) => is_array($item)
                    && is_string($item['engine'] ?? null)
                    && ($item['status'] ?? 'installed') === 'installed',
            ));

            $this->engine = $this->defaultEngine();
        } catch (CipiApiException $e) {
            // Older API without /dbs/engines — omit engine selector.
            if (! in_array($e->getStatusCode(), [404, 403, 501], true)) {
                $this->handleApiError($e);
            }
            $this->availableEngines = [];
        }
    }

    protected function defaultEngine(): string
    {
        foreach ($this->availableEngines as $item) {
            if (! empty($item['default'])) {
                return (string) $item['engine'];
            }
        }

        return isset($this->availableEngines[0]['engine'])
            ? (string) $this->availableEngines[0]['engine']
            : '';
    }

    public function createApp(): void
    {
        $rules = [
            'user' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
            'domain' => ['required', 'string', 'max:255'],
            'php' => ['required', 'regex:/^\d+\.\d+$/'],
            'custom' => ['boolean'],
            'octane' => ['boolean'],
        ];

        if (! $this->custom) {
            $rules['repository'] = ['required', 'string'];
            $rules['branch'] = ['required', 'string', 'max:64'];
            if ($this->availableEngines !== []) {
                $allowed = implode(',', array_column($this->availableEngines, 'engine'));
                $rules['engine'] = ['required', 'in:'.$allowed];
            }
        } else {
            $rules['repository'] = ['nullable', 'string'];
            $rules['branch'] = ['nullable', 'string', 'max:64'];
            $rules['docroot'] = ['nullable', 'string', 'max:64'];
        }

        $this->validate($rules);

        $payload = [
            'user' => $this->user,
            'domain' => $this->domain,
            'php' => $this->php,
            'custom' => $this->custom,
        ];

        if ($this->repository) {
            $payload['repository'] = $this->repository;
            $payload['branch'] = $this->branch ?: 'main';
        }

        if ($this->custom && $this->docroot) {
            $payload['docroot'] = $this->docroot;
        }

        if (! $this->custom && $this->engine !== '') {
            $payload['engine'] = $this->engine;
        }

        if (! $this->custom && $this->octane) {
            $payload['octane'] = true;
        }

        try {
            $response = $this->client()->createApp($payload);
            $this->showCreateModal = false;
            $this->dispatchJob($response, 'App creation');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function confirmDeleteApp(string $name): void
    {
        $this->deleteAppName = $name;
        $this->showDeleteModal = true;
    }

    public function cancelDeleteApp(): void
    {
        $this->showDeleteModal = false;
        $this->deleteAppName = '';
    }

    public function deleteApp(): void
    {
        if ($this->deleteAppName === '') {
            return;
        }

        $name = $this->deleteAppName;

        try {
            $response = $this->client()->deleteApp($name);
            $this->showDeleteModal = false;
            $this->deleteAppName = '';
            $this->dispatchJob($response, "Delete app {$name}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        $this->loadApps();
    }

    /** @param  array<string, mixed>  $patch */
    #[On('app-changed')]
    public function onAppChanged(string $name, array $patch): void
    {
        foreach ($this->apps as $index => $app) {
            if (($app['app'] ?? '') !== $name) {
                continue;
            }

            $this->apps[$index] = array_merge($app, $patch);

            return;
        }
    }

    public function render()
    {
        return view('cipi-gui::livewire.apps', [
            'servers' => CipiServer::where('is_active', true)->orderBy('name')->get(),
            'phpVersions' => $this->installedPhpVersions !== []
                ? $this->installedPhpVersions
                : config('cipi-gui.php_versions'),
        ]);
    }
}
