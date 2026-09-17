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

    /** laravel | custom | node */
    public string $appKind = 'laravel';

    public string $docroot = '';

    public string $engine = '';

    public bool $octane = false;

    public string $nodeMode = 'spa';

    public string $nodeFramework = '';

    public string $nodeVersion = '';

    public string $nodeBuild = '';

    public string $nodeStart = '';

    public string $nodeOutput = '';

    public string $nodeHealthPath = '';

    /** @var list<array{major: string, version: ?string, default: bool, apps?: list<string>}> */
    public array $nodeRuntimes = [];

    public bool $nodeUnsupported = false;

    /** @var array<int, array{engine: string, status?: string, port?: int|null, default?: bool}> */
    public array $availableEngines = [];

    /** @var list<string> */
    public array $installedPhpVersions = [];

    public ?string $defaultPhpVersion = null;

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
        $this->reset([
            'user', 'domain', 'repository', 'branch', 'docroot', 'engine', 'error',
            'nodeMode', 'nodeFramework', 'nodeVersion', 'nodeBuild', 'nodeStart',
            'nodeOutput', 'nodeHealthPath',
        ]);
        $this->appKind = 'laravel';
        $this->custom = false;
        $this->octane = false;
        $this->nodeMode = 'spa';
        $this->loadAvailableEngines();
        $this->loadInstalledPhpVersions();
        $this->loadNodeRuntimes();
        $this->php = $this->defaultPhpForNewApp();
        $this->showCreateModal = true;
    }

    public function updatedAppKind(): void
    {
        $this->custom = $this->appKind === 'custom';

        if ($this->appKind !== 'laravel') {
            $this->engine = '';
            $this->octane = false;
        } elseif ($this->engine === '' && $this->availableEngines !== []) {
            $this->engine = $this->defaultEngine();
        }

        if ($this->appKind === 'node' && $this->nodeRuntimes === [] && ! $this->nodeUnsupported) {
            $this->loadNodeRuntimes();
        }
    }

    protected function loadInstalledPhpVersions(): void
    {
        $this->installedPhpVersions = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);
        $this->defaultPhpVersion = null;

        if (! $this->currentServer()) {
            return;
        }

        try {
            $data = $this->client()->listPhp();
            if (isset($data['default']) && is_string($data['default']) && $data['default'] !== '') {
                $this->defaultPhpVersion = $data['default'];
            }
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

    protected function defaultPhpForNewApp(): string
    {
        if ($this->defaultPhpVersion !== null
            && in_array($this->defaultPhpVersion, $this->installedPhpVersions, true)) {
            return $this->defaultPhpVersion;
        }

        return $this->installedPhpVersions[0] ?? '8.5';
    }

    public function updatedCustom(): void
    {
        $this->appKind = $this->custom ? 'custom' : 'laravel';
        $this->updatedAppKind();
    }

    protected function loadNodeRuntimes(): void
    {
        $this->nodeRuntimes = [];
        $this->nodeUnsupported = false;
        $this->nodeVersion = '';

        if (! $this->currentServer()) {
            return;
        }

        try {
            $this->nodeRuntimes = array_values(array_filter(
                $this->client()->listNodeRuntimes(),
                fn ($item) => is_array($item) && ! empty($item['major']),
            ));

            foreach ($this->nodeRuntimes as $runtime) {
                if (! empty($runtime['default'])) {
                    $this->nodeVersion = (string) $runtime['major'];
                    break;
                }
            }
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->nodeUnsupported = true;

                return;
            }
            $this->handleApiError($e);
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
                    && in_array($item['status'] ?? '', ['installed', 'running'], true),
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
        $isNode = $this->appKind === 'node';
        $isCustom = $this->appKind === 'custom';

        $rules = [
            'user' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
            'domain' => ['required', 'string', 'max:255'],
            'custom' => ['boolean'],
            'octane' => ['boolean'],
        ];

        if ($isNode) {
            $rules['repository'] = ['required', 'string'];
            $rules['branch'] = ['required', 'string', 'max:64'];
            $rules['nodeMode'] = ['required', 'in:spa,static,ssr'];
            $rules['nodeFramework'] = ['nullable', 'in:next,nuxt,sveltekit,astro,remix,vite'];
            $rules['nodeVersion'] = ['nullable', 'string', 'max:8'];
            $rules['nodeBuild'] = ['nullable', 'string', 'max:256'];
            $rules['nodeStart'] = ['nullable', 'string', 'max:256'];
            $rules['nodeOutput'] = ['nullable', 'string', 'max:128'];
            $rules['nodeHealthPath'] = ['nullable', 'string', 'max:128'];
        } else {
            $rules['php'] = ['required', 'regex:/^\d+\.\d+$/'];

            if (! $isCustom) {
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
        }

        $this->validate($rules);

        if ($isNode) {
            $payload = [
                'user' => $this->user,
                'domain' => $this->domain,
                'node' => $this->nodeMode,
                'repository' => $this->repository,
                'branch' => $this->branch ?: 'main',
            ];

            if ($this->nodeFramework !== '') {
                $payload['framework'] = $this->nodeFramework;
            }
            if (trim($this->nodeVersion) !== '') {
                $payload['node_version'] = trim($this->nodeVersion);
            }
            if (trim($this->nodeBuild) !== '') {
                $payload['build'] = trim($this->nodeBuild);
            }
            if (trim($this->nodeStart) !== '') {
                $payload['start'] = trim($this->nodeStart);
            }
            if (trim($this->nodeOutput) !== '') {
                $payload['output'] = trim($this->nodeOutput);
            }
            if (trim($this->nodeHealthPath) !== '') {
                $payload['health_path'] = trim($this->nodeHealthPath);
            }
        } else {
            $payload = [
                'user' => $this->user,
                'domain' => $this->domain,
                'php' => $this->php,
                'custom' => $isCustom,
            ];

            if ($this->repository) {
                $payload['repository'] = $this->repository;
                $payload['branch'] = $this->branch ?: 'main';
            }

            if ($isCustom && $this->docroot) {
                $payload['docroot'] = $this->docroot;
            }

            if (! $isCustom && $this->engine !== '') {
                $payload['engine'] = $this->engine;
            }

            if (! $isCustom && $this->octane) {
                $payload['octane'] = true;
            }
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
