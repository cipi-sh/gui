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
class AppDetail extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    public string $appName;

    public ?array $app = null;

    /** @var array<int, string> */
    public array $aliases = [];

    public bool $loading = true;

    public string $activeTab = 'overview';

    // Edit form
    public string $editPhp = '';

    public string $editBranch = '';

    public string $editRepository = '';

    public string $editDomain = '';

    /** @var list<string> Installed PHP versions from API (fallback: config hints) */
    public array $installedPhpVersions = [];

    public bool $phpListUnsupported = false;

    // Alias form
    public string $newAlias = '';

    // WWW redirects (Cipi 4.8+ / API 1.12+)
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

    public bool $authJsonForce = false;

    // Artisan (Laravel apps)
    public string $artisanCommand = '';

    // Whitelisted app run (composer / npm / …)
    public string $runCommand = '';

    /** @var list<string> */
    public array $runAllowedCommands = [];

    /** @var list<string> */
    public array $runNotes = [];

    public bool $runLoaded = false;

    public bool $runUnsupported = false;

    public bool $showDeleteModal = false;

    // HTTP healthcheck (API 1.16+)
    /** @var array{enabled?: bool, url?: ?string, expect?: ?int, state?: ?string, failcount?: int} */
    public array $health = [];

    public bool $healthUnsupported = false;

    public bool $healthEnabled = false;

    public string $healthUrl = '';

    public string $healthExpect = '200';

    public ?array $healthCheckResult = null;

    public function mount(?string $name = null): void
    {
        $this->appName = $name ?? (string) request()->route('name');

        if ($this->appName === '') {
            abort(404);
        }

        $this->ensureServerSelected();
        $this->loadApp();
    }

    public function loadApp(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $this->app = $this->normalizeApp($this->client()->showApp($this->appName));
            $this->aliases = $this->client()->listAliases($this->appName);
            $this->editPhp = $this->app['php'] ?? '8.5';
            $this->editBranch = $this->app['branch'] ?? '';
            $this->editRepository = $this->app['repository'] ?? '';
            $this->editDomain = $this->app['domain'] ?? '';
            $this->loadInstalledPhpVersions();
            $this->loadHealth();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    protected function loadHealth(): void
    {
        $this->healthUnsupported = false;
        $this->healthCheckResult = null;
        $domain = (string) ($this->app['domain'] ?? '');

        try {
            $this->health = $this->client()->getAppHealth($this->appName);
            $this->healthEnabled = (bool) ($this->health['enabled'] ?? false);
            $this->healthUrl = (string) ($this->health['url'] ?? ($domain !== '' ? 'https://'.$domain.'/up' : ''));
            $this->healthExpect = (string) ($this->health['expect'] ?? 200);
        } catch (CipiApiException $e) {
            $this->healthUnsupported = true;
            $this->health = [];
            $this->healthEnabled = false;
            $this->healthUrl = $domain !== '' ? 'https://'.$domain.'/up' : '';
            $this->healthExpect = '200';
        }
    }

    public function saveHealth(): void
    {
        $this->validate([
            'healthUrl' => ['nullable', 'string', 'max:512', 'regex:/^https?:\/\/.+/i'],
            'healthExpect' => ['required', 'integer', 'min:100', 'max:599'],
        ]);

        try {
            $url = trim($this->healthUrl);
            $this->health = $this->client()->setAppHealth(
                $this->appName,
                $url !== '' ? $url : null,
                (int) $this->healthExpect,
            );
            $this->healthEnabled = true;
            $this->healthCheckResult = null;
            $this->dispatch('notify', type: 'success', message: 'Healthcheck enabled');
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
            $this->dispatch('notify', type: 'success', message: 'Healthcheck disabled');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function runHealthCheck(): void
    {
        try {
            $this->healthCheckResult = $this->client()->checkAppHealth($this->appName);
            $ok = (bool) ($this->healthCheckResult['ok'] ?? false);
            $this->dispatch(
                'notify',
                type: $ok ? 'success' : 'error',
                message: $ok
                    ? 'Healthcheck OK ('.$this->healthCheckResult['got'].')'
                    : 'Healthcheck failed (got '.$this->healthCheckResult['got'].', expected '.$this->healthCheckResult['expect'].')',
            );
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function loadInstalledPhpVersions(): void
    {
        $this->phpListUnsupported = false;
        $this->installedPhpVersions = [];

        try {
            $data = $this->client()->listPhp();
            $versions = [];
            foreach ($data['versions'] ?? [] as $row) {
                if (is_array($row) && ! empty($row['version'])) {
                    $versions[] = (string) $row['version'];
                }
            }
            $this->installedPhpVersions = $versions !== []
                ? $versions
                : (array) config('cipi-gui.php_versions', ['8.4', '8.5']);
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->phpListUnsupported = true;
                $this->installedPhpVersions = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);

                return;
            }
            $this->installedPhpVersions = (array) config('cipi-gui.php_versions', ['8.4', '8.5']);
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === 'basicauth') {
            $this->loadBasicAuth();
        }

        if ($tab === 'aliases') {
            $this->loadWwwStatus();
        }

        if ($tab === 'env') {
            $this->loadEnv();
        }

        if ($tab === 'authjson') {
            $this->loadAuthJson();
        }

        if ($tab === 'run') {
            $this->loadRunCommands();
        }
    }

    public function saveApp(): void
    {
        $this->validate([
            'editPhp' => ['required', 'regex:/^\d+\.\d+$/'],
            'editBranch' => ['nullable', 'string', 'max:64'],
            'editRepository' => ['nullable', 'string'],
            'editDomain' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->installedPhpVersions !== [] && ! in_array($this->editPhp, $this->installedPhpVersions, true)) {
            $this->addError('editPhp', 'PHP '.$this->editPhp.' is not installed on this server. Install it from Server → Manage, or pick: '.implode(', ', $this->installedPhpVersions).'.');

            return;
        }

        $currentPhp = (string) ($this->app['php'] ?? '');
        $currentBranch = (string) ($this->app['branch'] ?? '');
        $currentRepo = (string) ($this->app['repository'] ?? '');
        $currentDomain = (string) ($this->app['domain'] ?? '');

        $payload = [];
        if ($this->editPhp !== '' && $this->editPhp !== $currentPhp) {
            $payload['php'] = $this->editPhp;
        }
        if ($this->editBranch !== $currentBranch) {
            $payload['branch'] = $this->editBranch;
        }
        if ($this->editRepository !== $currentRepo) {
            $payload['repository'] = $this->editRepository;
        }
        if ($this->editDomain !== '' && $this->editDomain !== $currentDomain) {
            $payload['domain'] = $this->editDomain;
        }

        if ($payload === []) {
            $this->dispatch('notify', type: 'info', message: 'No changes to save.');

            return;
        }

        try {
            $response = $this->client()->editApp($this->appName, $payload);
            $this->dispatchJob($response, 'App update');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function recreateWebhook(bool $rotateSecret = false): void
    {
        try {
            $response = $this->client()->recreateWebhook($this->appName, $rotateSecret);
            $this->dispatchJob($response, $rotateSecret ? 'Webhook recreate + rotate secret' : 'Webhook recreate');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deploy(): void
    {
        try {
            $response = $this->client()->deploy($this->appName);
            $this->dispatchJob($response, 'Deploy');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function rollback(): void
    {
        try {
            $response = $this->client()->deployRollback($this->appName);
            $this->dispatchJob($response, 'Deploy rollback');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function unlockDeploy(): void
    {
        try {
            $response = $this->client()->deployUnlock($this->appName);
            $this->dispatchJob($response, 'Deploy unlock');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function installSsl(): void
    {
        try {
            $response = $this->client()->installSsl($this->appName);
            $this->dispatchJob($response, 'SSL install');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function forceSsl(): void
    {
        try {
            $response = $this->client()->forceSsl($this->appName);
            $this->dispatchJob($response, 'Force HTTPS');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function loadWwwStatus(): void
    {
        $this->wwwUnsupported = false;

        try {
            $this->wwwStatus = $this->client()->wwwStatus($this->appName);
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [404, 403, 501], true)) {
                $this->wwwStatus = null;
                $this->wwwUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function wwwAdd(): void
    {
        try {
            $response = $this->client()->wwwAdd($this->appName);
            $this->dispatchJob($response, 'Add www/apex alias');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function wwwForceToRoot(): void
    {
        try {
            $response = $this->client()->wwwForceToRoot($this->appName);
            $this->dispatchJob($response, 'Force www → apex');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function wwwForceFromRoot(): void
    {
        try {
            $response = $this->client()->wwwForceFromRoot($this->appName);
            $this->dispatchJob($response, 'Force apex → www');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function wwwClear(): void
    {
        try {
            $response = $this->client()->wwwClear($this->appName);
            $this->dispatchJob($response, 'Clear www redirect');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function addAlias(): void
    {
        $this->validate(['newAlias' => ['required', 'string', 'max:255']]);

        try {
            $response = $this->client()->addAlias($this->appName, $this->newAlias);
            $this->newAlias = '';
            $this->dispatchJob($response, 'Add alias');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function removeAlias(string $alias): void
    {
        try {
            $response = $this->client()->removeAlias($this->appName, $alias);
            $this->dispatchJob($response, 'Remove alias');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function loadBasicAuth(): void
    {
        try {
            $this->basicAuth = $this->client()->basicAuthStatus($this->appName);

            if (is_array($this->basicAuth) && array_key_exists('enabled', $this->basicAuth)) {
                $this->basicAuth['enabled'] = $this->appFlagIsTrue($this->basicAuth['enabled']);
            }
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function enableBasicAuth(): void
    {
        $payload = array_filter([
            'user' => $this->basicAuthUser ?: 'admin',
            'password' => $this->basicAuthPassword ?: null,
        ]);

        try {
            $result = $this->client()->basicAuthEnable($this->appName, $payload);
            $this->basicAuth = $result;
            if (array_key_exists('enabled', $this->basicAuth)) {
                $this->basicAuth['enabled'] = $this->appFlagIsTrue($this->basicAuth['enabled']);
            }
            $this->generatedPassword = $result['password'] ?? null;
            $this->basicAuthPassword = '';
            $this->patchApp(['basic_auth' => true]);
            $this->dispatch('notify', type: 'success', message: 'Basic auth enabled.');
            $this->loadApp();
            $this->patchApp(['basic_auth' => true]);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableBasicAuth(): void
    {
        try {
            $this->client()->basicAuthDisable($this->appName);
            $this->basicAuth = ['enabled' => false, 'users' => []];
            $this->patchApp(['basic_auth' => false]);
            $this->dispatch('notify', type: 'success', message: 'Basic auth disabled.');
            $this->loadApp();
            $this->patchApp(['basic_auth' => false]);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── .env ──────────────────────────────────────────────────────────

    public function loadEnv(): void
    {
        $this->envUnsupported = false;
        $this->envLoaded = false;

        if ($this->app['custom'] ?? false) {
            $this->envUnsupported = true;

            return;
        }

        try {
            $data = $this->client()->showEnv($this->appName);
            $vars = is_array($data['vars'] ?? null) ? $data['vars'] : [];
            $this->syncEnvRowsFromVars($vars);
            $this->envLoaded = true;
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)
                || str_contains(strtolower($e->getMessage()), 'custom app')) {
                $this->envUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function addEnvRow(): void
    {
        $key = strtoupper(trim($this->envNewKey));
        if ($key === '') {
            $this->dispatch('notify', type: 'error', message: 'Env key is required.');

            return;
        }

        foreach ($this->envRows as $row) {
            if (strcasecmp($row['key'], $key) === 0) {
                $this->dispatch('notify', type: 'error', message: "Key {$key} already exists.");

                return;
            }
        }

        $this->envRows[] = ['key' => $key, 'value' => $this->envNewValue];
        $this->envNewKey = '';
        $this->envNewValue = '';
    }

    public function removeEnvRow(int $index): void
    {
        if (! isset($this->envRows[$index])) {
            return;
        }

        unset($this->envRows[$index]);
        $this->envRows = array_values($this->envRows);
    }

    public function saveEnv(): void
    {
        $current = [];
        foreach ($this->envRows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $current[$key] = (string) ($row['value'] ?? '');
        }

        $set = [];
        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $this->envOriginal) || $this->envOriginal[$key] !== $value) {
                $set[$key] = $value;
            }
        }

        $unset = [];
        foreach (array_keys($this->envOriginal) as $key) {
            if (! array_key_exists($key, $current)) {
                $unset[] = $key;
            }
        }

        if ($set === [] && $unset === []) {
            $this->dispatch('notify', type: 'info', message: 'No .env changes to save.');

            return;
        }

        try {
            $data = $this->client()->updateEnv($this->appName, $set, $unset);
            $vars = is_array($data['vars'] ?? null) ? $data['vars'] : $current;
            $this->syncEnvRowsFromVars($vars);
            $this->dispatch('notify', type: 'success', message: '.env updated.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    /** @param  array<string, mixed>  $vars */
    protected function syncEnvRowsFromVars(array $vars): void
    {
        $normalized = [];
        foreach ($vars as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }

        ksort($normalized, SORT_STRING);
        $this->envOriginal = $normalized;
        $this->envRows = [];
        foreach ($normalized as $key => $value) {
            $this->envRows[] = ['key' => $key, 'value' => $value];
        }
    }

    // ── Shared auth.json ──────────────────────────────────────────────

    public function loadAuthJson(): void
    {
        $this->authJsonUnsupported = false;
        $this->authJsonLoaded = false;
        $this->authJsonExists = false;
        $this->authJsonContent = null;

        try {
            $data = $this->client()->showAuthJson($this->appName);
            $content = $data['content'] ?? [];
            $this->authJsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            $this->authJsonExists = true;
            $this->authJsonLoaded = true;
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 501], true)) {
                $this->authJsonUnsupported = true;

                return;
            }

            // 404 (or CLI "not found") → file missing; offer create.
            if ($e->getStatusCode() === 404
                || str_contains(strtolower($e->getMessage()), 'not found')
                || str_contains(strtolower($e->getMessage()), 'does not exist')
                || str_contains(strtolower($e->getMessage()), 'no such file')) {
                $this->authJsonExists = false;
                $this->authJsonLoaded = true;
                $this->authJsonContent = "{\n    \"http-basic\": {}\n}";

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function createAuthJson(): void
    {
        $raw = trim($this->authJsonContent ?? '');
        $hasCustomBody = $raw !== '' && $raw !== "{\n    \"http-basic\": {}\n}";

        if ($hasCustomBody) {
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->dispatch('notify', type: 'error', message: 'Invalid JSON: '.json_last_error_msg());

                return;
            }
        }

        try {
            $data = $this->client()->createAuthJson($this->appName, $this->authJsonForce);
            $this->authJsonExists = true;
            $this->authJsonLoaded = true;
            $this->authJsonForce = false;

            if ($hasCustomBody) {
                $data = $this->client()->updateAuthJson($this->appName, $raw);
                $content = $data['content'] ?? json_decode($raw, true);
                $this->authJsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;
                $this->dispatch('notify', type: 'success', message: 'auth.json created and saved.');
            } else {
                $content = $data['content'] ?? [];
                $this->authJsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
                $this->dispatch('notify', type: 'success', message: 'auth.json created.');
            }
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 409) {
                $this->dispatch('notify', type: 'error', message: 'auth.json already exists. Enable force to overwrite, or load and edit it.');
                $this->loadAuthJson();

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function saveAuthJson(): void
    {
        $raw = trim($this->authJsonContent ?? '');
        if ($raw === '') {
            $this->dispatch('notify', type: 'error', message: 'JSON body is required.');

            return;
        }

        json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->dispatch('notify', type: 'error', message: 'Invalid JSON: '.json_last_error_msg());

            return;
        }

        try {
            $data = $this->client()->updateAuthJson($this->appName, $raw);
            $content = $data['content'] ?? json_decode($raw, true);
            $this->authJsonContent = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $raw;
            $this->authJsonExists = true;
            $this->dispatch('notify', type: 'success', message: 'auth.json saved.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteAuthJson(): void
    {
        try {
            $this->client()->deleteAuthJson($this->appName);
            $this->authJsonExists = false;
            $this->authJsonContent = "{\n    \"http-basic\": {}\n}";
            $this->dispatch('notify', type: 'success', message: 'auth.json deleted.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── Artisan ───────────────────────────────────────────────────────

    public function runArtisanPreset(string $command): void
    {
        $this->artisanCommand = $command;
    }

    public function runArtisan(): void
    {
        $command = trim($this->artisanCommand);
        if ($command === '') {
            $this->dispatch('notify', type: 'error', message: 'Artisan command is required.');

            return;
        }

        try {
            $response = $this->client()->runArtisan($this->appName, $command);
            $this->dispatchJob($response, 'Artisan: '.$command);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── Whitelisted run ───────────────────────────────────────────────

    public function loadRunCommands(): void
    {
        $this->runUnsupported = false;

        if ($this->runLoaded && $this->runAllowedCommands !== []) {
            return;
        }

        try {
            $data = $this->client()->listRunCommands();
            $commands = $data['commands'] ?? [];
            $notes = $data['notes'] ?? [];
            $this->runAllowedCommands = is_array($commands)
                ? array_values(array_filter($commands, fn ($c) => is_string($c) && $c !== ''))
                : [];
            $this->runNotes = is_array($notes)
                ? array_values(array_filter($notes, fn ($n) => is_string($n) && $n !== ''))
                : [];
            $this->runLoaded = true;
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->runUnsupported = true;

                return;
            }

            $this->handleApiError($e);
        }
    }

    public function runAppPreset(string $command): void
    {
        $this->runCommand = $command;
        $this->runAppCommand();
    }

    public function runAppCommand(): void
    {
        $command = trim($this->runCommand);
        if ($command === '') {
            $this->dispatch('notify', type: 'error', message: 'Command is required.');

            return;
        }

        try {
            $response = $this->client()->runAppCommand($this->appName, $command);
            $this->dispatchJob($response, 'Run: '.$command);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
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

    public function confirmDeleteApp(): void
    {
        $this->showDeleteModal = true;
    }

    public function cancelDeleteApp(): void
    {
        $this->showDeleteModal = false;
    }

    public function deleteApp(): void
    {
        try {
            $response = $this->client()->deleteApp($this->appName);
            $this->showDeleteModal = false;
            $this->dispatchJob($response, "Delete app {$this->appName}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        if (str_starts_with($this->jobLabel, 'Delete app')) {
            $this->redirect(route('cipi-gui.apps'), navigate: true);

            return;
        }

        $this->loadApp();

        if ($this->activeTab === 'aliases') {
            $this->loadWwwStatus();
        }
    }

    public function render()
    {
        $isCustom = (bool) ($this->app['custom'] ?? false);

        $tabs = [
            'overview' => 'Overview',
            'aliases' => 'Aliases & SSL',
            'deploy' => 'Deploy',
        ];

        if (! $isCustom) {
            $tabs['env'] = 'Env';
        }

        $tabs['authjson'] = 'Auth.json';

        if (! $isCustom) {
            $tabs['artisan'] = 'Artisan';
        }

        $tabs['run'] = 'Commands';
        $tabs['basicauth'] = 'Basic Auth';
        $tabs['logs'] = 'Logs';

        return view('cipi-gui::livewire.app-detail', [
            'phpVersions' => $this->installedPhpVersions !== []
                ? $this->installedPhpVersions
                : config('cipi-gui.php_versions'),
            'server' => $this->currentServer(),
            'tabs' => $tabs,
            'artisanPresets' => [
                ['label' => 'cache:clear', 'command' => 'cache:clear'],
                ['label' => 'optimize', 'command' => 'optimize'],
                ['label' => 'optimize:clear', 'command' => 'optimize:clear'],
                ['label' => 'migrate --force', 'command' => 'migrate --force'],
            ],
            'runPresets' => [
                ['label' => 'composer install', 'command' => 'composer install --no-interaction'],
                ['label' => 'composer install --no-dev', 'command' => 'composer install --no-dev --no-interaction'],
                ['label' => 'composer dump-autoload', 'command' => 'composer dump-autoload --no-interaction'],
                ['label' => 'npm install', 'command' => 'npm install'],
                ['label' => 'npm ci', 'command' => 'npm ci'],
                ['label' => 'npm run build', 'command' => 'npm run build'],
            ],
        ])->title($this->appName.' — App');
    }
}
