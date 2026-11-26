<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;
use CipiGui\Infrastructure\Tokens;

/**
 * Microsoft Azure, with a service principal on a subscription: virtual
 * machines, resource groups, public IP addresses, network security groups
 * and their rules, DNS zones and storage accounts, through Azure Resource
 * Manager. https://learn.microsoft.com/rest/api/azure/
 */
final class Azure extends Connector
{
    private const ARM = 'https://management.azure.com';

    private const COMPUTE = '2024-07-01';

    private const NETWORK = '2024-01-01';

    private const DNS = '2018-05-01';

    private const RESOURCES = '2021-04-01';

    private const STORAGE = '2023-05-01';

    private const LOCATIONS = [
        'westeurope' => 'West Europe (Netherlands)', 'northeurope' => 'North Europe (Ireland)', 'italynorth' => 'Italy North (Milan)',
        'germanywestcentral' => 'Germany West Central', 'francecentral' => 'France Central', 'switzerlandnorth' => 'Switzerland North',
        'uksouth' => 'UK South', 'swedencentral' => 'Sweden Central', 'spaincentral' => 'Spain Central',
        'eastus' => 'East US', 'eastus2' => 'East US 2', 'westus2' => 'West US 2', 'centralus' => 'Central US', 'canadacentral' => 'Canada Central',
        'brazilsouth' => 'Brazil South', 'southeastasia' => 'Southeast Asia', 'japaneast' => 'Japan East', 'australiaeast' => 'Australia East', 'centralindia' => 'Central India',
    ];

    public function key(): string
    {
        return 'azure';
    }

    public function label(): string
    {
        return 'Microsoft Azure';
    }

    public function short(): string
    {
        return 'Azure';
    }

