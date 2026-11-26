<?php

namespace CipiGui\Infrastructure;

use CipiGui\Models\CipiConnectorAccount;
use Closure;

/**
 * The DNS records a provider asks for (an email domain's DKIM and return
 * path, a scanner's ownership TXT) written straight into the Cloudflare zone
 * that holds the domain, on whichever Cloudflare account of the panel has
 * it. A record already there is left alone; a CNAME that points elsewhere is
 * reported, never overwritten.
 */
final class DnsPublisher
{
    /** Tests stand in here: fn (): list<ApiClient>, the Cloudflare clients to look in. */
    public static ?Closure $clientsUsing = null;

    private static ?bool $available = null;

    /** Forget what available() found (a Cloudflare account added or removed since). */
    public static function flush(): void
    {
        self::$available = null;
    }

    /** Is there a Cloudflare account to publish to? Asked once a request: every row asks. */
    public static function available(): bool
    {
        if (self::$clientsUsing !== null) {
            return (self::$clientsUsing)() !== [];
        }

        try {
            return self::$available ??= CipiConnectorAccount::query()->where('connector', 'cloudflare')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The row action that publishes the records of a row.
     *
     * @param  Closure(object, array<string, mixed>, array<string, mixed>): array{0: string, 1: list<array<string, mixed>>}  $records  the domain and its records
     */
    public static function action(Closure $records, string $label = 'Publish DNS on Cloudflare'): Action
    {
        return Action::make('publish-dns', __($label))->icon('cloud')
            ->confirm(__('Add the records this asks for to the Cloudflare zone of the domain? Records already there are kept.'))
            ->when(fn () => self::available())
            ->run(function (object $api, array $row, array $data, array $scope) use ($records) {
                [$domain, $list] = $records($api, $row, $scope);

                return Outcome::show(__('DNS of :domain on Cloudflare', ['domain' => $domain]), self::publish($domain, $list), __('Records published.'));
            });
    }

    /**
     * Writes the records into the zone of $domain.
     *
     * @param  list<array<string, mixed>>  $records  each: type, name (the full name), content, and priority for MX
     */
    public static function publish(string $domain, array $records): string
    {
        $records = array_values(array_filter($records, fn ($r) => is_array($r) && ($r['type'] ?? '') !== '' && ($r['name'] ?? '') !== '' && ($r['content'] ?? '') !== ''));

        if ($records === []) {
            throw new ConnectorException(__('The provider asks for no DNS record here.'));
        }

        [$api, $zone] = self::zone($domain) ?? throw new ConnectorException(__("No Cloudflare account of the panel has the zone of :domain. Add these records at its DNS:\n:records", ['domain' => $domain, 'records' => self::text($records)]));

        $lines = [];

        foreach ($records as $record) {
            $type = strtoupper((string) $record['type']);
            $name = rtrim(strtolower((string) $record['name']), '.');
            $content = (string) $record['content'];
            $existing = (array) ($api->get('/zones/'.$zone.'/dns_records', ['type' => $type, 'name' => $name])['result'] ?? []);

            $same = array_filter($existing, fn ($r) => is_array($r) && self::same($type, (string) ($r['content'] ?? ''), $content));

            if ($same !== []) {
                $lines[] = '= '.$type.' '.$name.' — '.__('already there');

                continue;
            }

            if ($type === 'CNAME' && $existing !== []) {
                $lines[] = '! '.$type.' '.$name.' — '.__('points to :other: change it by hand if this one should win', ['other' => (string) ($existing[0]['content'] ?? '?')]);

                continue;
            }

            $api->post('/zones/'.$zone.'/dns_records', array_filter([
                'type' => $type,
                'name' => $name,
                'content' => $type === 'CNAME' ? rtrim($content, '.') : $content,
                'ttl' => 1,
                'proxied' => in_array($type, ['A', 'AAAA', 'CNAME'], true) ? false : null,
                'priority' => $type === 'MX' ? (int) ($record['priority'] ?? 10) : null,
            ], fn ($v) => $v !== null));

            $lines[] = '+ '.$type.' '.$name.' → '.$content;
        }

        return implode("\n", $lines);
    }

    /**
     * The records as one would type them at any DNS.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public static function text(array $records): string
    {
        return implode("\n", array_map(
            fn ($r) => sprintf('%-6s %s → %s%s', strtoupper((string) ($r['type'] ?? '')), (string) ($r['name'] ?? ''), (string) ($r['content'] ?? ''), isset($r['priority']) && $r['priority'] !== null && $r['priority'] !== '' ? ' (priority '.$r['priority'].')' : ''),
            $records,
        ));
    }

    /** The full name of a record a provider gives relative to its domain ("send", "@", "resend._domainkey"). */
    public static function fqdn(string $name, string $domain): string
    {
        $name = rtrim(strtolower(trim($name)), '.');
        $domain = rtrim(strtolower($domain), '.');

        return match (true) {
            $name === '' || $name === '@' => $domain,
            $name === $domain || str_ends_with($name, '.'.$domain) => $name,
            default => $name.'.'.$domain,
        };
    }

    /**
     * The nameservers Cloudflare gave the zone of exactly $domain, for a
     * registrar's "point to Cloudflare"; null when no account has the zone.
     *
     * @return list<string>|null
     */
    public static function cloudflareNameservers(string $domain): ?array
    {
        $domain = rtrim(strtolower($domain), '.');

        foreach (self::clients() as $api) {
            try {
                $zone = $api->get('/zones', ['name' => $domain])['result'][0] ?? null;
            } catch (ConnectorException) {
                continue;
            }

            if (is_array($zone) && ($zone['name'] ?? '') === $domain) {
                $servers = array_values(array_filter((array) ($zone['name_servers'] ?? []), 'is_string'));

                if ($servers !== []) {
                    return $servers;
                }
            }
        }

        return null;
    }

    /**
     * The row action of a registrar: the domain's nameservers set to its Cloudflare zone's.
     *
     * @param  Closure(object, array<string, mixed>, list<string>): void  $set  writes the nameservers at the registrar
     * @param  Closure(array<string, mixed>): string  $domainOf
     */
    public static function nameserversAction(Closure $domainOf, Closure $set): Action
    {
        return Action::make('to-cloudflare', __('Point to Cloudflare'))->icon('cloud')
            ->confirm(__('Set the domain\'s nameservers to the ones of its Cloudflare zone? DNS moves to Cloudflare as the change propagates.'))
            ->when(fn () => self::available())
            ->run(function (object $api, array $row) use ($domainOf, $set) {
                $domain = $domainOf($row);
                $servers = self::cloudflareNameservers($domain)
                    ?? throw new ConnectorException(__('No Cloudflare account of the panel has a zone for :domain: add it there first (Cloudflare → Zones → Add a zone).', ['domain' => $domain]));

                $set($api, $row, $servers);

                return __('Nameservers of :domain: :servers.', ['domain' => $domain, 'servers' => implode(', ', $servers)]);
            });
    }

    /**
     * The Cloudflare zone holding $domain, longest match first.
     *
     * @return array{0: ApiClient, 1: string}|null
     */
    private static function zone(string $domain): ?array
    {
        $labels = explode('.', rtrim(strtolower($domain), '.'));
        $candidates = [];

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        foreach (self::clients() as $api) {
            foreach ($candidates as $candidate) {
                try {
                    $zone = ($api->get('/zones', ['name' => $candidate])['result'][0]['id'] ?? null);
                } catch (ConnectorException) {
                    continue 2;
                }

                if (is_string($zone) && $zone !== '') {
                    return [$api, $zone];
                }
            }
        }

        return null;
    }

    /** @return list<ApiClient> */
    private static function clients(): array
    {
        if (self::$clientsUsing !== null) {
            return (self::$clientsUsing)();
        }

        $clients = [];

        foreach (CipiConnectorAccount::query()->where('connector', 'cloudflare')->orderBy('name')->get() as $account) {
            $client = $account->client();

            if ($client instanceof ApiClient) {
                $clients[] = $client;
            }
        }

        return $clients;
    }

    private static function same(string $type, string $a, string $b): bool
    {
        $normal = fn (string $v) => $type === 'TXT' ? trim($v, '"') : rtrim(strtolower($v), '.');

        return $normal($a) === $normal($b);
    }
}
