<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiServer;
use CipiGui\Services\CipiApiClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    /** @var array<int, array{status: ?array, error: ?string}> */
    public array $serverStatuses = [];

    public bool $loaded = false;

    public ?string $checkedAt = null;

    /** Called by wire:init so the page paints before the servers answer. */
    public function loadStatuses(): void
    {
        $servers = CipiServer::where('is_active', true)->orderBy('name')->get();

        $this->serverStatuses = CipiApiClient::statusForMany($servers);

        foreach ($servers as $server) {
            $status = $this->serverStatuses[$server->id]['status'] ?? null;
            if (is_array($status)) {
                $this->syncIpFromStatus($server, $status);
            }
        }

        $this->loaded = true;
        $this->checkedAt = now()->format('H:i:s');
    }

    public function refresh(): void
    {
        $this->loadStatuses();
    }

    public function open(int $id, string $target = 'server'): void
    {
        session(['cipi_gui_server_id' => $id]);

        $this->redirect(match ($target) {
            'apps' => route('cipi-gui.apps'),
            'databases' => route('cipi-gui.databases'),
            default => route('cipi-gui.server-manage', ['serverId' => $id]),
        });
    }

    /** @param  array<string, mixed>  $status */
    protected function syncIpFromStatus(CipiServer $server, array $status): void
    {
        $ip = $status['system']['ip'] ?? $status['system']['ipv4'] ?? null;

        if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        if ($server->ip !== $ip) {
            $server->forceFill(['ip' => $ip])->save();
        }
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @return array{used: string, total: string, percent: ?int}
     */
    public function disk(?array $status): array
    {
        $disk = $status['resources']['disk'] ?? [];
        $percent = isset($disk['usage_percent']) && is_numeric($disk['usage_percent']) ? (int) $disk['usage_percent'] : null;
        $used = (string) ($disk['used'] ?? '');
        $total = (string) ($disk['total'] ?? '');

        if (($used === '' || $total === '') && ! empty($disk['display'])
            && preg_match('/^(\S+)\/(\S+)\s*\((\d+)%\)/', (string) $disk['display'], $m)) {
            $used = $used !== '' ? $used : $m[1];
            $total = $total !== '' ? $total : $m[2];
            $percent ??= (int) $m[3];
        }

        return ['used' => $used, 'total' => $total, 'percent' => $percent];
    }

    public function meterClass(?int $percent): string
    {
        return match (true) {
            $percent === null => '',
            $percent >= 90 => 'is-danger',
            $percent >= 75 => 'is-warn',
            default => '',
        };
    }

    public function render()
    {
        $servers = CipiServer::orderBy('name')->get();
        $active = $servers->where('is_active', true);

        $summary = ['online' => 0, 'apps' => 0, 'services' => 0, 'services_up' => 0, 'versions' => []];
        foreach ($active as $server) {
            $status = $this->serverStatuses[$server->id]['status'] ?? null;
            if (! is_array($status)) {
                continue;
            }
            $summary['online']++;
            $summary['apps'] += (int) ($status['apps'] ?? 0);
            foreach ((array) ($status['services'] ?? []) as $state) {
                $summary['services']++;
                $summary['services_up'] += $state === 'running' ? 1 : 0;
            }
            if (! empty($status['system']['cipi'])) {
                $summary['versions'][] = (string) $status['system']['cipi'];
            }
        }
        $summary['versions'] = array_values(array_unique($summary['versions']));
        usort($summary['versions'], 'version_compare');

        return view('cipi-gui::livewire.dashboard', [
            'servers' => $active,
            'hasServers' => $servers->isNotEmpty(),
            'summary' => $summary,
        ]);
    }
}
