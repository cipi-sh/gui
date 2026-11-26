<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\OvhClient;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * OVHcloud, with an application key, its secret and a consumer key: VPS,
 * dedicated servers, Public Cloud projects and their instances, DNS zones
 * and their records. OVH lists names only: the details of each are asked
 * one by one, up to a cap per page. https://api.ovh.com/console/
 */
final class Ovh extends Connector
{
    private const ENDPOINTS = [
        'ovh-eu' => 'https://eu.api.ovh.com/1.0',
        'ovh-ca' => 'https://ca.api.ovh.com/1.0',
        'ovh-us' => 'https://api.us.ovhcloud.com/1.0',
    ];

    /** How many items of a list are asked about one by one. */
    private const CAP = 60;

    public function key(): string
    {
        return 'ovh';
    }

    public function label(): string
    {
        return 'OVHcloud';
    }

    public function short(): string
    {
        return 'OVH';
    }

    public function description(): string
    {
        return __('VPS, dedicated servers, Public Cloud instances, DNS zones and records.');
    }

    public function icon(): string
    {
        return 'server-stack';
    }

    public function docs(): string
    {
        return 'https://help.ovhcloud.com/csm/en-gb-api-getting-started-ovhcloud-api?id=kb_article_view&sysparm_article=KB0042784';
    }

