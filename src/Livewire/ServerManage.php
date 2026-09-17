<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Server')]
class ServerManage extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    public string $activeTab = 'php';

    public bool $loading = true;

    public bool $unsupported = false;

    /** @var array{default: ?string, installable: list<string>, versions: list<array>} */
    public array $phpData = ['default' => null, 'installable' => [], 'versions' => []];

    public string $phpInstallVersion = '8.5';

    /** @var array{default: ?string, engines: list<array>} */
    public array $enginesData = ['default' => null, 'engines' => []];

    /** @var list<array{id: int, type: string, comment: string, fingerprint: string, current_session: bool}> */
    public array $sshKeys = [];

    public string $sshKey = '';

    /** @var list<array{name: string, status: string, since: ?string}> */
    public array $services = [];

    /** @var array<string, mixed> */
    public array $smtp = [];

    public bool $smtpUnsupported = false;

    public string $smtpHost = '';

    public string $smtpPort = '587';

    public string $smtpUser = '';

    public string $smtpPassword = '';

    public string $smtpFrom = '';

    public string $smtpTo = '';

    public bool $smtpTls = true;

    public bool $smtpEnabled = true;

    public bool $smtpSendTest = true;

    /** @var list<array{major: string, version: ?string, default: bool, apps?: list<string>}> */
    public array $nodeRuntimes = [];

    public bool $nodeUnsupported = false;

    /** @var list<array{id: string, packages?: list<string>, description?: string, installed?: bool, partial?: bool}> */
    public array $packages = [];

    public bool $packagesUnsupported = false;

    /** @var array{reminder_minutes?: int, checks?: list<array>} */
    public array $monitor = [];

    public bool $monitorUnsupported = false;

    /** @var array<string, mixed> */
    public array $zt = [];

    public bool $ztUnsupported = false;

    /** @var array{allow_all?: bool, entries?: list<string>, file?: string, client_ip?: string} */
    public array $ipWhitelist = [];

    public bool $ipWhitelistUnsupported = false;

    public string $ipWhitelistEntry = '';

    /** @var array<string, mixed> */
    public array $search = [];

    public bool $searchUnsupported = false;

    public function mount(?int $serverId = null): void
    {
        if ($serverId !== null) {
            $this->serverId = $serverId;
            session(['cipi_gui_server_id' => $serverId]);
        }

        $this->ensureServerSelected();
        $this->loadAll();
    }

    public function updatedServerId(): void
    {
        session(['cipi_gui_server_id' => $this->serverId]);
        $this->loadAll();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;

        try {
            $this->loadOptionalTab($tab);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function loadAll(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->unsupported = false;

        if (! $this->currentServer()) {
            $this->loading = false;

            return;
        }

        try {
            $this->loadPhp();
            $this->loadEngines();
            $this->loadSsh();
            $this->loadServices();
            $this->loadSmtp();
            $this->loadOptionalTab($this->activeTab);
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->unsupported = true;
            } else {
                $this->handleApiError($e);
            }
        } finally {
            $this->loading = false;
        }
    }

    protected function loadOptionalTab(string $tab): void
    {
        match ($tab) {
            'node' => $this->loadNodeRuntimes(),
            'packages' => $this->loadPackages(),
            'monitor' => $this->loadMonitor(),
            'zt' => $this->loadZt(),
            'ip' => $this->loadIpWhitelist(),
            'search' => $this->loadSearch(),
            default => null,
        };
    }

    protected function loadNodeRuntimes(): void
    {
        $this->nodeUnsupported = false;
        try {
            $this->nodeRuntimes = array_values(array_filter(
                $this->client()->listNodeRuntimes(),
                fn ($item) => is_array($item) && ! empty($item['major']),
            ));
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->nodeUnsupported = true;
                $this->nodeRuntimes = [];

                return;
            }
            throw $e;
        }
    }

    protected function loadPackages(): void
    {
        $this->packagesUnsupported = false;
        try {
            $this->packages = $this->client()->listPackages();
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->packagesUnsupported = true;
                $this->packages = [];

                return;
            }
            throw $e;
        }
    }

    protected function loadMonitor(): void
    {
        $this->monitorUnsupported = false;
        try {
            $this->monitor = $this->client()->monitorStatus();
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->monitorUnsupported = true;
                $this->monitor = [];

                return;
            }
            throw $e;
        }
    }

    protected function loadZt(): void
    {
        $this->ztUnsupported = false;
        try {
            $this->zt = $this->client()->ztStatus();
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->ztUnsupported = true;
                $this->zt = [];

                return;
            }
            throw $e;
        }
    }

    protected function loadIpWhitelist(): void
    {
        $this->ipWhitelistUnsupported = false;
        try {
            $this->ipWhitelist = $this->client()->getIpWhitelist();
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->ipWhitelistUnsupported = true;
                $this->ipWhitelist = [];

                return;
            }
            throw $e;
        }
    }

    protected function loadSearch(): void
    {
        $this->searchUnsupported = false;
        try {
            $this->search = $this->client()->searchStatus();
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->searchUnsupported = true;
                $this->search = [];

                return;
            }
            throw $e;
        }
    }

    public function addIpWhitelistEntry(): void
    {
        $this->validate(['ipWhitelistEntry' => ['required', 'string', 'max:64']]);

        try {
            $this->ipWhitelist = $this->client()->addIpWhitelistEntry(trim($this->ipWhitelistEntry));
            $this->ipWhitelistEntry = '';
            $this->dispatch('notify', type: 'success', message: 'IP added to API whitelist');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function removeIpWhitelistEntry(string $ip): void
    {
        try {
            $this->ipWhitelist = $this->client()->removeIpWhitelistEntry($ip);
            $this->dispatch('notify', type: 'success', message: 'IP removed from API whitelist');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function allowAllIpWhitelist(): void
    {
        try {
            $this->ipWhitelist = $this->client()->allowAllIpWhitelist();
            $this->dispatch('notify', type: 'success', message: 'API whitelist set to allow all');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function loadPhp(): void
    {
        try {
            $this->phpData = $this->client()->listPhp();
            $installable = $this->phpData['installable'] ?? ['8.3', '8.4', '8.5'];
            $installed = array_column($this->phpData['versions'] ?? [], 'version');
            $candidates = array_values(array_diff($installable, $installed));
            $this->phpInstallVersion = $candidates[0] ?? ($installable[0] ?? '8.5');
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->unsupported = true;

                return;
            }
            throw $e;
        }
    }

    protected function loadEngines(): void
    {
        try {
            $this->enginesData = $this->client()->listDatabaseEngines();
        } catch (CipiApiException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 501], true)) {
                throw $e;
            }
        }
    }

    protected function loadSsh(): void
    {
        try {
            $this->sshKeys = $this->client()->listSshKeys();
        } catch (CipiApiException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 501], true)) {
                throw $e;
            }
        }
    }

    protected function loadServices(): void
    {
        try {
            $this->services = $this->client()->listServices();
        } catch (CipiApiException $e) {
            if (! in_array($e->getStatusCode(), [403, 404, 501], true)) {
                throw $e;
            }
        }
    }

    protected function loadSmtp(): void
    {
        $this->smtpUnsupported = false;
        try {
            $this->smtp = $this->client()->getSmtp();
            $this->smtpHost = (string) ($this->smtp['host'] ?? '');
            $this->smtpPort = (string) ($this->smtp['port'] ?? '587');
            $this->smtpUser = (string) ($this->smtp['user'] ?? '');
            $this->smtpFrom = (string) ($this->smtp['from'] ?? '');
            $this->smtpTo = (string) ($this->smtp['to'] ?? '');
            $this->smtpTls = (bool) ($this->smtp['tls'] ?? true);
            $this->smtpEnabled = (bool) ($this->smtp['enabled'] ?? true);
            // Never prefill password from API (not returned).
            $this->smtpPassword = '';
        } catch (CipiApiException $e) {
            if (in_array($e->getStatusCode(), [403, 404, 501], true)) {
                $this->smtpUnsupported = true;

                return;
            }
            throw $e;
        }
    }

    public function saveSmtp(): void
    {
        $rules = [
            'smtpHost' => ['required', 'string', 'max:255'],
            'smtpPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtpUser' => ['required', 'string', 'max:255'],
            'smtpFrom' => ['required', 'email', 'max:255'],
            'smtpTo' => ['required', 'email', 'max:255'],
        ];
        if (empty($this->smtp['configured'])) {
            $rules['smtpPassword'] = ['required', 'string', 'min:1', 'max:512'];
        } else {
            $rules['smtpPassword'] = ['nullable', 'string', 'max:512'];
        }
        $this->validate($rules);

        try {
            $payload = [
                'host' => $this->smtpHost,
                'port' => (int) $this->smtpPort,
                'user' => $this->smtpUser,
                'from' => $this->smtpFrom,
                'to' => $this->smtpTo,
                'tls' => $this->smtpTls,
                'enabled' => $this->smtpEnabled,
                'test' => $this->smtpSendTest,
            ];
            if ($this->smtpPassword !== '') {
                $payload['password'] = $this->smtpPassword;
            }
            $this->smtp = $this->client()->updateSmtp($payload);
            $this->smtpPassword = '';
            $this->dispatch('notify', type: 'success', message: 'SMTP settings saved');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function enableSmtp(): void
    {
        try {
            $this->smtp = $this->client()->enableSmtp();
            $this->smtpEnabled = true;
            $this->dispatch('notify', type: 'success', message: 'SMTP notifications enabled');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function disableSmtp(): void
    {
        try {
            $this->smtp = $this->client()->disableSmtp();
            $this->smtpEnabled = false;
            $this->dispatch('notify', type: 'success', message: 'SMTP notifications disabled');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function testSmtp(): void
    {
        try {
            $this->client()->testSmtp();
            $this->dispatch('notify', type: 'success', message: 'Test email sent');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteSmtp(): void
    {
        try {
            $this->smtp = $this->client()->deleteSmtp();
            $this->smtpPassword = '';
            $this->dispatch('notify', type: 'success', message: 'SMTP configuration removed');
            $this->loadSmtp();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function installPhp(): void
    {
        $this->validate(['phpInstallVersion' => ['required', 'regex:/^\d+\.\d+$/']]);

        try {
            $response = $this->client()->installPhp($this->phpInstallVersion);
            $this->dispatchJob($response, 'Install PHP '.$this->phpInstallVersion);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function installEngine(string $engine): void
    {
        try {
            $response = $this->client()->installDbEngine($engine);
            $this->dispatchJob($response, 'Install '.$engine);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function addSshKey(): void
    {
        $this->validate(['sshKey' => ['required', 'string', 'min:20']]);

        try {
            $this->client()->addSshKey(trim($this->sshKey));
            $this->sshKey = '';
            $this->loadSsh();
            $this->dispatch('notify', type: 'success', message: 'SSH key added');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function removeSshKey(int $id): void
    {
        try {
            $this->client()->removeSshKey($id);
            $this->loadSsh();
            $this->dispatch('notify', type: 'success', message: 'SSH key removed');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function restartService(string $name): void
    {
        try {
            $response = $this->client()->restartService($name);
            $this->dispatchJob($response, 'Restart '.$name);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        $this->loadAll();
    }

    public function render()
    {
        return view('cipi-gui::livewire.server-manage', [
            'server' => $this->currentServer(),
            'tabs' => [
                'php' => 'PHP',
                'engines' => 'Database engines',
                'node' => 'Node',
                'ssh' => 'SSH keys',
                'services' => 'Services',
                'smtp' => 'Email (SMTP)',
                'search' => 'Search',
                'packages' => 'Packages',
                'monitor' => 'Monitor',
                'zt' => 'Zero Trust',
                'ip' => 'IP whitelist',
            ],
        ]);
    }
}
