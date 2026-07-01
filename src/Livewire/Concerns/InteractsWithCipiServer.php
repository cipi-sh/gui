<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;

trait InteractsWithCipiServer
{
    public ?int $serverId = null;

    public ?string $error = null;

    protected function currentServer(): ?CipiServer
    {
        $id = $this->serverId ?? session('cipi_gui_server_id');

        if (! $id) {
            return null;
        }

        return CipiServer::where('is_active', true)->find($id);
    }

    protected function client(): CipiApiClient
    {
        $server = $this->currentServer();

        if (! $server) {
            throw new CipiApiException('No server selected. Add a server first.', 400);
        }

        return CipiApiClient::for($server);
    }

    protected function handleApiError(CipiApiException $e): void
    {
        $this->error = $e->getMessage();
        $this->dispatch('notify', type: 'error', message: $e->getMessage());
    }

    protected function appFlagIsTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['true', '1', 1], true);
    }

    /** @param  array<string, mixed>  $app */
    protected function normalizeApp(array $app): array
    {
        $app['suspended'] = $this->appFlagIsTrue($app['suspended'] ?? false);
        $app['custom'] = $this->appFlagIsTrue($app['custom'] ?? false);

        if (array_key_exists('basic_auth', $app)) {
            $app['basic_auth'] = $this->appFlagIsTrue($app['basic_auth']);
        }

        return $app;
    }

    /** @param  array<string, mixed>  $patch */
    protected function rememberAppPatch(string $appName, array $patch): void
    {
        $patches = session('cipi_gui_app_patches', []);
        $patches[$appName] = array_merge($patches[$appName] ?? [], $patch);
        session(['cipi_gui_app_patches' => $patches]);

        $this->dispatch('app-changed', name: $appName, patch: $patch);
    }

    /** @param  array<int, array<string, mixed>>  $apps */
    protected function applySessionAppPatches(array $apps): array
    {
        $patches = session('cipi_gui_app_patches', []);
        if ($patches === []) {
            return $apps;
        }

        $remaining = [];

        foreach ($apps as $i => $app) {
            $name = $app['app'] ?? '';
            if ($name === '' || ! isset($patches[$name])) {
                continue;
            }

            $patch = $patches[$name];
            $stillNeeded = false;

            foreach ($patch as $key => $value) {
                if (($app[$key] ?? null) !== $value) {
                    $stillNeeded = true;

                    break;
                }
            }

            if ($stillNeeded) {
                $apps[$i] = array_merge($app, $patch);
                $remaining[$name] = $patch;
            }
        }

        session(['cipi_gui_app_patches' => $remaining]);

        return $apps;
    }

    protected function ensureServerSelected(): void
    {
        if ($this->serverId) {
            return;
        }

        $fromSession = session('cipi_gui_server_id');
        if ($fromSession && CipiServer::where('is_active', true)->where('id', $fromSession)->exists()) {
            $this->serverId = (int) $fromSession;

            return;
        }

        $first = CipiServer::where('is_active', true)->orderBy('name')->first();
        if ($first) {
            $this->serverId = $first->id;
            session(['cipi_gui_server_id' => $first->id]);
        }
    }
}
