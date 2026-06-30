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
        $this->token = $this->normalizeToken($this->token);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:64', 'unique:cipi_servers,name', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'url' => ['required', 'url', 'max:255'],
            'token' => ['required', 'string', 'min:10'],
        ], [
            'name.regex' => 'Name may only contain letters, numbers, hyphens and underscores.',
            'name.unique' => 'A server with this name already exists.',
            'url.url' => 'Enter a valid URL (e.g. https://vps.example.com).',
            'token.min' => 'The API token looks too short.',
        ]);

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
            CipiApiClient::for($server)->testConnection();
            $this->success = "Server \"{$server->name}\" connected successfully.";
            $this->dispatch('notify', type: 'success', message: $this->success);
        } catch (CipiApiException $e) {
            $this->error = "Server saved but connection test failed: {$e->getMessage()}";
            $this->dispatch('notify', type: 'error', message: $this->error);
        }

        $this->reset(['name', 'url', 'token']);

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

    public function testConnection(int $id): void
    {
        $this->testing = true;
        $this->error = null;
        $this->success = null;

        $server = CipiServer::findOrFail($id);

        try {
            CipiApiClient::for($server)->testConnection();
            $this->success = "Connection to \"{$server->name}\" OK.";
        } catch (CipiApiException $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->testing = false;
        }
    }

    public function toggleActive(int $id): void
    {
        $server = CipiServer::findOrFail($id);
        $server->update(['is_active' => ! $server->is_active]);
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

    public function selectServer(int $id): void
    {
        session(['cipi_gui_server_id' => $id]);
        $this->redirect(route('cipi-gui.dashboard'), navigate: true);
    }

    public function render()
    {
        return view('cipi-gui::livewire.servers', [
            'servers' => CipiServer::orderBy('name')->get(),
        ]);
    }
}
