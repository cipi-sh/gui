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
 * Google Cloud, with a service account's JSON key: Compute Engine (VM
 * instances, firewall rules, static addresses, disks, snapshots), Cloud DNS,
 * Cloud SQL and Cloud Storage buckets of one project.
 * https://cloud.google.com/compute/docs/reference/rest/v1
 */
final class GoogleCloud extends Connector
{
    private const COMPUTE = 'https://compute.googleapis.com/compute/v1/projects/';

    private const DNS = 'https://dns.googleapis.com/dns/v1/projects/';

    private const IMAGES = [
        'projects/ubuntu-os-cloud/global/images/family/ubuntu-2404-lts-amd64' => 'Ubuntu 24.04 LTS (Cipi)',
        'projects/ubuntu-os-cloud/global/images/family/ubuntu-2204-lts' => 'Ubuntu 22.04 LTS',
        'projects/debian-cloud/global/images/family/debian-12' => 'Debian 12',
    ];

    public function key(): string
    {
        return 'gcp';
    }

    public function label(): string
    {
        return 'Google Cloud';
    }

    public function short(): string
    {
        return 'GCP';
    }

    public function description(): string
    {
        return __('Compute Engine instances, firewall rules, static addresses, disks and snapshots, Cloud DNS, Cloud SQL and Storage buckets of a project.');
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function docs(): string
    {
        return 'https://cloud.google.com/iam/docs/keys-create-delete';
    }

    public function fields(): array
    {
        return [
            Field::textarea('key', __('Service account key (JSON)'))->required()->secret()
                ->help(__('The whole JSON file Google downloads: the panel keeps it encrypted and never shows it again.')),
            Field::text('project', __('Project ID'))->placeholder('my-project-123456')
                ->help(__('Empty: the project of the key.')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('console.cloud.google.com → the project → APIs & Services: enable Compute Engine API, Cloud DNS API and Cloud SQL Admin API.'),
            __('IAM & Admin → Service accounts → Create service account (cipi-panel).'),
            __('Roles: Compute Admin, DNS Administrator, Cloud SQL Viewer (Cloud SQL Admin to restart), Storage Admin — or their Viewer roles for a read-only panel.'),
            __('The account → Keys → Add key → Create new key → JSON: paste the whole file here.'),
            __('One account per project; a key reaches others only where the service account has a role.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $key = json_decode(self::string($credentials, 'key'), true);

        if (! is_array($key)) {
            throw new ConnectorException(__('The service account key is not JSON: paste the whole file.'));
        }

        $project = self::string($credentials, 'project', (string) ($key['project_id'] ?? ''));

        if (! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/', $project)) {
            throw new ConnectorException(__('No valid project ID (the key has none, or the one typed is not a project ID).'));
        }

        return new ScopedClient(self::COMPUTE.$project, ['Authorization' => 'Bearer '.Tokens::google($key)], ['project' => $project, 'email' => (string) ($key['client_email'] ?? '')]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $project = $api->get($api->baseUrl);

        return 'ok · '.($project['name'] ?? $api->scope('project')).' · '.$api->scope('email');
    }

    public function sections(): array
    {
        return [
            $this->instances(),
            $this->firewalls(),
            $this->addresses(),
            $this->disks(),
            $this->snapshots(),
            $this->dnsZones(),
            $this->dnsRecords(),
            $this->sql(),
            $this->buckets(),
        ];
    }

    /**
     * Every page of a list (nextPageToken); an aggregated list is flattened
     * across its zones or regions.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key = 'items', array $query = [], bool $aggregated = false): array
    {
        $query = ['maxResults' => 500, ...$query];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            function (array $answer) use ($key, $aggregated) {
                if (! $aggregated) {
                    return array_values(array_filter((array) ($answer[$key] ?? []), 'is_array'));
                }

                $rows = [];
                foreach ((array) ($answer['items'] ?? []) as $scope => $group) {
                    foreach ((array) ($group[$key] ?? []) as $item) {
                        if (is_array($item)) {
                            $rows[] = $item + ['_scope' => (string) $scope];
                        }
                    }
                }

                return $rows;
            },
            fn (array $answer) => isset($answer['nextPageToken']) && $answer['nextPageToken'] !== '' ? [...$query, 'pageToken' => $answer['nextPageToken']] : null,
        );
    }

    /** The last segment of a Google resource URL: zones/europe-west1-b → europe-west1-b. */
    private static function base(mixed $url): string
    {
        $url = is_string($url) ? $url : '';

        return $url === '' ? '' : (string) substr($url, (int) strrpos($url, '/') + 1);
    }

    /** @return array<string, string> zone => zone */
    private static function zones(ApiClient $api): array
    {
        $zones = array_column(self::every($api, '/zones'), 'name');
        sort($zones);

        return array_combine($zones, $zones) ?: [];
    }

    private function instances(): Section
    {
        $vm = fn (array $row) => '/zones/'.self::base($row['zone'] ?? '').'/instances/'.$row['name'];

        return Section::make('instances', __('VM instances'))
            ->icon('server')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/aggregated/instances', 'instances', ['returnPartialSuccess' => 'true'], true))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(['running' => 'green', 'provisioning' => 'yellow', 'staging' => 'yellow', 'stopping' => 'yellow', 'repairing' => 'yellow', 'terminated' => 'zinc', 'stopped' => 'zinc', 'suspended' => 'zinc', '*' => 'zinc']),
                Column::make('zone', __('Zone'))->using(fn ($row) => self::base($row['zone'] ?? '')),
                Column::make('machineType', __('Machine type'))->using(fn ($row) => self::base($row['machineType'] ?? '')),
                Column::make('external', __('External IP'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'networkInterfaces.0.accessConfigs.0.natIP'))),
                Column::make('internal', __('Internal IP'))->mono()->using(fn ($row) => data_get($row, 'networkInterfaces.0.networkIP')),
                Column::make('creationTimestamp', __('Created'))->date(),
            ])
            ->create(__('New instance'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$/'])->help(__('Lowercase letters, digits and dashes')),
                Field::select('zone', __('Zone'), fn (ApiClient $api) => self::zones($api))->required()->default('europe-west1-b'),
                Field::text('machine', __('Machine type'))->required()->default('e2-small')->help(__('e2-small, e2-medium, n2-standard-2…')),
                Field::select('image', __('Image'), self::IMAGES)->required()->default(array_key_first(self::IMAGES)),
                Field::number('disk', __('Disk (GB)'))->default(30),
                Field::textarea('ssh_key', __('SSH public key'))->help(__('Signs in as the user named in the key comment (ssh-ed25519 AAAA… user)')),
                Field::checkbox('web', __('Allow HTTP and HTTPS (tags http-server, https-server)'))->default(true),
            ], function (ScopedClient $api, array $row, array $data) {
                $body = [
                    'name' => $data['name'],
                    'machineType' => 'zones/'.$data['zone'].'/machineTypes/'.$data['machine'],
                    'disks' => [['boot' => true, 'autoDelete' => true, 'initializeParams' => ['sourceImage' => $data['image'], 'diskSizeGb' => (string) ($data['disk'] ?? 30)]]],
                    'networkInterfaces' => [['network' => 'global/networks/default', 'accessConfigs' => [['type' => 'ONE_TO_ONE_NAT', 'name' => 'External NAT']]]],
                ];
                if ($data['web']) {
                    $body['tags'] = ['items' => ['http-server', 'https-server']];
                }
                if (is_string($data['ssh_key']) && $data['ssh_key'] !== '') {
                    $key = trim($data['ssh_key']);
                    $user = preg_match('/\s(\S+?)(@\S+)?$/', $key, $m) ? preg_replace('/[^a-z0-9_]/', '', strtolower($m[1])) : 'cipi';
                    $body['metadata'] = ['items' => [['key' => 'ssh-keys', 'value' => ($user ?: 'cipi').':'.$key]]];
                }
                $api->post('/zones/'.$data['zone'].'/instances', $body);

                return __('Instance requested: it appears in a minute.');
            })
            ->actions([
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => in_array($row['status'] ?? '', ['TERMINATED', 'STOPPED', 'SUSPENDED'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Starting.'), fn () => $api->post($vm($row).'/start'))),
                Action::make('stop', __('Stop'))->icon('pause')->confirm(__('Stop the instance? Its disk stays; the external address changes unless it is static.'))->when(fn ($row) => ($row['status'] ?? '') === 'RUNNING')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Stopping.'), fn () => $api->post($vm($row).'/stop'))),
                Action::make('reset', __('Reset'))->icon('arrow-path')->confirm(__('Reset the instance — a hard restart, like the power button?'))->when(fn ($row) => ($row['status'] ?? '') === 'RUNNING')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Reset.'), fn () => $api->post($vm($row).'/reset'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the instance and the disks set to be deleted with it?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleting.'), fn () => $api->delete($vm($row)))),
            ]);
    }

    private function firewalls(): Section
    {
        return Section::make('firewalls', __('Firewall rules'))
            ->icon('shield-check')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/global/firewalls'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('direction', __('Direction'))->badge(['ingress' => 'blue', 'egress' => 'zinc']),
                Column::make('allowed', __('Allows'))->mono()->using(fn ($row) => collect(self::items($row['allowed'] ?? []))->map(fn ($a) => ($a['IPProtocol'] ?? '?').(isset($a['ports']) ? ':'.implode(',', (array) $a['ports']) : ''))->implode(' ')),
                Column::make('sourceRanges', __('From'))->mono(),
                Column::make('targetTags', __('Targets'))->using(fn ($row) => $row['targetTags'] ?? __('every instance')),
                Column::make('disabled', __('Off'))->bool(),
            ])
            ->create(__('New rule'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$/']),
                Field::text('ports', __('Ports'))->required()->default('80, 443')->help(__('22, 80, 443, 8000-8100, 53/udp, icmp')),
                Field::text('sources', __('From'))->default('0.0.0.0/0')->help(__('Address ranges, separated by commas')),
                Field::text('tags', __('Target tags'))->help(__('Empty: every instance of the network')),
                Field::text('network', __('Network'))->default('default'),
            ], function (ApiClient $api, array $row, array $data) {
                $allowed = [];
                foreach (self::ports($data['ports']) as $rule) {
                    $allowed[] = $rule['protocol'] === 'icmp' || $rule['port'] === 'all'
                        ? ['IPProtocol' => $rule['protocol']]
                        : ['IPProtocol' => $rule['protocol'], 'ports' => [(string) $rule['port']]];
                }
                $api->post('/global/firewalls', array_filter([
                    'name' => $data['name'],
                    'network' => 'global/networks/'.($data['network'] ?: 'default'),
                    'direction' => 'INGRESS',
                    'allowed' => $allowed,
                    'sourceRanges' => self::lines($data['sources'] ?? '0.0.0.0/0') ?: ['0.0.0.0/0'],
                    'targetTags' => self::lines($data['tags'] ?? '') ?: null,
                ], fn ($v) => $v !== null));

                return __('Rule created.');
            })
            ->actions([
                Action::make('disable', __('Turn off'))->icon('pause')->when(fn ($row) => empty($row['disabled']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rule off.'), fn () => $api->patch('/global/firewalls/'.$row['name'], ['disabled' => true]))),
                Action::make('enable', __('Turn on'))->icon('play')->when(fn ($row) => ! empty($row['disabled']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rule on.'), fn () => $api->patch('/global/firewalls/'.$row['name'], ['disabled' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this firewall rule?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rule deleted.'), fn () => $api->delete('/global/firewalls/'.$row['name']))),
            ]);
    }

