<?php

namespace CipiGui\Support;

/**
 * The mesh network of Zero Trust (Tailscale, NetBird) as the Zero Trust tab
 * manages it: what a join form may send, and what each button may do on this
 * server right now.
 *
 * The page decides nothing: the server does. Before a click it says what it
 * would refuse and why (`details.mesh.panel.blocked` of `GET /api/zt`), and
 * these helpers only put that into words — a button greyed out with its
 * reason next to it instead of an error after the click. Whatever is sent,
 * the CLI holds the line again.
 */
class Mesh
{
    public const PROVIDERS = ['tailscale' => 'Tailscale', 'netbird' => 'NetBird'];

    /** A join key as the server takes it: no spaces, no quotes. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._~:?&=%+\/-]{8,512}$/';

    public const HOSTNAME_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

    public const TAG_PATTERN = '/^(tag:)?[a-zA-Z][a-zA-Z0-9-]{0,62}$/';

    public const URL_PATTERN = '/^https?:\/\/[A-Za-z0-9._-]+(:[0-9]{1,5})?(\/[A-Za-z0-9._~\/-]*)?$/';

    public static function label(mixed $provider): string
    {
        return self::PROVIDERS[is_string($provider) ? $provider : ''] ?? 'Mesh';
    }

    /** What the key of a provider is called, where it is made. */
    public static function keyName(string $provider): string
    {
        return $provider === 'netbird' ? 'Setup key' : 'Auth key';
    }

    /**
     * What is wrong with a join form, field by field; [] when it can be sent.
     *
     * @param  array{provider?: mixed, key?: mixed, hostname?: mixed, control_url?: mixed, tags?: mixed}  $form
     * @return array<string, string>
     */
    public static function joinErrors(array $form): array
    {
        $errors = [];
        $provider = is_string($form['provider'] ?? null) ? $form['provider'] : '';
        if (! isset(self::PROVIDERS[$provider])) {
            $errors['provider'] = 'Tailscale or NetBird.';
        }
        $key = trim((string) ($form['key'] ?? ''));
        if ($key !== '' && ! preg_match(self::KEY_PATTERN, $key)) {
            $errors['key'] = 'That does not look like a key: 8 to 512 characters, no spaces or quotes.';
        }
        $hostname = trim((string) ($form['hostname'] ?? ''));
        if ($hostname !== '' && ! preg_match(self::HOSTNAME_PATTERN, $hostname)) {
            $errors['hostname'] = 'Letters, digits and hyphens, up to 63 — the name of the machine on the network.';
        }
        $url = trim((string) ($form['control_url'] ?? ''));
        if ($url !== '' && (strlen($url) > 255 || ! preg_match(self::URL_PATTERN, $url))) {
            $errors['control_url'] = 'The address of the coordination server: https://host or https://host:port.';
        }
        if ($provider === 'tailscale' && self::tags($form['tags'] ?? '') === null) {
            $errors['tags'] = 'Tags are letters, digits and hyphens, separated by commas: cipi, prod.';
        }

        return $errors;
    }

    /**
     * Tags as the API takes them, from what was typed ("cipi, tag:prod").
     * [] for none; null when one is not a tag.
     *
     * @return list<string>|null
     */
    public static function tags(mixed $typed): ?array
    {
        $out = [];
        foreach (explode(',', is_string($typed) ? $typed : '') as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if (! preg_match(self::TAG_PATTERN, $tag)) {
                return null;
            }
            $out[] = str_starts_with($tag, 'tag:') ? $tag : 'tag:'.$tag;
        }

        return array_values(array_unique($out));
    }

