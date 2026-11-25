<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Firewall as F;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Firewall (API 1.44+ / Cipi ≥ 5.14.0): the server's Firewall tab.
 *
 *  - UFW: the rules, opening a port (to everyone or to an address), removing
 *    what the panel or `cipi firewall allow` opened, blocking an address;
 *  - fail2ban: failed SSH logins before a ban, the banned addresses, unban;
 *  - CrowdSec: on/off (jobs), its allowlist, its decisions (unbanned with the
 *    same button as fail2ban's);
 *  - the visitor's address behind Cloudflare (`cipi nginx real-ip`): on,
 *    Cloudflare's ranges fetched again, off — what fail2ban and CrowdSec ban.
 *
 * Reading needs a level on the server; changing needs `manage`. What keeps the
 * server reachable — its SSH port, 80, 443, a Zero Trust lock, the panel's own
 * address — is refused by the API and the CLI whatever is sent from here; the
 * page does not offer it either. Every value an action takes is one the page
 * lists (the rules, the bans, the allowlist are locked), never one the request
 * made up.
 */
class Firewall extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    public bool $loaded = false;

    public bool $unsupported = false;

    public string $unsupportedReason = '';

    /** `GET /api/firewall`, as the server last answered. */
    #[Locked]
    public array $firewall = [];

    // ── forms ──
    public string $rulePort = '';

    public string $ruleFrom = '';

    public string $ruleProto = 'tcp';

    public string $blockAddress = '';

    public string $attempts = '';

    public string $allowAddress = '';

    public function mount(?int $serverId = null): void
    {
        $this->serverId = $serverId ?? (int) session('cipi_gui_server_id');
    }

    public function init(): void
    {
        $this->load();
    }

    public function canManage(): bool
    {
        return Access::canManageServer($this->panelUser(), (int) ($this->serverId ?? 0));
    }

    protected function mayRead(): bool
    {
        $id = (int) ($this->serverId ?? 0);

        return $id !== 0 && Access::canViewServer($this->panelUser(), $id);
    }

    public function load(): void
    {
        if (! $this->mayRead()) {
            $this->loaded = true;

            return;
        }

        try {
            $this->sync($this->client()->firewall());
            $this->unsupported = false;
            $this->unsupportedReason = '';
            $this->error = null;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->unsupported = true;
                $this->unsupportedReason = $this->friendlyApiError($e);
            } else {
                $this->error = $this->friendlyApiError($e);
            }
        } finally {
            $this->loaded = true;
        }
    }

    protected function sync(?array $firewall): void
    {
        if ($firewall === null) {
            // The change went through; the read after it did not: ask again.
            $firewall = $this->client()->firewall();
        }
        $this->firewall = $firewall;
        $this->attempts = isset($firewall['fail2ban']['attempts']) ? (string) $firewall['fail2ban']['attempts'] : '';
    }

    // ── UFW ───────────────────────────────────────────────────────────

    public function addRule(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $port = F::normalizePort($this->rulePort);
        $from = trim($this->ruleFrom);
        if ($port === null) {
            $this->addError('rulePort', 'A port (1-65535) or a range such as 8000:8100.');
        }
        if ($from !== '' && ! F::isAddress($from)) {
            $this->addError('ruleFrom', 'An IPv4/IPv6 address or a CIDR range — or empty for everyone.');
        }
        if (! in_array($this->ruleProto, ['tcp', 'udp'], true)) {
            $this->addError('ruleProto', 'TCP or UDP.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $label = $port.'/'.$this->ruleProto.($from !== '' ? ' from '.$from : '');
        if ($this->change(fn (CipiApiClient $api) => $api->firewallAllow($port, $from !== '' ? $from : null, $this->ruleProto), 'Opened '.$label.'.')) {
            $this->reset(['rulePort', 'ruleFrom']);
            $this->ruleProto = 'tcp';
        }
    }

    /** A rule the page lists, by its place in the list; only one the server says may be removed. */
    public function removeRule(int $index): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $rule = $this->firewall['ufw']['rules'][$index] ?? null;
        if (! is_array($rule) || ! ($rule['removable'] ?? false) || ($rule['kind'] ?? '') !== 'port') {
            $this->dispatch('notify', type: 'error', message: 'This rule is not removed from the panel.');

            return;
        }

        $from = ($rule['from'] ?? 'any') === 'any' ? null : (string) $rule['from'];
        $this->change(
            fn (CipiApiClient $api) => $api->firewallRemove((string) $rule['port'], $from, (string) ($rule['proto'] ?? 'tcp'), (string) ($rule['action'] ?? 'allow')),
            'Removed '.F::portLabel($rule).($from ? ' from '.$from : '').'.',
        );
    }

    public function blockAddress(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $address = trim($this->blockAddress);
        if (! F::isAddress($address)) {
            $this->addError('blockAddress', 'An IPv4/IPv6 address or a CIDR range.');

            return;
        }
        if (F::tooWide($address)) {
            $this->addError('blockAddress', 'Too wide a range: IPv4 /8 and IPv6 /16 at most.');

            return;
        }

        if ($this->change(fn (CipiApiClient $api) => $api->firewallBlock($address), 'Blocked '.$address.': no port of the server answers it.')) {
            $this->blockAddress = '';
        }
    }

    public function unblock(string $address): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $listed = array_column(F::sections($this->firewall)['blocks'], 'from');
        if (! in_array($address, $listed, true)) {
            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->firewallUnblock($address), 'Unblocked '.$address.'.');
    }

    // ── fail2ban ──────────────────────────────────────────────────────

    public function saveAttempts(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        if (! preg_match('/^\d{1,3}$/', trim($this->attempts)) || (int) $this->attempts < 1 || (int) $this->attempts > 100) {
            $this->addError('attempts', 'From 1 to 100.');

            return;
        }

        $n = (int) $this->attempts;
        $this->change(fn (CipiApiClient $api) => $api->firewallAttempts($n), $n === 1
            ? 'One failed SSH login now bans the address — a mistyped password bans its owner too.'
            : $n.' failed SSH logins now ban the address.');
    }

    public function defaultAttempts(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->firewallAttempts(null), 'Back to the default threshold.');
    }

    /** An address the page lists as banned — by fail2ban, CrowdSec or both. */
    public function unban(string $ip): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        if (! in_array($ip, array_column(F::bans($this->firewall), 'ip'), true)) {
            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->firewallUnban($ip), 'Unbanned '.$ip.'.');
    }

    // ── The visitor's address behind Cloudflare ───────────────────────

    public function enableRealIp(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $was = (bool) ($this->firewall['real_ip']['enabled'] ?? false);
        $this->change(fn (CipiApiClient $api) => $api->firewallRealIpEnable(), $was
            ? 'Cloudflare\'s ranges fetched again.'
            : 'nginx now takes the visitor\'s address from Cloudflare.');
    }

    public function refreshRealIp(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->firewallRealIpRefresh(), 'Cloudflare\'s ranges fetched again.');
    }

    /** Only what the server says may be turned off: on, and kept by this setting alone (not Zero Trust). */
    public function disableRealIp(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        if (! ($this->firewall['real_ip']['can_disable'] ?? false)) {
            $this->dispatch('notify', type: 'error', message: 'This is not turned off from the panel.');

            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->firewallRealIpDisable(), 'nginx sees the address of each connection again.');
    }

    // ── CrowdSec ──────────────────────────────────────────────────────

    public function enableCrowdsec(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->startJob('Enable CrowdSec', fn (CipiApiClient $api) => $api->crowdsecEnable());
    }

    public function disableCrowdsec(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->startJob('Disable CrowdSec', fn (CipiApiClient $api) => $api->crowdsecDisable());
    }

    public function crowdsecAllow(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $address = trim($this->allowAddress);
        if (! F::isAddress($address)) {
            $this->addError('allowAddress', 'An IPv4/IPv6 address or a CIDR range.');

            return;
        }

        if ($this->change(fn (CipiApiClient $api) => $api->crowdsecAllow($address), $address.' is never banned by CrowdSec.')) {
            $this->allowAddress = '';
        }
    }

    public function crowdsecUnallow(string $address): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        if (! in_array($address, (array) ($this->firewall['crowdsec']['allowlist'] ?? []), true)) {
            return;
        }

        $this->change(fn (CipiApiClient $api) => $api->crowdsecUnallow($address), $address.' removed from CrowdSec\'s allowlist.');
    }

    protected function onJobCompleted(array $data): void
    {
        $this->load();
    }

    /** An enable that stopped halfway may have installed part of it: show what the server has now. */
    protected function onJobFailed(array $data): void
    {
        $this->load();
    }

    /**
     * A synchronous change; the API answers with the firewall after it.
     *
     * @param  callable(CipiApiClient): array{data: ?array, message: string}  $call
     */
    protected function change(callable $call, string $success): bool
    {
        try {
            $result = $call($this->client());
            $this->sync($result['data'] ?? null);
            $this->error = null;
            $this->dispatch('notify', type: 'success', message: $success);

            return true;
        } catch (CipiApiException $e) {
            $this->handleApiError($e);

            return false;
        }
    }

    public function render()
    {
        return view('cipi-gui::livewire.firewall', [
            'canManage' => $this->canManage(),
            'sections' => F::sections($this->firewall),
            'bans' => F::bans($this->firewall),
        ]);
    }
}
