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

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:64', 'unique:cipi_servers,name', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'url' => ['required', 'url', 'max:255'],
            'token' => ['required', 'string', 'min:10'],
        ], [
            'name.regex' => 'Name may only contain letters, numbers, hyphens and underscores.',
        ]);

        $validated['url'] = rtrim($validated['url'], '/');

        $server = CipiServer::create($validated);

        try {
            CipiApiClient::for($server)->testConnection();
            $this->success = "Server \"{$server->name}\" connected successfully.";
        } catch (CipiApiException $e) {
            $this->error = "Server saved but connection test failed: {$e->getMessage()}";
        }

        $this->reset(['name', 'url', 'token']);

        if (! session('cipi_gui_server_id')) {
            session(['cipi_gui_server_id' => $server->id]);
        }
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
