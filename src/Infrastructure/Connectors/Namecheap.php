<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\NamecheapClient;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * Namecheap, with an API key from an allowlisted address: the domains with
 * their expiry and auto-renewal, nameservers (Namecheap's, custom, or the
 * Cloudflare zone's), and the host records of Namecheap's DNS, pointed at a
 * server of the panel by its name. Namecheap writes the records of a domain
 * all at once: the panel reads them, changes one, and writes them back.
 * https://www.namecheap.com/support/api/intro/
 */
final class Namecheap extends Connector
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'MXE', 'TXT', 'NS', 'CAA', 'URL', 'URL301', 'FRAME'];

    public function key(): string
    {
        return 'namecheap';
    }

    public function label(): string
    {
        return 'Namecheap';
    }

    public function description(): string
    {
        return __('Domains with expiry and auto-renewal, nameservers (Namecheap\'s, custom, or their Cloudflare zone), host records pointed at a server of the panel by its name.');
    }

    public function icon(): string
    {
        return 'globe';
    }

    public function category(): string
    {
        return 'dns';
    }

    public function docs(): string
    {
        return 'https://www.namecheap.com/support/api/intro/';
    }

    public function fields(): array
    {
        return [
            Field::text('api_user', __('API user'))->required()->help(__('Your Namecheap username.')),
            Field::password('api_key', __('API key'))->required(),
            Field::text('client_ip', __('Panel address (IPv4)'))->required()->placeholder('203.0.113.10')->rules(['ip'])
                ->help(__('The public IPv4 the panel calls from: Namecheap answers only allowlisted addresses.')),
            Field::checkbox('sandbox', __('Sandbox (api.sandbox.namecheap.com)')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('namecheap.com → Profile → Tools → Business & Dev Tools → Namecheap API Access → ON (the account needs $50 of balance, 20 domains or $50 spent in the last two years).'),
            __('Whitelisted IPs: add the public IPv4 of the server the panel runs on, and type the same one here.'),
            __('The API key is on the same page; the API user is your username.'),
        ];
    }

    public function client(array $credentials): NamecheapClient
    {
        $user = self::string($credentials, 'api_user');

        return new NamecheapClient(($credentials['sandbox'] ?? false) ? 'https://api.sandbox.namecheap.com' : 'https://api.namecheap.com', [], [
            'user' => $user,
            'key' => self::string($credentials, 'api_key'),
            'username' => $user,
            'ip' => self::string($credentials, 'client_ip'),
        ]);
    }

    public function verify(object $api): string
    {
        /** @var NamecheapClient $api */
        $balance = NamecheapClient::attributes($api->command('namecheap.users.getBalances'), 'UserGetBalancesResult')[0] ?? [];

        return 'ok · '.__('balance :amount :currency', ['amount' => $balance['AvailableBalance'] ?? '?', 'currency' => $balance['Currency'] ?? '']);
    }

    public function sections(): array
    {
        return [
            $this->domains(),
            $this->hosts(),
        ];
    }

    /**
     * SLD and TLD, as Namecheap asks for them: example and co.uk.
     *
     * @return array{SLD: string, TLD: string}
     */
    public static function split(string $domain): array
    {
        $parts = explode('.', strtolower($domain), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ConnectorException(__(':domain is not a domain.', ['domain' => $domain]));
        }

        return ['SLD' => $parts[0], 'TLD' => $parts[1]];
    }

    /**
     * The host records of a domain and how its mail is set.
     *
     * @return array{hosts: list<array<string, string>>, email: string}
     */
    public static function hostsOf(NamecheapClient $api, string $domain): array
    {
        $result = $api->command('namecheap.domains.dns.getHosts', self::split($domain))->DomainDNSGetHostsResult;

        return ['hosts' => NamecheapClient::attributes($result, 'host'), 'email' => (string) ($result['EmailType'] ?? '')];
    }

    /**
     * Writes every host record of a domain at once.
     *
     * @param  list<array<string, string>>  $hosts  Name, Type, Address, MXPref, TTL
     */
    public static function setHosts(NamecheapClient $api, string $domain, array $hosts, string $email = ''): void
    {
        $params = self::split($domain);
        $mx = false;

        foreach (array_values($hosts) as $i => $host) {
            $n = $i + 1;
            $params['HostName'.$n] = $host['Name'];
            $params['RecordType'.$n] = $host['Type'];
            $params['Address'.$n] = $host['Address'];
            $params['TTL'.$n] = $host['TTL'] ?? '1800';

            if (in_array($host['Type'], ['MX', 'MXE'], true)) {
                $params['MXPref'.$n] = $host['MXPref'] ?? '10';
                $mx = true;
            }
        }

        // Without MX records, keep whatever mail setting the domain had (forwarding, private email).
        $params['EmailType'] = $mx ? 'MX' : ($email !== '' && $email !== 'MX' ? $email : null);

        $api->command('namecheap.domains.dns.setHosts', $params);
    }

    private function domains(): Section
    {
        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->id('Name')
            ->list(function (NamecheapClient $api) {
                $rows = [];

                for ($page = 1; $page <= 20; $page++) {
                    $result = $api->command('namecheap.domains.getList', ['PageSize' => 100, 'Page' => $page]);
                    $batch = NamecheapClient::attributes($result->DomainGetListResult, 'Domain');
                    $rows = [...$rows, ...$batch];

                    if (count($batch) < 100) {
                        break;
                    }
                }

                return $rows;
            })
            ->columns([
                Column::make('Name', __('Domain'))->mono(),
                Column::make('Expires', __('Expires')),
                Column::make('AutoRenew', __('Auto-renew'))->bool()->using(fn ($row) => ($row['AutoRenew'] ?? '') === 'true'),
                Column::make('IsLocked', __('Locked'))->bool()->using(fn ($row) => ($row['IsLocked'] ?? '') === 'true'),
                Column::make('IsOurDNS', __('Namecheap DNS'))->bool()->using(fn ($row) => ($row['IsOurDNS'] ?? '') === 'true'),
                Column::make('IsExpired', __('Expired'))->bool()->using(fn ($row) => ($row['IsExpired'] ?? '') === 'true'),
            ])
            ->actions([
                Action::make('hosts', __('Host records'))->icon('list-bullet')->when(fn ($row) => ($row['IsOurDNS'] ?? '') === 'true')
                    ->opens('hosts', fn (array $row) => ['domain' => (string) $row['Name']]),
                Action::make('ns', __('Nameservers'))->icon('server')
                    ->run(function (NamecheapClient $api, array $row) {
                        $result = $api->command('namecheap.domains.dns.getList', self::split((string) $row['Name']))->DomainDNSGetListResult;
                        $servers = [];

                        foreach ($result->Nameserver as $ns) {
                            $servers[] = (string) $ns;
                        }

                        return Outcome::show(__('Nameservers of :domain', ['domain' => $row['Name']]), ((string) ($result['IsUsingOurDNS'] ?? '') === 'true' ? __('Namecheap\'s own')."\n" : '').implode("\n", $servers));
                    }),
                Action::make('ns-set', __('Set nameservers'))->icon('pencil')
                    ->fields([Field::textarea('ns', __('Nameservers'))->required()->placeholder("ns1.example.net\nns2.example.net")])
                    ->run(fn (NamecheapClient $api, array $row, array $data) => tap(__('Nameservers saved.'), fn () => $api->command('namecheap.domains.dns.setCustom', [...self::split((string) $row['Name']), 'Nameservers' => implode(',', self::lines($data['ns'] ?? ''))]))),
                Action::make('ns-default', __('Back to Namecheap DNS'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['IsOurDNS'] ?? '') !== 'true')
                    ->confirm(__('Put the domain back on Namecheap\'s nameservers?'))
                    ->run(fn (NamecheapClient $api, array $row) => tap(__('The domain is on Namecheap DNS.'), fn () => $api->command('namecheap.domains.dns.setDefault', self::split((string) $row['Name'])))),
                DnsPublisher::nameserversAction(fn (array $row) => (string) $row['Name'], fn (NamecheapClient $api, array $row, array $servers) => $api->command('namecheap.domains.dns.setCustom', [...self::split((string) $row['Name']), 'Nameservers' => implode(',', $servers)])),
            ]);
    }

    private function hosts(): Section
    {
        $fields = fn () => [
            Field::select('Type', __('Type'), array_combine(self::TYPES, self::TYPES))->required()->default(fn ($row) => $row['Type'] ?? 'A'),
            Field::text('Name', __('Host'))->required()->default(fn ($row) => $row['Name'] ?? '@')->help(__('@ for the domain itself, * for a wildcard.')),
            Field::suggest('Address', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['Address'] ?? null) ?? ($row['Address'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA).')),
            Field::number('TTL', __('TTL'))->default(fn ($row) => $row['TTL'] ?? 1800)->rules(['min:60', 'max:60000']),
            Field::number('MXPref', __('Priority (MX)'))->default(fn ($row) => $row['MXPref'] ?? 10),
        ];
        $host = fn (array $data) => [
            'Name' => (string) $data['Name'],
            'Type' => (string) $data['Type'],
            'Address' => (string) ServerTargets::resolve($data['Type'] ?? null, $data['Address'] ?? null),
            'TTL' => (string) ($data['TTL'] ?? 1800),
            'MXPref' => (string) ($data['MXPref'] ?? 10),
        ];
        $strip = fn (array $h) => array_intersect_key($h, array_flip(['Name', 'Type', 'Address', 'TTL', 'MXPref']));

        return Section::make('hosts', __('Host records'))
            ->childOf('domains', 'domain')
            ->id('HostId')
            ->list(fn (NamecheapClient $api, array $scope) => self::hostsOf($api, (string) $scope['domain'])['hosts'])
            ->columns([
                Column::make('Type', __('Type'))->badge(),
                Column::make('Name', __('Host'))->mono(),
                Column::make('Address', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['Address'] ?? null)),
                Column::make('MXPref', __('Priority'))->using(fn ($row) => in_array($row['Type'] ?? '', ['MX', 'MXE'], true) ? ($row['MXPref'] ?? '') : ''),
                Column::make('TTL', __('TTL')),
            ])
            ->create(__('New record'), $fields(), function (NamecheapClient $api, array $row, array $data, array $scope) use ($host, $strip) {
                $current = self::hostsOf($api, (string) $scope['domain']);
                self::setHosts($api, (string) $scope['domain'], [...array_map($strip, $current['hosts']), $host($data)], $current['email']);

                return __('Record created.');
            })
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields())
                    ->run(function (NamecheapClient $api, array $row, array $data, array $scope) use ($host, $strip) {
                        $current = self::hostsOf($api, (string) $scope['domain']);
                        $hosts = array_map(fn ($h) => ($h['HostId'] ?? '') === ($row['HostId'] ?? '') ? $host($data) : $strip($h), $current['hosts']);
                        self::setHosts($api, (string) $scope['domain'], $hosts, $current['email']);

                        return __('Record saved.');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(function (NamecheapClient $api, array $row, array $data, array $scope) use ($strip) {
                        $current = self::hostsOf($api, (string) $scope['domain']);
                        $hosts = array_values(array_map($strip, array_filter($current['hosts'], fn ($h) => ($h['HostId'] ?? '') !== ($row['HostId'] ?? ''))));
                        self::setHosts($api, (string) $scope['domain'], $hosts, $current['email']);

                        return __('Record deleted.');
                    }),
            ]);
    }
}
