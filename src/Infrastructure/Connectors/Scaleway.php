<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * Scaleway, with an API key's secret: Instances, flexible IPs, security
 * groups, DNS zones and records, managed databases and load balancers, per
 * zone or region (a filter of each page). https://www.scaleway.com/en/developers/api/
 */
final class Scaleway extends Connector
{
    private const ZONES = [
        'fr-par-1' => 'Paris 1', 'fr-par-2' => 'Paris 2', 'fr-par-3' => 'Paris 3',
        'nl-ams-1' => 'Amsterdam 1', 'nl-ams-2' => 'Amsterdam 2', 'nl-ams-3' => 'Amsterdam 3',
        'pl-waw-1' => 'Warsaw 1', 'pl-waw-2' => 'Warsaw 2', 'pl-waw-3' => 'Warsaw 3',
        'it-mil-1' => 'Milan 1',
    ];

    private const REGIONS = ['fr-par' => 'Paris', 'nl-ams' => 'Amsterdam', 'pl-waw' => 'Warsaw', 'it-mil' => 'Milan'];

    public function key(): string
    {
        return 'scaleway';
    }

    public function label(): string
    {
        return 'Scaleway';
    }

    public function description(): string
    {
        return __('Instances, flexible IPs, security groups, DNS zones and records, managed databases and load balancers.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://www.scaleway.com/en/docs/iam/how-to/create-api-keys/';
    }

    public function fields(): array
    {
        return [
            Field::password('secret_key', __('Secret key'))->required()->placeholder('00000000-0000-0000-0000-000000000000'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('console.scaleway.com → IAM → Applications → Create application (cipi-panel), then a policy for it: InstancesFullAccess, DomainsDNSFullAccess, RelationalDatabasesReadOnly, LoadBalancersReadOnly (or the FullAccess ones) on the project.'),
            __('The application → API keys → Generate API key: paste the secret key, shown once (the access key is not needed).'),
            __('Instances, IPs, security groups and load balancers have a zone filter, databases a region filter.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.scaleway.com', ['X-Auth-Token' => self::string($credentials, 'secret_key')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $servers = $api->get('/instance/v1/zones/fr-par-1/servers', ['per_page' => 1]);

        return 'ok · '.trans_choice(':count instance in Paris 1|:count instances in Paris 1', (int) ($servers['total_count'] ?? count((array) ($servers['servers'] ?? []))));
    }

    public function sections(): array
    {
        return [
            $this->servers(),
            $this->ips(),
            $this->securityGroups(),
            $this->zonesDns(),
            $this->records(),
            $this->databases(),
            $this->balancers(),
        ];
    }

    /**
     * Every page of a list: Scaleway answers with total_count.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key, array $query = []): array
    {
        $query = ['per_page' => 100, 'page' => 1, ...$query];
        $seen = 0;

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            function (array $answer) use ($key, &$seen) {
                $items = array_values(array_filter((array) ($answer[$key] ?? []), 'is_array'));
                $seen += count($items);

                return $items;
            },
            function (array $answer, int $page) use ($query, &$seen) {
                return $seen < (int) ($answer['total_count'] ?? 0) && $seen > 0 ? [...$query, 'page' => $page + 1] : null;
            },
        );
    }

    private static function zoneFilter(): Field
    {
        return Field::select('zone', __('Zone'), self::ZONES)->default('fr-par-1');
    }

    private static function regionFilter(): Field
    {
        return Field::select('region', __('Region'), self::REGIONS)->default('fr-par');
    }

    /** @param  array<string, mixed>  $scope */
    private static function zone(array $scope): string
    {
        $zone = (string) ($scope['zone'] ?? 'fr-par-1');

        return isset(self::ZONES[$zone]) ? $zone : 'fr-par-1';
    }

    /** @param  array<string, mixed>  $scope */
    private static function region(array $scope): string
    {
        $region = (string) ($scope['region'] ?? 'fr-par');

        return isset(self::REGIONS[$region]) ? $region : 'fr-par';
    }

    private function servers(): Section
    {
        $act = fn (ApiClient $api, array $row, array $scope, string $action) => $api->post('/instance/v1/zones/'.self::zone($scope).'/servers/'.$row['id'].'/action', ['action' => $action]);

        return Section::make('servers', __('Instances'))
            ->icon('server')
            ->filters([self::zoneFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/instance/v1/zones/'.self::zone($scope).'/servers', 'servers'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('state', __('State'))->badge(['running' => 'green', 'stopped' => 'zinc', 'stopped in place' => 'zinc', 'starting' => 'yellow', 'stopping' => 'yellow', 'locked' => 'red', '*' => 'zinc']),
                Column::make('commercial_type', __('Type'))->mono(),
                Column::make('public_ip', __('Address'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'public_ip.address') ?? data_get($row, 'public_ips.0.address'))),
                Column::make('image.name', __('Image')),
                Column::make('creation_date', __('Created'))->date(),
            ])
            ->actions([
                Action::make('poweron', __('Power on'))->icon('play')->when(fn ($row) => str_starts_with((string) ($row['state'] ?? ''), 'stopped'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Powering on.'), fn () => $act($api, $row, $scope, 'poweron'))),
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Reboot the instance?'))->when(fn ($row) => ($row['state'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rebooting.'), fn () => $act($api, $row, $scope, 'reboot'))),
                Action::make('poweroff', __('Power off'))->icon('pause')->confirm(__('Power off the instance? Its volumes are kept; a stopped instance is not billed for compute.'))->when(fn ($row) => ($row['state'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Powering off.'), fn () => $act($api, $row, $scope, 'poweroff'))),
                Action::make('terminate', __('Terminate'))->icon('trash')->danger(__('Terminate the instance and delete its volumes?'))->when(fn ($row) => ($row['state'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Terminating.'), fn () => $act($api, $row, $scope, 'terminate'))),
            ]);
    }

    private function ips(): Section
    {
        return Section::make('ips', __('Flexible IPs'))
            ->icon('globe-europe-africa')
            ->filters([self::zoneFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/instance/v1/zones/'.self::zone($scope).'/ips', 'ips'))
            ->columns([
                Column::make('address', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['address'] ?? null)),
                Column::make('type', __('Type')),
                Column::make('reverse', __('Reverse'))->mono(),
                Column::make('server.name', __('Attached to')),
            ]);
    }

    private function securityGroups(): Section
    {
        return Section::make('security-groups', __('Security groups'))
            ->icon('shield-check')
            ->filters([self::zoneFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/instance/v1/zones/'.self::zone($scope).'/security_groups', 'security_groups'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('inbound_default_policy', __('Inbound default'))->badge(['accept' => 'yellow', 'drop' => 'green']),
                Column::make('outbound_default_policy', __('Outbound default')),
                Column::make('servers', __('Instances'))->using(fn ($row) => collect(self::items($row['servers'] ?? []))->pluck('name')->implode(', ')),
                Column::make('stateful', __('Stateful'))->bool(),
            ]);
    }

    private function zonesDns(): Section
    {
        return Section::make('dns', __('DNS zones'))
            ->icon('globe-europe-africa')
            ->id(fn ($row) => trim(($row['subdomain'] ?? '') !== '' ? $row['subdomain'].'.'.$row['domain'] : (string) ($row['domain'] ?? ''), '.'))
            ->list(fn (ApiClient $api) => self::every($api, '/domain/v2beta1/dns-zones', 'dns_zones'))
            ->columns([
                Column::make('zone', __('Zone'))->mono()->using(fn ($row) => trim(($row['subdomain'] ?? '') !== '' ? $row['subdomain'].'.'.$row['domain'] : (string) ($row['domain'] ?? ''), '.')),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'pending' => 'yellow', 'error' => 'red', '*' => 'zinc']),
                Column::make('ns', __('Nameservers'))->mono(),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('records', fn ($row) => ['dns_zone' => trim(($row['subdomain'] ?? '') !== '' ? $row['subdomain'].'.'.$row['domain'] : (string) $row['domain'], '.')]),
            ]);
    }

    private function records(): Section
    {
        $patch = fn (ApiClient $api, array $scope, array $change) => $api->patch('/domain/v2beta1/dns-zones/'.rawurlencode((string) $scope['dns_zone']).'/records', ['changes' => [$change], 'return_all_records' => false]);

        return Section::make('records', __('DNS records'))
            ->childOf('dns', 'dns_zone')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/domain/v2beta1/dns-zones/'.rawurlencode((string) $scope['dns_zone']).'/records', 'records'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono()->using(fn ($row) => ($row['name'] ?? '') === '' ? '@' : $row['name']),
                Column::make('data', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['data'] ?? null)),
                Column::make('priority', __('Priority')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record'), [
                Field::select('type', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'ALIAS'], $t))->required()->default('A'),
                Field::text('name', __('Name'))->help(__('Empty for the zone itself')),
                Field::suggest('data', __('Value'), fn () => ServerTargets::options())->required()->help(__('An address, a name, or a server of the panel by its name (A, AAAA)')),
                Field::number('ttl', __('TTL'))->default(3600),
                Field::number('priority', __('Priority (MX, SRV)')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $patch($api, $scope, ['add' => ['records' => [array_filter([
                'name' => (string) ($data['name'] ?? ''), 'type' => $data['type'], 'data' => ServerTargets::resolve($data['type'], $data['data']),
                'ttl' => $data['ttl'] ?? 3600, 'priority' => $data['priority'],
            ], fn ($v) => $v !== null)]]])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))->when(fn ($row) => ! in_array($row['type'] ?? '', ['SOA'], true))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $patch($api, $scope, ['delete' => ['id' => $row['id']]]))),
            ]);
    }

    private function databases(): Section
    {
        return Section::make('databases', __('Databases'))
            ->icon('circle-stack')
            ->filters([self::regionFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/rdb/v1/regions/'.self::region($scope).'/instances', 'instances'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('engine', __('Engine')),
                Column::make('status', __('Status'))->badge(['ready' => 'green', 'provisioning' => 'yellow', 'backuping' => 'yellow', 'error' => 'red', '*' => 'zinc']),
                Column::make('node_type', __('Node')),
                Column::make('endpoint', __('Endpoint'))->mono()->using(fn ($row) => ($ip = data_get($row, 'endpoints.0.ip') ?? data_get($row, 'endpoint.ip')) ? $ip.':'.(data_get($row, 'endpoints.0.port') ?? data_get($row, 'endpoint.port')) : ''),
                Column::make('created_at', __('Created'))->date(),
            ]);
    }

    private function balancers(): Section
    {
        return Section::make('lbs', __('Load balancers'))
            ->icon('arrows-right-left')
            ->filters([self::zoneFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/lb/v1/zones/'.self::zone($scope).'/lbs', 'lbs'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(['ready' => 'green', 'pending' => 'yellow', 'error' => 'red', '*' => 'zinc']),
                Column::make('ip', __('Address'))->mono()->using(fn ($row) => collect(self::items($row['ip'] ?? []))->pluck('ip_address')->implode(', ')),
                Column::make('type', __('Type')),
                Column::make('frontend_count', __('Frontends')),
                Column::make('backend_count', __('Backends')),
            ]);
    }
}
