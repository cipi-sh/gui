<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\Access;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Locked;

/**
 * Server → Updates: how the server updates Cipi itself — a release
 * installed only when it is signed by a trusted key, and a delay before a new
 * commit is installed (`cipi self-update policy`). Both off unless turned on.
 * The panel never starts an update: that stays the server's.
 */
trait ManagesUpdatePolicy
{
    /** @var array<string, mixed>|null what the server said */
    #[Locked]
    public ?array $updatePolicy = null;

    #[Locked]
    public ?string $updatePolicyUnsupported = null;

    public bool $upVerify = false;

    public string $upDelay = '0';

    /** The fingerprint the person compared with the one on cipi.sh. */
    public string $upFingerprint = '';

    public ?string $upRefused = null;

    public function loadUpdatePolicy(): void
    {
        $this->updatePolicyUnsupported = null;
        $this->upRefused = null;
        try {
            $this->syncUpdatePolicy($this->client()->selfUpdatePolicy());
        } catch (CipiApiException $e) {
            $this->updatePolicy = null;
            $this->updatePolicyUnsupported = match (true) {
                $e->getStatusCode() === 403 => 'This token cannot read the update policy: it needs the updates-view and updates-manage abilities — create it again from Connections.',
                in_array($e->getStatusCode(), [404, 405, 501, 503], true) => 'The update policy needs an up-to-date Cipi on this server: cipi self-update && cipi api update.',
                default => self::versionless(self::plainCliText($e->getMessage())),
            };
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function syncUpdatePolicy(array $data): void
    {
        $this->updatePolicy = $data;
        $this->upVerify = (bool) ($data['verify'] ?? false);
        $this->upDelay = (string) (int) ($data['delay_hours'] ?? 0);
        $this->upFingerprint = '';
    }

    public function saveUpdatePolicy(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }
        $this->resetErrorBag(['upDelay', 'upFingerprint']);
        $this->upRefused = null;
        if (! ctype_digit($this->upDelay) || (int) $this->upDelay > 168) {
            $this->addError('upDelay', 'From no delay to a week.');

            return;
        }
        $signed = array_key_exists('signed_releases', (array) $this->updatePolicy)
            ? (bool) $this->updatePolicy['signed_releases']
            : ((array) ($this->updatePolicy['shipped_signers'] ?? []) !== [] || (array) ($this->updatePolicy['signers'] ?? []) !== []);
        if ($this->upVerify && ! $signed && empty($this->updatePolicy['verify'])) {
            $this->upVerify = false;
            $this->upRefused = 'Signed releases are not available at the moment: Cipi releases are not signed yet. Use the delay instead.';

            return;
        }
        $payload = ['verify' => $this->upVerify, 'delay_hours' => (int) $this->upDelay];
        $firstTrust = $this->upVerify && empty($this->updatePolicy['signers_ready']);
        if ($firstTrust) {
            $fp = trim($this->upFingerprint);
            if (! preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $fp)) {
                $this->addError('upFingerprint', 'Paste the fingerprint you compared — SHA256:… as published on cipi.sh.');

                return;
            }
            $payload['fingerprint'] = $fp;
        }

        try {
            $this->syncUpdatePolicy($this->client()->setSelfUpdatePolicy($payload));
            $this->dispatch('notify', type: 'success', message: 'Update policy saved.');
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 422) {
                $this->upRefused = self::plainCliText($e->getMessage());

                return;
            }
            $this->handleApiError($e);
        }
    }
}