    /**
     * The body of `POST /api/zt/mesh` for a form {@see joinErrors} found
     * nothing wrong with: only what was filled in, only what the provider has.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function joinPayload(array $form): array
    {
        $provider = (string) $form['provider'];
        $payload = ['provider' => $provider];
        foreach (['key', 'hostname', 'control_url'] as $field) {
            $value = trim((string) ($form[$field] ?? ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }
        foreach (['direct', 'accept_dns', 'accept_routes'] as $flag) {
            if (! empty($form[$flag])) {
                $payload[$flag] = true;
            }
        }
        if ($provider === 'tailscale') {
            $tags = self::tags($form['tags'] ?? '') ?? [];
            if ($tags !== []) {
                $payload['tags'] = $tags;
            }
            if (! empty($form['ssh'])) {
                $payload['ssh'] = true;
            }
        }

        return $payload;
    }

    /**
     * What each button of the card may do now.
     *
     * `managed` is false when the server reports a mesh but not what the panel
     * may do with it: it is then changed at the server's shell.
     *
     * @return array{managed: bool, lock_ssh: ?string, lock_http: ?string, leave: ?string}
     *         each of the three: null when the server would do it, else why not
     */
    public static function blocked(mixed $mesh): array
    {
        $panel = is_array($mesh) && is_array($mesh['panel'] ?? null) ? $mesh['panel'] : null;
        $blocked = is_array($panel['blocked'] ?? null) ? $panel['blocked'] : [];
        $reason = static fn (string $k): ?string => is_string($blocked[$k] ?? null) && trim($blocked[$k]) !== '' ? trim($blocked[$k]) : null;

        return [
            'managed' => $panel !== null,
            'lock_ssh' => $reason('lock_ssh'),
            'lock_http' => $reason('lock_http'),
            'leave' => $reason('disable'),
        ];
    }

    /** How the panel reaches this server's API, in a sentence; null when the server does not say. */
    public static function route(mixed $mesh): ?string
    {
        $panel = is_array($mesh) && is_array($mesh['panel'] ?? null) ? $mesh['panel'] : null;
        if ($panel === null) {
            return null;
        }
        $label = self::label($mesh['provider'] ?? null);
        $from = is_string($panel['from'] ?? null) && filter_var($panel['from'], FILTER_VALIDATE_IP) ? ' (from '.$panel['from'].')' : '';
        $client = is_array($panel['client'] ?? null) ? $panel['client'] : null;
        $you = $client !== null && is_string($client['from'] ?? null) && filter_var($client['from'], FILTER_VALIDATE_IP) ? ' (from '.$client['from'].')' : '';

        return match ($panel['via'] ?? null) {
            'mesh' => 'This panel reaches the server over '.$label.$from.': closing 80/443 to the world does not cut it off — leaving the network would.',
            'local' => 'This panel runs on the server itself. '.match ($client['via'] ?? null) {
                'mesh' => 'You are looking at it over '.$label.$you.': closing 80/443 to the world keeps this page.',
                'tunnel' => 'You reach it through the Cloudflare tunnel: closing 80/443 to the world keeps this page.',
                'local' => 'You are looking at it from the server itself: closing 80/443 to the world keeps this page.',
                'public', 'unknown' => 'You are looking at it on its public address'.$you.': closing 80/443 to the world would close this page too, so the server refuses it from here — open the panel on its '.$label.' address first.',
                default => 'Its pages are on 80/443 like every site: closed to the world, they answer on the network only.',
            },
            'tunnel' => 'This panel reaches the server through its Cloudflare tunnel: closing 80/443 to the world does not cut it off.',
            'public' => 'This panel reaches the server on its public address'.$from.': closing 80/443 to the world would cut it off, so the server refuses it from here.',
            default => 'The server could not tell how this panel reaches it'.$from.': it refuses to close 80/443 from here.',
        };
    }

    /** The last SSH login the server saw come over the mesh — the proof `lock ssh` asks for. */
    public static function seen(mixed $mesh): ?string
    {
        $seen = is_array($mesh) && is_array($mesh['ssh'] ?? null) && is_array($mesh['ssh']['seen'] ?? null) ? $mesh['ssh']['seen'] : null;
        if ($seen === null) {
            return null;
        }
        $from = is_string($seen['from'] ?? null) && filter_var($seen['from'], FILTER_VALIDATE_IP) ? $seen['from'] : null;
        $at = is_string($seen['at'] ?? null) ? trim($seen['at']) : '';
        if ($from === null && $at === '') {
            return null;
        }

        return 'Last login over the network'.($from !== null ? ' from '.$from : '').($at !== '' ? ' · '.mb_substr($at, 0, 40) : '');
    }
}
