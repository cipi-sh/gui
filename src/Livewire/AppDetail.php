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
use CipiGui\Support\Clock;
use CipiGui\Support\DeployLog;
use CipiGui\Support\PhpVersions;
use CipiGui\Support\Presets;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
class AppDetail extends Component
{
    use InteractsWithCipiServer {
        client as protected serverClient;
    }
    use ManagesAsyncJobs;

    /** The lists the panel ships with; what the tabs show is Presets::get(), which an admin can edit. */
    public const ARTISAN_PRESETS = Presets::DEFAULTS[Presets::ARTISAN];

    public const RUN_PRESETS = Presets::DEFAULTS[Presets::RUN];

    /**
     * The app this page is about, from the route. Locked: Livewire applies what
     * the browser sends to every public property, and with this one writable a
     * request could point an open page at another app of the server (4.2.0).
     */
    #[Locked]
    public string $appName;

    public ?array $app = null;

    /** @var array<int, string> */
    public array $aliases = [];

    public bool $loading = true;

    #[Url(as: 'tab', except: 'overview')]
    public string $activeTab = 'overview';

    /**
     * What the signed-in user may do on this app (view, deploy, edit, env, logs,
     * run, delete) — for the view, which shows or hides tabs and buttons with it.
     * Locked, and read again from the grants on every request (hydrate()): it is
     * never the authority. Actions ask Access through allowed(), every call to
     * the server goes through client().
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $can = [];

    // Edit form
    public string $editPhp = '';

    public string $editBranch = '';

    /** Environment label (`env`, API 1.36+). */
    public string $editEnv = AppEnv::DEFAULT;

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

    /** @var list<string> The installed versions the app can be moved to (legacy ones left out). */
    public array $installedPhpVersions = [];

    /** @var list<string> Installed too, and not assignable: 7.4–8.2 on a server set up before Cipi 4.5.4. */
    public array $legacyPhpVersions = [];

    public bool $phpListUnsupported = false;

    // Aliases / WWW
    public string $newAlias = '';

    public ?array $wwwStatus = null;

    public bool $wwwUnsupported = false;

    // Basic auth
    public ?array $basicAuth = null;

    /** cipi/agent routes and basic auth (API 1.41+): {mode, public, prefix}; null when unknown. */
    public ?array $basicAuthAgent = null;

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

    /** @var array<int, int> index in $auditRecords → seconds that deploy took, from the deploy log banners (Cipi 5.6+) */
    public array $auditTook = [];

    /** The server has no `cipi deploy --history` (before API 1.39 / Cipi 5.9.0): durations come from deploy.log alone. */
    public bool $deployHistoryUnsupported = false;

    // Deploy in progress (API 1.37+ / Cipi ≥ 5.8.4): `cipi deploy --status`, polled while the page is open
    /** Lines of the deploy log asked for with every status read, shown live under the banner. */
    public const DEPLOY_TAIL_LINES = 15;

    /** @var array<string, mixed>|null the last status read (its tail kept apart); null before the first read */
    public ?array $deployStatus = null;

    /** The route is missing (older API), the token lacks deploy-manage, or the server's Cipi has no --status: nothing is shown or polled. */
    public bool $deployStatusUnsupported = false;

    /** @var list<string> the last lines of the deploy log while a deploy runs, waits or was left interrupted */
    public array $deployTail = [];

    // One deploy's lines of the deploy log, opened from the audit table
    public ?int $auditLogIndex = null;

    /** @var list<string> */
    public array $auditLogLines = [];

    /** The deploy is older than what the panel reads of the log (the last two pages of 1000 lines). */
    public bool $auditLogMissing = false;

    // cipi.yml (API 1.35+ / Cipi ≥ 5.6.4)
    /** @var array{app: string, file: ?string, auto: bool, valid: ?bool, in_sync: ?bool, errors: list<string>, warnings: list<string>, changes: list<array{kind: string, summary: string}>, blockers: list<string>, notes: list<string>}|null */
    public ?array $yml = null;

    /** null, or why the tab cannot be shown: `ability` (token lacks yml-view) or `server` (API or Cipi too old) */
    public ?string $ymlUnsupported = null;

    /** The server's own message when it answered 503 (Cipi too old, or the command failed). */
    public ?string $ymlUnsupportedDetail = null;

    /** Whether the sudo rule for webhook deploys is in place, as reported by the last auto-apply toggle. */
    public ?bool $ymlSudoers = null;

    /** @var array{outcome: string, applied: int, failed: int, failures: list<string>, blockers: list<string>, errors: list<string>}|null */
    public ?array $ymlLastApply = null;

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
        $this->refreshAbilities();

