<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * Gandi, with a personal access token: the domains with their expiry and
 * auto-renewal, nameservers (by hand or to the Cloudflare zone), and the
 * records of LiveDNS, pointed at a server of the panel by its name.
 * https://api.gandi.net/docs/
 */
final class Gandi extends Connector
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'DNAME', 'PTR'];

    public function key(): string
    {
        return 'gandi';
    }

    public function label(): string
    {
        return 'Gandi';
    }

    public function description(): string
    {
        return __('Domains with expiry and auto-renewal, nameservers (by hand, or to their Cloudflare zone), LiveDNS records pointed at a server of the panel by its name.');
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
        return 'https://api.gandi.net/docs/authentication/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('Personal access token'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('admin.gandi.net → Organizations → your organization → Personal Access Tokens → Create a token.'),
            __('Permissions: Domains — "See and renew domain names" and "Manage domain name technical configurations" (nameservers, LiveDNS); restrict it to the domains you want, and set an expiry.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.gandi.net/v5', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        return 'ok · '.trans_choice(':count domain|:count domains', count($api->get('/domain/domains', ['per_page' => 500])));
    }

    public function sections(): array
    {
        return [
            $this->domains(),
            $this->records(),
        ];
    }

    /**
     * Values typed one per line: a TXT may hold commas.
     *
     * @return list<string>
     */
    private static function values(mixed $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', is_string($text) ? $text : '') ?: [])));
    }

    private function domains(): Section
    {
        $domain = fn (array $row) => '/domain/domains/'.rawurlencode((string) $row['fqdn']);

        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->id('fqdn')
            ->list(fn (ApiClient $api) => array_values(array_filter($api->get('/domain/domains', ['per_page' => 500]), 'is_array')))
            ->columns([
                Column::make('fqdn', __('Domain'))->mono(),
                Column::make('expires', __('Expires'))->date()->using(fn ($row) => (string) data_get($row, 'dates.registry_ends_at', '')),
                Column::make('autorenew', __('Auto-renew'))->bool()->using(fn ($row) => (bool) (is_array($row['autorenew'] ?? null) ? ($row['autorenew']['enabled'] ?? false) : ($row['autorenew'] ?? false))),
                Column::make('dns', __('DNS'))->badge(['livedns' => 'green', '*' => 'zinc'])->using(fn ($row) => (string) data_get($row, 'nameserver.current', '')),
                Column::make('status', __('Status'))->using(fn ($row) => implode(', ', (array) ($row['status'] ?? []))),
            ])
            ->actions([
                Action::make('records', __('LiveDNS records'))->icon('list-bullet')
                    ->when(fn ($row) => data_get($row, 'nameserver.current') === 'livedns')
                    ->opens('records', fn (array $row) => ['domain' => (string) $row['fqdn']]),
                Action::make('ns', __('Nameservers'))->icon('server')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Nameservers of :domain', ['domain' => $row['fqdn']]), implode("\n", array_map('strval', $api->get($domain($row).'/nameservers'))))),
                Action::make('ns-set', __('Set nameservers'))->icon('pencil')
                    ->fields([Field::textarea('ns', __('Nameservers'))->required()->placeholder("ns1.example.net\nns2.example.net")->help(__('One per line.'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Nameservers saved.'), fn () => $api->put($domain($row).'/nameservers', ['nameservers' => self::lines($data['ns'] ?? '')]))),
                Action::make('livedns', __('Back to LiveDNS'))->icon('arrow-uturn-left')->when(fn ($row) => data_get($row, 'nameserver.current') !== 'livedns')
                    ->confirm(__('Put the domain back on Gandi\'s LiveDNS? Its records there answer again.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('The domain is on LiveDNS.'), fn () => $api->post('/livedns/domains', ['fqdn' => $row['fqdn']]))),
                DnsPublisher::nameserversAction(fn (array $row) => (string) $row['fqdn'], fn (ApiClient $api, array $row, array $servers) => $api->put($domain($row).'/nameservers', ['nameservers' => $servers])),
                Action::make('autorenew-on', __('Renew automatically'))->icon('arrow-path')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Auto-renewal on.'), fn () => $api->patch($domain($row).'/autorenew', ['enabled' => true]))),
                Action::make('autorenew-off', __('Stop renewing'))->icon('pause')->confirm(__('Turn auto-renewal off? The domain expires at its date unless renewed by hand.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Auto-renewal off.'), fn () => $api->patch($domain($row).'/autorenew', ['enabled' => false]))),
            ]);
    }

    private function records(): Section
    {
        $path = fn (array $scope) => '/livedns/domains/'.rawurlencode((string) $scope['domain']).'/records';
        $fields = fn () => [
            Field::select('type', __('Type'), array_combine(self::TYPES, self::TYPES))->required()->default(fn ($row) => $row['rrset_type'] ?? 'A'),
            Field::text('name', __('Name'))->required()->default(fn ($row) => $row['rrset_name'] ?? '@')->help(__('@ for the domain itself, * for a wildcard.')),
            Field::textarea('values', __('Values'))->required()->default(fn ($row) => implode("\n", array_map(fn ($v) => ServerTargets::nameOf($v) ?? $v, (array) ($row['rrset_values'] ?? []))))
                ->help(__('One per line: an address, a name, or a server of the panel by its name (A, AAAA). MX: "10 mail.example.com.".')),
            Field::number('ttl', __('TTL'))->default(fn ($row) => $row['rrset_ttl'] ?? 10800)->rules(['min:300']),
        ];

        return Section::make('records', __('LiveDNS records'))
            ->childOf('domains', 'domain')
            ->id(fn (array $row) => $row['rrset_name'].'/'.$row['rrset_type'])
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter($api->get($path($scope)), 'is_array')))
            ->columns([
                Column::make('rrset_type', __('Type'))->badge(),
                Column::make('rrset_name', __('Name'))->mono(),
                Column::make('rrset_values', __('Values'))->mono()->using(fn ($row) => implode(', ', array_map(fn ($v) => ServerTargets::label($v), (array) ($row['rrset_values'] ?? [])))),
                Column::make('rrset_ttl', __('TTL')),
            ])
            ->create(__('New record'), $fields(), fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post($path($scope), [
                'rrset_name' => (string) $data['name'],
                'rrset_type' => (string) $data['type'],
                'rrset_values' => ServerTargets::resolveAll($data['type'] ?? null, self::values($data['values'] ?? '')),
                'rrset_ttl' => max(300, (int) ($data['ttl'] ?? 10800)),
            ])))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields())
                    ->run(function (ApiClient $api, array $row, array $data, array $scope) use ($path) {
                        $name = (string) $data['name'];
                        $type = (string) $data['type'];

                        if ($name !== $row['rrset_name'] || $type !== $row['rrset_type']) {
                            $api->delete($path($scope).'/'.rawurlencode((string) $row['rrset_name']).'/'.$row['rrset_type']);
                        }

                        $api->put($path($scope).'/'.rawurlencode($name).'/'.$type, [
                            'rrset_values' => ServerTargets::resolveAll($type, self::values($data['values'] ?? '')),
                            'rrset_ttl' => max(300, (int) ($data['ttl'] ?? 10800)),
                        ]);

                        return __('Record saved.');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record set?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $api->delete($path($scope).'/'.rawurlencode((string) $row['rrset_name']).'/'.$row['rrset_type']))),
            ]);
    }
}
