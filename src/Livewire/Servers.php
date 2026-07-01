<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Servers')]
class Servers extends Component
{
    public string $name = '';

    public string $url = '';

    public string $ip = '';

    public string $token = '';

    public ?string $error = null;

    public ?string $success = null;

    public bool $testing = false;

    public function addServer(): void
    {
        $this->error = null;
        $this->success = null;

        $this->name = trim($this->name);
        $this->url = $this->normalizeUrl($this->url);
        $this->ip = trim($this->ip);
        $this->token = $this->normalizeToken($this->token);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:64', 'unique:cipi_servers,name', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'url' => ['required', 'url', 'max:255'],
            'ip' => ['nullable', 'ip'],
            'token' => ['required', 'string', 'min:10'],
        ], [
            'name.regex' => 'Name may only contain letters, numbers, hyphens and underscores.',
            'name.unique' => 'A server with this name already exists.',
            'url.url' => 'Enter a valid URL (e.g. https://vps.example.com).',
            'ip.ip' => 'Enter a valid IP address.',
            'token.min' => 'The API token looks too short.',
        ]);

        if ($validated['ip'] === '') {
            $validated['ip'] = $this->resolveIpFromUrl($validated['url']);
        }

        try {
            $server = CipiServer::create($validated);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->error = 'Could not save the server. Ensure migrations ran: php artisan migrate';

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Could not save the server: '.$e->getMessage();

            return;
        }

        try {
            $status = CipiApiClient::for($server)->testConnection();
            $this->syncIpFromStatus($server, $status);
            $this->success = "Server \"{$server->name}\" connected successfully.";
            $this->dispatch('notify', type: 'success', message: $this->success);
        } catch (CipiApiException $e) {
            $this->error = "Server saved but connection test failed: {$e->getMessage()}";
            $this->dispatch('notify', type: 'error', message: $this->error);
        }

        $this->reset(['name', 'url', 'ip', 'token']);

        if (! session('cipi_gui_server_id')) {
            session(['cipi_gui_server_id' => $server->id]);
        }
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

        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        if ($server->ip !== $ip) {
            $server->update(['ip' => $ip]);
        }
    }

    public function testConnection(int $id): void
    {
        $this->testing = true;
        $this->error = null;
        $this->success = null;

        $server = CipiServer::findOrFail($id);

        try {
            $status = CipiApiClient::for($server)->testConnection();
            $this->syncIpFromStatus($server, $status);
            $server->refresh();
            $this->success = "Connection to \"{$server->name}\" OK.";
        } catch (CipiApiException $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->testing = false;
        }
    }

    public function deleteServer(int $id): void
    {
        $server = CipiServer::findOrFail($id);

        if (session('cipi_gui_server_id') == $id) {
            session()->forget('cipi_gui_server_id');
        }

        $server->delete();
        $this->success = 'Server removed.';
    }

    public function render()
    {
        return view('cipi-gui::livewire.servers', [
            'servers' => CipiServer::orderBy('name')->get(),
        ]);
    }
}
