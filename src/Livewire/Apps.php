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
#[Title('Apps')]
class Apps extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /** @var array<int, array> */
    public array $apps = [];

    public bool $loading = true;

    public bool $showCreateModal = false;

    public string $user = '';

    public string $domain = '';

    public string $repository = '';

    public string $branch = 'main';

    public string $php = '8.4';

    public bool $custom = false;

    public string $docroot = '';

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
            $this->apps = $this->client()->listApps();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        } finally {
            $this->loading = false;
        }
    }

    public function openCreate(): void
    {
        $this->reset(['user', 'domain', 'repository', 'branch', 'docroot', 'error']);
        $this->php = '8.4';
        $this->custom = false;
        $this->showCreateModal = true;
    }

    public function createApp(): void
    {
        $rules = [
            'user' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/'],
            'domain' => ['required', 'string', 'max:255'],
            'php' => ['required', 'in:'.implode(',', config('cipi-gui.php_versions'))],
            'custom' => ['boolean'],
        ];

        if (! $this->custom) {
            $rules['repository'] = ['required', 'string'];
            $rules['branch'] = ['required', 'string', 'max:64'];
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

        try {
            $response = $this->client()->createApp($payload);
            $this->showCreateModal = false;
            $this->dispatchJob($response, 'App creation');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteApp(string $name): void
    {
        try {
            $response = $this->client()->deleteApp($name);
            $this->dispatchJob($response, "Delete app {$name}");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        $this->loadApps();
    }

    public function render()
    {
        return view('cipi-gui::livewire.apps', [
            'servers' => CipiServer::where('is_active', true)->orderBy('name')->get(),
            'phpVersions' => config('cipi-gui.php_versions'),
        ]);
    }
}