    public function description(): string
    {
        return __('Virtual machines, resource groups, public IP addresses, network security groups, DNS zones and storage accounts of a subscription.');
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function docs(): string
    {
        return 'https://learn.microsoft.com/entra/identity-platform/howto-create-service-principal-portal';
    }

    public function fields(): array
    {
        return [
            Field::text('tenant_id', __('Tenant ID'))->required()->placeholder('00000000-0000-0000-0000-000000000000')->help(__('Microsoft Entra ID → Overview → Tenant ID (or the tenant domain)')),
            Field::text('client_id', __('Application (client) ID'))->required()->placeholder('00000000-0000-0000-0000-000000000000'),
            Field::password('client_secret', __('Client secret'))->required()->help(__('The secret\'s Value, not its ID')),
            Field::text('subscription_id', __('Subscription ID'))->required()->placeholder('00000000-0000-0000-0000-000000000000'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('portal.azure.com → Microsoft Entra ID → App registrations → New registration (cipi-panel, single tenant): its Application (client) ID and the Tenant ID are on its overview.'),
            __('The app → Certificates & secrets → New client secret: copy its Value now, it is shown once.'),
            __('Subscriptions → the subscription → Access control (IAM) → Add role assignment → Contributor (or Reader, plus Virtual Machine Contributor, Network Contributor and DNS Zone Contributor) → Members: the app.'),
            __('One account per subscription.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $sub = self::string($credentials, 'subscription_id');

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $sub)) {
            throw new ConnectorException(__('The subscription ID is a GUID: Subscriptions → the subscription → Subscription ID.'));
        }

        $token = Tokens::azure(self::string($credentials, 'tenant_id'), self::string($credentials, 'client_id'), self::string($credentials, 'client_secret'));

        return new ScopedClient(self::ARM.'/subscriptions/'.$sub, ['Authorization' => 'Bearer '.$token], ['subscription' => $sub]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $sub = $api->get($api->baseUrl, ['api-version' => '2022-12-01']);

        return 'ok · '.($sub['displayName'] ?? $api->scope('subscription')).' · '.($sub['state'] ?? '');
    }

    public function sections(): array
    {
        return [
            $this->vms(),
            $this->groups(),
            $this->publicIps(),
            $this->nsgs(),
            $this->nsgRules(),
            $this->dnsZones(),
            $this->dnsRecords(),
            $this->storage(),
        ];
    }

    /**
     * Every page of a list (nextLink).
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter((array) ($answer['value'] ?? []), 'is_array')),
            fn (array $answer) => is_string($answer['nextLink'] ?? null) && str_starts_with($answer['nextLink'], self::ARM) ? $answer['nextLink'] : null,
        );
    }

    /** /subscriptions/…/resourceGroups/web/providers/… → web */
    private static function group(mixed $id): string
    {
        return preg_match('#/resourceGroups/([^/]+)#i', is_string($id) ? $id : '', $m) ? $m[1] : '';
    }

    /** A resource by its id, as an address: https://management.azure.com/<id><suffix>?api-version= */
    private static function at(array $row, string $suffix, string $version): string
    {
        $id = (string) ($row['id'] ?? '');

        if (! str_starts_with($id, '/subscriptions/')) {
            throw new ConnectorException(__('Azure answered with an id the panel does not recognise.'));
        }

        return self::ARM.$id.$suffix.'?api-version='.$version;
    }

    private static function power(array $row): string
    {
        foreach ((array) data_get($row, 'properties.instanceView.statuses', []) as $status) {
            $code = (string) ($status['code'] ?? '');
            if (str_starts_with($code, 'PowerState/')) {
                return substr($code, 11);
            }
        }

        return (string) data_get($row, 'properties.provisioningState', '');
    }

    private function vms(): Section
    {
        return Section::make('vms', __('Virtual machines'))
            ->icon('server')
            ->list(fn (ApiClient $api) => self::every($api, '/providers/Microsoft.Compute/virtualMachines', ['api-version' => self::COMPUTE, 'statusOnly' => 'true']))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('power', __('State'))->using(fn ($row) => self::power($row))->badge(['running' => 'green', 'starting' => 'yellow', 'stopping' => 'yellow', 'deallocating' => 'yellow', 'stopped' => 'zinc', 'deallocated' => 'zinc', '*' => 'zinc']),
                Column::make('group', __('Resource group'))->using(fn ($row) => self::group($row['id'] ?? '')),
                Column::make('location', __('Location')),
                Column::make('properties.hardwareProfile.vmSize', __('Size')),
                Column::make('properties.storageProfile.osDisk.osType', __('OS')),
            ])
            ->actions([
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => in_array(self::power($row), ['stopped', 'deallocated'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Starting.'), fn () => $api->post(self::at($row, '/start', self::COMPUTE)))),
                Action::make('restart', __('Restart'))->icon('arrow-path')->confirm(__('Restart the virtual machine?'))->when(fn ($row) => self::power($row) === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Restarting.'), fn () => $api->post(self::at($row, '/restart', self::COMPUTE)))),
                Action::make('stop', __('Stop'))->icon('pause')->confirm(__('Power off the machine? Azure still bills its compute until it is deallocated.'))->when(fn ($row) => self::power($row) === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Stopping.'), fn () => $api->post(self::at($row, '/powerOff', self::COMPUTE)))),
                Action::make('deallocate', __('Deallocate'))->icon('power')->confirm(__('Stop and release the machine\'s hardware? Billing for compute stops; a dynamic public IP is lost.'))->when(fn ($row) => in_array(self::power($row), ['running', 'stopped'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deallocating.'), fn () => $api->post(self::at($row, '/deallocate', self::COMPUTE)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the virtual machine? Its disks and network interface stay in the resource group.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleting.'), fn () => $api->delete(self::at($row, '', self::COMPUTE)))),
            ]);
    }

    private function groups(): Section
    {
        return Section::make('groups', __('Resource groups'))
            ->icon('archive-box')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/resourcegroups', ['api-version' => self::RESOURCES]))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('location', __('Location')),
                Column::make('properties.provisioningState', __('State'))->badge(['succeeded' => 'green', 'deleting' => 'red', '*' => 'yellow']),
            ])
            ->create(__('New resource group'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[-\w.()]{1,90}$/']),
                Field::select('location', __('Location'), self::LOCATIONS)->required()->default('westeurope'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Resource group created.'), fn () => $api->put('/resourcegroups/'.rawurlencode((string) $data['name']).'?api-version='.self::RESOURCES, ['location' => $data['location']])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the resource group and EVERY resource in it?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleting the group and what it holds.'), fn () => $api->delete('/resourcegroups/'.rawurlencode((string) $row['name']).'?api-version='.self::RESOURCES))),
            ]);
    }

    private function publicIps(): Section
    {
        return Section::make('ips', __('Public IP addresses'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/providers/Microsoft.Network/publicIPAddresses', ['api-version' => self::NETWORK]))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('address', __('Address'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'properties.ipAddress'))),
                Column::make('properties.publicIPAllocationMethod', __('Allocation')),
                Column::make('sku.name', __('SKU')),
                Column::make('attached', __('Attached to'))->using(fn ($row) => ($id = data_get($row, 'properties.ipConfiguration.id')) ? explode('/', (string) $id)[8] ?? '' : ''),
                Column::make('group', __('Resource group'))->using(fn ($row) => self::group($row['id'] ?? '')),
                Column::make('location', __('Location')),
            ])
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the address? Detach it from its interface first.'))->when(fn ($row) => data_get($row, 'properties.ipConfiguration') === null)
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleted.'), fn () => $api->delete(self::at($row, '', self::NETWORK)))),
            ]);
    }

    private function nsgs(): Section
    {
        return Section::make('nsgs', __('Network security groups'))
            ->icon('shield-check')
            ->list(fn (ApiClient $api) => self::every($api, '/providers/Microsoft.Network/networkSecurityGroups', ['api-version' => self::NETWORK]))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('group', __('Resource group'))->using(fn ($row) => self::group($row['id'] ?? '')),
                Column::make('location', __('Location')),
                Column::make('rules', __('Rules'))->using(fn ($row) => count((array) data_get($row, 'properties.securityRules', []))),
            ])
            ->actions([
                Action::make('rules', __('Rules'))->icon('list-bullet')->opens('nsg_rules', fn ($row) => ['nsg' => (string) $row['id'], 'nsg_name' => (string) $row['name']]),
            ]);
    }

    private function nsgRules(): Section
    {
        $nsg = fn (array $scope) => (string) $scope['nsg'];

        return Section::make('nsg_rules', __('Security rules'))
            ->childOf('nsgs', 'nsg_name')
            ->list(fn (ApiClient $api, array $scope) => array_values((array) data_get($api->get(self::at(['id' => $nsg($scope)], '', self::NETWORK)), 'properties.securityRules', [])))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('properties.priority', __('Priority')),
                Column::make('properties.direction', __('Direction'))->badge(['inbound' => 'blue', 'outbound' => 'zinc']),
                Column::make('properties.access', __('Access'))->badge(['allow' => 'green', 'deny' => 'red']),
                Column::make('properties.protocol', __('Protocol')),
                Column::make('ports', __('Ports'))->mono()->using(fn ($row) => data_get($row, 'properties.destinationPortRange') ?? implode(',', (array) data_get($row, 'properties.destinationPortRanges', []))),
                Column::make('from', __('From'))->mono()->using(fn ($row) => data_get($row, 'properties.sourceAddressPrefix') ?? implode(',', (array) data_get($row, 'properties.sourceAddressPrefixes', []))),
            ])
            ->create(__('New rule'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[-\w.]{1,80}$/'])->default('allow-https'),
                Field::number('priority', __('Priority'))->required()->default(300)->help(__('100–4096: lower runs first')),
                Field::select('access', __('Access'), ['Allow' => __('Allow'), 'Deny' => __('Deny')])->required()->default('Allow'),
                Field::select('protocol', __('Protocol'), ['Tcp' => 'TCP', 'Udp' => 'UDP', 'Icmp' => 'ICMP', '*' => __('Any')])->required()->default('Tcp'),
                Field::text('ports', __('Destination ports'))->required()->default('443')->help(__('443, 80, 8000-8100 or *')),
                Field::text('from', __('Source'))->required()->default('*')->help(__('*, Internet, an address or a range')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule created.'), fn () => $api->put(self::at(['id' => $nsg($scope)], '/securityRules/'.rawurlencode((string) $data['name']), self::NETWORK), ['properties' => [
                'priority' => (int) $data['priority'], 'direction' => 'Inbound', 'access' => $data['access'], 'protocol' => $data['protocol'],
                'sourcePortRange' => '*', 'destinationAddressPrefix' => '*',
                ...(str_contains((string) $data['ports'], ',') ? ['destinationPortRanges' => self::lines($data['ports'])] : ['destinationPortRange' => (string) $data['ports']]),
                'sourceAddressPrefix' => (string) $data['from'],
            ]])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this rule?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rule deleted.'), fn () => $api->delete(self::at($row, '', self::NETWORK)))),
            ]);
    }

    private function dnsZones(): Section
    {
        return Section::make('dns', __('DNS zones'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/providers/Microsoft.Network/dnszones', ['api-version' => self::DNS]))
            ->columns([
                Column::make('name', __('Zone'))->mono(),
                Column::make('group', __('Resource group'))->using(fn ($row) => self::group($row['id'] ?? '')),
                Column::make('properties.numberOfRecordSets', __('Record sets')),
                Column::make('properties.nameServers', __('Nameservers'))->mono(),
            ])
            ->create(__('New zone'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::text('group', __('Resource group'))->required()->help(__('An existing one (Resource groups)')),
            ], fn (ScopedClient $api, array $row, array $data) => tap(__('Zone created: point the domain at its nameservers.'), fn () => $api->put('/resourceGroups/'.rawurlencode((string) $data['group']).'/providers/Microsoft.Network/dnszones/'.rawurlencode((string) $data['name']).'?api-version='.self::DNS, ['location' => 'global'])))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('dns_records', fn ($row) => ['zone' => (string) $row['id'], 'zone_name' => (string) $row['name']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the zone and all its records?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone deleted.'), fn () => $api->delete(self::at($row, '', self::DNS)))),
            ]);
    }

    /** The values of a record set, whatever its type. @return list<string> */
    private static function values(array $row): array
    {
        $p = (array) ($row['properties'] ?? []);

        return array_values(array_filter(array_map('strval', [
            ...array_column((array) ($p['ARecords'] ?? []), 'ipv4Address'),
            ...array_column((array) ($p['AAAARecords'] ?? []), 'ipv6Address'),
            ...(isset($p['CNAMERecord']['cname']) ? [$p['CNAMERecord']['cname']] : []),
            ...array_map(fn ($mx) => ($mx['preference'] ?? '').' '.($mx['exchange'] ?? ''), (array) ($p['MXRecords'] ?? [])),
            ...array_map(fn ($txt) => implode('', (array) ($txt['value'] ?? [])), (array) ($p['TXTRecords'] ?? [])),
            ...array_column((array) ($p['NSRecords'] ?? []), 'nsdname'),
            ...array_column((array) ($p['PTRRecords'] ?? []), 'ptrdname'),
            ...array_map(fn ($caa) => ($caa['flags'] ?? 0).' '.($caa['tag'] ?? '').' "'.($caa['value'] ?? '').'"', (array) ($p['CAARecords'] ?? [])),
            ...(isset($p['SOARecord']['host']) ? [$p['SOARecord']['host'].' '.($p['SOARecord']['email'] ?? '')] : []),
        ])));
    }

    /** The properties of a record set from the values typed, per type. */
    private static function body(string $type, array $values, int $ttl): array
    {
        $values = ServerTargets::resolveAll($type, $values);
        $p = ['TTL' => $ttl];

        switch ($type) {
            case 'A': $p['ARecords'] = array_map(fn ($v) => ['ipv4Address' => $v], $values); break;
            case 'AAAA': $p['AAAARecords'] = array_map(fn ($v) => ['ipv6Address' => $v], $values); break;
            case 'CNAME': $p['CNAMERecord'] = ['cname' => $values[0] ?? '']; break;
            case 'TXT': $p['TXTRecords'] = array_map(fn ($v) => ['value' => [trim($v, '"')]], $values); break;
            case 'NS': $p['NSRecords'] = array_map(fn ($v) => ['nsdname' => $v], $values); break;
            case 'PTR': $p['PTRRecords'] = array_map(fn ($v) => ['ptrdname' => $v], $values); break;
            case 'MX':
                $p['MXRecords'] = array_map(function ($v) {
                    if (! preg_match('/^(\d+)\s+(\S+)$/', trim($v), $m)) {
                        throw new ConnectorException(__('An MX value is "10 mail.example.com".'));
                    }

                    return ['preference' => (int) $m[1], 'exchange' => $m[2]];
                }, $values);
                break;
            default:
                throw new ConnectorException(__('The panel writes A, AAAA, CNAME, MX, TXT, NS and PTR records here; the others from the Azure portal.'));
        }

        return ['properties' => $p];
    }

    private function dnsRecords(): Section
    {
        $type = fn (array $row) => (string) substr((string) ($row['type'] ?? ''), (int) strrpos((string) ($row['type'] ?? ''), '/') + 1);

        return Section::make('dns_records', __('DNS records'))
            ->childOf('dns', 'zone_name')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, self::at(['id' => (string) $scope['zone']], '/recordsets', self::DNS), []))
            ->columns([
                Column::make('type', __('Type'))->using($type)->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('values', __('Values'))->mono()->using(fn ($row) => implode(', ', array_map(fn ($v) => ServerTargets::label($v), self::values($row)))),
                Column::make('properties.TTL', __('TTL')),
            ])
            ->create(__('New record'), [
                Field::select('type', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'PTR'], $t))->required()->default('A'),
                Field::text('name', __('Name'))->required()->default('@')->help(__('@ for the zone itself, or www, api…')),
                Field::textarea('values', __('Values'))->required()->help(__('One per line — an address, or a server of the panel by its name (A, AAAA). MX as "10 mail.example.com".')),
                Field::number('ttl', __('TTL'))->default(3600),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->put(
                self::at(['id' => (string) $scope['zone']], '/'.$data['type'].'/'.rawurlencode((string) ($data['name'] ?: '@')), self::DNS),
                self::body((string) $data['type'], self::lines($data['values']), (int) ($data['ttl'] ?? 3600)),
            )))
            ->actions([
                Action::make('edit', __('Edit values'))->icon('pencil')->when(fn ($row) => ! in_array($type($row), ['SOA', 'SRV', 'CAA'], true))->fields([
                    Field::textarea('values', __('Values'))->required()->default(fn ($row) => implode("\n", self::values($row))),
                    Field::number('ttl', __('TTL'))->default(fn ($row) => data_get($row, 'properties.TTL', 3600)),
                ])->run(fn (ApiClient $api, array $row, array $data) => tap(__('Record saved.'), fn () => $api->put(self::at($row, '', self::DNS), self::body($type($row), self::lines($data['values']), (int) ($data['ttl'] ?? 3600))))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record set?'))->when(fn ($row) => ! in_array($type($row), ['SOA'], true) && ! ($type($row) === 'NS' && ($row['name'] ?? '') === '@'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Record deleted.'), fn () => $api->delete(self::at($row, '', self::DNS)))),
            ]);
    }

    private function storage(): Section
    {
        return Section::make('storage', __('Storage accounts'))
            ->icon('archive-box')
            ->list(fn (ApiClient $api) => self::every($api, '/providers/Microsoft.Storage/storageAccounts', ['api-version' => self::STORAGE]))
            ->columns([
                Column::make('name', __('Account'))->mono(),
                Column::make('kind', __('Kind')),
                Column::make('sku.name', __('SKU')),
                Column::make('group', __('Resource group'))->using(fn ($row) => self::group($row['id'] ?? '')),
                Column::make('location', __('Location')),
                Column::make('properties.primaryEndpoints.blob', __('Blob endpoint'))->mono(),
            ]);
    }
}
