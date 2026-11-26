<?php

namespace CipiGui\Infrastructure;

/**
 * A provider the panel drives through its API: what an account needs to sign
 * in, how to check it, and the lists and actions of its page. A connector
 * describes; the page (CipiGui\Livewire\Infrastructure) renders and runs, and
 * Settings → Connectors keeps its accounts.
 */
abstract class Connector
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** A Heroicon name for the menu. */
    abstract public function icon(): string;

    /** Where the provider explains how to create the credentials. */
    abstract public function docs(): string;

    /** The panel's own step-by-step: where to create the credentials, with which permissions. */
    public function guide(): string
    {
        return 'https://cipi.sh/docs/gui-connectors#'.$this->key();
    }

    /**
     * Where the credentials are made and the permissions they need, step by
     * step: shown in Settings → Connectors next to the form that saves them.
     *
     * @return list<string>
     */
    public function instructions(): array
    {
        return [];
    }

    /** A short name for the badge of the provider (DO, AWS, GCP…). */
    public function short(): string
    {
        return $this->label();
    }

    /** Where Settings → Connectors lists it: a key of Connectors::CATEGORIES. */
    public function category(): string
    {
        return 'cloud';
    }

    /**
     * The other groups of Settings → Connectors it also belongs to, with what
     * it does there: AWS is servers, but also S3, SES and CloudWatch.
     *
     * @return array<string, string> category => what this connector does there
     */
    public function alsoIn(): array
    {
        return [];
    }

    /**
     * What an account needs: tokens, keys, the address of a self-hosted instance.
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * The API client signed in with an account's credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    abstract public function client(array $credentials): object;

    /**
     * Do the credentials work? A short status for the accounts list ("ok · who"),
     * or a ConnectorException.
     */
    abstract public function verify(object $api): string;

    /**
     * The tabs of the page, the first one opens.
     *
     * @return list<Section>
     */
    abstract public function sections(): array;

    /** Asks again about work the provider runs in the background (Outcome::pending). */
    public function poll(object $api, string $token): Outcome
    {
        throw new ConnectorException(__('This connector has nothing to check again.'));
    }

    public function section(string $key): ?Section
    {
        foreach ($this->sections() as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        return null;
    }

    /** The address of a self-hosted instance, as typed: with https:// and no trailing slash. */
    protected static function instanceUrl(mixed $url): string
    {
        $url = trim(is_string($url) ? $url : '');

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    /** @param  array<string, mixed>  $credentials */
    protected static function string(array $credentials, string $key, string $default = ''): string
    {
        $value = $credentials[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * A list or a map out of a decoded answer, or nothing: what a provider
     * sends is untyped, and a field it leaves out is no error.
     *
     * @return array<mixed>
     */
    protected static function items(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Firewall ports as typed in a form: "22, 80, 443, 8000-8100, 53/udp, icmp".
     * TCP unless a protocol follows a slash.
     *
     * @return list<array{protocol: string, port: string|null}>
     */
    protected static function ports(mixed $text): array
    {
        $rules = [];

        foreach (preg_split('/[\s,;]+/', is_string($text) ? strtolower($text) : '') ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if ($token === 'icmp') {
                $rules[] = ['protocol' => 'icmp', 'port' => null];

                continue;
            }

            if (! preg_match('#^(\d{1,5}(?:-\d{1,5})?|any|all)(?:/(tcp|udp))?$#', $token, $match)) {
                throw new ConnectorException(__('":token" is not a port: use 22, 8000-8100, 53/udp or icmp.', ['token' => $token]));
            }

            $rules[] = ['protocol' => $match[2] ?? 'tcp', 'port' => in_array($match[1], ['any', 'all'], true) ? 'all' : $match[1]];
        }

        return $rules;
    }

    /**
     * Names or values typed one per line or separated by commas.
     *
     * @return list<string>
     */
    protected static function lines(mixed $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', is_string($text) ? $text : '') ?: [])));
    }
}
