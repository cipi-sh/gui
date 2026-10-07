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
class ServerManage extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    public const TABS = [
        'overview' => 'Overview',
        'php' => 'PHP',
        'node' => 'Node',
        'engines' => 'Databases',
        'services' => 'Services',
        'health' => 'Health',
        'monitor' => 'Monitor',
        'smtp' => 'Email',
        'ssh' => 'SSH',
        'search' => 'Search',
        'packages' => 'Packages',
        'zt' => 'Zero Trust',
        'ip' => 'API access',
    ];

    #[Url(as: 'tab', except: 'overview')]
    public string $activeTab = 'overview';

    public bool $loading = true;

    /** @var array<string, bool> tab => endpoint missing or ability not granted */
    public array $unsupported = [];

    /** @var array<string, bool> */
    public array $loaded = [];

    public array $status = [];

    /** @var array{default: ?string, installable: list<string>, versions: list<array>} */
    public array $phpData = ['default' => null, 'installable' => [], 'versions' => []];

    public string $phpInstallVersion = '8.5';

    /** @var array{default: ?string, engines: list<array>} */
    public array $enginesData = ['default' => null, 'engines' => []];

    public array $sshKeys = [];

    public string $sshKey = '';

    public array $services = [];

    public array $healthChecks = [];

    public array $smtp = [];

    public string $smtpHost = '';

    public string $smtpPort = '587';

    public string $smtpUser = '';

    public string $smtpPassword = '';

    public string $smtpFrom = '';

    public string $smtpTo = '';

    public bool $smtpTls = true;

    public bool $smtpEnabled = true;

    public bool $smtpSendTest = true;

    public array $nodeRuntimes = [];

    public array $packages = [];

    public array $monitor = [];

    public array $zt = [];

    public array $ipWhitelist = [];

    public string $ipWhitelistEntry = '';

    public array $search = [];

    public function mount(?int $serverId = null): void
    {
        $this->ensureServerSelected($serverId);

        if (! array_key_exists($this->activeTab, self::TABS)) {
            $this->activeTab = 'overview';
        }

        $this->loadTab($this->activeTab);
        $this->loading = false;
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            return;
        }

        $this->activeTab = $tab;

        if (! ($this->loaded[$tab] ?? false)) {
            $this->loadTab($tab);
        }
    }

    public function refresh(): void
    {
        $this->loaded = [];
        $this->loadTab($this->activeTab);
        $this->dispatch('notify', type: 'info', message: 'Refreshed.');
    }

    protected function loadTab(string $tab): void
    {
        if (! $this->currentServer()) {
            return;
        }

        $this->error = null;

        $loader = match ($tab) {
            'overview' => function () {
                $this->status = $this->client()->getStatus();
                $this->guard('services', fn () => $this->services = $this->client()->listServices());
                $this->guard('monitor', fn () => $this->monitor = $this->client()->monitorStatus());
                $this->guard('health', fn () => $this->healthChecks = $this->client()->listHealth());
            },
            'php' => function () {
                $this->phpData = $this->client()->listPhp() + ['default' => null, 'installable' => [], 'versions' => []];
                $installed = array_column($this->phpData['versions'], 'version');
                $candidates = array_values(array_diff($this->phpData['installable'] ?: ['8.3', '8.4', '8.5'], $installed));
                $this->phpInstallVersion = $candidates[0] ?? '';
            },
            'node' => fn () => $this->nodeRuntimes = array_values(array_filter($this->client()->listNodeRuntimes(), fn ($r) => is_array($r) && ! empty($r['major']))),
            'engines' => fn () => $this->enginesData = $this->client()->listDatabaseEngines() + ['default' => null, 'engines' => []],
            'services' => fn () => $this->services = $this->client()->listServices(),
            'health' => fn () => $this->healthChecks = $this->client()->listHealth(),
            'monitor' => fn () => $this->monitor = $this->client()->monitorStatus(),
            'smtp' => fn () => $this->syncSmtp($this->client()->getSmtp()),
            'ssh' => fn () => $this->sshKeys = $this->client()->listSshKeys(),
            'search' => fn () => $this->search = $this->client()->searchStatus(),
            'packages' => fn () => $this->packages = $this->client()->listPackages(),
            'zt' => fn () => $this->zt = $this->client()->ztStatus(),
            'ip' => fn () => $this->ipWhitelist = $this->client()->getIpWhitelist(),
            default => null,
        };

        if ($loader) {
            $this->guard($tab, $loader);
        }
    }

    /** Run a loader; a missing endpoint or ability marks only that section as unsupported. */
    protected function guard(string $tab, callable $loader): void
    {
        try {
            $loader();
            $this->unsupported[$tab] = false;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->unsupported[$tab] = true;
            } else {
                $this->handleApiError($e);
            }
        } finally {
            $this->loaded[$tab] = true;
        }
    }

    // ── PHP / engines / services ──────────────────────────────────────

    public function installPhp(): void
    {
        $this->validate(['phpInstallVersion' => ['required', 'regex:/^\d+\.\d+$/']]);

        $version = $this->phpInstallVersion;
        $this->startJob('Install PHP '.$version, fn (CipiApiClient $api) => $api->installPhp($version));
    }

    public function installEngine(string $engine): void
    {
        $this->startJob('Install '.$this->engineLabel($engine), fn (CipiApiClient $api) => $api->installDbEngine($engine));
    }

    public function restartService(string $name): void
    {
        $this->startJob('Restart '.$name, fn (CipiApiClient $api) => $api->restartService($name));
    }

    // ── SSH keys ──────────────────────────────────────────────────────

    public function addSshKey(): void
    {
        $this->sshKey = trim($this->sshKey);
        $this->validate(['sshKey' => ['required', 'string', 'min:40', 'regex:/^(ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com)\s+\S+/']], [
            'sshKey.regex' => 'Paste a public key (ssh-ed25519 AAAA… comment).',
        ]);

        try {
            $this->client()->addSshKey($this->sshKey);
            $this->sshKey = '';
            $this->sshKeys = $this->client()->listSshKeys();
            $this->dispatch('notify', type: 'success', message: 'SSH key added for the cipi user.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function removeSshKey(int $id): void
    {
        try {
            $this->client()->removeSshKey($id);
            $this->sshKeys = $this->client()->listSshKeys();
            $this->dispatch('notify', type: 'success', message: 'SSH key removed.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── SMTP ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $smtp */
    protected function syncSmtp(array $smtp): void
    {
        $this->smtp = $smtp;
        $this->smtpHost = (string) ($smtp['host'] ?? '');
        $this->smtpPort = (string) ($smtp['port'] ?? '587');
        $this->smtpUser = (string) ($smtp['user'] ?? '');
        $this->smtpFrom = (string) ($smtp['from'] ?? '');
        $this->smtpTo = (string) ($smtp['to'] ?? '');
        $this->smtpTls = (bool) ($smtp['tls'] ?? true);
        $this->smtpEnabled = empty($smtp['configured']) ? true : (bool) ($smtp['enabled'] ?? true);
        $this->smtpPassword = ''; // never returned by the API
    }

    public function saveSmtp(): void
    {
        $this->validate([
            'smtpHost' => ['required', 'string', 'max:255'],
            'smtpPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtpUser' => ['required', 'string', 'max:255'],
            'smtpPassword' => [empty($this->smtp['configured']) ? 'required' : 'nullable', 'string', 'max:512'],
            'smtpFrom' => ['required', 'email', 'max:255'],
            'smtpTo' => ['required', 'email', 'max:255'],
        ]);

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

        $this->smtpCall(fn (CipiApiClient $api) => $api->updateSmtp($payload), $this->smtpSendTest ? 'SMTP saved — test email sent to '.$this->smtpTo.'.' : 'SMTP saved.');
    }

    public function enableSmtp(): void
    {
        $this->smtpCall(fn (CipiApiClient $api) => $api->enableSmtp(), 'Email notifications enabled.');
    }

    public function disableSmtp(): void
    {
        $this->smtpCall(fn (CipiApiClient $api) => $api->disableSmtp(), 'Email notifications paused.');
    }

    public function testSmtp(): void
    {
        $this->smtpCall(fn (CipiApiClient $api) => $api->testSmtp(), 'Test email sent to '.($this->smtp['to'] ?? 'the recipient').'.');
    }

    public function deleteSmtp(): void
    {
        $this->smtpCall(fn (CipiApiClient $api) => $api->deleteSmtp(), 'SMTP configuration removed.');
    }

    /** @param  callable(CipiApiClient): array  $call */
    protected function smtpCall(callable $call, string $success): void
    {
        try {
            $this->syncSmtp($call($this->client()));
            $this->dispatch('notify', type: 'success', message: $success);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── API IP whitelist ──────────────────────────────────────────────

    public function addIpWhitelistEntry(): void
    {
        $this->ipWhitelistEntry = trim($this->ipWhitelistEntry);
        $this->validate(['ipWhitelistEntry' => ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
            [$ip, $mask] = array_pad(explode('/', $value, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ($mask !== null && ! ctype_digit($mask))) {
                $fail('Enter an IPv4/IPv6 address or a CIDR range.');
            }
        }]]);

        $this->ipCall(fn (CipiApiClient $api) => $api->addIpWhitelistEntry($this->ipWhitelistEntry), $this->ipWhitelistEntry.' can now call the API.');
        $this->ipWhitelistEntry = '';
    }

    public function removeIpWhitelistEntry(string $ip): void
    {
        $this->ipCall(fn (CipiApiClient $api) => $api->removeIpWhitelistEntry($ip), $ip.' removed from the API whitelist.');
    }

    public function allowAllIpWhitelist(): void
    {
        $this->ipCall(fn (CipiApiClient $api) => $api->allowAllIpWhitelist(), 'The API accepts every client IP again.');
    }

    /** @param  callable(CipiApiClient): array  $call */
    protected function ipCall(callable $call, string $success): void
    {
        try {
            $this->ipWhitelist = $call($this->client());
            $this->dispatch('notify', type: 'success', message: $success);
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    protected function onJobCompleted(array $data): void
    {
        $this->loaded = [];
        $this->loadTab($this->activeTab);
    }

    public function monitorStateClass(?string $state): string
    {
        return match ($state) {
            'ok' => 'badge-green',
            'warn', 'warning' => 'badge-amber',
            'crit', 'critical', 'fail', 'failed' => 'badge-red',
            default => 'badge-gray',
        };
    }

    public function render()
    {
        $server = $this->currentServer();

        return view('cipi-gui::livewire.server-manage', [
            'server' => $server,
            'tabs' => self::TABS,
        ])->title($server ? 'Server · '.$server->name : 'Server');
    }
}
