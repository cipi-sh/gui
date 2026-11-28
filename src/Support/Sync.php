<?php

namespace CipiGui\Support;

/**
 * The transfer wizard of a server's page (apps sent to another server with
 * `cipi sync`): what does not need the framework.
 */
final class Sync
{
    /** An address or a hostname, as the server takes it for a target. */
    public const HOST_RE = '/^[A-Za-z0-9]([A-Za-z0-9.:-]{0,252}[A-Za-z0-9])?$/';

    /**
     * Where SSH reaches a server of the panel: its IP when the panel knows
     * it, the host of its API URL otherwise. Only a suggestion — the field
     * stays editable, since the address two servers reach each other on
     * (a private network, a mesh) is not always the one the panel uses.
     */
    public static function sshHost(?string $ip, ?string $url): string
    {
        $ip = trim((string) $ip);
        if ($ip !== '' && preg_match(self::HOST_RE, $ip)) {
            return $ip;
        }
        $host = (string) parse_url(trim((string) $url), PHP_URL_HOST);

        return preg_match(self::HOST_RE, $host) ? $host : '';
    }

    /**
     * The apps a transfer can move, by name: `cipi sync` does not recreate
     * Node apps yet — those are created on the target and deployed from Git.
     *
     * @param  list<array{app: string, kind?: string}>  $apps
     * @return list<string>
     */
    public static function movable(array $apps): array
    {
        return array_values(array_map(
            fn (array $app) => (string) $app['app'],
            array_filter($apps, fn (array $app) => ($app['kind'] ?? '') !== 'node' && ($app['app'] ?? '') !== ''),
        ));
    }

    /**
     * The fields of the form, checked before anything is asked of a server.
     *
     * @param  list<string>  $apps      the ones ticked
     * @param  list<string>  $existing  the apps of the source a transfer can move
     * @return array<string, string> field => what is wrong
     */
    public static function errors(string $host, string $port, bool $all, array $apps, array $existing): array
    {
        $errors = [];
        if (! preg_match(self::HOST_RE, trim($host))) {
            $errors['syncHost'] = 'An IP address or a hostname the other server answers SSH on.';
        }
        if (! ctype_digit(trim($port)) || (int) $port < 1 || (int) $port > 65535) {
            $errors['syncPort'] = 'A port from 1 to 65535.';
        }
        if ($existing === []) {
            $errors['syncApps'] = 'This server has no app a transfer can move.';
        } elseif (! $all) {
            if ($apps === []) {
                $errors['syncApps'] = 'Choose at least one app, or send them all.';
            } elseif (array_diff($apps, $existing) !== []) {
                $errors['syncApps'] = 'One of the apps cannot be sent: it is not on this server any more, or it is a Node app. Reload the page.';
            }
        }

        return $errors;
    }

    /**
     * What the check of a target said, for the page: [state, sentence].
     * state: ok | old | trust | error.
     *
     * @param  array<string, mixed>|null  $check
     * @return array{0: string, 1: string}
     */
    public static function verdict(?array $check, bool $peer): array
    {
        if ($check === null) {
            return ['none', ''];
        }
        if (! empty($check['ok'])) {
            return empty($check['auto_passphrase'])
                ? ['old', 'The target answers, but its Cipi is too old for a transfer from the panel: update it there (cipi self-update), then check again.']
                : ['ok', 'The target accepts this server\'s key and Cipi answers there.'];
        }

        return match ((string) ($check['reason'] ?? '')) {
            'not-trusted' => ['trust', $peer
                ? 'The target does not accept this server\'s key. Its token may lack the sync-manage ability: the key could not be added there.'
                : 'The target does not trust this server\'s key yet: add the key below on it, then check again.'],
            'unreachable' => ['error', 'The target does not answer over SSH at that address and port — from this server, not from your browser. A private or mesh address may be the one to use.'],
            'host-key-changed' => ['error', 'The host key of the target is not the one this server knows. If the target was rebuilt, remove the old key on this server (ssh-keygen -R <address> as root), then check again.'],
            'no-cipi' => ['error', 'SSH answers at that address, but Cipi does not: it is not a Cipi server, or its cipi user cannot run it.'],
            'no-key' => ['error', 'This server has no sync key: sudo -u cipi ssh-keygen -t ed25519 -f /home/cipi/.ssh/id_ed25519 -N "" on it.'],
            default => ['error', trim((string) ($check['message'] ?? '')) !== '' ? (string) $check['message'] : 'The target could not be checked.'],
        };
    }

    /**
     * The sentence of the confirmation: what will be on the target afterwards.
     *
     * @param  list<string>  $apps
     */
    public static function summary(bool $all, array $apps, int $total, bool $db, bool $storage, string $target): string
    {
        $what = $all
            ? ($total === 1 ? 'the app of this server' : "all {$total} apps of this server")
            : (count($apps) === 1 ? "the app {$apps[0]}" : count($apps).' apps ('.implode(', ', $apps).')');
        $with = match (true) {
            $db && $storage => 'with their databases and uploaded files',
            $db => 'with their databases',
            $storage => 'with their uploaded files',
            default => 'without databases or uploaded files: settings, .env and deploy recipe only',
        };

        return "Send {$what} to {$target}, {$with}? Apps that are already there are updated"
            .($db || $storage ? ' — their '.($db ? 'database' : '').($db && $storage ? ' and ' : '').($storage ? 'storage' : '').' there is replaced' : '')
            .'; the others are created. Nothing is removed or stopped on this server.';
    }
}
