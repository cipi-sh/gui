<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Apps')]
class Apps extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /** Framework presets — same defaults as `cipi app create --node --framework=…` (lib/node.sh). */
    public const NODE_PRESETS = [
        'next' => ['label' => 'Next.js', 'mode' => 'ssr', 'build' => 'npm run build', 'start' => 'npx next start -H 127.0.0.1', 'output' => ''],
        'nuxt' => ['label' => 'Nuxt', 'mode' => 'ssr', 'build' => 'npm run build', 'start' => 'node .output/server/index.mjs', 'output' => ''],
        'sveltekit' => ['label' => 'SvelteKit', 'mode' => 'ssr', 'build' => 'npm run build', 'start' => 'node build', 'output' => ''],
        'astro' => ['label' => 'Astro', 'mode' => 'ssr', 'build' => 'npm run build', 'start' => 'node ./dist/server/entry.mjs', 'output' => ''],
        'remix' => ['label' => 'Remix', 'mode' => 'ssr', 'build' => 'npm run build', 'start' => 'npm run start', 'output' => ''],
        'vite' => ['label' => 'Vite', 'mode' => 'spa', 'build' => 'npm run build', 'start' => '', 'output' => 'dist'],
    ];

    /** @var array<int, array> */
    public array $apps = [];

    public bool $loading = true;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: 'all')]
    public string $kindFilter = 'all';

    public bool $showCreateModal = false;

    public string $deleteAppName = '';

    public string $deleteConfirmation = '';

    // Create form
    public string $user = '';

    public string $domain = '';

    public string $repository = '';

    public string $branch = 'main';

    public string $php = '8.5';

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

    public function loadApps(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->apps = [];

        if (! $this->currentServer()) {
            $this->loading = false;

            return;
        }

        try {
            $this->apps = array_map(
                fn (array $app) => $this->normalizeApp($app),
                $this->client()->listApps(),
            );
            usort($this->apps, fn ($a, $b) => strcmp($a['app'] ?? '', $b['app'] ?? ''));
            $this->apps = $this->applySessionAppPatches($this->apps);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    public function setKindFilter(string $kind): void
    {
        $this->kindFilter = in_array($kind, ['all', 'laravel', 'node', 'custom'], true) ? $kind : 'all';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->kindFilter = 'all';
    }

    // ── Create ────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->reset([
            'user', 'domain', 'repository', 'branch', 'docroot', 'engine', 'error',
            'nodeMode', 'nodeFramework', 'nodeVersion', 'nodeBuild', 'nodeStart',
            'nodeOutput', 'nodeHealthPath', 'octane',
        ]);
        $this->resetErrorBag();
        $this->appKind = 'laravel';
        $this->loadAvailableEngines();
        $this->loadInstalledPhpVersions();
        $this->loadNodeRuntimes();
        $this->php = $this->defaultPhpForNewApp();
        $this->showCreateModal = true;
    }

    public function closeCreate(): void
    {
        $this->showCreateModal = false;
        $this->resetErrorBag();
    }

    public function setAppKind(string $kind): void
    {
        $this->appKind = in_array($kind, ['laravel', 'node', 'custom'], true) ? $kind : 'laravel';
        $this->resetErrorBag();

        if ($this->appKind !== 'laravel') {
            $this->octane = false;
        } elseif ($this->engine === '' && $this->availableEngines !== []) {
            $this->engine = $this->defaultEngine();
        }

        if ($this->appKind === 'node' && $this->nodeRuntimes === [] && ! $this->nodeUnsupported) {
            $this->loadNodeRuntimes();
        }
    }

    public function updatedNodeFramework(string $value): void
    {
        if (isset(self::NODE_PRESETS[$value])) {
            $this->nodeMode = self::NODE_PRESETS[$value]['mode'];
        }
    }

    /** Placeholder values shown in the Node fields (what Cipi applies when left empty). */
    public function nodePreset(): array
    {
        return self::NODE_PRESETS[$this->nodeFramework] ?? [
            'mode' => $this->nodeMode,
            'build' => 'npm run build',
            'start' => 'npm run start',
            'output' => 'dist',
        ];
    }

    protected function loadInstalledPhpVersions(): void
    {
        $this->installedPhpVersions = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);
        $this->defaultPhpVersion = null;

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
        } catch (CipiApiException) {
            // Older API or missing php-view: keep the config hints.
        }
    }

    protected function defaultPhpForNewApp(): string
    {
        if ($this->defaultPhpVersion !== null && in_array($this->defaultPhpVersion, $this->installedPhpVersions, true)) {
            return $this->defaultPhpVersion;
        }

        return end($this->installedPhpVersions) ?: '8.5';
    }

    protected function loadNodeRuntimes(): void
    {
        $this->nodeRuntimes = [];
        $this->nodeUnsupported = false;
        $this->nodeVersion = '';

        try {
            $this->nodeRuntimes = array_values(array_filter(
                $this->client()->listNodeRuntimes(),
                fn ($item) => is_array($item) && ! empty($item['major']),
            ));
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e)) {
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

        try {
            $data = $this->client()->listDatabaseEngines();
            $engines = is_array($data['engines'] ?? null) ? $data['engines'] : [];

            $this->availableEngines = array_values(array_filter(
                $engines,
                fn ($item) => is_array($item)
                    && is_string($item['engine'] ?? null)
                    && in_array($item['status'] ?? '', ['installed', 'running'], true),
            ));

            $this->engine = $this->defaultEngine();
        } catch (CipiApiException $e) {
            // Older API without /dbs/engines — omit the engine selector.
            if (! $this->isUnsupported($e)) {
                $this->handleApiError($e);
            }
        }
    }

    protected function defaultEngine(): string
    {
        foreach ($this->availableEngines as $item) {
            if (! empty($item['default'])) {
                return (string) $item['engine'];
            }
        }

        return (string) ($this->availableEngines[0]['engine'] ?? '');
    }

    public function createApp(): void
    {
        $isNode = $this->appKind === 'node';
        $isCustom = $this->appKind === 'custom';
        $reserved = implode(',', (array) config('cipi-gui.reserved_usernames', []));

        $rules = [
            'user' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/', 'not_in:'.$reserved],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'octane' => ['boolean'],
        ];

        if ($isNode) {
            $rules += [
                'repository' => ['required', 'string', 'max:512'],
                'branch' => ['required', 'string', 'max:64'],
                'nodeMode' => ['required', 'in:spa,static,ssr'],
                'nodeFramework' => ['nullable', 'in:'.implode(',', array_keys(self::NODE_PRESETS))],
                'nodeVersion' => ['nullable', 'string', 'max:8'],
                'nodeBuild' => ['nullable', 'string', 'max:256'],
                'nodeStart' => ['nullable', 'string', 'max:256'],
                'nodeOutput' => ['nullable', 'string', 'max:128'],
                'nodeHealthPath' => ['nullable', 'string', 'max:128', 'regex:/^\//'],
            ];
        } else {
            $rules['php'] = ['required', 'regex:/^\d+\.\d+$/'];

            if (! $isCustom) {
                $rules['repository'] = ['required', 'string', 'max:512'];
                $rules['branch'] = ['required', 'string', 'max:64'];
                if ($this->availableEngines !== []) {
                    $rules['engine'] = ['required', 'in:'.implode(',', array_column($this->availableEngines, 'engine'))];
                }
            } else {
                $rules['repository'] = ['nullable', 'string', 'max:512'];
                $rules['branch'] = ['nullable', 'string', 'max:64'];
                $rules['docroot'] = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]*$/'];
            }
        }

        $this->validate($rules, [
            'user.regex' => 'Use 3–32 lowercase letters or digits, starting with a letter.',
            'user.not_in' => 'This name is reserved on Cipi servers.',
            'domain.regex' => 'Enter a hostname such as app.example.com or *.example.com.',
            'nodeHealthPath.regex' => 'The health path starts with /.',
        ]);

        $payload = ['user' => $this->user, 'domain' => strtolower($this->domain)];

        if ($isNode) {
            $payload += ['node' => $this->nodeMode, 'repository' => $this->repository, 'branch' => $this->branch ?: 'main'];
            foreach ([
                'framework' => $this->nodeFramework,
                'node_version' => $this->nodeVersion,
                'build' => $this->nodeBuild,
                'start' => $this->nodeMode === 'ssr' ? $this->nodeStart : '',
                'output' => $this->nodeMode !== 'ssr' ? $this->nodeOutput : '',
                'health_path' => $this->nodeMode === 'ssr' ? $this->nodeHealthPath : '',
            ] as $key => $value) {
                if (trim($value) !== '') {
                    $payload[$key] = trim($value);
                }
            }
        } else {
            $payload += ['php' => $this->php, 'custom' => $isCustom];

            if ($this->repository !== '') {
                $payload['repository'] = $this->repository;
                $payload['branch'] = $this->branch ?: 'main';
            }
            if ($isCustom && $this->docroot !== '') {
                $payload['docroot'] = $this->docroot;
            }
            if (! $isCustom && $this->engine !== '') {
                $payload['engine'] = $this->engine;
            }
            if (! $isCustom && $this->octane) {
                $payload['octane'] = true;
            }
        }

        if ($this->startJob("Create app {$this->user}", fn (CipiApiClient $api) => $api->createApp($payload))) {
            $this->showCreateModal = false;
        }
    }

    // ── Row actions ───────────────────────────────────────────────────

    public function deploy(string $name): void
    {
        $this->startJob("Deploy {$name}", fn (CipiApiClient $api) => $api->deploy($name));
    }

    public function toggleSuspend(string $name, bool $suspended): void
    {
        $this->startJob(
            ($suspended ? 'Unsuspend ' : 'Suspend ').$name,
            fn (CipiApiClient $api) => $suspended ? $api->unsuspendApp($name) : $api->suspendApp($name),
        );
    }

    public function confirmDeleteApp(string $name): void
    {
        $this->deleteAppName = $name;
        $this->deleteConfirmation = '';
        $this->resetErrorBag();
    }

    public function cancelDeleteApp(): void
    {
        $this->deleteAppName = '';
        $this->deleteConfirmation = '';
    }

    public function deleteApp(): void
    {
        $name = $this->deleteAppName;
        if ($name === '') {
            return;
        }

        if ($this->deleteConfirmation !== $name) {
            $this->addError('deleteConfirmation', 'Type the app name to confirm.');

            return;
        }

        if ($this->startJob("Delete app {$name}", fn (CipiApiClient $api) => $api->deleteApp($name))) {
            $this->cancelDeleteApp();
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
            if (($app['app'] ?? '') === $name) {
                $this->apps[$index] = array_merge($app, $patch);

                return;
            }
        }
    }

    public function render()
    {
        $counts = ['all' => count($this->apps), 'laravel' => 0, 'node' => 0, 'custom' => 0];
        foreach ($this->apps as $app) {
            $counts[$this->appKind($app)]++;
        }

        $needle = mb_strtolower(trim($this->search));
        $visible = array_values(array_filter($this->apps, function (array $app) use ($needle): bool {
            if ($this->kindFilter !== 'all' && $this->appKind($app) !== $this->kindFilter) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [$app['app'] ?? '', $app['domain'] ?? '', implode(' ', $app['aliases'] ?? []), $app['repository'] ?? '']));

            return str_contains($haystack, $needle);
        }));

        $server = $this->currentServer();

        return view('cipi-gui::livewire.apps', [
            'server' => $server,
            'visibleApps' => $visible,
            'counts' => $counts,
            'phpVersions' => $this->installedPhpVersions !== [] ? $this->installedPhpVersions : config('cipi-gui.php_versions'),
            'presets' => self::NODE_PRESETS,
        ])->title($server ? 'Apps · '.$server->name : 'Apps');
    }
}