        // What the page asks the server before it can render goes out together
        // (CipiApiClient::prefetch): the deploy status with the overview's lists.
        $this->prefetchDeployStatus = true;
        try {
            $this->loadApp();

            if ($this->app !== null && ! array_key_exists($this->activeTab, $this->tabs())) {
                $this->activeTab = 'overview';
            }

            if ($this->app !== null && $this->activeTab !== 'overview') {
                $this->loadTab($this->activeTab);
            }

            // A deploy may be running as the page opens: say so at once, not at the first poll.
            if ($this->app !== null) {
                $this->pollDeployStatus(true);
            }
        } finally {
            $this->prefetchDeployStatus = false;
            CipiApiClient::forgetPrefetched();
        }
    }

    /** Set by mount() only (not a public property: the browser cannot reach it). */
    protected bool $prefetchDeployStatus = false;

    /**
     * The GET paths the loaders of the overview are about to ask, for this
     * kind of app — exactly those, so every prefetched answer is taken.
     *
     * @return list<string|array{0: string, 1: array<string, mixed>}>
     */
    protected function overviewPaths(): array
    {
        $paths = ['/php', '/node', "/apps/{$this->appName}/health"];
        if ($this->isNodeApp($this->app ?? [])) {
            $paths[] = "/apps/{$this->appName}/node";
        }
        if ($this->isLaravelApp($this->app ?? [])) {
            $paths[] = '/search';
        }
        if ($this->prefetchDeployStatus) {
            $paths[] = ["/apps/{$this->appName}/deploy/status", ['tail' => self::DEPLOY_TAIL_LINES]];
        }
        if (($this->app['engine'] ?? '') === 'mariadb' && $this->serverId !== null
            && ! \Illuminate\Support\Facades\Cache::has('cipi-gui.mysql-server.'.$this->serverId)) {
            $paths[] = '/dbs/engines'; // MariaDB or MySQL (ensureEngineServers)
        }

        return $paths;
    }

    /**
     * Every request after the first. The page's state comes back from the
     * browser: what is signed (the snapshot) is what the server last sent, but a
     * grant may have changed since. The abilities are read again from the
     * grants, and a user who can no longer view the app is out — whatever the
     * page they still have open says.
     */
    public function hydrate(): void
    {
        $this->refreshAbilities();
    }

    /** Abilities of the signed-in user on this app, as granted right now; 403 without `view`. */
    protected function refreshAbilities(): void
    {
        $this->can = Access::appAbilityMap($this->panelUser(), (int) ($this->serverId ?? 0), $this->appName);

        if (! ($this->can['view'] ?? false)) {
            abort(403, 'Your access does not include this app.');
        }
    }

    /**
     * The one way this page reaches a server: the panel's own token does the
     * work, so nothing is sent for an app the signed-in user may not view on
     * the server in use — asked of Access at the moment of the call, not taken
     * from the page.
     */
    protected function client(): CipiApiClient
    {
        if (! Access::can($this->panelUser(), 'view', (int) ($this->serverId ?? 0), $this->appName)) {
            abort(403, 'Your access does not include this app.');
        }

        return $this->serverClient();
    }

    public function loadApp(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $data = $this->client()->showApp($this->appName);
            if (! isset($data['app'])) {
                throw new CipiApiException("App '{$this->appName}' was not found on this server.", 404);
            }
            $this->app = $this->normalizeApp($data);
            $this->aliases = $this->app['aliases'];
            $this->editPhp = $this->app['php'] !== '' ? $this->app['php'] : '8.5';
            $this->editBranch = $this->app['branch'];
            $this->editEnv = $this->app['env'];
            $this->editRepository = $this->app['repository'];
            $this->editDomain = $this->app['domain'];
            $this->editNodeMode = (string) ($this->app['node_mode'] ?? '');
            $this->editNodeVersion = (string) ($this->app['node_version'] ?? '');
            // Asked at once instead of one after the other: each is a `sudo cipi …`
            // on the server, and the page waited for their sum.
            $this->client()->prefetch($this->overviewPaths());
            if (($this->app['engine'] ?? '') === 'mariadb') {
                // MariaDB or MySQL on port 3306 (Cipi 5.13.0): known once per server.
                $this->ensureEngineServers();
            }
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
            if (! $this->prefetchDeployStatus) {
                CipiApiClient::forgetPrefetched();
            }
        }
    }

    /** @return array<string, string> */
    public function tabs(): array
    {
        $app = $this->app ?? [];
        $isLaravel = $this->isLaravelApp($app);
        $isNode = $this->isNodeApp($app);

        $can = fn (string $ability) => $this->can[$ability] ?? false;

        $tabs = ['overview' => 'Overview'];
        if ($can('view')) {
            // A component of its own (Livewire\Metrics) reads, polls and draws it.
            $tabs['metrics'] = 'Metrics';
        }
        $tabs += [
            'domains' => 'Domains & SSL',
            'routing' => 'Routing',
            'deploy' => 'Deploy',
            'yml' => 'cipi.yml',
        ];
        if ($isLaravel && $can('env')) {
            $tabs['env'] = 'Environment';
        }
        if (! $isNode && $can('env')) {
            $tabs['authjson'] = 'Composer auth';
        }
        if ($isLaravel && $can('run')) {
            $tabs['artisan'] = 'Artisan';
        }
        if ($can('run')) {
            $tabs['run'] = 'Commands';
        }
        if ($can('edit')) {
            $tabs['access'] = 'Access';
        }
        if ($can('logs')) {
            $tabs['logs'] = 'Logs';
        }

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
            'yml' => $this->loadYml(),
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
        $this->legacyPhpVersions = [];
        $fallback = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);

        try {
            $data = $this->client()->listPhp();
            // The app keeps a legacy version it already runs on (the form shows
            // it as the current one); no app can be moved to one.
            $versions = PhpVersions::assignable($data);
            $this->legacyPhpVersions = PhpVersions::legacy($data);
            $this->installedPhpVersions = $versions !== [] || $this->legacyPhpVersions !== [] ? $versions : $fallback;
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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

        $isNode = $this->isNodeApp($this->app ?? []);
        $this->editEnv = trim($this->editEnv);

        $rules = [
            'editBranch' => ['nullable', 'string', 'max:64'],
            'editEnv' => ['required', 'string', 'max:'.AppEnv::MAX_LENGTH, AppEnv::rule()],
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

        $this->validate($rules, [
            'editDomain.regex' => 'Enter a hostname such as app.example.com or *.example.com.',
            'editEnv.regex' => AppEnv::message(),
            'editEnv.max' => AppEnv::message(),
        ]);

        // Unchanged is always fine — an app on a legacy version keeps it while
        // its branch or domain is edited. A change has to be to a version the
        // server has and still gives to apps.
        $phpChanged = ! $isNode && $this->editPhp !== (string) $this->app['php'];
        if ($phpChanged && ($this->installedPhpVersions !== [] || $this->legacyPhpVersions !== []) && ! in_array($this->editPhp, $this->installedPhpVersions, true)) {
            $pick = $this->installedPhpVersions !== [] ? 'pick: '.implode(', ', $this->installedPhpVersions) : 'install a current version from Server → PHP';
            $this->addError('editPhp', in_array($this->editPhp, $this->legacyPhpVersions, true)
                ? 'PHP '.$this->editPhp.' is a legacy version: an app cannot be moved to it. '.ucfirst($pick).'.'
                : 'PHP '.$this->editPhp.' is not installed on this server. Install it from Server → PHP, or '.$pick.'.');

            return;
        }

        $payload = [];
        if (! $isNode && $this->editPhp !== '' && $this->editPhp !== (string) $this->app['php']) {
            $payload['php'] = $this->editPhp;
        }
        if ($this->editBranch !== (string) $this->app['branch']) {
            $payload['branch'] = $this->editBranch;
        }
        if ($this->editEnv !== (string) $this->app['env']) {
            $payload['env'] = $this->editEnv;
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
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Suspend '.$this->appName, fn (CipiApiClient $api) => $api->suspendApp($this->appName));
    }

    public function unsuspendApp(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Unsuspend '.$this->appName, fn (CipiApiClient $api) => $api->unsuspendApp($this->appName));
    }

    public function fixPermissions(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Fix permissions', fn (CipiApiClient $api) => $api->fixPermissions($this->appName));
    }

    public function restartNode(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Restart Node (blue/green)', fn (CipiApiClient $api) => $api->nodeRestart($this->appName));
    }

    public function recreateWebhook(bool $rotateSecret = false): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob(
            $rotateSecret ? 'Recreate webhook + rotate secret' : 'Recreate webhook',
            fn (CipiApiClient $api) => $api->recreateWebhook($this->appName, $rotateSecret),
        );
    }

    public function deploy(): void
    {
        if (! $this->allowed('deploy')) {
            return;
        }

        $this->startJob('Deploy '.$this->appName, fn (CipiApiClient $api) => $api->deploy($this->appName));
    }

    public function rollback(): void
    {
        if (! $this->allowed('deploy')) {
            return;
        }

        $this->startJob('Roll back '.$this->appName, fn (CipiApiClient $api) => $api->deployRollback($this->appName));
    }

    public function unlockDeploy(): void
    {
        if (! $this->allowed('deploy')) {
            return;
        }

        $this->startJob($this->pausedCommit() !== null ? 'Resume automatic deploys' : 'Unlock deploy', fn (CipiApiClient $api) => $api->deployUnlock($this->appName));
    }

    /**
     * The commit whose automatic deploy failed (Cipi 5.13.0): the webhook does
     * not deploy it again. A new push deploys as usual; a deploy from here that
     * succeeds, or unlockDeploy(), resumes it.
     */
    public function pausedCommit(): ?string
    {
        $commit = $this->deployStatus['paused_commit'] ?? null;

        return is_string($commit) && preg_match('/^[0-9a-f]{7,64}$/', $commit) ? $commit : null;
    }

    public function installSsl(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob("Let's Encrypt certificate", fn (CipiApiClient $api) => $api->installSsl($this->appName));
    }

    public function forceSsl(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Add www/apex alias', fn (CipiApiClient $api) => $api->wwwAdd($this->appName));
    }

    public function wwwForceToRoot(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Redirect www → apex', fn (CipiApiClient $api) => $api->wwwForceToRoot($this->appName));
    }

    public function wwwForceFromRoot(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Redirect apex → www', fn (CipiApiClient $api) => $api->wwwForceFromRoot($this->appName));
    }

    public function wwwClear(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->startJob('Clear www redirect', fn (CipiApiClient $api) => $api->wwwClear($this->appName));
    }

    public function addAlias(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

        $enabled = (bool) ($this->appRedirect['enabled'] ?? false);

        $this->routingCall(
            fn (CipiApiClient $api) => $this->syncRedirects($enabled ? $api->disableAppRedirect($this->appName) : $api->enableAppRedirect($this->appName)),
            $enabled ? 'Redirect disabled — the app serves traffic again.' : 'Redirect enabled.',
        );
    }

    public function removeAppRedirect(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->routingCall(function (CipiApiClient $api) {
            $this->syncRedirects($api->unsetAppRedirect($this->appName));
            $this->redirectTo = '';
            $this->redirectCode = '301';
            $this->redirectKeepPath = true;
        }, 'Whole-app redirect removed.');
    }

    public function addPathRedirect(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

        $this->routingCall(fn (CipiApiClient $api) => $this->syncRedirects($api->removePathRedirect($this->appName, $from)), 'Path redirect removed.');
    }

    public function addProxy(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

        $this->dcNodeBuild = trim($this->dcNodeBuild);
        $this->validate([
            'dcKeepReleases' => ['required', 'integer', 'min:1', 'max:20'],
            'dcNodeBuild' => ['nullable', 'string', BuildCommand::rule()],
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
            $this->auditTook = $this->auditRecords === [] ? [] : DeployLog::match($this->auditRecords, $this->deployLogBanners());
            $this->auditLoaded = true;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->auditUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    /**
     * How long each deploy took. The ledger has no duration of its own; Cipi
     * 5.6+ writes one per deploy to the deploy log, in its end banner.
     *
     * On API 1.39+ / Cipi 5.9.0+ the banners come from `cipi deploy --history`,
     * which reads the rotated logs too. Before that the panel read deploy.log
     * itself — and that file is rotated every night, so a deploy kept its time
     * until the first rotation only: one started by a Git push, looked at the
     * day after, never showed any. That reading is still what an older server
     * gets (the most recent 1000 lines of today's file).
     *
     * @return list<array{event: string, release: string, seconds: int}>
     */
    protected function deployLogBanners(): array
    {
        return $this->deployHistoryBanners() ?? DeployLog::banners($this->deployLogPages()['lines']);
    }

    /** @return list<array{event: string, release: string, seconds: int}>|null null when the server has no deploy history */
    protected function deployHistoryBanners(): ?array
    {
        if ($this->deployHistoryUnsupported) {
            return null;
        }

        try {
            $runs = $this->client()->deployHistory($this->appName, 500);
        } catch (CipiApiException) {
            $runs = null;
        }

        if ($runs === null) {
            $this->deployHistoryUnsupported = true;

            return null;
        }

        return DeployLog::bannersFromRuns($runs);
    }

    /**
     * The newest lines of the deploy log, oldest first: page 1 of the API's log
     * reader is the last 1000 lines, page 2 the 1000 before those. $pages > 1 reads
     * further back when the log has that much.
     *
     * @return array{lines: list<string>, total_pages: int}
     */
    protected function deployLogPages(int $pages = 1): array
    {
        $lines = [];
        $totalPages = 1;

        for ($page = 1; $page <= $pages && $page <= $totalPages; $page++) {
            try {
                $data = $this->client()->appLogs($this->appName, ['type' => 'deploy', 'per_page' => 1000, 'page' => $page]);
            } catch (CipiApiException) {
                break;
            }

            $older = [];
            foreach ((array) ($data['files'] ?? []) as $file) {
                if (! is_array($file) || ! is_array($file['lines'] ?? null)) {
                    continue;
                }
                $totalPages = max($totalPages, (int) ($file['total_pages'] ?? 1));
                foreach ($file['lines'] as $line) {
                    if (is_string($line)) {
                        $older[] = $line;
                    }
                }
            }
            $lines = array_merge($older, $lines);
        }

        return ['lines' => $lines, 'total_pages' => $totalPages];
    }

    /**
     * The lines of one deploy of the audit table — from its start banner to the
     * post-deploy steps, cipi.yml apply and healthcheck verdict that follow its end
     * banner — in a modal. The last 1000 lines of the log usually hold it; when
     * they do not, the 1000 before them are read too.
     */
    public function showAuditLog(int $index): void
    {
        if (! $this->allowed('logs')) {
            return;
        }
        if (! isset($this->auditRecords[$index])) {
            return;
        }

        $this->auditLogIndex = $index;
        $this->auditLogLines = [];
        $this->auditLogMissing = false;

        $pages = $this->deployLogPages();
        $sections = DeployLog::sections($pages['lines']);
        $matched = DeployLog::matchIndexes($this->auditRecords, $sections);
        if (! isset($matched[$index]) && $pages['total_pages'] > 1) {
            $pages = $this->deployLogPages(2);
            $sections = DeployLog::sections($pages['lines']);
            $matched = DeployLog::matchIndexes($this->auditRecords, $sections);
        }

        if (isset($matched[$index])) {
            $this->auditLogLines = DeployLog::slice($pages['lines'], $sections[$matched[$index]]);
        } else {
            $this->auditLogMissing = true;
        }
    }

    public function closeAuditLog(): void
    {
        $this->auditLogIndex = null;
        $this->auditLogLines = [];
        $this->auditLogMissing = false;
    }

    /**
     * The last published deploy's time and the average over the loaded ledger.
     *
     * @return array{last: int, average: int, count: int}|null
     */
    public function deployTimes(): ?array
    {
        $seconds = [];
        foreach ($this->auditRecords as $index => $record) {
            if (($record['event'] ?? '') === 'published' && isset($this->auditTook[$index])) {
                $seconds[] = $this->auditTook[$index];
            }
        }

        if ($seconds === []) {
            return null;
        }

        return ['last' => $seconds[0], 'average' => (int) round(array_sum($seconds) / count($seconds)), 'count' => count($seconds)];
    }

    // ── Deploy in progress — whoever started it ───────────────────────

    /**
     * `cipi deploy <app> --status` through the API (1.37+ / Cipi ≥ 5.8.4): whether a
     * deploy is running right now — started by the panel, `cipi deploy`, a Git push
     * through the webhook or a rollback — waiting for the webhook's per-minute
     * cron, or left interrupted. Polled while the page is open. When a run the
     * panel did not start ends, the page says how it went and reloads as it does
     * after one of its own jobs. $quiet reads the status without reporting a run
     * that ended (the page opening; a panel job that just reported it itself).
     */
    public function pollDeployStatus(bool $quiet = false): void
    {
        if ($this->deployStatusUnsupported || $this->app === null) {
            return;
        }

        try {
            $status = $this->client()->deployStatus($this->appName, self::DEPLOY_TAIL_LINES);
        } catch (CipiApiException $e) {
            // No route (older API), a token without deploy-manage, or a 503 from a
            // server whose Cipi has no --status yet: the feature is simply off. A
            // connection failure is transient and keeps what was shown.
            if ($this->isUnsupported($e) || ($e->getStatusCode() === 503 && ! isset($e->getDetails()['connection']))) {
                $this->deployStatusUnsupported = true;
                $this->deployStatus = null;
                $this->deployTail = [];
            }

            return;
        }

        $previous = $this->deployStatus;
        $tail = [];
        foreach ((array) ($status['tail'] ?? []) as $line) {
            if (is_string($line)) {
                $tail[] = $line;
            }
        }
        unset($status['tail']);

        $this->deployStatus = $status;
        $this->deployTail = $this->deployBanner() !== null ? $tail : [];

        if (! $quiet && $previous !== null && self::deployActiveIn($previous) && ! self::deployActiveIn($status)) {
            $this->onExternalDeployFinished($previous, $status);
        }
    }

    /** A deploy is running or about to: the buttons that would collide with it are off. */
    public function deployActive(): bool
    {
        return $this->deployStatus !== null && self::deployActiveIn($this->deployStatus);
    }

    /** @param  array<string, mixed>  $status */
    protected static function deployActiveIn(array $status): bool
    {
        return in_array($status['state'] ?? null, ['running', 'queued'], true);
    }

    /**
     * What the banner under the header says while a deploy runs, waits or was left
     * interrupted; null when idle or unknown.
     *
     * @return array{state: string, variant: string, title: string, detail: string, started_text: string, trigger: ?string, branch: string, from_release: string, started: ?Carbon, elapsed: ?int}|null
     */
    public function deployBanner(): ?array
    {
        $status = $this->deployStatus;
        if ($status === null) {
            return null;
        }

        $state = (string) ($status['state'] ?? 'idle');
        if (! in_array($state, ['running', 'queued', 'interrupted'], true)) {
            return null;
        }

        $current = is_array($status['current'] ?? null) ? $status['current'] : [];
        $trigger = is_string($current['trigger'] ?? null) && $current['trigger'] !== '' ? $current['trigger'] : null;
        $by = DeployLog::triggerLabel($trigger);
        $branch = (string) ($current['branch'] ?? '');
        $from = (string) ($current['from_release'] ?? '');
        $started = null;
        if (is_string($current['started_at'] ?? null) && $current['started_at'] !== '') {
            try {
                $started = Clock::local($current['started_at']);
            } catch (\Throwable) {
                $started = null;
            }
        }
        $elapsed = is_numeric($current['elapsed'] ?? null) ? max(0, (int) $current['elapsed']) : null;

        $title = match ($state) {
            'queued' => 'Deploy queued — a push was received',
            'interrupted' => 'The last deploy did not finish — started by '.$by,
            default => ($trigger === 'rollback' ? 'Rolling back' : 'Deploying').' — started by '.$by,
        };
        $detail = match (true) {
            $state === 'queued' => 'The webhook accepted it; the per-minute cron on the server starts it within a minute.',
            $state === 'interrupted' => 'Nothing is running it any more — a reboot, a killed process. Check the log below; if the lock it left behind is stuck, unlock it.',
            ($status['phase'] ?? null) === 'post-deploy' => 'The new release is live. The post-deploy steps, cipi.yml and the healthcheck are running.',
            default => 'Deployer is building the release: checkout, dependencies, build, migrations.'
                .($from !== '' && $from !== 'none' ? " Release {$from} stays live until the new one is ready." : ''),
        };

        $startedText = '';
        if ($started !== null) {
            $startedText = 'Started '.$started->format('H:i:s').($state === 'interrupted' ? ' on '.$started->format('Y-m-d') : '').'.';
        }

        return [
            'state' => $state,
            'variant' => $state === 'interrupted' ? 'warn' : 'info',
            'title' => $title,
            'detail' => $detail,
            'started_text' => $startedText,
            'trigger' => $trigger,
            'branch' => $branch,
            'from_release' => $from,
            'started' => $started,
            'elapsed' => $elapsed,
        ];
    }

    /**
     * A run the status was following has ended: a toast with the outcome, unless
     * the panel's own job for it is reporting it, then the same refresh as after a
     * finished job — the live release, the ledger, the tab in view, the Logs tab.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $status
     */
    protected function onExternalDeployFinished(array $previous, array $status): void
    {
        $current = is_array($previous['current'] ?? null) ? $previous['current'] : [];
        $last = is_array($status['last'] ?? null) ? $status['last'] : [];
        $trigger = (string) ($current['trigger'] ?? $last['trigger'] ?? '');

        if (! $this->panelJobReportsThisDeploy($trigger)) {
            [$type, $message] = $this->deployOutcomeMessage($status, $trigger);
            $this->dispatch('notify', type: $type, message: $message);
        }

        $this->auditLoaded = false;
        $this->loadApp();
        match ($this->activeTab) {
            'deploy' => $this->loadDeployTab(),
            'yml' => $this->loadYml(),
            default => null,
        };
        $this->dispatch('app-job-finished');
    }

    /**
     * The panel started this run itself and its job is still open here: the job's
     * own toast covers it, a second one would say the same thing twice.
     */
    protected function panelJobReportsThisDeploy(string $trigger): bool
    {
        if ($this->activeJobId === null) {
            return false;
        }

        return ($trigger === 'panel' && str_starts_with($this->jobLabel, 'Deploy '))
            || ($trigger === 'rollback' && str_starts_with($this->jobLabel, 'Roll back '));
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{0: string, 1: string} toast type and message
     */
    protected function deployOutcomeMessage(array $status, string $trigger): array
    {
        $last = is_array($status['last'] ?? null) ? $status['last'] : [];
        $by = DeployLog::triggerLabel($trigger !== '' ? $trigger : (is_string($last['trigger'] ?? null) ? $last['trigger'] : null));

        if (($status['state'] ?? '') === 'interrupted') {
            return ['error', "The deploy started by {$by} did not finish — nothing is running it any more. Check the deploy log; unlock if the lock is stuck."];
        }

        $kind = ($last['kind'] ?? 'deploy') === 'rollback' ? 'Rollback' : 'Deploy';
        $release = (string) ($last['release'] ?? '');
        $took = is_numeric($last['duration'] ?? null) ? ' in '.DeployLog::format((int) $last['duration']) : '';

        if (($last['result'] ?? '') === 'ok') {
            return ['success', "{$kind} by {$by} finished{$took}".($release !== '' && $release !== '?' ? ": release {$release} is live." : '.')];
        }

        return ['error', "{$kind} by {$by} failed{$took}".($release !== '' && $release !== '?' ? " — release {$release} is still live." : '.')];
    }

    // ── cipi.yml: plan, apply, auto-apply ─────────────────────────────

    public function loadYml(): void
    {
        $this->ymlUnsupported = null;
        $this->ymlUnsupportedDetail = null;

        try {
            $this->yml = $this->client()->ymlStatus($this->appName);
        } catch (CipiApiException $e) {
            if ($reason = $this->ymlUnsupportedReason($e)) {
                $this->yml = null;
                $this->ymlUnsupported = $reason;
                $this->ymlUnsupportedDetail = $reason === 'server' && $e->getStatusCode() === 503 ? $e->getMessage() : null;

                return;
            }

            $this->handleApiError($e);
        }
    }

    /** Apply is pointless without a valid file, when nothing differs, or while something blocks it. */
    public function ymlCanApply(): bool
    {
        return $this->yml !== null
            && $this->yml['file'] !== null
            && $this->yml['valid'] === true
            && $this->yml['in_sync'] !== true
            && $this->yml['blockers'] === [];
    }

    public function applyYml(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        if (! $this->ymlCanApply()) {
            $this->dispatch('notify', type: 'info', message: 'Nothing to apply.');

            return;
        }

        try {
            $this->dispatchJob($this->client()->ymlApply($this->appName), 'Apply cipi.yml');
        } catch (CipiApiException $e) {
            $this->handleYmlManageError($e);
        }
    }

    public function enableYmlAuto(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->setYmlAuto(true);
    }

    public function disableYmlAuto(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

        $this->setYmlAuto(false);
    }

    protected function setYmlAuto(bool $enabled): void
    {
        try {
            $data = $enabled ? $this->client()->ymlAutoEnable($this->appName) : $this->client()->ymlAutoDisable($this->appName);
            $this->ymlSudoers = is_bool($data['sudoers'] ?? null) ? $data['sudoers'] : null;
            $this->dispatch('notify', type: 'success', message: $enabled
                ? 'Auto-apply on — cipi.yml is applied after every successful deploy.'
                : 'Auto-apply off — deploys no longer apply cipi.yml.');
            $this->loadYml();
        } catch (CipiApiException $e) {
            $this->handleYmlManageError($e);
        }
    }

    /** `ability` when the token lacks the ability, `server` when the API or Cipi is too old; null otherwise. */
    protected function ymlUnsupportedReason(CipiApiException $e): ?string
    {
        return match (true) {
            $e->getStatusCode() === 403 && ! str_contains($e->getMessage(), 'IP not allowed') => 'ability',
            in_array($e->getStatusCode(), [404, 405, 501], true) => 'server',
            // 503 is also what the client raises when the server cannot be reached.
            $e->getStatusCode() === 503 && ! isset($e->getDetails()['connection']) => 'server',
            default => null,
        };
    }

    protected function handleYmlManageError(CipiApiException $e): void
    {
        $message = match ($this->ymlUnsupportedReason($e)) {
            'ability' => 'This token lacks the yml-manage ability (403). Recreate it from Connections with yml-manage to apply cipi.yml and turn auto-apply on or off.',
            'server' => rtrim($e->getStatusCode() === 503 ? $e->getMessage() : 'Not available on this server', '.').' — cipi.yml from the panel requires Cipi 5.6.4+ and cipi/api 1.35+ (cipi self-update && cipi api update).',
            default => $this->friendlyApiError($e),
        };

        $this->error = $message;
        $this->dispatch('notify', type: 'error', message: $message);
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
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('edit')) {
            return;
        }

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
            // An API before 1.43 on Cipi 5.11–5.12 answers the status as an error
            // (the command exited 1 after printing it): the state is in its text.
            $state = self::basicAuthStateFromCliText($e->getMessage());
            if ($state !== null) {
                $this->basicAuth = $state;
            } else {
                $this->handleApiError($e);
            }
        }
        $this->loadBasicAuthAgent();
    }

    /**
     * Whether the cipi/agent routes stay outside basic auth (Cipi 5.11.0). An
     * older API has no such route: the card then says nothing about it.
     */
    protected function loadBasicAuthAgent(): void
    {
        try {
            $this->basicAuthAgent = $this->client()->basicAuthAgent($this->appName);
        } catch (CipiApiException) {
            $this->basicAuthAgent = null;
        }
    }

    public function setBasicAuthAgent(string $mode): void
    {
        if (! $this->allowed('edit') || ! in_array($mode, ['public', 'protected'], true)) {
            return;
        }

        try {
            $this->basicAuthAgent = $this->client()->setBasicAuthAgent($this->appName, $mode);
            $this->dispatch('notify', type: 'success', message: $mode === 'public'
                ? 'cipi/agent routes are left out of basic auth when the release has the agent.'
                : 'cipi/agent routes stay behind basic auth.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function enableBasicAuth(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
            if (is_string($result['notice'] ?? null) && $result['notice'] !== '') {
                $this->dispatch('notify', type: 'warning', message: $result['notice']);
            } else {
                $this->dispatch('notify', type: 'success', message: 'Basic auth enabled.');
            }
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableBasicAuth(): void
    {
        if (! $this->allowed('edit')) {
            return;
        }

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
        if (! $this->allowed('env')) {
            return;
        }

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
        if (! $this->allowed('env')) {
            return;
        }

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

    /**
     * Move a row of the .env (drag and drop, or the up/down buttons). Saved
     * with the other changes: the order goes with them (API 1.43 / Cipi 5.13).
     */
    public function moveEnvRow(int $from, int $to): void
    {
        if (! $this->allowed('env')) {
            return;
        }
        $count = count($this->envRows);
        if ($from < 0 || $from >= $count || $to < 0 || $to >= $count || $from === $to) {
            return;
        }
        $rows = $this->envRows;
        [$row] = array_splice($rows, $from, 1);
        array_splice($rows, $to, 0, [$row]);
        $this->envRows = array_values($rows);
    }

    /**
     * Move the row of $key to where the row of $target is (drag and drop, the
     * arrow keys): keys, not positions, so a page drawn before the last change
     * can never move or overwrite the wrong row.
     */
    public function moveEnvKey(string $key, string $target): void
    {
        $keys = array_column($this->envRows, 'key');
        $from = array_search($key, $keys, true);
        $to = array_search($target, $keys, true);
        if ($from === false || $to === false) {
            return;
        }
        $this->moveEnvRow((int) $from, (int) $to);
    }

    /** True when the rows are not in the order of the file (keys added or removed aside). */
    public function envOrderChanged(): bool
    {
        $current = [];
        foreach ($this->envRows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '' && array_key_exists($key, $this->envOriginal)) {
                $current[] = $key;
            }
        }
        $original = array_values(array_intersect(array_keys($this->envOriginal), $current));

        if ($current !== $original) {
            return true;
        }

        // A new key placed anywhere but at the end, where --set puts it.
        $seenNew = false;
        foreach ($this->envRows as $row) {
            $isNew = ! array_key_exists((string) ($row['key'] ?? ''), $this->envOriginal);
            if ($isNew) {
                $seenNew = true;
            } elseif ($seenNew) {
                return true;
            }
        }

        return false;
    }

    /** Remove the row of $key (by key: see moveEnvKey()). */
    public function removeEnvKey(string $key): void
    {
        $index = array_search($key, array_column($this->envRows, 'key'), true);
        if ($index !== false) {
            $this->removeEnvRow((int) $index);
        }
    }

    public function removeEnvRow(int $index): void
    {
        if (! $this->allowed('env')) {
            return;
        }

        if (isset($this->envRows[$index])) {
            unset($this->envRows[$index]);
            $this->envRows = array_values($this->envRows);
        }
    }

    public function resetEnv(): void
    {
        if (! $this->allowed('env')) {
            return;
        }

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

        return count($diff['set']) + count($diff['unset']) + ($this->envOrderChanged() ? 1 : 0);
    }

    public function saveEnv(): void
    {
        if (! $this->allowed('env')) {
            return;
        }

        ['set' => $set, 'unset' => $unset] = $this->envDiff();
        $order = null;
        if ($this->envOrderChanged()) {
            $order = [];
            foreach ($this->envRows as $row) {
                $key = trim((string) ($row['key'] ?? ''));
                if ($key !== '') {
                    $order[] = $key;
                }
            }
        }

        if ($set === [] && $unset === [] && $order === null) {
            $this->dispatch('notify', type: 'info', message: 'No .env changes to save.');

            return;
        }

        try {
            $data = $this->client()->updateEnv($this->appName, $set, $unset, $order);
            $this->syncEnvRowsFromVars(is_array($data['vars'] ?? null) ? $data['vars'] : []);
            $changes = count($set) + count($unset) + ($order !== null ? 1 : 0);
            if ($order !== null && array_keys($this->envOriginal) !== $order) {
                // An API before 1.43 takes the keys and drops the order.
                $this->dispatch('notify', type: 'warning', message: '.env saved, but this server did not keep the new order — it needs API 1.43 and Cipi 5.13 (cipi self-update && cipi api update).');
            } else {
                $this->dispatch('notify', type: 'success', message: '.env saved ('.$changes.' '.\Illuminate\Support\Str::plural('change', $changes).'). Deploy or run config:cache to apply.');
            }
        } catch (CipiApiException $e) {
            if ($order !== null && $set === [] && $unset === [] && $e->getStatusCode() === 422 && ! str_contains(strtolower($e->getMessage()), 'order')) {
                // Only a new order, refused by an API before 1.43 ("set or unset").
                $this->dispatch('notify', type: 'warning', message: 'Reordering the .env needs API 1.43 and Cipi 5.13 on this server (cipi self-update && cipi api update).');

                return;
            }
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
        if (! $this->allowed('env')) {
            return;
        }

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
        if (! $this->allowed('env')) {
            return;
        }

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
        if (! $this->allowed('env')) {
            return;
        }

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

    // The one-click commands above each input: a list for the whole panel,
    // which its admins edit here (Support\Presets) — the defaults included.

    /** Which list is being edited: '' (none), 'artisan' or 'run'. */
    public string $presetEditing = '';

    public string $presetDraft = '';

    public function editPresets(string $kind): void
    {
        if (! Access::isAdmin($this->panelUser()) || ! Presets::isKind($kind)) {
            return;
        }

        $this->resetErrorBag('presetDraft');
        $this->presetDraft = '';
        $this->presetEditing = $this->presetEditing === $kind ? '' : $kind;
    }

    /** Save the command in the box below the list — or, with nothing typed there, the one in the tab's input. */
    public function addPreset(string $kind): void
    {
        if (! $this->presetsAllowed($kind)) {
            return;
        }

        $command = trim($this->presetDraft) !== '' ? $this->presetDraft : ($kind === Presets::ARTISAN ? $this->artisanCommand : $this->runCommand);
        if ($error = Presets::error($kind, $command)) {
            $this->addError('presetDraft', $error);

            return;
        }

        $this->resetErrorBag('presetDraft');
        Presets::add($kind, $command);
        $this->presetDraft = '';
        $this->dispatch('notify', type: 'success', message: 'Saved to the list — for every app and everyone on the panel.');
    }

    public function removePreset(string $kind, string $command): void
    {
        if (! $this->presetsAllowed($kind)) {
            return;
        }

        Presets::remove($kind, $command);
    }

    public function resetPresets(string $kind): void
    {
        if (! $this->presetsAllowed($kind)) {
            return;
        }

        Presets::reset($kind);
        $this->dispatch('notify', type: 'success', message: 'The list is back to the panel\'s defaults.');
    }

    /** Editing the lists is for admins of the panel: they are shared by every app and every user. */
    protected function presetsAllowed(string $kind): bool
    {
        if (! Presets::isKind($kind)) {
            return false;
        }
        if (! Access::isAdmin($this->panelUser())) {
            $this->denied();

            return false;
        }

        return true;
    }

    public function runArtisan(): void
    {
        if (! $this->allowed('run')) {
            return;
        }

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
        if (! $this->allowed('run')) {
            return;
        }

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
        if (! $this->allowed('delete')) {
            return;
        }

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
        if (! $this->allowed('delete')) {
            return;
        }

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

        $this->rememberYmlApply($data);
        // The ledger is read again when the Deploy tab is next shown: a deploy
        // started from the header while another tab was open changed it too.
        $this->auditLoaded = false;
        $this->loadApp();

        match ($this->activeTab) {
            'domains' => $this->loadWwwStatus(),
            'routing' => $this->loadRouting(),
            'deploy' => $this->loadDeployTab(),
            'yml' => $this->loadYml(),
            default => null,
        };

        // The job reported the deploy; the status must not report it a second time.
        $this->pollDeployStatus(true);

        $this->dispatch('app-job-finished'); // the Logs tab is a nested component: it reloads itself
    }

    protected function onJobFailed(array $data): void
    {
        // The ledger records failed deploys too.
        $this->auditLoaded = false;

        // A partial, blocked or invalid apply still fails the job: keep its result
        // and show the plan as it is now (a partial apply changed part of it).
        if ($this->rememberYmlApply($data)) {
            $this->loadApp();
            $this->loadYml();
        }

        // A deploy that published and failed afterwards changed the live
        // release: reload it as a completed deploy would.
        if ($this->jobDeployPublished()) {
            $this->loadApp();
        }

        if ($this->activeTab === 'deploy') {
            $this->loadDeployTab();
        }

        $this->pollDeployStatus(true);

        $this->dispatch('app-job-finished');
    }

    protected function rememberYmlApply(array $data): bool
    {
        if (($data['type'] ?? null) !== 'app-yml-apply') {
            return false;
        }

        $this->ymlLastApply = $this->jobYmlApply();

        return true;
    }

    /**
     * The signed-in user has this ability on the app; a refusal is reported in
     * the page. Asked of Access on every call: `$can` is what the view shows,
     * not what an action may rely on.
     */
    protected function allowed(string $ability): bool
    {
        return $this->appAllowed($ability, (int) ($this->serverId ?? 0), $this->appName);
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

    /**
     * The public address of the server the app runs on, as the panel knows it:
     * typed on Connections, or read from the server's status. null when it is
     * not an address — nothing is built into an `ssh` command from anything else.
     */
    public static function serverIp(?CipiServer $server): ?string
    {
        $ip = trim((string) ($server?->ip ?? ''));

        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** `ssh <user>@<address>` for the app, or null while its user or the server's address is not known. */
    public static function sshCommand(string $user, ?string $ip): ?string
    {
        return $user !== '' && $ip !== null ? "ssh {$user}@{$ip}" : null;
    }

    public function render()
    {
        $server = $this->currentServer();
        $serverIp = self::serverIp($server);

        return view('cipi-gui::livewire.app-detail', [
            'phpVersions' => $this->installedPhpVersions !== [] || $this->legacyPhpVersions !== [] ? $this->installedPhpVersions : config('cipi-gui.php_versions'),
            'legacyPhpVersions' => $this->legacyPhpVersions,
            'server' => $server,
            'serverIp' => $serverIp,
            'sshCommand' => self::sshCommand((string) ($this->app['user'] ?? ''), $serverIp),
            'tabs' => $this->app ? $this->tabs() : [],
            'artisanPresets' => Presets::get(Presets::ARTISAN),
            'runPresets' => Presets::get(Presets::RUN),
            'presetsCustom' => [Presets::ARTISAN => Presets::isCustom(Presets::ARTISAN), Presets::RUN => Presets::isCustom(Presets::RUN)],
            'canEditPresets' => Access::isAdmin($this->panelUser()),
            'isAdmin' => Access::isAdmin($this->panelUser()),
            'envSuggestions' => AppEnv::SUGGESTIONS,
            'envMaxLength' => AppEnv::MAX_LENGTH,
        ])->title($this->appName.($server ? ' · '.$server->name : ''));
    }
}
