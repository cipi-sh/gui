<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
class AppDetail extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    public const ARTISAN_PRESETS = [
        'about', 'migrate:status', 'migrate --force', 'optimize', 'optimize:clear', 'cache:clear',
        'config:cache', 'queue:restart', 'schedule:list', 'storage:link',
    ];

    public const RUN_PRESETS = [
        'composer install --no-dev --no-interaction', 'composer dump-autoload --optimize',
        'npm ci', 'npm run build', 'git status', 'du -sh current shared logs',
    ];

    public string $appName;

    public ?array $app = null;

    /** @var array<int, string> */
    public array $aliases = [];

    public bool $loading = true;

    #[Url(as: 'tab', except: 'overview')]
    public string $activeTab = 'overview';

    // Edit form
    public string $editPhp = '';

    public string $editBranch = '';

    public string $editRepository = '';

    public string $editDomain = '';

    // Node fields (Node apps; the version pin also applies to Laravel builds — API 1.31+)
    public string $editNodeVersion = '';

    public string $editBuild = '';

    public string $editStart = '';

    public string $editOutput = '';

    public string $editHealthPath = '';

    public string $editNodeMode = '';

    public ?array $nodeInfo = null;

    /** @var list<array{major: string, version: ?string, default: bool, apps?: list<string>}> */
    public array $nodeRuntimes = [];

    /** @var list<string> */
    public array $installedPhpVersions = [];

    public bool $phpListUnsupported = false;

    // Aliases / WWW
    public string $newAlias = '';

    public ?array $wwwStatus = null;

    public bool $wwwUnsupported = false;

    // Basic auth
    public ?array $basicAuth = null;

    public string $basicAuthUser = 'admin';

    public string $basicAuthPassword = '';

    public ?string $generatedPassword = null;

    // .env (Laravel apps — API 1.14+)
    /** @var array<int, array{key: string, value: string}> */
    public array $envRows = [];

    /** @var array<string, string> */
    public array $envOriginal = [];

    public bool $envLoaded = false;

    public bool $envUnsupported = false;

    public string $envNewKey = '';

    public string $envNewValue = '';

    // Shared auth.json (Composer — not HTTP Basic Auth)
    public ?string $authJsonContent = null;

    public bool $authJsonLoaded = false;

    public bool $authJsonExists = false;

    public bool $authJsonUnsupported = false;

    // Artisan / app run
    public string $artisanCommand = '';

    public string $runCommand = '';

    /** @var list<string> */
    public array $runAllowedCommands = [];

    /** @var list<string> */
    public array $runNotes = [];

    public bool $runLoaded = false;

    public bool $runUnsupported = false;

    // Delete
    public bool $showDeleteModal = false;

    public string $deleteConfirmation = '';

    // HTTP healthcheck (API 1.16+)
    public array $health = [];

    public bool $healthUnsupported = false;

    public bool $healthEnabled = false;

    public string $healthUrl = '';

    public string $healthExpect = '200';

    public ?array $healthCheckResult = null;

    // Routing (API 1.31+ / Cipi ≥ 5.3.1)
    public ?array $appRedirect = null;

    /** @var list<array{from: string, to: string, code: int, keep_path: bool}> */
    public array $pathRedirects = [];

    /** @var list<array<string, mixed>> */
    public array $proxies = [];

    public bool $routingLoaded = false;

    public bool $routingUnsupported = false;

    public string $redirectTo = '';

    public string $redirectCode = '301';

    public bool $redirectKeepPath = true;

    public string $pathRedirectFrom = '';

    public string $pathRedirectTo = '';

    public string $pathRedirectCode = '301';

    public bool $pathRedirectKeepPath = true;

    public string $proxyPrefix = '';

    public string $proxyUpstream = '';

    public bool $proxyStripPrefix = false;

    public bool $proxyPreserveHost = false;

    public string $proxyTimeout = '60';

    public bool $proxyBuffering = true;

    // Deploy config (structured deploy.php options)
    public bool $deployConfigLoaded = false;

    public bool $deployConfigUnsupported = false;

    public string $dcKeepReleases = '5';

    public bool $dcMigrate = true;

    public bool $dcOptimize = true;

    public bool $dcStorageLink = true;

    public bool $dcQueueRestart = true;

    public bool $dcHorizonTerminate = false;

    public bool $dcPredeploySnapshot = false;

    public string $dcNodeBuild = '';

    public string $dcExtraArtisan = '';

    // Deploy audit ledger (API 1.31+ / Cipi ≥ 5.4.0)
    /** @var list<array<string, mixed>> */
    public array $auditRecords = [];

    public bool $auditLoaded = false;

    public bool $auditUnsupported = false;

    public string $auditDays = '90';

    // Meilisearch / Laravel Scout (API 1.31+ / Cipi ≥ 5.2.2)
    public ?array $searchStatus = null;

    public bool $searchUnsupported = false;

    public function mount(?string $name = null): void
    {
        $this->appName = $name ?? (string) request()->route('name');

        if ($this->appName === '') {
            abort(404);
        }

        $this->ensureServerSelected();
        $this->loadApp();

        if ($this->app !== null && ! array_key_exists($this->activeTab, $this->tabs())) {
            $this->activeTab = 'overview';
        }

        if ($this->app !== null && $this->activeTab !== 'overview') {
            $this->loadTab($this->activeTab);
        }
    }

    public function loadApp(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $this->app = $this->normalizeApp($this->client()->showApp($this->appName));
            $this->aliases = $this->app['aliases'];
            $this->editPhp = $this->app['php'] !== '' ? $this->app['php'] : '8.5';
            $this->editBranch = $this->app['branch'];
            $this->editRepository = $this->app['repository'];
            $this->editDomain = $this->app['domain'];
            $this->editNodeMode = (string) ($this->app['node_mode'] ?? '');
            $this->editNodeVersion = (string) ($this->app['node_version'] ?? '');
            $this->loadInstalledPhpVersions();
            $this->loadNodeRuntimes();
            $this->loadNodeStatus();
            $this->loadHealth();
            if ($this->isLaravelApp($this->app)) {
                $this->loadSearchStatus();
            }
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    /** @return array<string, string> */
    public function tabs(): array
    {
        $app = $this->app ?? [];
        $isLaravel = $this->isLaravelApp($app);
        $isNode = $this->isNodeApp($app);

        $tabs = [
            'overview' => 'Overview',
            'domains' => 'Domains & SSL',
            'routing' => 'Routing',
            'deploy' => 'Deploy',
        ];
        if ($isLaravel) {
            $tabs['env'] = 'Environment';
        }
        if (! $isNode) {
            $tabs['authjson'] = 'Composer auth';
        }
        if ($isLaravel) {
            $tabs['artisan'] = 'Artisan';
        }
        $tabs['run'] = 'Commands';
        $tabs['access'] = 'Access';
        $tabs['logs'] = 'Logs';

        return $tabs;
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->tabs())) {
            return;
        }

        $this->activeTab = $tab;
        $this->loadTab($tab);
    }

    protected function loadTab(string $tab): void
    {
        match ($tab) {
            'domains' => $this->loadWwwStatus(),
            'routing' => $this->loadRouting(),
            'deploy' => $this->loadDeployTab(),
            'env' => $this->loadEnv(),
            'authjson' => $this->loadAuthJson(),
            'run' => $this->loadRunCommands(),
            'access' => $this->loadBasicAuth(),
            default => null,
        };
    }

    protected function loadDeployTab(): void
    {
        if ($this->isLaravelApp($this->app ?? [])) {
            $this->loadDeployConfig();
        }
        if (! $this->auditLoaded) {
            $this->loadDeployAudit();
        }
    }

    // ── Overview: PHP / Node / health / search ────────────────────────

    protected function loadInstalledPhpVersions(): void
    {
        $this->phpListUnsupported = false;
        $fallback = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);

        try {
            $versions = [];
            foreach ($this->client()->listPhp()['versions'] ?? [] as $row) {
                if (is_array($row) && ! empty($row['version'])) {
                    $versions[] = (string) $row['version'];
                }
            }
            $this->installedPhpVersions = $versions !== [] ? $versions : $fallback;
        } catch (CipiApiException $e) {
            $this->phpListUnsupported = $this->isUnsupported($e);
            $this->installedPhpVersions = $fallback;
        }
    }

    protected function loadNodeRuntimes(): void
    {
        $this->nodeRuntimes = [];

        try {
            $this->nodeRuntimes = array_values(array_filter(
                $this->client()->listNodeRuntimes(),
                fn ($item) => is_array($item) && ! empty($item['major']),
            ));
        } catch (CipiApiException $e) {
            if (! $this->isUnsupported($e)) {
                $this->handleApiError($e);
            }
        }
    }

    protected function loadNodeStatus(): void
    {
        $this->nodeInfo = null;

        if (! $this->isNodeApp($this->app ?? [])) {
            return;
        }

        try {
            $status = $this->client()->nodeStatus($this->appName);
            $this->nodeInfo = $status;
            if (! empty($status['version'])) {
                $this->editNodeVersion = (string) $status['version'];
            }
            if (! empty($status['mode'])) {
                $this->editNodeMode = (string) $status['mode'];
            }
            $this->editBuild = (string) ($status['build'] ?? '');
            $this->editStart = (string) ($status['start'] ?? '');
            $this->editOutput = (string) ($status['output'] ?? '');
            $this->editHealthPath = (string) ($status['health_path'] ?? '');
        } catch (CipiApiException $e) {
            if (! $this->isUnsupported($e)) {
                $this->handleApiError($e);
            }
        }
    }

    protected function loadHealth(): void
    {
        $this->healthUnsupported = false;
        $this->healthCheckResult = null;
        $domain = str_replace('*.', '', (string) ($this->app['domain'] ?? ''));
        $default = $domain !== '' ? 'https://'.$domain.($this->isLaravelApp($this->app ?? []) ? '/up' : '/') : '';

        try {
            $this->health = $this->client()->getAppHealth($this->appName);
            $this->healthEnabled = (bool) ($this->health['enabled'] ?? false);
            $this->healthUrl = (string) ($this->health['url'] ?? $default);
            $this->healthExpect = (string) ($this->health['expect'] ?? 200);
        } catch (CipiApiException) {
            $this->healthUnsupported = true;
            $this->health = [];
            $this->healthEnabled = false;
            $this->healthUrl = $default;
            $this->healthExpect = '200';
        }
    }

    public function saveHealth(): void
    {
        $this->validate([
            'healthUrl' => ['nullable', 'string', 'max:512', 'regex:/^https?:\/\/.+/i'],
            'healthExpect' => ['required', 'integer', 'min:100', 'max:599'],
        ], ['healthUrl.regex' => 'The URL must start with http:// or https://']);

        try {
            $url = trim($this->healthUrl);
            $this->health = $this->client()->setAppHealth($this->appName, $url !== '' ? $url : null, (int) $this->healthExpect);
            $this->healthEnabled = true;
            $this->healthCheckResult = null;
            $this->dispatch('notify', type: 'success', message: 'Healthcheck saved — probed every 5 minutes.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableHealth(): void
    {
        try {
            $this->health = $this->client()->unsetAppHealth($this->appName);
            $this->healthEnabled = false;
            $this->healthCheckResult = null;
            $this->dispatch('notify', type: 'success', message: 'Healthcheck disabled.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function runHealthCheck(): void
    {
        try {
            $this->healthCheckResult = $this->client()->checkAppHealth($this->appName);
            $ok = (bool) ($this->healthCheckResult['ok'] ?? false);
            $this->dispatch('notify',
                type: $ok ? 'success' : 'error',
                message: $ok
                    ? 'Healthcheck OK ('.($this->healthCheckResult['got'] ?? '').')'
                    : 'Healthcheck failed: got '.($this->healthCheckResult['got'] ?? '?').', expected '.($this->healthCheckResult['expect'] ?? '?'),
            );
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function saveApp(): void
    {
        $isNode = $this->isNodeApp($this->app ?? []);

        $rules = [
            'editBranch' => ['nullable', 'string', 'max:64'],
            'editRepository' => ['nullable', 'string', 'max:512'],
            'editDomain' => ['nullable', 'string', 'max:255', 'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'editNodeVersion' => ['nullable', 'string', 'max:8'],
        ];

        if ($isNode) {
            $rules += [
                'editNodeMode' => ['required', 'in:spa,static,ssr'],
                'editBuild' => ['nullable', 'string', 'max:256'],
                'editStart' => ['nullable', 'string', 'max:256'],
                'editOutput' => ['nullable', 'string', 'max:128'],
                'editHealthPath' => ['nullable', 'string', 'max:128'],
            ];
        } else {
            $rules['editPhp'] = ['required', 'regex:/^\d+\.\d+$/'];
        }

        $this->validate($rules, ['editDomain.regex' => 'Enter a hostname such as app.example.com or *.example.com.']);

        if (! $isNode && $this->installedPhpVersions !== [] && ! in_array($this->editPhp, $this->installedPhpVersions, true)) {
            $this->addError('editPhp', 'PHP '.$this->editPhp.' is not installed on this server. Install it from Server → PHP, or pick: '.implode(', ', $this->installedPhpVersions).'.');

            return;
        }

        $payload = [];
        if (! $isNode && $this->editPhp !== '' && $this->editPhp !== (string) $this->app['php']) {
            $payload['php'] = $this->editPhp;
        }
        if ($this->editBranch !== (string) $this->app['branch']) {
            $payload['branch'] = $this->editBranch;
        }
        if ($this->editRepository !== (string) $this->app['repository']) {
            $payload['repository'] = $this->editRepository;
        }
        if ($this->editDomain !== '' && $this->editDomain !== (string) $this->app['domain']) {
            $payload['domain'] = strtolower($this->editDomain);
        }

        // node_version also pins a Laravel app's asset build to a Node major.
        $currentNodeVersion = (string) ($this->app['node_version'] ?? '');
        $nodeVersion = trim($this->editNodeVersion);
        if ($nodeVersion !== $currentNodeVersion) {
            if ($nodeVersion === '' || $nodeVersion === 'default') {
                if ($currentNodeVersion !== '') {
                    $payload['node_version'] = 'default';
                }
            } else {
                $payload['node_version'] = $nodeVersion;
            }
        }

        if ($isNode) {
            if ($this->editNodeMode !== '' && $this->editNodeMode !== (string) ($this->app['node_mode'] ?? '')) {
                $payload['node'] = $this->editNodeMode;
            }

            foreach (['build' => $this->editBuild, 'start' => $this->editStart, 'output' => $this->editOutput, 'health_path' => $this->editHealthPath] as $key => $value) {
                $value = trim($value);
                if ($value !== '' && $value !== (string) ($this->nodeInfo[$key] ?? '')) {
                    $payload[$key] = $value;
                }
            }
        }

        if ($payload === []) {
            $this->dispatch('notify', type: 'info', message: 'Nothing changed.');

            return;
        }

        $this->startJob('Update '.$this->appName, fn (CipiApiClient $api) => $api->editApp($this->appName, $payload));
    }

    // ── Lifecycle actions (async jobs) ────────────────────────────────

    public function suspendApp(): void
    {
        $this->startJob('Suspend '.$this->appName, fn (CipiApiClient $api) => $api->suspendApp($this->appName));
    }

    public function unsuspendApp(): void
    {
        $this->startJob('Unsuspend '.$this->appName, fn (CipiApiClient $api) => $api->unsuspendApp($this->appName));
    }

    public function fixPermissions(): void
    {
        $this->startJob('Fix permissions', fn (CipiApiClient $api) => $api->fixPermissions($this->appName));
    }

    public function restartNode(): void
    {
        $this->startJob('Restart Node (blue/green)', fn (CipiApiClient $api) => $api->nodeRestart($this->appName));
    }

    public function recreateWebhook(bool $rotateSecret = false): void
    {
        $this->startJob(
            $rotateSecret ? 'Recreate webhook + rotate secret' : 'Recreate webhook',
            fn (CipiApiClient $api) => $api->recreateWebhook($this->appName, $rotateSecret),
        );
    }

    public function deploy(): void
    {
        $this->startJob('Deploy '.$this->appName, fn (CipiApiClient $api) => $api->deploy($this->appName));
    }

    public function rollback(): void
    {
        $this->startJob('Roll back '.$this->appName, fn (CipiApiClient $api) => $api->deployRollback($this->appName));
    }

    public function unlockDeploy(): void
    {
        $this->startJob('Unlock deploy', fn (CipiApiClient $api) => $api->deployUnlock($this->appName));
    }

    public function installSsl(): void
    {
        $this->startJob("Let's Encrypt certificate", fn (CipiApiClient $api) => $api->installSsl($this->appName));
    }

    public function forceSsl(): void
    {
        $this->startJob('Force HTTPS', fn (CipiApiClient $api) => $api->forceSsl($this->appName));
    }

    // ── Domains: aliases + www ────────────────────────────────────────

    public function loadWwwStatus(): void
    {
        $this->wwwUnsupported = false;

        try {
            $this->wwwStatus = $this->client()->wwwStatus($this->appName);
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e)) {
                $this->wwwStatus = null;
                $this->wwwUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function wwwAdd(): void
    {
        $this->startJob('Add www/apex alias', fn (CipiApiClient $api) => $api->wwwAdd($this->appName));
    }

    public function wwwForceToRoot(): void
    {
        $this->startJob('Redirect www → apex', fn (CipiApiClient $api) => $api->wwwForceToRoot($this->appName));
    }

    public function wwwForceFromRoot(): void
    {
        $this->startJob('Redirect apex → www', fn (CipiApiClient $api) => $api->wwwForceFromRoot($this->appName));
    }

    public function wwwClear(): void
    {
        $this->startJob('Clear www redirect', fn (CipiApiClient $api) => $api->wwwClear($this->appName));
    }

    public function addAlias(): void
    {
        $this->newAlias = strtolower(trim($this->newAlias));
        $this->validate(['newAlias' => ['required', 'string', 'max:255', 'regex:/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/']], [
            'newAlias.regex' => 'Enter a hostname such as www.example.com.',
        ]);

        $alias = $this->newAlias;
        if ($this->startJob("Add alias {$alias}", fn (CipiApiClient $api) => $api->addAlias($this->appName, $alias))) {
            $this->newAlias = '';
        }
    }

    public function removeAlias(string $alias): void
    {
        $this->startJob("Remove alias {$alias}", fn (CipiApiClient $api) => $api->removeAlias($this->appName, $alias));
    }

    // ── Routing: app/path redirects + proxies ─────────────────────────

    public function loadRouting(): void
    {
        $this->routingUnsupported = false;

        try {
            $this->syncRedirects($this->client()->listRedirects($this->appName));
            $this->syncProxies($this->client()->listProxies($this->appName));
            $this->routingLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e)) {
                $this->routingUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function syncRedirects(array $data): void
    {
        $this->appRedirect = is_array($data['redirect'] ?? null) ? $data['redirect'] : null;
        $this->pathRedirects = is_array($data['redirects'] ?? null) ? array_values($data['redirects']) : [];

        if ($this->appRedirect !== null) {
            $this->redirectTo = (string) ($this->appRedirect['to'] ?? '');
            $this->redirectCode = (string) ($this->appRedirect['code'] ?? 301);
            $this->redirectKeepPath = (bool) ($this->appRedirect['keep_path'] ?? true);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function syncProxies(array $data): void
    {
        $this->proxies = is_array($data['proxies'] ?? null) ? array_values($data['proxies']) : [];
    }

    public function saveAppRedirect(): void
    {
        $this->validate([
            'redirectTo' => ['required', 'string', 'max:2048', 'regex:/^https?:\/\//i'],
            'redirectCode' => ['required', 'in:301,302,307,308'],
        ], ['redirectTo.regex' => 'Use an absolute URL starting with https://']);

        $this->routingCall(
            fn (CipiApiClient $api) => $this->syncRedirects($api->setAppRedirect($this->appName, trim($this->redirectTo), (int) $this->redirectCode, $this->redirectKeepPath)),
            'Whole-app redirect saved.',
        );
    }

    public function toggleAppRedirect(): void
    {
        $enabled = (bool) ($this->appRedirect['enabled'] ?? false);

        $this->routingCall(
            fn (CipiApiClient $api) => $this->syncRedirects($enabled ? $api->disableAppRedirect($this->appName) : $api->enableAppRedirect($this->appName)),
            $enabled ? 'Redirect disabled — the app serves traffic again.' : 'Redirect enabled.',
        );
    }

    public function removeAppRedirect(): void
    {
        $this->routingCall(function (CipiApiClient $api) {
            $this->syncRedirects($api->unsetAppRedirect($this->appName));
            $this->redirectTo = '';
            $this->redirectCode = '301';
            $this->redirectKeepPath = true;
        }, 'Whole-app redirect removed.');
    }

    public function addPathRedirect(): void
    {
        $this->validate([
            'pathRedirectFrom' => ['required', 'string', 'max:512', 'regex:/^\//'],
            'pathRedirectTo' => ['required', 'string', 'max:2048'],
            'pathRedirectCode' => ['required', 'in:301,302,307,308'],
        ], ['pathRedirectFrom.regex' => 'The path starts with /']);

        $this->routingCall(function (CipiApiClient $api) {
            $this->syncRedirects($api->addPathRedirect($this->appName, trim($this->pathRedirectFrom), trim($this->pathRedirectTo), (int) $this->pathRedirectCode, $this->pathRedirectKeepPath));
            $this->reset(['pathRedirectFrom', 'pathRedirectTo', 'pathRedirectCode', 'pathRedirectKeepPath']);
        }, 'Path redirect saved.');
    }

    public function removePathRedirect(string $from): void
    {
        $this->routingCall(fn (CipiApiClient $api) => $this->syncRedirects($api->removePathRedirect($this->appName, $from)), 'Path redirect removed.');
    }

    public function addProxy(): void
    {
        $this->validate([
            'proxyPrefix' => ['required', 'string', 'max:512', 'regex:/^\//'],
            'proxyUpstream' => ['required', 'string', 'max:2048', 'regex:/^https?:\/\//i'],
            'proxyTimeout' => ['required', 'integer', 'min:1', 'max:3600'],
        ], ['proxyPrefix.regex' => 'The prefix starts with /', 'proxyUpstream.regex' => 'Use an http:// or https:// upstream URL']);

        $this->routingCall(function (CipiApiClient $api) {
            $this->syncProxies($api->addProxy($this->appName, [
                'prefix' => trim($this->proxyPrefix),
                'upstream' => trim($this->proxyUpstream),
                'strip_prefix' => $this->proxyStripPrefix,
                'preserve_host' => $this->proxyPreserveHost,
                'timeout' => (int) $this->proxyTimeout,
                'buffering' => $this->proxyBuffering,
            ]));
            $this->reset(['proxyPrefix', 'proxyUpstream', 'proxyStripPrefix', 'proxyPreserveHost', 'proxyTimeout', 'proxyBuffering']);
        }, 'Proxy saved.');
    }

    public function removeProxy(string $prefix): void
    {
        $this->routingCall(fn (CipiApiClient $api) => $this->syncProxies($api->removeProxy($this->appName, $prefix)), 'Proxy removed.');
    }

    /** @param  callable(CipiApiClient): mixed  $call */
    protected function routingCall(callable $call, string $success): void
    {
        try {
            $call($this->client());
            $this->dispatch('notify', type: 'success', message: $success);
            $this->loadApp();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── Deploy config + audit ─────────────────────────────────────────

    public function loadDeployConfig(): void
    {
        $this->deployConfigUnsupported = false;

        if (! $this->isLaravelApp($this->app ?? [])) {
            $this->deployConfigUnsupported = true;

            return;
        }

        try {
            $this->syncDeployConfig($this->client()->showDeployConfig($this->appName));
            $this->deployConfigLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 422) {
                $this->deployConfigUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function syncDeployConfig(array $data): void
    {
        $this->dcKeepReleases = (string) ($data['keep_releases'] ?? 5);
        $this->dcMigrate = (bool) ($data['migrate'] ?? true);
        $this->dcOptimize = (bool) ($data['optimize'] ?? true);
        $this->dcStorageLink = (bool) ($data['storage_link'] ?? true);
        $this->dcQueueRestart = (bool) ($data['queue_restart'] ?? true);
        $this->dcHorizonTerminate = (bool) ($data['horizon_terminate'] ?? false);
        $this->dcPredeploySnapshot = (bool) ($data['predeploy_snapshot'] ?? false);
        $this->dcNodeBuild = (string) ($data['node_build'] ?? '');
        $extra = is_array($data['extra_artisan'] ?? null) ? $data['extra_artisan'] : [];
        $this->dcExtraArtisan = implode("\n", array_map('strval', $extra));
    }

    public function saveDeployConfig(): void
    {
        $this->validate([
            'dcKeepReleases' => ['required', 'integer', 'min:1', 'max:20'],
            'dcNodeBuild' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $data = $this->client()->updateDeployConfig($this->appName, [
                'keep_releases' => (int) $this->dcKeepReleases,
                'migrate' => $this->dcMigrate,
                'optimize' => $this->dcOptimize,
                'storage_link' => $this->dcStorageLink,
                'queue_restart' => $this->dcQueueRestart,
                'horizon_terminate' => $this->dcHorizonTerminate,
                'predeploy_snapshot' => $this->dcPredeploySnapshot,
                'node_build' => $this->dcNodeBuild,
                'extra_artisan' => array_values(array_filter(array_map('trim', explode("\n", $this->dcExtraArtisan)))),
            ]);
            $this->syncDeployConfig($data);
            $this->dispatch('notify', type: 'success', message: 'Deploy options saved — deploy.php regenerated.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function updatedAuditDays(): void
    {
        $this->loadDeployAudit();
    }

    public function loadDeployAudit(): void
    {
        $this->auditUnsupported = false;

        $days = max(1, min(3650, (int) $this->auditDays ?: 90));
        $this->auditDays = (string) $days;

        try {
            $data = $this->client()->deployAudit($this->appName, $days);
            $records = is_array($data['records'] ?? null) ? $data['records'] : [];
            $this->auditRecords = array_reverse(array_values($records)); // newest first
            $this->auditLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->auditUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    // ── Search (Meilisearch / Scout) ──────────────────────────────────

    public function loadSearchStatus(): void
    {
        $this->searchUnsupported = false;

        try {
            $this->searchStatus = $this->client()->searchStatus();
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->searchUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function enableSearch(): void
    {
        try {
            $this->client()->enableSearch($this->appName);
            $this->dispatch('notify', type: 'success', message: 'Search enabled — SCOUT_DRIVER=meilisearch with a scoped key.');
            $this->loadSearchStatus();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableSearch(): void
    {
        try {
            $this->client()->disableSearch($this->appName);
            $this->dispatch('notify', type: 'success', message: 'Search disabled — previous SCOUT_DRIVER restored.');
            $this->loadSearchStatus();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function searchEnabledForApp(): bool
    {
        $apps = $this->searchStatus['apps'] ?? [];

        return is_array($apps) && array_key_exists($this->appName, $apps);
    }

    // ── Access: HTTP basic auth ───────────────────────────────────────

    public function loadBasicAuth(): void
    {
        try {
            $this->basicAuth = $this->client()->basicAuthStatus($this->appName);
            $this->basicAuth['enabled'] = $this->appFlagIsTrue($this->basicAuth['enabled'] ?? false);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function enableBasicAuth(): void
    {
        $this->validate([
            'basicAuthUser' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'basicAuthPassword' => ['nullable', 'string', 'min:8', 'max:128'],
        ]);

        try {
            $result = $this->client()->basicAuthEnable($this->appName, array_filter([
                'user' => $this->basicAuthUser,
                'password' => $this->basicAuthPassword ?: null,
            ]));
            $this->basicAuth = $result;
            $this->basicAuth['enabled'] = $this->appFlagIsTrue($result['enabled'] ?? true);
            $this->generatedPassword = $result['password'] ?? null;
            $this->basicAuthPassword = '';
            $this->patchApp(['basic_auth' => true]);
            $this->dispatch('notify', type: 'success', message: 'Basic auth enabled.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableBasicAuth(): void
    {
        try {
            $this->client()->basicAuthDisable($this->appName);
            $this->basicAuth = ['enabled' => false, 'users' => []];
            $this->generatedPassword = null;
            $this->patchApp(['basic_auth' => false]);
            $this->dispatch('notify', type: 'success', message: 'Basic auth disabled.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── .env ──────────────────────────────────────────────────────────

    public function loadEnv(): void
    {
        $this->envUnsupported = false;
        $this->envLoaded = false;

        if (! $this->isLaravelApp($this->app ?? [])) {
            $this->envUnsupported = true;

            return;
        }

        try {
            $data = $this->client()->showEnv($this->appName);
            $this->syncEnvRowsFromVars(is_array($data['vars'] ?? null) ? $data['vars'] : []);
            $this->envLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || str_contains(strtolower($e->getMessage()), 'custom app')) {
                $this->envUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function addEnvRow(): void
    {
        $key = strtoupper(trim($this->envNewKey));
        if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            $this->addError('envNewKey', 'Keys use A–Z, 0–9 and _ and start with a letter.');

            return;
        }

        foreach ($this->envRows as $row) {
            if (strcasecmp($row['key'], $key) === 0) {
                $this->addError('envNewKey', "{$key} already exists — edit it in the list.");

                return;
            }
        }

        $this->envRows[] = ['key' => $key, 'value' => $this->envNewValue];
        $this->envNewKey = '';
        $this->envNewValue = '';
        $this->resetErrorBag('envNewKey');
    }

    public function removeEnvRow(int $index): void
    {
        if (isset($this->envRows[$index])) {
            unset($this->envRows[$index]);
            $this->envRows = array_values($this->envRows);
        }
    }

    public function resetEnv(): void
    {
        $this->syncEnvRowsFromVars($this->envOriginal);
    }

    /** @return array{set: array<string, string>, unset: list<string>} */
    protected function envDiff(): array
    {
        $current = [];
        foreach ($this->envRows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '') {
                $current[$key] = (string) ($row['value'] ?? '');
            }
        }

        $set = [];
        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $this->envOriginal) || $this->envOriginal[$key] !== $value) {
                $set[$key] = $value;
            }
        }

        $unset = array_values(array_diff(array_keys($this->envOriginal), array_keys($current)));

        return ['set' => $set, 'unset' => $unset];
    }

    public function envChangeCount(): int
    {
        $diff = $this->envDiff();

        return count($diff['set']) + count($diff['unset']);
    }

    public function saveEnv(): void
    {
        ['set' => $set, 'unset' => $unset] = $this->envDiff();

        if ($set === [] && $unset === []) {
            $this->dispatch('notify', type: 'info', message: 'No .env changes to save.');

            return;
        }

        try {
            $data = $this->client()->updateEnv($this->appName, $set, $unset);
            $this->syncEnvRowsFromVars(is_array($data['vars'] ?? null) ? $data['vars'] : []);
            $this->dispatch('notify', type: 'success', message: '.env saved ('.(count($set) + count($unset)).' changes). Deploy or run config:cache to apply.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    /** @param  array<string, mixed>  $vars */
    protected function syncEnvRowsFromVars(array $vars): void
    {
        $normalized = [];
        foreach ($vars as $key => $value) {
            if (is_string($key) && $key !== '') {
                $normalized[$key] = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
            }
        }

        $this->envOriginal = $normalized;
        $this->envRows = [];
        foreach ($normalized as $key => $value) {
            $this->envRows[] = ['key' => $key, 'value' => $value];
        }
    }

    public function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/(PASSWORD|PASS|SECRET|TOKEN|_KEY$|^APP_KEY$|PRIVATE|CREDENTIAL|DSN)/i', $key);
    }

    // ── Composer auth.json ────────────────────────────────────────────

    public function loadAuthJson(): void
    {
        $this->authJsonUnsupported = false;
        $this->authJsonLoaded = false;
        $this->authJsonExists = false;
        $this->authJsonContent = null;

        try {
            $data = $this->client()->showAuthJson($this->appName);
            $this->authJsonContent = json_encode($data['content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            $this->authJsonExists = true;
            $this->authJsonLoaded = true;
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 501], true)) {
                $this->authJsonUnsupported = true;

                return;
            }

            $message = strtolower($e->getMessage());
            if ($e->getStatusCode() === 404 || str_contains($message, 'not found') || str_contains($message, 'does not exist') || str_contains($message, 'no such file')) {
                $this->authJsonLoaded = true;
                $this->authJsonContent = $this->authJsonTemplate();

                return;
            }

            $this->handleApiError($e);
        }
    }

    protected function authJsonTemplate(): string
    {
        return json_encode([
            'http-basic' => ['repo.example.com' => ['username' => 'token', 'password' => 'secret']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function saveAuthJson(): void
    {
        $raw = trim($this->authJsonContent ?? '');
        $decoded = json_decode($raw, true);

        if ($raw === '' || ! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            $this->addError('authJsonContent', 'Invalid JSON: '.(json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'an object is required'));

            return;
        }

        try {
            if (! $this->authJsonExists) {
                $this->client()->createAuthJson($this->appName, false);
            }
            $data = $this->client()->updateAuthJson($this->appName, $raw);
            $this->authJsonContent = json_encode($data['content'] ?? $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;
            $this->dispatch('notify', type: 'success', message: $this->authJsonExists ? 'auth.json saved.' : 'auth.json created.');
            $this->authJsonExists = true;
            $this->resetErrorBag('authJsonContent');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteAuthJson(): void
    {
        try {
            $this->client()->deleteAuthJson($this->appName);
            $this->authJsonExists = false;
            $this->authJsonContent = $this->authJsonTemplate();
            $this->dispatch('notify', type: 'success', message: 'auth.json deleted.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── Artisan + whitelisted commands ────────────────────────────────

    public function useArtisanPreset(string $command): void
    {
        $this->artisanCommand = $command;
    }

    public function runArtisan(): void
    {
        $command = trim(preg_replace('/^(php\s+)?artisan\s+/', '', $this->artisanCommand) ?? '');
        if ($command === '') {
            $this->addError('artisanCommand', 'Enter an Artisan command.');

            return;
        }

        $this->startJob('artisan '.$command, fn (CipiApiClient $api) => $api->runArtisan($this->appName, $command));
    }

    public function loadRunCommands(): void
    {
        $this->runUnsupported = false;

        if ($this->runLoaded) {
            return;
        }

        try {
            $data = $this->client()->listRunCommands();
            $this->runAllowedCommands = array_values(array_filter((array) ($data['commands'] ?? []), fn ($c) => is_string($c) && $c !== ''));
            $this->runNotes = array_values(array_filter((array) ($data['notes'] ?? []), fn ($n) => is_string($n) && $n !== ''));
            $this->runLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e)) {
                $this->runUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function useRunPreset(string $command): void
    {
        $this->runCommand = $command;
    }

    public function runAppCommand(): void
    {
        $command = trim($this->runCommand);
        if ($command === '') {
            $this->addError('runCommand', 'Enter a command.');

            return;
        }

        $this->startJob('$ '.$command, fn (CipiApiClient $api) => $api->runAppCommand($this->appName, $command));
    }

    // ── Delete ────────────────────────────────────────────────────────

    public function confirmDeleteApp(): void
    {
        $this->deleteConfirmation = '';
        $this->resetErrorBag('deleteConfirmation');
        $this->showDeleteModal = true;
    }

    public function cancelDeleteApp(): void
    {
        $this->showDeleteModal = false;
    }

    public function deleteApp(): void
    {
        if ($this->deleteConfirmation !== $this->appName) {
            $this->addError('deleteConfirmation', 'Type the app name to confirm.');

            return;
        }

        if ($this->startJob("Delete app {$this->appName}", fn (CipiApiClient $api) => $api->deleteApp($this->appName))) {
            $this->showDeleteModal = false;
        }
    }

    /** @param  array<string, mixed>  $patch */
    protected function patchApp(array $patch): void
    {
        if ($this->app !== null) {
            $this->app = array_merge($this->app, $patch);
        }

        $this->rememberAppPatch($this->appName, $patch);
    }

    protected function onJobCompleted(array $data): void
    {
        if (str_starts_with($this->jobLabel, 'Delete app')) {
            $this->redirect(route('cipi-gui.apps'));

            return;
        }

        $this->loadApp();

        match ($this->activeTab) {
            'domains' => $this->loadWwwStatus(),
            'routing' => $this->loadRouting(),
            'deploy' => (function () {
                $this->auditLoaded = false;
                $this->loadDeployTab();
            })(),
            default => null,
        };
    }

    public function siteUrl(): string
    {
        $domain = ltrim(str_replace('*.', '', (string) ($this->app['domain'] ?? '')), '.');

        return (($this->app['force_https'] ?? false) ? 'https://' : 'http://').$domain;
    }

    public function repositoryUrl(): ?string
    {
        $repo = (string) ($this->app['repository'] ?? '');

        if (preg_match('#^git@([^:]+):(.+?)(\.git)?$#', $repo, $m)) {
            return 'https://'.$m[1].'/'.$m[2];
        }

        return preg_match('#^https?://#', $repo) ? preg_replace('/\.git$/', '', $repo) : null;
    }

    public function render()
    {
        $server = $this->currentServer();

        return view('cipi-gui::livewire.app-detail', [
            'phpVersions' => $this->installedPhpVersions !== [] ? $this->installedPhpVersions : config('cipi-gui.php_versions'),
            'server' => $server,
            'tabs' => $this->app ? $this->tabs() : [],
            'artisanPresets' => self::ARTISAN_PRESETS,
            'runPresets' => self::RUN_PRESETS,
        ])->title($this->appName.($server ? ' · '.$server->name : ''));
    }
}
