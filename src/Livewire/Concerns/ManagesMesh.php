<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Mesh;

/**
 * The mesh network of Zero Trust — Tailscale or NetBird — from the server's
 * Zero Trust tab: join a network, leave it, close and reopen the SSH port and
 * 80/443 to the world, Tailscale SSH on and off.
 *
 * Changing needs `manage` on the server and a token with `zt-manage`. What
 * must not happen from a browser — closing SSH before anyone has signed in
 * over the mesh, closing the way this panel reaches the server — is refused
 * by the server whatever is sent from here: the page greys the button out
 * and shows the server's reason, and an action that goes anyway gets the
 * same refusal back.
 *
 * The key of a join is sent once and then forgotten: it is cleared from the
 * form as soon as the server has answered, whatever it answered.
 *
 * Expects the host component to have `$zt` (what `GET /api/zt` answered) and
 * the server helpers of {@see InteractsWithCipiServer}.
 */
trait ManagesMesh
{
    public string $meshProvider = 'tailscale';

    public string $meshKey = '';

    public string $meshHostname = '';

    public string $meshControlUrl = '';

    public string $meshTags = '';

    public bool $meshDirect = false;

    public bool $meshAcceptDns = false;

    public bool $meshAcceptRoutes = false;

    public bool $meshSsh = false;

    /** What the server said after the last change, shown in the card until the next one. */
    public ?string $meshMessage = null;

    public function joinMesh(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $form = [
            'provider' => $this->meshProvider,
            'key' => $this->meshKey,
            'hostname' => $this->meshHostname,
            'control_url' => $this->meshControlUrl,
            'tags' => $this->meshTags,
            'direct' => $this->meshDirect,
            'accept_dns' => $this->meshAcceptDns,
            'accept_routes' => $this->meshAcceptRoutes,
            'ssh' => $this->meshSsh,
        ];
        foreach (Mesh::joinErrors($form) as $field => $message) {
            $this->addError('mesh'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), $message);
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $label = Mesh::label($this->meshProvider);
        try {
            $done = $this->meshChange(fn (CipiApiClient $api) => $api->ztMeshJoin(Mesh::joinPayload($form), $this->meshBrowserIp()), 'This server is on the '.$label.' network.');
        } finally {
            // Sent once: the key does not stay in the page, whatever the server answered.
            $this->meshKey = '';
        }
        if ($done) {
            $this->reset(['meshHostname', 'meshControlUrl', 'meshTags', 'meshDirect', 'meshAcceptDns', 'meshAcceptRoutes', 'meshSsh']);
        }
    }

    public function leaveMesh(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->meshChange(fn (CipiApiClient $api) => $api->ztMeshLeave($this->meshBrowserIp()), 'This server left the network: what its locks closed is open again.');
    }

    public function lockMesh(string $port): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || ! $this->meshPort($port)) {
            return;
        }

        $this->meshChange(
            fn (CipiApiClient $api) => $api->ztMeshLock($port, $this->meshBrowserIp()),
            $port === 'ssh' ? 'SSH is closed to the world: it answers on the network only.' : '80/443 are closed to the world: the sites, the panel and the API answer on the network only.',
        );
    }

    public function unlockMesh(string $port): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || ! $this->meshPort($port)) {
            return;
        }

        $this->meshChange(
            fn (CipiApiClient $api) => $api->ztMeshUnlock($port, $this->meshBrowserIp()),
            $port === 'ssh' ? 'SSH is open to the world again.' : '80/443 are open to the world again.',
        );
    }

    public function setMeshSsh(bool $enabled): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->meshChange(
            fn (CipiApiClient $api) => $api->ztMeshSsh($enabled, $this->meshBrowserIp()),
            $enabled ? 'Tailscale SSH is on: the tailnet\'s policy says who signs in.' : 'Tailscale SSH is off: SSH over the network is OpenSSH, with the server\'s keys.',
        );
    }

    /**
     * The address of the person at the panel, as the panel saw it. The server
     * uses it only when the panel runs on that very server: 80/443 are not
     * closed under a page that is being looked at on the public address.
     */
    protected function meshBrowserIp(): ?string
    {
        $ip = (string) request()->ip();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** Zero Trust as the server has it now, for this panel and this browser. */
    protected function loadZt(): array
    {
        return $this->client()->ztStatus($this->meshBrowserIp());
    }

    protected function meshPort(string $port): bool
    {
        if (in_array($port, ['ssh', 'http'], true)) {
            return true;
        }
        $this->dispatch('notify', type: 'error', message: 'SSH, or 80/443.');

        return false;
    }

    /**
     * A change; the server answers with Zero Trust after it, and what it did.
     *
     * @param  callable(CipiApiClient): array{data: ?array, message: string}  $call
     */
    protected function meshChange(callable $call, string $success): bool
    {
        try {
            $result = $call($this->client());
            // The change went through; when the read after it did not, ask again.
            $this->zt = is_array($result['data'] ?? null) ? $result['data'] : $this->loadZt();
            $this->meshMessage = trim((string) ($result['message'] ?? '')) !== '' ? self::versionless(self::plainCliText((string) $result['message'])) : null;
            $this->error = null;
            $this->dispatch('notify', type: 'success', message: $success);

            return true;
        } catch (CipiApiException $e) {
            $this->meshMessage = null;
            $this->error = match (true) {
                in_array($e->getStatusCode(), [404, 405], true) => 'Managing the mesh from the panel needs an update of Cipi on this server (cipi self-update && cipi api update).',
                $e->getStatusCode() === 403 && str_contains(strtolower($e->getMessage()), 'ability') => 'The token of this connection is missing the zt-manage ability: create it again with the command on Connections, on the server.',
                default => $this->friendlyApiError($e),
            };
            $this->dispatch('notify', type: 'error', message: $this->error);
            // A refusal may come with a server that changed meanwhile: show what it has now.
            try {
                $this->zt = $this->loadZt();
            } catch (CipiApiException) {
                // The first error is the one to show.
            }

            return false;
        }
    }
}
