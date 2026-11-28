<?php

namespace CipiGui\Support;

/**
 * What the Firewall tab shows and checks (4.6.0, API 1.44 / Cipi 5.14.0):
 * the rules of `GET /api/firewall` in words, the bans of fail2ban and CrowdSec
 * in one list, and the form values the API would refuse, refused first.
 * No framework: tests/standalone.php runs it.
 */
final class Firewall
{
    /** A port (1–65535) or a range (8000:8100), without leading zeros; null when it is neither. */
    public static function normalizePort(string $port): ?string
    {
        if (! preg_match('/^\s*(\d{1,5})(?::(\d{1,5}))?\s*$/', $port, $m)) {
            return null;
        }
        $lo = (int) $m[1];
        if ($lo < 1 || $lo > 65535) {
            return null;
        }
        if (! isset($m[2]) || $m[2] === '') {
            return (string) $lo;
        }
        $hi = (int) $m[2];

        return $hi > $lo && $hi <= 65535 ? $lo.':'.$hi : null;
    }

    /** An IPv4/IPv6 address, or a CIDR range of one. */
    public static function isAddress(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 49) {
            return false;
        }
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }
        [$ip, $mask] = explode('/', $value, 2);
        if (! ctype_digit($mask)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (int) $mask <= 32;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && (int) $mask <= 128;
    }

    /** Wider than IPv4 /8 or IPv6 /16: the API refuses to block it. */
    public static function tooWide(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }
        $mask = (int) substr($value, strpos($value, '/') + 1);

        return str_contains($value, ':') ? $mask < 16 : $mask < 8;
    }

    /** "8080/tcp", "80,443/tcp", "3000", "Every port". */
    public static function portLabel(array $rule): string
    {
        $port = (string) ($rule['port'] ?? 'any');
        $proto = (string) ($rule['proto'] ?? 'any');
        if ($port === 'any') {
            return $proto === 'any' ? 'Every port' : 'Every '.strtoupper($proto).' port';
        }

        return $proto === 'any' ? $port : $port.'/'.$proto;
    }

    public static function fromLabel(array $rule): string
    {
        $from = (string) ($rule['from'] ?? 'any');

        return $from === 'any' ? 'Anywhere' : $from;
    }

    public static function familyLabel(?string $family): string
    {
        return match ($family) {
            'v4' => 'IPv4',
            'v6' => 'IPv6',
            default => 'IPv4 + IPv6',
        };
    }

    /** The Cipi feature that owns a rule (its comment), in words. */
    public static function managedLabel(?string $managedBy): ?string
    {
        return match ($managedBy) {
            'zero-trust' => 'Zero Trust',
            'metrics' => 'Metrics',
            'crowdsec' => 'CrowdSec rescue',
            'cipi' => 'Cipi',
            default => null,
        };
    }

    /**
     * The Zero Trust locks of `GET /api/firewall`, in words: what is kept
     * closed, by whom, and the commands that reopen it on the server. A lock
     * is Cloudflare's (`cipi zt lock`) or the mesh network's (`cipi zt
     * tailscale|netbird lock`); a server that does not say who holds a lock
     * has Cloudflare's. `mesh` says whether a mesh network holds one of them:
     * those are also reopened from the Zero Trust tab. Null when nothing is
     * locked.
     *
     * @return array{text: string, commands: list<string>, mesh: bool}|null
     */
    public static function zeroTrustLocks(array $zeroTrust): ?array
    {
        $by = is_array($zeroTrust['by'] ?? null) ? $zeroTrust['by'] : [];
        $holders = static function (string $what) use ($zeroTrust, $by): array {
            if (empty($zeroTrust['lock_'.$what])) {
                return [];
            }
            $named = array_values(array_unique(array_filter(
                array_map(static fn ($h) => is_string($h) ? strtolower($h) : '', is_array($by[$what] ?? null) ? $by[$what] : []),
                static fn (string $h) => in_array($h, ['cloudflare', 'tailscale', 'netbird'], true),
            )));

            return $named !== [] ? $named : ['cloudflare'];
        };
        $http = $holders('http');
        $ssh = $holders('ssh');
        if ($http === [] && $ssh === []) {
            return null;
        }
        $name = static fn (string $h): string => match ($h) {
            'tailscale' => 'the Tailscale network',
            'netbird' => 'the NetBird network',
            default => 'Cloudflare',
        };
        $parts = [];
        $commands = [];
        if ($http !== []) {
            $parts[] = '80/443 to '.implode(' and ', array_map($name, $http));
            foreach ($http as $h) {
                $commands[] = $h === 'cloudflare' ? 'sudo cipi zt unlock http' : 'sudo cipi zt '.$h.' unlock http';
            }
        }
        if ($ssh !== []) {
            $mesh = array_values(array_filter($ssh, static fn (string $h) => $h !== 'cloudflare'));
            $parts[] = $mesh !== [] && count($mesh) === count($ssh)
                ? 'SSH to '.implode(' and ', array_map($name, $mesh))
                : 'SSH closed';
            foreach ($ssh as $h) {
                $commands[] = $h === 'cloudflare' ? 'sudo cipi zt ssh unlock' : 'sudo cipi zt '.$h.' unlock ssh';
            }
        }

        return [
            'text' => 'Zero Trust keeps '.implode(' and ', $parts).': opening '.($http !== [] ? 'them' : 'it').' to everyone from here is refused.',
            'commands' => $commands,
            'mesh' => array_diff(array_merge($http, $ssh), ['cloudflare']) !== [],
        ];
    }

    /**
     * The mesh network of `GET /api/zt` (`details.mesh`), in the words the
     * Zero Trust tab shows. Null when the server says nothing of a mesh.
     *
     * @return array{on: bool, label: string, badge: string, tone: string, key: ?string, ssh: string}|null
     */
    public static function mesh(mixed $mesh): ?array
    {
        if (! is_array($mesh)) {
            return null;
        }
        $on = ! empty($mesh['enabled']) && is_string($mesh['provider'] ?? null) && $mesh['provider'] !== '';
        $label = match ($mesh['provider'] ?? null) {
            'tailscale' => 'Tailscale',
            'netbird' => 'NetBird',
            default => is_string($mesh['label'] ?? null) && $mesh['label'] !== '' ? $mesh['label'] : 'Mesh',
        };
        if (! $on) {
            return ['on' => false, 'label' => $label, 'badge' => 'Off', 'tone' => 'gray', 'key' => null, 'ssh' => ''];
        }
        $connected = ! empty($mesh['connected']);
        $warnings = is_array($mesh['warnings'] ?? null) ? $mesh['warnings'] : [];
        $key = null;
        if (($mesh['provider'] ?? null) === 'tailscale') {
            $expiry = is_string($mesh['key_expiry'] ?? null) ? substr($mesh['key_expiry'], 0, 10) : null;
            $days = is_numeric($mesh['key_expires_in_days'] ?? null) ? (int) $mesh['key_expires_in_days'] : null;
            $key = $expiry === null ? 'Does not expire'
                : ($days !== null && $days <= 0 ? 'Expired on '.$expiry
                    : 'Expires on '.$expiry.($days !== null ? ' — in '.$days.' '.($days === 1 ? 'day' : 'days') : ''));
        }
        $ssh = is_array($mesh['ssh'] ?? null) && ! empty($mesh['ssh']['provider_ssh'])
            ? ($label === 'Tailscale' ? "Tailscale SSH — the tailnet's policy signs people in" : $label."'s own SSH server answers in place of OpenSSH")
            : "OpenSSH, with the server's keys";

        return [
            'on' => true,
            'label' => $label,
            'badge' => $label.' '.($connected ? 'connected' : 'disconnected'),
            'tone' => $connected ? ($warnings === [] ? 'green' : 'amber') : 'red',
            'key' => $key,
            'ssh' => $ssh,
        ];
    }

    /** Seconds the way `cipi firewall attempts` prints them: 1d, 6h, 30m, 45s. */
    public static function duration(mixed $seconds): string
    {
        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return '—';
        }
        $s = (int) $seconds;

        return match (true) {
            $s % 86400 === 0 => ($s / 86400).'d',
            $s % 3600 === 0 => ($s / 3600).'h',
            $s % 60 === 0 => ($s / 60).'m',
            default => $s.'s',
        };
    }

    /**
     * Every banned address — each fail2ban jail and CrowdSec's decisions — in
     * one list. An address banned in several places is one row with every
     * place named; one unban lifts all of them.
     *
     * @return list<array{ip: string, by: list<string>, reason: string, duration: string}>
     */
    public static function bans(array $firewall): array
    {
        $rows = [];
        foreach ((array) ($firewall['fail2ban']['jails'] ?? []) as $jail) {
            if (! is_array($jail)) {
                continue;
            }
            foreach ((array) ($jail['banned'] ?? []) as $ip) {
                if (! is_string($ip) || $ip === '') {
                    continue;
                }
                $rows[$ip] ??= ['ip' => $ip, 'by' => [], 'reason' => '', 'duration' => ''];
                $rows[$ip]['by'][] = 'fail2ban · '.(string) ($jail['jail'] ?? '');
            }
        }
        foreach ((array) ($firewall['crowdsec']['decisions'] ?? []) as $d) {
            $ip = is_array($d) ? (string) ($d['ip'] ?? '') : '';
            if ($ip === '') {
                continue;
            }
            $rows[$ip] ??= ['ip' => $ip, 'by' => [], 'reason' => '', 'duration' => ''];
            $rows[$ip]['by'][] = 'CrowdSec';
            $rows[$ip]['reason'] = (string) ($d['reason'] ?? '');
            $rows[$ip]['duration'] = (string) ($d['duration'] ?? '');
        }

        return array_values($rows);
    }

    /**
     * The rules in ufw's order, and apart from them the blocked addresses
     * (what `cipi firewall block` made).
     *
     * @return array{rules: list<array>, blocks: list<array>}
     */
    public static function sections(array $firewall): array
    {
        $rules = array_values(array_filter((array) ($firewall['ufw']['rules'] ?? []), 'is_array'));

        return [
            'rules' => array_values(array_filter($rules, fn ($r) => ($r['kind'] ?? '') !== 'block')),
            'blocks' => array_values(array_filter($rules, fn ($r) => ($r['kind'] ?? '') === 'block')),
        ];
    }
}
