<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Sync;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * The Transfer tab of a server: its apps sent to another server (`cipi sync`),
 * as a wizard — what, where, a check of the way there, then the transfer as a
 * job the page follows.
 *
 * The server whose page this is is the source: it exports, sends over SSH and
 * has the target import. A target that is in the panel is prepared from here
 * (this server's key is added to it, and taken out again when the transfer
 * ends unless asked to stay); one that is not is shown the key to trust.
 *
 * Encryption is the server's: the archive is encrypted with a passphrase the
 * source makes for that one transfer and hands to the target's import over
 * SSH. The panel never sees it — there is no field for it, here or in the API.
 *
 * Every action needs `manage` on this server (and on the target, when it is
 * one of the panel's) and a token with `sync-manage`.
 */
trait ManagesSync
{
    /** The target when it is a server of this panel; 0: one that is not. */
    #[Url(as: 'to', except: 0)]
    public int $syncPeerId = 0;

    public string $syncHost = '';

    public string $syncPort = '22';

    /** all: every app of this server · chosen: the ones ticked. */
    public string $syncScope = 'all';

    /** @var list<string> */
    public array $syncApps = [];

    public bool $syncWithDb = true;

    public bool $syncWithStorage = false;

    /** Leave this server trusted on the target after the transfer (a target of the panel). */
    public bool $syncKeepTrust = false;

    /** @var list<array{app: string, domain: string, kind: string}> the apps of this server (Node apps are listed, not moved) */
    #[Locked]
    public array $syncSourceApps = [];

    /** @var array<string, mixed>|null this server's sync key */
    #[Locked]
    public ?array $syncKey = null;

    /** @var array<string, mixed>|null what the last check of the target said */
    #[Locked]
    public ?array $syncCheck = null;

    /** The server of the panel this wizard added the key to: what it takes back afterwards. */
    #[Locked]
    public int $syncTrustedPeerId = 0;

    /** ability | server | null: why the tab cannot be used. */
    #[Locked]
    public ?string $syncUnsupported = null;

    public ?string $syncNote = null;

    public function loadSync(): void
    {
        $this->syncUnsupported = null;
        // Nothing to ask of the server for someone who cannot move apps.
        if (! Access::canManageServer($this->panelUser(), (int) $this->serverId)) {
            $this->syncSourceApps = [];
            $this->syncKey = null;

            return;
        }
        $this->syncSourceApps = array_values(array_map(fn (array $app) => [
            'app' => (string) ($app['app'] ?? ''),
            'domain' => (string) ($app['domain'] ?? ''),
            'kind' => $this->appKind($app),
        ], array_filter($this->client()->listApps(), fn ($app) => is_array($app) && ($app['app'] ?? '') !== '')));

        try {
            $this->syncKey = $this->client()->syncKey();
        } catch (CipiApiException $e) {
            $this->syncKey = null;
            $this->syncUnsupported = $e->getStatusCode() === 403 ? 'ability' : 'server';
        }

        if ($this->syncPeerId !== 0 && $this->syncPeer() === null) {
            $this->syncPeerId = 0;
        }
        if ($this->syncPeerId !== 0 && $this->syncHost === '') {
            $peer = $this->syncPeer();
            $this->syncHost = Sync::sshHost($peer?->ip, $peer?->url);
        }
    }

    /** The other servers of the panel this user manages. */
    public function syncPeers(): \Illuminate\Support\Collection
    {
        return $this->manageableServers()->where('id', '!=', (int) $this->serverId)->values();
    }

    protected function syncPeer(): ?CipiServer
    {
        return $this->syncPeerId === 0 ? null : $this->syncPeers()->firstWhere('id', $this->syncPeerId);
    }

    public function updatedSyncPeerId(): void
    {
        $peer = $this->syncPeer();
        if ($peer === null) {
            $this->syncPeerId = 0;
        }
        $this->syncHost = $peer ? Sync::sshHost($peer->ip, $peer->url) : '';
        $this->syncPort = '22';
        $this->syncCheck = null;
        $this->syncNote = null;
        $this->resetErrorBag();
    }

    public function updatedSyncHost(): void
    {
        $this->syncCheck = null;
    }

    public function updatedSyncPort(): void
    {
        $this->syncCheck = null;
    }

    /**
     * Prepare the target and check the way there: this server's key is added
     * to a target of the panel, then this server is asked whether the target
     * accepts it and runs Cipi. Nothing is sent.
     */
    public function checkSync(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || ! $this->syncFormValid()) {
            return;
        }

        $this->syncCheck = null;
        $this->syncNote = null;
        $peer = $this->syncPeer();

        try {
            $this->syncKey ??= $this->client()->syncKey();
            $key = (string) ($this->syncKey['key'] ?? '');

            // A target of the panel: the panel hands it the key itself.
            if ($peer !== null && $key !== '' && Access::canManageServer($this->panelUser(), $peer->id)) {
                try {
                    $trusted = CipiApiClient::for($peer)->syncTrust($key);
                    if (! empty($trusted['changed'])) {
                        $this->syncTrustedPeerId = $peer->id;
                    }
                } catch (CipiApiException $e) {
                    $this->syncNote = match (true) {
                        $e->getStatusCode() === 403 => "The token of {$peer->name} is missing the sync-manage ability: its key could not be added there. Recreate that token from Connections, or trust the key on the server by hand.",
                        in_array($e->getStatusCode(), [404, 405, 503], true) => "{$peer->name} needs an update before the panel can prepare it (cipi self-update && cipi api update there). Until then, trust the key on it by hand.",
                        default => "The key could not be added on {$peer->name}: ".$this->friendlyApiError($e),
                    };
                }
            }

            $this->syncCheck = $this->client()->syncCheck(trim($this->syncHost), (int) $this->syncPort);
            $this->error = null;
        } catch (CipiApiException $e) {
            $this->syncError($e);
        }
    }

    /** The transfer: a job of this server — export, send, import on the target. */
    public function startSync(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || ! $this->syncFormValid()) {
            return;
        }
        if (empty($this->syncCheck['ok']) || empty($this->syncCheck['auto_passphrase'])
            || ($this->syncCheck['host'] ?? null) !== trim($this->syncHost) || (int) ($this->syncCheck['port'] ?? 0) !== (int) $this->syncPort) {
            $this->addError('syncHost', 'Check the connection to this target first.');

            return;
        }

        $all = $this->syncScope !== 'chosen';
        $apps = $all ? [] : array_values(array_unique($this->syncApps));
        $payload = [
            'host' => trim($this->syncHost),
            'port' => (int) $this->syncPort,
            'apps' => $apps,
            'with_db' => $this->syncWithDb,
            'with_storage' => $this->syncWithStorage,
        ];
        $count = $all ? count(Sync::movable($this->syncSourceApps)) : count($apps);
        $target = $this->syncPeer()?->name ?? trim($this->syncHost);

        $this->startJob("Transfer {$count} ".($count === 1 ? 'app' : 'apps')." to {$target}", fn (CipiApiClient $api) => $api->syncPush($payload));
    }

    /**
     * When the transfer has ended, whatever its end: the key this wizard
     * added on the target is taken out again, unless it was asked to stay.
     *
     * @param  array<string, mixed>  $data  the finished job
     */
    protected function syncAfterJob(array $data): void
    {
        if (($data['type'] ?? '') !== 'sync-push') {
            return;
        }
        $this->syncCheck = null;
        if ($this->syncTrustedPeerId !== 0 && ! $this->syncKeepTrust) {
            $this->forgetSyncTrust();
        }
    }

    /**
     * Take this server's key back out of a target of the panel: the one the
     * wizard added it to, or the one chosen now (a page that was closed while
     * a transfer ran has nothing to remember it by).
     */
    public function forgetSyncTrust(): void
    {
        $id = $this->syncTrustedPeerId !== 0 ? $this->syncTrustedPeerId : $this->syncPeerId;
        $peer = $id === 0 ? null : $this->syncPeers()->firstWhere('id', $id);
        if ($peer === null || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || ! Access::canManageServer($this->panelUser(), $peer->id)) {
            $this->syncTrustedPeerId = 0;

            return;
        }

        try {
            $this->syncKey ??= $this->client()->syncKey();
            $key = (string) ($this->syncKey['key'] ?? '');
            if ($key === '') {
                return;
            }
            $removed = CipiApiClient::for($peer)->syncUntrust($key);
            $this->syncTrustedPeerId = 0;
            $this->syncCheck = null;
            $this->syncNote = ! empty($removed['changed'])
                ? "This server's key was removed from {$peer->name}: it is no longer trusted there."
                : "This server's key is not trusted on {$peer->name}: there was nothing to remove.";
        } catch (CipiApiException $e) {
            $this->syncNote = "This server's key may still be trusted on {$peer->name} — it could not be removed (".$this->friendlyApiError($e).'). On that server: cipi sync untrust --key="…", with the key shown here.';
        }
    }

    protected function syncFormValid(): bool
    {
        $this->resetErrorBag();
        $this->syncHost = trim($this->syncHost);
        $this->syncPort = trim($this->syncPort) === '' ? '22' : trim($this->syncPort);
        $errors = Sync::errors($this->syncHost, $this->syncPort, $this->syncScope !== 'chosen', array_values($this->syncApps), Sync::movable($this->syncSourceApps));
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }

        return $errors === [];
    }

    protected function syncError(CipiApiException $e): void
    {
        $this->error = match (true) {
            in_array($e->getStatusCode(), [404, 405, 503], true) => 'Transfers from the panel need an update of Cipi on this server (cipi self-update && cipi api update).',
            $e->getStatusCode() === 403 && str_contains(strtolower($e->getMessage()), 'ability') => 'The token of this connection is missing the sync-manage ability: recreate it from Connections.',
            default => $this->friendlyApiError($e),
        };
        $this->dispatch('notify', type: 'error', message: $this->error);
    }
}
