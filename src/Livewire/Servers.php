<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Connections')]
class Servers extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public string $ip = '';

    public string $token = '';

    public bool $showFormModal = false;

    public ?int $confirmDeleteId = null;

    /** @var array<int, array{ok: bool, message: string, cipi?: ?string, whitelisted?: string}> */
    public array $testResults = [];

    /** Where the last connection test said this panel calls the API from (the server's client_ip). */
    public const PANEL_IP_CACHE_KEY = 'cipi-gui.panel_ip';

    public function openAdd(): void
    {
        $this->reset(['editingId', 'name', 'url', 'ip', 'token']);
        $this->resetErrorBag();
        $this->showFormModal = true;
    }

    public function openEdit(int $id): void
    {
        $server = CipiServer::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $server->id;
        $this->name = $server->name;
        $this->url = $server->url;
        $this->ip = (string) $server->ip;
        $this->token = '';
        $this->showFormModal = true;
    }

    public function closeForm(): void
    {
        $this->showFormModal = false;
        $this->reset(['editingId', 'name', 'url', 'ip', 'token']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->url = $this->normalizeUrl($this->url);
        $this->ip = trim($this->ip);
        $this->token = $this->normalizeToken($this->token);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('cipi_servers', 'name')->ignore($this->editingId)],
            'url' => ['required', 'url', 'max:255'],
            'ip' => ['nullable', 'ip'],
            'token' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:10'],
        ], [
            'name.regex' => 'Use letters, numbers, hyphens and underscores only.',
            'name.unique' => 'A server with this name already exists.',
            'url.url' => 'Enter a valid URL, e.g. https://vps.example.com',
            'ip.ip' => 'Enter a valid IPv4 or IPv6 address.',
            'token.min' => 'The API token looks too short.',
        ]);

        if ($validated['ip'] === '' || $validated['ip'] === null) {
            $validated['ip'] = $this->resolveIpFromUrl($validated['url']);
        }

        if ($this->editingId && ($validated['token'] ?? '') === '') {
            unset($validated['token']);
        }

        try {
            if ($this->editingId) {
                $server = CipiServer::findOrFail($this->editingId);
                $server->fill($validated);
                $server->last_error = null;
                $server->save();
            } else {
                $server = CipiServer::create($validated + ['is_active' => true]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->addError('name', 'Could not save the server. Ensure migrations ran: php artisan migrate');

            return;
        }

        $editing = (bool) $this->editingId;
        $this->closeForm();
        $this->runConnectionTest($server->id);

        if (! session('cipi_gui_server_id')) {
            session(['cipi_gui_server_id' => $server->id]);
        }

        $ok = $this->testResults[$server->id]['ok'] ?? false;
        $this->dispatch('notify',
            type: $ok ? 'success' : 'error',
            message: $ok
                ? ($editing ? "Connection \"{$server->name}\" updated." : "Server \"{$server->name}\" connected.")
                : "Saved, but the connection test failed: ".($this->testResults[$server->id]['message'] ?? 'unknown error'),
        );
        $this->serversChanged();
    }

    public function testConnection(int $id): void
    {
        if ($this->runConnectionTest($id)) {
            $this->serversChanged();
        }
    }

    /**
     * Test one connection and record the result. True when the test changed
     * what the header and the sidebar show for the server (the dot: last_error).
     */
    private function runConnectionTest(int $id): bool
    {
        $server = CipiServer::findOrFail($id);
        $errorBefore = $server->last_error;

        try {
            $client = CipiApiClient::for($server);
            $status = $client->testConnection();
            $this->syncIpFromStatus($server, $status);
            $this->testResults[$id] = [
                'ok' => true,
                'message' => 'Connected',
                'cipi' => $status['system']['cipi'] ?? null,
            ];
            $this->ensurePanelIpWhitelisted($client, $id);
        } catch (CipiApiException $e) {
            $this->testResults[$id] = ['ok' => false, 'message' => $e->getMessage()];
        }

        return $server->last_error !== $errorBefore;
    }

    /**
     * The server's API IP whitelist (cipi api ip-whitelist) decides who may
     * call it, and the server knows which address this panel calls from
     * (client_ip). After a successful test, keep the panel on that list:
     *
     *  - list restricted and the address not on it (allowed through a CIDR,
     *    say): add it, so the panel survives the CIDR being dropped;
     *  - list open to any IP: nothing to add — on the server, `add` on an open
     *    list *restricts* it to the added address and would lock every other
     *    client out. The address is remembered and shown next to the token
     *    command, to be kept when the list is restricted.
     *
     * An API that cannot answer (older release, token without
     * ip-whitelist-manage) leaves the test result as it is.
     */
    private function ensurePanelIpWhitelisted(CipiApiClient $client, int $id): void
    {
        try {
            $list = $client->getIpWhitelist();
        } catch (CipiApiException) {
            return;
        }

        $clientIp = trim((string) ($list['client_ip'] ?? ''));
        if ($clientIp === '' || ! filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return;
        }
        Cache::put(self::PANEL_IP_CACHE_KEY, $clientIp, now()->addDays(30));

        $entries = is_array($list['entries'] ?? null) ? array_map('strval', $list['entries']) : [];
        if (! empty($list['allow_all']) || in_array('*', $entries, true) || in_array($clientIp, $entries, true)) {
            return;
        }

        try {
            $client->addIpWhitelistEntry($clientIp);
        } catch (CipiApiException) {
            return;
        }
        $this->testResults[$id]['message'] = "Connected — {$clientIp} (this panel) added to the API IP whitelist";
        $this->testResults[$id]['whitelisted'] = $clientIp;
    }

    public function toggleActive(int $id): void
    {
        $server = CipiServer::findOrFail($id);
        $server->forceFill(['is_active' => ! $server->is_active])->save();

        if (! $server->is_active && (int) session('cipi_gui_server_id') === $server->id) {
            session()->forget('cipi_gui_server_id');
        }

        $this->serversChanged();
        $this->dispatch('notify', type: 'info', message: $server->is_active ? "{$server->name} enabled." : "{$server->name} disabled — hidden from the switcher.");
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function deleteServer(): void
    {
        $server = CipiServer::find($this->confirmDeleteId);
        $this->confirmDeleteId = null;

        if (! $server) {
            return;
        }

        if ((int) session('cipi_gui_server_id') === $server->id) {
            session()->forget('cipi_gui_server_id');
        }

        $name = $server->name;
        $server->delete();
        unset($this->testResults[$server->id]);
        $this->serversChanged();
        $this->dispatch('notify', type: 'success', message: "Connection \"{$name}\" removed. Nothing was changed on the server.");
    }

    /**
     * The header (server switcher) and the sidebar (an entry per server in
     * fleet view) belong to the layout, not to this page: they re-render on
     * this event.
     */
    private function serversChanged(): void
    {
        $this->dispatch('servers-changed');
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if (preg_match('/^bearer\s+/i', $token)) {
            $token = trim((string) preg_replace('/^bearer\s+/i', '', $token));
        }

        return $token;
    }

    private function resolveIpFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $resolved = gethostbyname($host);

        if ($resolved === $host) {
            return null;
        }

        return filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : null;
    }

    /** @param  array<string, mixed>  $status */
    private function syncIpFromStatus(CipiServer $server, array $status): void
    {
        $ip = $status['system']['ip'] ?? $status['system']['ipv4'] ?? null;

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) && $server->ip !== $ip) {
            $server->forceFill(['ip' => $ip])->save();
        }
    }

    public function render()
    {
        $abilities = (array) config('cipi-gui.token_abilities', []);

        return view('cipi-gui::livewire.servers', [
            'servers' => CipiServer::orderBy('name')->get(),
            'tokenCommand' => 'cipi api token create --name=gui --abilities='.implode(',', $abilities),
            'abilityCount' => count($abilities),
            'panelIp' => Cache::get(self::PANEL_IP_CACHE_KEY),
            'deleting' => $this->confirmDeleteId ? CipiServer::find($this->confirmDeleteId) : null,
        ]);
    }
}
