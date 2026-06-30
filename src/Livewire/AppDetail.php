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

    // Alias form
    public string $newAlias = '';

    // Basic auth
    public ?array $basicAuth = null;

    public string $basicAuthUser = 'admin';

    public string $basicAuthPassword = '';

    public ?string $generatedPassword = null;

    public function mount(string $name): void
    {
        $this->appName = $name;
        $this->ensureServerSelected();
        $this->loadApp();
    }

    public function loadApp(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $this->app = $this->client()->showApp($this->appName);
            $this->aliases = $this->client()->listAliases($this->appName);
            $this->editPhp = $this->app['php'] ?? '8.4';
            $this->editBranch = $this->app['branch'] ?? '';
            $this->editRepository = $this->app['repository'] ?? '';
            $this->editDomain = $this->app['domain'] ?? '';
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === 'basicauth') {
            $this->loadBasicAuth();
        }
    }

    public function saveApp(): void
    {
        $payload = array_filter([
            'php' => $this->editPhp ?: null,
            'branch' => $this->editBranch ?: null,
            'repository' => $this->editRepository ?: null,
            'domain' => $this->editDomain !== ($this->app['domain'] ?? '') ? $this->editDomain : null,
        ]);

        if (empty($payload)) {
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

    public function suspend(): void
    {
        try {
            $response = $this->client()->suspendApp($this->appName);
            $this->dispatchJob($response, 'Suspend app');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function unsuspend(): void
    {
        try {
            $response = $this->client()->unsuspendApp($this->appName);
            $this->dispatchJob($response, 'Unsuspend app');
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
            $this->generatedPassword = $result['password'] ?? null;
            $this->basicAuthPassword = '';
            $this->dispatch('notify', type: 'success', message: 'Basic auth enabled.');
            $this->loadApp();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableBasicAuth(): void
    {
        try {
            $this->client()->basicAuthDisable($this->appName);
            $this->basicAuth = ['enabled' => false, 'users' => []];
            $this->dispatch('notify', type: 'success', message: 'Basic auth disabled.');
            $this->loadApp();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        $this->loadApp();
    }

    public function render()
    {
        return view('cipi-gui::livewire.app-detail', [
            'servers' => CipiServer::where('is_active', true)->orderBy('name')->get(),
            'phpVersions' => config('cipi-gui.php_versions'),
        ])->title($this->appName.' — App');
    }
}
