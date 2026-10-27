<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\AppEnv;
use CipiGui\Support\BuildCommand;
use CipiGui\Support\Panel;
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

    /** @var array<int, array> every app of every server that answered, each with `server_id` and `server_name` */
    public array $apps = [];

    /** @var array<int, string> server id → why its apps could not be loaded */
    public array $serverErrors = [];

    public bool $loading = true;

    /** Server whose apps are shown — 0 for every connected server (`?server=` in the URL). */
    #[Url(as: 'server', except: 0)]
    public int $scopeServerId = 0;

    /** Settings → Panel: every server in one list (fleet view) or the current server only. */
    public bool $fleet = true;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: 'all')]
    public string $kindFilter = 'all';

    public bool $showCreateModal = false;

    /** Server the create form targets (its engines, PHP versions and Node runtimes). */
    public int $createServerId = 0;

    /** The create form was closed with something typed in it: reopening restores it. */
    public bool $createDraft = false;

    public string $deleteAppName = '';

    public string $deleteServerName = '';

    public string $deleteConfirmation = '';

    // Create form
    public string $user = '';

    public string $domain = '';

    public string $repository = '';

    public string $branch = 'main';

    /** Environment label (`env`, API 1.36+): production, staging, … — free text within AppEnv::PATTERN. */
    public string $env = AppEnv::DEFAULT;

    public string $php = '8.5';

    /** laravel | custom | node */
    public string $appKind = 'laravel';

    public string $docroot = '';

    public string $engine = '';

    public bool $octane = false;

    /** Laravel only: asset build run inside every deploy (`node_build`, API 1.34+ / Cipi 5.6.0+). */
    public string $assetBuild = '';

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
        // ?server= also selects that server for the rest of the panel (shareable links).
        $this->ensureServerSelected();
        $this->fleet = Panel::fleetView();

        if (! $this->fleet) {
            $this->scopeServerId = (int) ($this->serverId ?? 0);
        }
    }

    /** Called by wire:init so the page paints before the servers answer. */
    public function loadApps(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->apps = [];
        $this->serverErrors = [];

        $servers = $this->fleet ? $this->activeServers() : collect(array_filter([$this->currentServer()]));

        if ($servers->isEmpty()) {
            $this->loading = false;

            return;
        }

        $user = $this->panelUser();
        $results = CipiApiClient::appsForMany($servers);

        foreach ($servers as $server) {
            $result = $results[$server->id] ?? ['apps' => null, 'error' => 'No response', 'status' => 503];

            if ($result['apps'] === null) {
                $this->serverErrors[$server->id] = $this->friendlyApiError(
                    new CipiApiException((string) ($result['error'] ?: 'No response'), (int) ($result['status'] ?? 500)),
                );

                continue;
            }

            $seesAll = Access::seesAllAppsOn($user, $server->id);
            foreach ($result['apps'] as $app) {
                if (! is_array($app) || ! isset($app['app'])) {
                    continue;
                }
                if (! $seesAll && ! Access::can($user, 'view', $server->id, (string) $app['app'])) {
                    continue; // not one of this user's apps
                }
                $app = $this->normalizeApp($app);
                $app['server_id'] = $server->id;
                $app['server_name'] = $server->name;
                $app['can'] = Access::appAbilityMap($user, $server->id, (string) $app['app']);
                $this->apps[] = $app;
            }
        }

        usort($this->apps, fn (array $a, array $b) => strcmp($a['app'], $b['app']) ?: strcmp($a['server_name'], $b['server_name']));
        $this->apps = $this->applySessionAppPatches($this->apps);
        $this->loading = false;
    }

    /** Show one server's apps (0 = every server). A filter on the loaded list, kept in the URL. */
    public function setScope(int $serverId): void
    {
        if ($this->fleet) {
            $this->scopeServerId = $serverId;
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
        if ($this->fleet) {
            $this->scopeServerId = 0;
        }
    }

    // ── Create ────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetErrorBag();
        $this->error = null;

        if (! $this->createDraft) {
            $this->resetCreateFields();
        }

        // A kept draft stays on the server it was written for; otherwise the server
        // in view, or the current one.
        $target = $this->createDraft && $this->createServerId !== 0
            ? $this->createServerId
            : ($this->scopeServerId !== 0 ? $this->scopeServerId : (int) ($this->serverId ?? 0));

        $this->selectCreateServer($target, keepChoices: $this->createDraft);
        $this->showCreateModal = true;
    }

    /**
     * Hide the dialog. Nothing typed is lost: a click outside the dialog does not
     * close it, and Cancel or Escape keep the draft until the app is created or the
     * form is cleared.
     */
    public function closeCreate(): void
    {
        $this->showCreateModal = false;
        $this->resetErrorBag();
        $this->createDraft = $this->hasCreateInput();
    }

    public function clearCreateForm(): void
    {
        $this->resetCreateFields();
        $this->resetErrorBag();
        $this->loadCreateOptions(keepChoices: false);
    }

    public function updatedCreateServerId(mixed $value): void
    {
        $this->selectCreateServer((int) $value, keepChoices: true);
    }

    protected function resetCreateFields(): void
    {
        $this->reset([
            'user', 'domain', 'repository', 'branch', 'env', 'docroot', 'engine',
            'nodeMode', 'nodeFramework', 'nodeVersion', 'nodeBuild', 'nodeStart',
            'nodeOutput', 'nodeHealthPath', 'octane', 'assetBuild',
        ]);
        $this->appKind = 'laravel';
        $this->createDraft = false;
    }

    protected function hasCreateInput(): bool
    {
        foreach (['user', 'domain', 'repository', 'docroot', 'assetBuild', 'nodeFramework', 'nodeBuild', 'nodeStart', 'nodeOutput', 'nodeHealthPath'] as $field) {
            if (trim((string) $this->{$field}) !== '') {
                return true;
            }
        }

        return $this->branch !== 'main' || $this->env !== AppEnv::DEFAULT || $this->appKind !== 'laravel' || $this->octane;
    }

    /** Make $id the server the create form (and the create job) talks to, and load what it offers. Only servers the user manages. */
    protected function selectCreateServer(int $id, bool $keepChoices): void
    {
        $servers = $this->manageableServers();
        $server = $servers->firstWhere('id', $id) ?? $servers->first();

        if (! $server) {
            $this->createServerId = 0;

            return;
        }

        $this->createServerId = $server->id;
        $this->serverId = $server->id;
        $this->loadCreateOptions($keepChoices);
    }

    /** Engines, PHP versions and Node runtimes of the create server — keeping a draft's choices when it still offers them. */
    protected function loadCreateOptions(bool $keepChoices): void
    {
        $engine = $this->engine;
        $php = $this->php;
        $nodeVersion = $this->nodeVersion;

        $this->loadAvailableEngines();
        $this->loadInstalledPhpVersions();
        $this->loadNodeRuntimes();

        if ($keepChoices && $engine !== '' && in_array($engine, array_column($this->availableEngines, 'engine'), true)) {
            $this->engine = $engine;
        }
        if ($keepChoices && $nodeVersion !== '' && in_array($nodeVersion, array_map(fn (array $r) => (string) $r['major'], $this->nodeRuntimes), true)) {
            $this->nodeVersion = $nodeVersion;
        }
        $this->php = $keepChoices && in_array($php, $this->installedPhpVersions, true) ? $php : $this->defaultPhpForNewApp();
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

        $this->env = trim($this->env);

        $rules = [
            'user' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/', 'not_in:'.$reserved],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'env' => ['required', 'string', 'max:'.AppEnv::MAX_LENGTH, AppEnv::rule()],
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
                $this->assetBuild = trim($this->assetBuild);
                $rules['assetBuild'] = ['nullable', 'string', BuildCommand::rule()];
            } else {
                $rules['repository'] = ['nullable', 'string', 'max:512'];
                $rules['branch'] = ['nullable', 'string', 'max:64'];
                $rules['docroot'] = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]*$/'];
            }
        }

        if ($this->createServerId === 0 || ! $this->serverAllowed(Access::MANAGE, $this->createServerId)) {
            return;
        }

        $this->validate($rules, [
            'user.regex' => 'Use 3–32 lowercase letters or digits, starting with a letter.',
            'user.not_in' => 'This name is reserved on Cipi servers.',
            'domain.regex' => 'Enter a hostname such as app.example.com or *.example.com.',
            'env.regex' => AppEnv::message(),
            'env.max' => AppEnv::message(),
            'nodeHealthPath.regex' => 'The health path starts with /.',
        ]);

        // `env` is validated and dropped by servers before API 1.36 (production there).
        $payload = ['user' => $this->user, 'domain' => strtolower($this->domain), 'env' => $this->env];

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
            // Only Laravel apps take node_build; the API answers 422 on custom and Node apps.
            if (! $isCustom && $this->assetBuild !== '') {
                $payload['node_build'] = $this->assetBuild;
            }
        }

        if ($this->startJob("Create app {$this->user}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->createApp($payload))) {
            // Kept until the job succeeds, so a refused name or domain can be fixed and resent.
            $this->showCreateModal = false;
            $this->createDraft = true;
        }
    }

    /** " on fra1" when more than one server is connected — jobs can target any of them. */
    protected function jobServerSuffix(): string
    {
        if (CipiServer::where('is_active', true)->count() < 2) {
            return '';
        }

        return ' on '.($this->currentServer()?->name ?? 'server');
    }

    // ── Row actions (each row carries its server) ─────────────────────

    public function deploy(string $name, int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->appAllowed('deploy', (int) $this->serverId, $name)) {
            return;
        }

        $this->startJob("Deploy {$name}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->deploy($name));
    }

    public function toggleSuspend(string $name, bool $suspended, int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->appAllowed('edit', (int) $this->serverId, $name)) {
            return;
        }

        $this->startJob(
            ($suspended ? 'Unsuspend ' : 'Suspend ').$name.$this->jobServerSuffix(),
            fn (CipiApiClient $api) => $suspended ? $api->unsuspendApp($name) : $api->suspendApp($name),
        );
    }

    public function confirmDeleteApp(string $name, int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->appAllowed('delete', (int) $this->serverId, $name)) {
            return;
        }

        $this->deleteAppName = $name;
        $this->deleteServerName = (string) ($this->currentServer()?->name ?? '');
        $this->deleteConfirmation = '';
        $this->resetErrorBag();
    }

    public function cancelDeleteApp(): void
    {
        $this->deleteAppName = '';
        $this->deleteServerName = '';
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

        if (! $this->appAllowed('delete', (int) $this->serverId, $name)) {
            return;
        }

        if ($this->startJob("Delete app {$name}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->deleteApp($name))) {
            $this->cancelDeleteApp();
        }
    }

    protected function onJobCompleted(array $data): void
    {
        if (str_starts_with($this->jobLabel, 'Create app')) {
            $this->resetCreateFields();
        }

        $this->loadApps();
    }

    /** @param  array<string, mixed>  $patch */
    #[On('app-changed')]
    public function onAppChanged(string $name, array $patch, ?int $serverId = null): void
    {
        foreach ($this->apps as $index => $app) {
            if (($app['app'] ?? '') === $name && ($serverId === null || (int) ($app['server_id'] ?? 0) === $serverId)) {
                $this->apps[$index] = array_merge($app, $patch);

                return;
            }
        }
    }

    public function render()
    {
        $servers = $this->fleet ? $this->activeServers() : collect(array_filter([$this->currentServer()]));
        $scopeServer = $this->scopeServerId !== 0 ? $servers->firstWhere('id', $this->scopeServerId) : null;
        if (! $this->fleet) {
            $scopeServer = $servers->first();
        }
        $scopeId = $scopeServer?->id ?? 0;
        $createServers = $this->manageableServers();

        $serverCounts = [];
        foreach ($this->apps as $app) {
            $serverCounts[$app['server_id']] = ($serverCounts[$app['server_id']] ?? 0) + 1;
        }

        $scoped = $scopeId === 0 ? $this->apps : array_values(array_filter($this->apps, fn (array $app) => (int) $app['server_id'] === $scopeId));

        $counts = ['all' => count($scoped), 'laravel' => 0, 'node' => 0, 'custom' => 0];
        foreach ($scoped as $app) {
            $counts[$this->appKind($app)]++;
        }

        $needle = mb_strtolower(trim($this->search));
        $visible = array_values(array_filter($scoped, function (array $app) use ($needle): bool {
            if ($this->kindFilter !== 'all' && $this->appKind($app) !== $this->kindFilter) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = mb_strtolower(implode(' ', [$app['app'] ?? '', $app['domain'] ?? '', implode(' ', $app['aliases'] ?? []), $app['repository'] ?? '', $app['server_name'] ?? '', $app['env'] ?? '']));

            return str_contains($haystack, $needle);
        }));

        $errors = $scopeId === 0 ? $this->serverErrors : array_intersect_key($this->serverErrors, [$scopeId => true]);

        return view('cipi-gui::livewire.apps', [
            'servers' => $servers,
            'fleet' => $this->fleet,
            'multiServer' => $this->fleet && $servers->count() > 1,
            'createServers' => $createServers,
            'canCreate' => $createServers->isNotEmpty(),
            'scopeServer' => $scopeServer,
            'scopeId' => $scopeId,
            'serverCounts' => $serverCounts,
            'serverErrors' => $errors,
            'scopedCount' => count($scoped),
            'visibleApps' => $visible,
            'counts' => $counts,
            'createServer' => $servers->firstWhere('id', $this->createServerId),
            'phpVersions' => $this->installedPhpVersions !== [] ? $this->installedPhpVersions : config('cipi-gui.php_versions'),
            'presets' => self::NODE_PRESETS,
            'buildPlaceholder' => BuildCommand::PLACEHOLDER,
            'buildMaxLength' => BuildCommand::MAX_LENGTH,
            'envSuggestions' => AppEnv::SUGGESTIONS,
            'envMaxLength' => AppEnv::MAX_LENGTH,
        ])->title($scopeServer ? 'Apps · '.$scopeServer->name : 'Apps');
    }
}