    private function addresses(): Section
    {
        return Section::make('addresses', __('Static addresses'))
            ->icon('globe-europe-africa')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/aggregated/addresses', 'addresses', [], true))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('address', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['address'] ?? null)),
                Column::make('region', __('Region'))->using(fn ($row) => self::base($row['region'] ?? '') ?: __('global')),
                Column::make('addressType', __('Type')),
                Column::make('status', __('Status'))->badge(['in_use' => 'green', 'reserved' => 'yellow', '*' => 'zinc']),
                Column::make('users', __('Used by'))->using(fn ($row) => collect(self::items($row['users'] ?? []))->map(fn ($u) => self::base($u))->implode(', ')),
            ])
            ->actions([
                Action::make('release', __('Release'))->icon('trash')->danger(__('Release the address? It goes back to Google and cannot be had again.'))->when(fn ($row) => ($row['status'] ?? '') !== 'IN_USE')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Released.'), fn () => $api->delete(isset($row['region']) ? '/regions/'.self::base($row['region']).'/addresses/'.$row['name'] : '/global/addresses/'.$row['name']))),
            ]);
    }

    private function disks(): Section
    {
        return Section::make('disks', __('Disks'))
            ->icon('circle-stack')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/aggregated/disks', 'disks', [], true))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('sizeGb', __('GB')),
                Column::make('type', __('Type'))->using(fn ($row) => self::base($row['type'] ?? '')),
                Column::make('zone', __('Zone'))->using(fn ($row) => self::base($row['zone'] ?? '')),
                Column::make('status', __('Status'))->badge(['ready' => 'green', '*' => 'yellow']),
                Column::make('users', __('Attached to'))->using(fn ($row) => collect(self::items($row['users'] ?? []))->map(fn ($u) => self::base($u))->implode(', ')),
            ])
            ->actions([
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('name', __('Snapshot name'))->required()->rules(['regex:/^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$/'])])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Snapshot started.'), fn () => $api->post('/zones/'.self::base($row['zone'] ?? '').'/disks/'.$row['name'].'/createSnapshot', ['name' => $data['name']]))),
            ]);
    }

    private function snapshots(): Section
    {
        return Section::make('snapshots', __('Snapshots'))
            ->icon('camera')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/global/snapshots'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('diskSizeGb', __('GB')),
                Column::make('sourceDisk', __('Of disk'))->using(fn ($row) => self::base($row['sourceDisk'] ?? '')),
                Column::make('status', __('Status'))->badge(['ready' => 'green', '*' => 'yellow']),
                Column::make('creationTimestamp', __('Created'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the snapshot?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Snapshot deleted.'), fn () => $api->delete('/global/snapshots/'.$row['name']))),
            ]);
    }

    private function dnsZones(): Section
    {
        return Section::make('dns', __('Cloud DNS'))
            ->icon('globe-europe-africa')
            ->id('name')
            ->list(fn (ScopedClient $api) => self::every($api, self::DNS.$api->scope('project').'/managedZones', 'managedZones'))
            ->columns([
                Column::make('dnsName', __('Domain'))->mono(),
                Column::make('name', __('Zone')),
                Column::make('visibility', __('Visibility'))->badge(['public' => 'green', 'private' => 'zinc']),
                Column::make('nameServers', __('Nameservers'))->mono(),
            ])
            ->create(__('New zone'), [
                Field::text('dnsName', __('Domain'))->required()->placeholder('example.com'),
                Field::text('name', __('Zone name'))->required()->rules(['regex:/^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$/'])->help(__('example-com')),
                Field::text('description', __('Description')),
            ], fn (ScopedClient $api, array $row, array $data) => tap(__('Zone created: point the domain at its nameservers.'), fn () => $api->post(self::DNS.$api->scope('project').'/managedZones', [
                'name' => $data['name'], 'dnsName' => rtrim((string) $data['dnsName'], '.').'.', 'description' => (string) ($data['description'] ?? ''), 'visibility' => 'public',
            ])))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('dns_records', fn ($row) => ['zone' => $row['name'], 'zone_name' => rtrim((string) $row['dnsName'], '.')]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the zone? It must hold only its NS and SOA records.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Zone deleted.'), fn () => $api->delete(self::DNS.$api->scope('project').'/managedZones/'.$row['name']))),
            ]);
    }

    private function dnsRecords(): Section
    {
        $fqdn = fn (?string $name, string $zone) => ($name === null || $name === '' || $name === '@') ? $zone.'.' : (str_ends_with($name, '.') ? $name : rtrim($name, '.').'.'.$zone.'.');
        $path = fn (ScopedClient $api, array $scope) => self::DNS.$api->scope('project').'/managedZones/'.$scope['zone'].'/rrsets';

        return Section::make('dns_records', __('DNS records'))
            ->childOf('dns', 'zone_name')
            ->id(fn ($row) => ($row['name'] ?? '').'/'.($row['type'] ?? ''))
            ->list(fn (ScopedClient $api, array $scope) => self::every($api, $path($api, $scope), 'rrsets'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('rrdatas', __('Values'))->mono()->using(fn ($row) => collect(self::items($row['rrdatas'] ?? []))->map(fn ($v) => ServerTargets::label($v))->implode(', ')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record'), [
                Field::select('type', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'], $t))->required()->default('A'),
                Field::text('name', __('Name'))->default('@')->help(__('@ for the zone itself, or www, api…')),
                Field::textarea('values', __('Values'))->required()->help(__('One per line — an address, or a server of the panel by its name (A, AAAA). TXT in quotes; CNAME and MX end with a dot.')),
                Field::number('ttl', __('TTL'))->default(300),
            ], fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post($path($api, $scope), [
                'name' => $fqdn($data['name'], $scope['zone_name']), 'type' => $data['type'], 'ttl' => $data['ttl'] ?? 300,
                'rrdatas' => ServerTargets::resolveAll($data['type'], self::lines($data['values'])),
            ])))
            ->actions([
                Action::make('edit', __('Edit values'))->icon('pencil')->when(fn ($row) => ! in_array($row['type'] ?? '', ['SOA'], true))->fields([
                    Field::textarea('values', __('Values'))->required()->default(fn ($row) => implode("\n", (array) ($row['rrdatas'] ?? []))),
                    Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? 300),
                ])->run(fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->patch($path($api, $scope).'/'.rawurlencode((string) $row['name']).'/'.$row['type'], [
                    'name' => $row['name'], 'type' => $row['type'], 'ttl' => $data['ttl'] ?? 300, 'rrdatas' => ServerTargets::resolveAll($row['type'] ?? null, self::lines($data['values'])),
                ]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete these records?'))->when(fn ($row) => ! in_array($row['type'] ?? '', ['SOA', 'NS'], true) || rtrim((string) ($row['name'] ?? ''), '.') !== '')
                    ->run(fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Records deleted.'), fn () => $api->delete($path($api, $scope).'/'.rawurlencode((string) $row['name']).'/'.$row['type']))),
            ]);
    }

    private function sql(): Section
    {
        $base = fn (ScopedClient $api) => 'https://sqladmin.googleapis.com/v1/projects/'.$api->scope('project').'/instances';

        return Section::make('sql', __('Cloud SQL'))
            ->icon('circle-stack')
            ->id('name')
            ->list(fn (ScopedClient $api) => self::every($api, $base($api)))
            ->columns([
                Column::make('name', __('Instance')),
                Column::make('databaseVersion', __('Engine')),
                Column::make('state', __('State'))->badge(['runnable' => 'green', 'suspended' => 'zinc', 'pending_create' => 'yellow', 'maintenance' => 'yellow', '*' => 'red']),
                Column::make('region', __('Region')),
                Column::make('settings.tier', __('Tier')),
                Column::make('ipAddresses', __('Addresses'))->mono()->using(fn ($row) => collect(self::items($row['ipAddresses'] ?? []))->pluck('ipAddress')->implode(', ')),
            ])
            ->actions([
                Action::make('restart', __('Restart'))->icon('arrow-path')->confirm(__('Restart the database instance? Connections drop for a moment.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Restarting.'), fn () => $api->post($base($api).'/'.$row['name'].'/restart'))),
            ]);
    }

    private function buckets(): Section
    {
        return Section::make('buckets', __('Storage buckets'))
            ->icon('archive-box')
            ->id('name')
            ->list(fn (ScopedClient $api) => self::every($api, 'https://storage.googleapis.com/storage/v1/b', 'items', ['project' => $api->scope('project'), 'maxResults' => 1000]))
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('location', __('Location')),
                Column::make('storageClass', __('Class')),
                Column::make('timeCreated', __('Created'))->date(),
            ]);
    }
}