    public function fields(): array
    {
        return [
            Field::select('endpoint', __('Region'), ['ovh-eu' => 'OVHcloud Europe', 'ovh-ca' => 'OVHcloud Canada', 'ovh-us' => 'OVHcloud US'])->required()->default('ovh-eu'),
            Field::text('application_key', __('Application key'))->required(),
            Field::password('application_secret', __('Application secret'))->required(),
            Field::password('consumer_key', __('Consumer key'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Europe: eu.api.ovh.com/createToken — Canada: ca.api.ovh.com/createToken — US: api.us.ovhcloud.com/createToken. Sign in with the account that owns the services.'),
            __('Rights: GET /*, POST /*, PUT /*, DELETE /* for the whole account — or narrower, e.g. GET /vps*, POST /vps/*/reboot, GET /domain/zone*, POST /domain/zone/*, PUT /domain/zone/*, DELETE /domain/zone/*, GET /cloud/*, POST /cloud/*.'),
            __('Validity: unlimited, or a date to renew it here. The page shows the application key, the application secret and the consumer key at once: paste the three.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $base = self::ENDPOINTS[self::string($credentials, 'endpoint', 'ovh-eu')] ?? self::ENDPOINTS['ovh-eu'];

        return new OvhClient(
            $base,
            self::string($credentials, 'application_key'),
            self::string($credentials, 'application_secret'),
            self::string($credentials, 'consumer_key'),
        );
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $me = $api->get('/me');

        return 'ok · '.trim(($me['nichandle'] ?? '').' '.(($me['email'] ?? '') !== '' ? '· '.$me['email'] : ''));
    }

    public function sections(): array
    {
        return [
            $this->vps(),
            $this->dedicated(),
            $this->projects(),
            $this->instances(),
            $this->zones(),
            $this->records(),
        ];
    }

    /**
     * The names OVH lists, each asked about, up to the cap.
     *
     * @return list<array<string, mixed>>
     */
    private static function detailed(ApiClient $api, string $path, ?callable $extra = null): array
    {
        $names = array_slice(array_values(array_filter((array) $api->get($path), 'is_scalar')), 0, self::CAP);
        $rows = [];

        foreach ($names as $name) {
            try {
                $row = $api->get($path.'/'.rawurlencode((string) $name));
            } catch (ConnectorException) {
                $row = [];
            }
            $row = ['_name' => (string) $name, ...$row];
            if ($extra !== null) {
                $row = [...$row, ...$extra($api, (string) $name)];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function vps(): Section
    {
        $at = fn (array $row) => '/vps/'.rawurlencode((string) $row['_name']);

        return Section::make('vps', __('VPS'))
            ->icon('server')
            ->id('_name')
            ->list(fn (ApiClient $api) => self::detailed($api, '/vps', function (ApiClient $api, string $name) {
                try {
                    return ['_ips' => array_values(array_filter((array) $api->get('/vps/'.rawurlencode($name).'/ips'), 'is_string'))];
                } catch (ConnectorException) {
                    return ['_ips' => []];
                }
            }))
            ->columns([
                Column::make('_name', __('VPS'))->mono(),
                Column::make('displayName', __('Name')),
                Column::make('state', __('State'))->badge(['running' => 'green', 'stopped' => 'zinc', 'installing' => 'yellow', 'maintenance' => 'yellow', 'rebooting' => 'yellow', '*' => 'zinc']),
                Column::make('model.name', __('Model')),
                Column::make('zone', __('Zone')),
                Column::make('_ips', __('Addresses'))->mono()->using(fn ($row) => implode(', ', array_map(fn ($ip) => ServerTargets::label($ip), (array) ($row['_ips'] ?? [])))),
            ])
            ->actions([
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Reboot the VPS?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rebooting.'), fn () => $api->post($at($row).'/reboot'))),
                Action::make('stop', __('Stop'))->icon('pause')->confirm(__('Stop the VPS? It stays off until you start it.'))->when(fn ($row) => ($row['state'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Stopping.'), fn () => $api->post($at($row).'/stop'))),
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => ($row['state'] ?? '') === 'stopped')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Starting.'), fn () => $api->post($at($row).'/start'))),
                Action::make('rename', __('Rename'))->icon('pencil')->fields([Field::text('displayName', __('Name'))->required()->default(fn ($row) => $row['displayName'] ?? '')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Renamed.'), fn () => $api->put($at($row), ['displayName' => $data['displayName']]))),
            ]);
    }

    private function dedicated(): Section
    {
        return Section::make('dedicated', __('Dedicated servers'))
            ->icon('server-stack')
            ->id('_name')
            ->list(fn (ApiClient $api) => self::detailed($api, '/dedicated/server'))
            ->columns([
                Column::make('_name', __('Server'))->mono(),
                Column::make('reverse', __('Reverse'))->mono(),
                Column::make('ip', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['ip'] ?? null)),
                Column::make('state', __('State'))->badge(['ok' => 'green', 'hacked' => 'red', 'hackedblocked' => 'red', '*' => 'yellow']),
                Column::make('datacenter', __('Datacenter')),
                Column::make('commercialRange', __('Range')),
                Column::make('os', __('OS')),
            ])
            ->actions([
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Hard-reboot the server (power cycle)?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Reboot requested.'), fn () => $api->post('/dedicated/server/'.rawurlencode((string) $row['_name']).'/reboot'))),
            ]);
    }

    private function projects(): Section
    {
        return Section::make('projects', __('Public Cloud'))
            ->icon('cloud')
            ->id('_name')
            ->list(fn (ApiClient $api) => self::detailed($api, '/cloud/project'))
            ->columns([
                Column::make('description', __('Project')),
                Column::make('_name', __('ID'))->mono(),
                Column::make('status', __('Status'))->badge(['ok' => 'green', 'creating' => 'yellow', '*' => 'red']),
                Column::make('creationDate', __('Created'))->date(),
            ])
            ->actions([
                Action::make('instances', __('Instances'))->icon('server')->opens('instances', fn ($row) => ['project' => (string) $row['_name'], 'project_name' => (string) (($row['description'] ?? '') ?: $row['_name'])]),
            ]);
    }

    private function instances(): Section
    {
        $at = fn (array $scope, array $row) => '/cloud/project/'.rawurlencode((string) $scope['project']).'/instance/'.rawurlencode((string) $row['id']);

        return Section::make('instances', __('Instances'))
            ->childOf('projects', 'project_name')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter((array) $api->get('/cloud/project/'.rawurlencode((string) $scope['project']).'/instance'), 'is_array')))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'build' => 'yellow', 'reboot' => 'yellow', 'shutoff' => 'zinc', 'error' => 'red', '*' => 'zinc']),
                Column::make('region', __('Region')),
                Column::make('flavorId', __('Flavor'))->mono(),
                Column::make('ips', __('Addresses'))->mono()->using(fn ($row) => collect(self::items($row['ipAddresses'] ?? []))->where('type', 'public')->pluck('ip')->map(fn ($ip) => ServerTargets::label($ip))->implode(', ')),
                Column::make('created', __('Created'))->date(),
            ])
            ->actions([
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Reboot the instance?'))->when(fn ($row) => strtoupper((string) ($row['status'] ?? '')) === 'ACTIVE')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rebooting.'), fn () => $api->post($at($scope, $row).'/reboot', ['type' => 'soft']))),
                Action::make('stop', __('Stop'))->icon('pause')->confirm(__('Stop the instance? OVHcloud still bills it while it is off.'))->when(fn ($row) => strtoupper((string) ($row['status'] ?? '')) === 'ACTIVE')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Stopping.'), fn () => $api->post($at($scope, $row).'/stop'))),
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => strtoupper((string) ($row['status'] ?? '')) === 'SHUTOFF')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Starting.'), fn () => $api->post($at($scope, $row).'/start'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the instance and its local disk?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Deleting.'), fn () => $api->delete($at($scope, $row)))),
            ]);
    }

    private function zones(): Section
    {
        return Section::make('zones', __('DNS zones'))
            ->icon('globe-europe-africa')
            ->id('_name')
            ->list(fn (ApiClient $api) => self::detailed($api, '/domain/zone'))
            ->columns([
                Column::make('_name', __('Zone'))->mono(),
                Column::make('nameServers', __('Nameservers'))->mono(),
                Column::make('dnssecSupported', __('DNSSEC'))->bool(),
                Column::make('lastUpdate', __('Updated'))->date(),
            ])
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('records', fn ($row) => ['zone' => (string) $row['_name'], 'zone_name' => (string) $row['_name']]),
                Action::make('refresh', __('Publish'))->icon('arrow-path')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone published.'), fn () => $api->post('/domain/zone/'.rawurlencode((string) $row['_name']).'/refresh'))),
            ]);
    }

    private function records(): Section
    {
        $zone = fn (array $scope) => '/domain/zone/'.rawurlencode((string) $scope['zone']);
        $publish = fn (ApiClient $api, array $scope) => $api->post($zone($scope).'/refresh');

        return Section::make('records', __('DNS records'))
            ->childOf('zones', 'zone_name')
            ->list(function (ApiClient $api, array $scope) use ($zone) {
                $ids = array_slice(array_values(array_filter((array) $api->get($zone($scope).'/record'), 'is_scalar')), 0, self::CAP * 4);
                $rows = [];
                foreach ($ids as $id) {
                    try {
                        $rows[] = $api->get($zone($scope).'/record/'.$id);
                    } catch (ConnectorException) {
                    }
                }

                return $rows;
            })
            ->columns([
                Column::make('fieldType', __('Type'))->badge(),
                Column::make('subDomain', __('Name'))->mono()->using(fn ($row) => ($row['subDomain'] ?? '') === '' ? '@' : $row['subDomain']),
                Column::make('target', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['target'] ?? null)),
                Column::make('ttl', __('TTL'))->using(fn ($row) => ($row['ttl'] ?? 0) ?: __('zone default')),
            ])
            ->create(__('New record'), [
                Field::select('fieldType', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'], $t))->required()->default('A'),
                Field::text('subDomain', __('Name'))->help(__('Empty for the zone itself, or www, api…')),
                Field::suggest('target', __('Value'), fn () => ServerTargets::options())->required()->help(__('An address, a name, or a server of the panel by its name (A, AAAA). MX as "10 mail.example.com."')),
                Field::number('ttl', __('TTL'))->help(__('Empty: the zone default')),
            ], function (ApiClient $api, array $row, array $data, array $scope) use ($zone, $publish) {
                $api->post($zone($scope).'/record', array_filter([
                    'fieldType' => $data['fieldType'], 'subDomain' => (string) ($data['subDomain'] ?? ''),
                    'target' => ServerTargets::resolve($data['fieldType'], $data['target']), 'ttl' => $data['ttl'],
                ], fn ($v) => $v !== null));
                $publish($api, $scope);

                return __('Record created and published.');
            })
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields([
                    Field::text('subDomain', __('Name'))->default(fn ($row) => $row['subDomain'] ?? ''),
                    Field::suggest('target', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['target'] ?? null) ?? ($row['target'] ?? '')),
                    Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? null),
                ])->run(function (ApiClient $api, array $row, array $data, array $scope) use ($zone, $publish) {
                    $api->put($zone($scope).'/record/'.$row['id'], array_filter([
                        'subDomain' => (string) ($data['subDomain'] ?? ''), 'target' => ServerTargets::resolve($row['fieldType'] ?? null, $data['target']), 'ttl' => $data['ttl'],
                    ], fn ($v) => $v !== null));
                    $publish($api, $scope);

                    return __('Record saved and published.');
                }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))->when(fn ($row) => ! in_array($row['fieldType'] ?? '', ['SOA'], true))
                    ->run(function (ApiClient $api, array $row, array $data, array $scope) use ($zone, $publish) {
                        $api->delete($zone($scope).'/record/'.$row['id']);
                        $publish($api, $scope);

                        return __('Record deleted and published.');
                    }),
            ]);
    }
}
