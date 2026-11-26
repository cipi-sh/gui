<?php

namespace CipiGui\Infrastructure\Aws;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;

/**
 * Amazon Lightsail, every kind of resource it has, for the AWS connector:
 * instances and their snapshots, static IPs, disks and their snapshots, key
 * pairs, managed databases, load balancers, container services, buckets,
 * DNS domains, distributions, certificates, alarms and the operations log.
 * Lightsail is a JSON API per region; DNS domains live in us-east-1 only.
 * https://docs.aws.amazon.com/lightsail/2016-11-28/api-reference/
 */
trait Lightsail
{
    /** Instance, disk, database and bucket states as badge colours. */
    private const LIGHTSAIL_STATE = ['running' => 'green', 'available' => 'green', 'ready' => 'green', 'active' => 'green', 'ok' => 'green', 'in-use' => 'blue', 'completed' => 'green', 'deployed' => 'green',
        'stopped' => 'zinc', 'disabled' => 'zinc', 'pending' => 'blue', 'starting' => 'blue', 'deploying' => 'blue', 'stopping' => 'yellow', 'alarm' => 'red', 'failed' => 'red', 'error' => 'red', '*' => 'yellow'];

    /** @return list<Section> */
    protected function lightsailSections(): array
    {
        return [
            $this->lightsailInstances(),
            $this->lightsailInstanceSnapshots(),
            $this->lightsailStaticIps(),
            $this->lightsailDisks(),
            $this->lightsailDiskSnapshots(),
            $this->lightsailKeyPairs(),
            $this->lightsailDatabases(),
            $this->lightsailLoadBalancers(),
            $this->lightsailContainers(),
            $this->lightsailBuckets(),
            $this->lightsailDomains(),
            $this->lightsailDomainEntries(),
            $this->lightsailDistributions(),
            $this->lightsailCertificates(),
            $this->lightsailAlarms(),
            $this->lightsailOperations(),
        ];
    }

    /**
     * A Lightsail call in the region of the scope (or the one given).
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private static function lightsail(AwsClient $api, array $scope, string $operation, array $payload = [], ?string $region = null): array
    {
        return $api->json('lightsail', 'Lightsail_20161128.'.$operation, $payload, $region ?? (is_string($scope['region'] ?? null) ? $scope['region'] : null));
    }

    /**
     * Every page of a Lightsail list: pageToken until nextPageToken is gone.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function lightsailAll(AwsClient $api, array $scope, string $operation, string $key, array $payload = [], ?string $region = null): array
    {
        $rows = [];
        $token = null;
        $page = 0;

        do {
            $answer = self::lightsail($api, $scope, $operation, [...$payload, ...($token ? ['pageToken' => $token] : [])], $region);

            foreach (is_array($answer[$key] ?? null) ? $answer[$key] : [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $token = is_string($answer['nextPageToken'] ?? null) && $answer['nextPageToken'] !== '' ? $answer['nextPageToken'] : null;
        } while ($token !== null && ++$page < 20);

        return $rows;
    }

    /**
     * Choices from a Lightsail list: key => label.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function lightsailOptions(AwsClient $api, array $scope, string $operation, string $key, callable $label, string $id = 'name', ?callable $keep = null): array
    {
        $options = [];

        foreach (self::lightsailAll($api, $scope, $operation, $key) as $row) {
            if ($keep === null || $keep($row)) {
                $options[(string) ($row[$id] ?? '')] = (string) $label($row);
            }
        }

        return $options;
    }

    /**
     * The availability zones of the region of the scope.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function lightsailZones(AwsClient $api, array $scope): array
    {
        $zones = [];

        foreach (self::lightsailAll($api, $scope, 'GetRegions', 'regions', ['includeAvailabilityZones' => true]) as $region) {
            if (($region['name'] ?? null) === $api->region($scope['region'] ?? null)) {
                foreach (is_array($region['availabilityZones'] ?? null) ? $region['availabilityZones'] : [] as $zone) {
                    $zones[(string) $zone['zoneName']] = (string) $zone['zoneName'];
                }
            }
        }

        return $zones;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function lightsailInstanceOptions(AwsClient $api, array $scope): array
    {
        return self::lightsailOptions($api, $scope, 'GetInstances', 'instances', fn ($i) => $i['name'].' · '.data_get($i, 'location.availabilityZone'));
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function lightsailBundles(AwsClient $api, array $scope): array
    {
        return self::lightsailOptions($api, $scope, 'GetBundles', 'bundles',
            fn ($b) => sprintf('%s · %d vCPU · %s GB · %d GB · $%s/mo', $b['bundleId'], $b['cpuCount'] ?? 0, $b['ramSizeInGb'] ?? 0, $b['diskSizeInGb'] ?? 0, $b['price'] ?? '?'),
            'bundleId', fn ($b) => (bool) ($b['isActive'] ?? false));
    }

    /** A Lightsail action that takes the row's name under $parameter and the form's fields. */
    private function lightsailAction(string $operation, string $label, string $icon, string $parameter, ?string $region = null): Action
    {
        return Action::make($operation, $label)->icon($icon)->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(
            __(':action: sent to :name.', ['action' => $label, 'name' => $row['name'] ?? $row[$parameter] ?? '']),
            fn () => self::lightsail($api, $scope, $operation, [$parameter => $row['name'] ?? $row[$parameter] ?? null, ...array_filter($data, fn ($value) => $value !== null)], $region),
        ));
    }

    private function lightsailInstances(): Section
    {
        $state = fn ($row) => data_get($row, 'state.name');

        return Section::make('lightsail', __('Instances'))
            ->icon('light-bulb')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetInstances', 'instances'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('state.name', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('publicIpAddress', __('Public IP'))->mono(),
                Column::make('isStaticIp', __('Static IP'))->bool(),
                Column::make('blueprintName', __('Blueprint')),
                Column::make('bundleId', __('Plan'))->mono(),
                Column::make('location.availabilityZone', __('Zone')),
                Column::make('createdAt', __('Created'))->date(),
            ])
            ->create(__('New instance'), [
                Field::text('name', __('Name'))->required(),
                Field::select('zone', __('Zone'), fn (AwsClient $api, array $scope) => self::lightsailZones($api, $scope))->required(),
                Field::select('blueprint', __('Blueprint'), fn (AwsClient $api, array $scope) => self::lightsailOptions($api, $scope, 'GetBlueprints', 'blueprints',
                    fn ($b) => trim(($b['name'] ?? '').' '.($b['version'] ?? '').' · '.($b['type'] ?? '')), 'blueprintId', fn ($b) => (bool) ($b['isActive'] ?? false)))->required(),
                Field::select('bundle', __('Plan'), fn (AwsClient $api, array $scope) => self::lightsailBundles($api, $scope))->required(),
                Field::select('key', __('Key pair'), fn (AwsClient $api, array $scope) => self::lightsailOptions($api, $scope, 'GetKeyPairs', 'keyPairs', fn ($k) => $k['name']))->help(__('Empty: the default key of the region')),
                Field::textarea('user_data', __('Launch script')),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Instance :name is being created.', ['name' => $data['name']]), fn () => self::lightsail($api, $scope, 'CreateInstances', array_filter([
                'instanceNames' => [$data['name']], 'availabilityZone' => $data['zone'], 'blueprintId' => $data['blueprint'], 'bundleId' => $data['bundle'],
                'keyPairName' => $data['key'], 'userData' => $data['user_data'],
            ]))))
            ->actions([
                $this->lightsailAction('StartInstance', __('Start'), 'play', 'instanceName')->when(fn ($row) => $state($row) === 'stopped'),
                $this->lightsailAction('StopInstance', __('Stop'), 'stop', 'instanceName')->confirm(__('Stop the instance? Without a static IP its public IP changes.'))->when(fn ($row) => $state($row) === 'running'),
                $this->lightsailAction('RebootInstance', __('Reboot'), 'arrow-path', 'instanceName')->confirm(__('Reboot the instance?'))->when(fn ($row) => $state($row) === 'running'),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('snapshot', __('Snapshot name'))->required()->default(fn ($row) => $row['name'].'-'.now()->format('Ymd-His'))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Snapshot started.'), fn () => self::lightsail($api, $scope, 'CreateInstanceSnapshot', ['instanceName' => $row['name'], 'instanceSnapshotName' => $data['snapshot']]))),
                Action::make('ports', __('Firewall ports'))->icon('shield-check')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(__('Ports of :name', ['name' => $row['name']]), self::lightsail($api, $scope, 'GetInstancePortStates', ['instanceName' => $row['name']])['portStates'] ?? [])),
                Action::make('open', __('Open a port'))->icon('lock-open')->fields([
                    Field::text('port', __('Port or range'))->required()->placeholder('443, 8000-8100'),
                    Field::select('protocol', __('Protocol'), ['tcp' => 'TCP', 'udp' => 'UDP', 'all' => __('All')])->default('tcp'),
                    Field::text('cidrs', __('From'))->default('0.0.0.0/0')->help(__('CIDRs, comma separated')),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Port opened.'), fn () => self::lightsail($api, $scope, 'OpenInstancePublicPorts', ['instanceName' => $row['name'], 'portInfo' => self::lightsailPort($data)]))),
                Action::make('close', __('Close a port'))->icon('lock-closed')->fields([
                    Field::text('port', __('Port or range'))->required(),
                    Field::select('protocol', __('Protocol'), ['tcp' => 'TCP', 'udp' => 'UDP', 'all' => __('All')])->default('tcp'),
                    Field::text('cidrs', __('From'))->default('0.0.0.0/0'),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Port closed.'), fn () => self::lightsail($api, $scope, 'CloseInstancePublicPorts', ['instanceName' => $row['name'], 'portInfo' => self::lightsailPort($data)]))),
                Action::make('access', __('SSH access details'))->icon('key')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show($row['name'], self::lightsail($api, $scope, 'GetInstanceAccessDetails', ['instanceName' => $row['name'], 'protocol' => 'ssh'])['accessDetails'] ?? [])),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['name'], $row)),
                $this->lightsailAction('DeleteInstance', __('Delete'), 'trash', 'instanceName')->danger(__('Delete the instance and its disk? Snapshots stay.')),
            ]);
    }

    /**
     * The portInfo of an open or close: a port or a range, a protocol, the sources.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function lightsailPort(array $data): array
    {
        [$from, $to] = array_pad(explode('-', (string) $data['port'], 2), 2, null);

        return [
            'fromPort' => (int) $from,
            'toPort' => (int) ($to ?? $from),
            'protocol' => $data['protocol'] ?: 'tcp',
            'cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['cidrs'] ?: '0.0.0.0/0'))))),
        ];
    }

    private function lightsailInstanceSnapshots(): Section
    {
        return Section::make('lightsail-snapshots', __('Instance snapshots'))
            ->icon('camera')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetInstanceSnapshots', 'instanceSnapshots'))
            ->columns([
                Column::make('name', __('Snapshot')),
                Column::make('fromInstanceName', __('Of')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('sizeInGb', __('Size (GB)')),
                Column::make('createdAt', __('Created'))->date(),
            ])
            ->actions([
                Action::make('restore', __('New instance from it'))->icon('arrow-uturn-left')->fields([
                    Field::text('name', __('Instance name'))->required(),
                    Field::select('zone', __('Zone'), fn (AwsClient $api, array $scope) => self::lightsailZones($api, $scope))->required(),
                    Field::select('bundle', __('Plan'), fn (AwsClient $api, array $scope) => self::lightsailBundles($api, $scope))->required()->help(__('The same size as the original, or bigger')),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Instance :name is being created.', ['name' => $data['name']]), fn () => self::lightsail($api, $scope, 'CreateInstancesFromSnapshot', [
                    'instanceNames' => [$data['name']], 'availabilityZone' => $data['zone'], 'instanceSnapshotName' => $row['name'], 'bundleId' => $data['bundle'],
                ]))),
                $this->lightsailAction('DeleteInstanceSnapshot', __('Delete'), 'trash', 'instanceSnapshotName')->danger(__('Delete the snapshot?')),
            ]);
    }

    private function lightsailStaticIps(): Section
    {
        return Section::make('lightsail-static-ips', __('Static IPs'))
            ->icon('map-pin')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetStaticIps', 'staticIps'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('ipAddress', __('IP'))->mono(),
                Column::make('attachedTo', __('Attached to')),
                Column::make('location.regionName', __('Region')),
            ])
            ->create(__('Allocate a static IP'), [
                Field::text('name', __('Name'))->required(),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Static IP allocated.'), fn () => self::lightsail($api, $scope, 'AllocateStaticIp', ['staticIpName' => $data['name']])))
            ->actions([
                Action::make('attach', __('Attach'))->icon('link')->when(fn ($row) => empty($row['isAttached']))
                    ->fields([Field::select('instance', __('Instance'), fn (AwsClient $api, array $scope) => self::lightsailInstanceOptions($api, $scope))->required()])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Static IP attached.'), fn () => self::lightsail($api, $scope, 'AttachStaticIp', ['staticIpName' => $row['name'], 'instanceName' => $data['instance']]))),
                $this->lightsailAction('DetachStaticIp', __('Detach'), 'link-slash', 'staticIpName')->confirm(__('Detach the static IP? The instance gets a new public IP.'))->when(fn ($row) => ! empty($row['isAttached'])),
                $this->lightsailAction('ReleaseStaticIp', __('Release'), 'trash', 'staticIpName')->danger(__('Release the IP? It may never come back.')),
            ]);
    }

    private function lightsailDisks(): Section
    {
        return Section::make('lightsail-disks', __('Disks'))
            ->icon('circle-stack')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetDisks', 'disks'))
            ->columns([
                Column::make('name', __('Disk')),
                Column::make('sizeInGb', __('Size (GB)')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('attachedTo', __('Attached to')),
                Column::make('path', __('Path'))->mono(),
                Column::make('location.availabilityZone', __('Zone')),
            ])
            ->create(__('New disk'), [
                Field::text('name', __('Name'))->required(),
                Field::select('zone', __('Zone'), fn (AwsClient $api, array $scope) => self::lightsailZones($api, $scope))->required()->help(__('The zone of the instance it will be attached to')),
                Field::number('size', __('Size (GB)'))->required()->default(32),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Disk created.'), fn () => self::lightsail($api, $scope, 'CreateDisk', ['diskName' => $data['name'], 'availabilityZone' => $data['zone'], 'sizeInGb' => $data['size']])))
            ->actions([
                Action::make('attach', __('Attach'))->icon('link')->when(fn ($row) => empty($row['isAttached']))->fields([
                    Field::select('instance', __('Instance'), fn (AwsClient $api, array $scope) => self::lightsailInstanceOptions($api, $scope))->required(),
                    Field::text('path', __('Device'))->required()->default('/dev/xvdf'),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Attaching: format and mount it on the instance.'), fn () => self::lightsail($api, $scope, 'AttachDisk', ['diskName' => $row['name'], 'instanceName' => $data['instance'], 'diskPath' => $data['path']]))),
                $this->lightsailAction('DetachDisk', __('Detach'), 'link-slash', 'diskName')->confirm(__('Unmount it on the instance first. Detach?'))->when(fn ($row) => ! empty($row['isAttached'])),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('snapshot', __('Snapshot name'))->required()->default(fn ($row) => $row['name'].'-'.now()->format('Ymd-His'))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Snapshot started.'), fn () => self::lightsail($api, $scope, 'CreateDiskSnapshot', ['diskName' => $row['name'], 'diskSnapshotName' => $data['snapshot']]))),
                $this->lightsailAction('DeleteDisk', __('Delete'), 'trash', 'diskName')->danger(__('Delete the disk and its data? Detach it first.'))->when(fn ($row) => empty($row['isAttached'])),
            ]);
    }

    private function lightsailDiskSnapshots(): Section
    {
        return Section::make('lightsail-disk-snapshots', __('Disk snapshots'))
            ->icon('camera')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetDiskSnapshots', 'diskSnapshots'))
            ->columns([
                Column::make('name', __('Snapshot')),
                Column::make('fromDiskName', __('Of')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('sizeInGb', __('Size (GB)')),
                Column::make('createdAt', __('Created'))->date(),
            ])
            ->actions([
                Action::make('restore', __('New disk from it'))->icon('arrow-uturn-left')->fields([
                    Field::text('name', __('Disk name'))->required(),
                    Field::select('zone', __('Zone'), fn (AwsClient $api, array $scope) => self::lightsailZones($api, $scope))->required(),
                    Field::number('size', __('Size (GB)'))->required()->default(fn ($row) => $row['sizeInGb'] ?? 32),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Disk created.'), fn () => self::lightsail($api, $scope, 'CreateDiskFromSnapshot', [
                    'diskName' => $data['name'], 'availabilityZone' => $data['zone'], 'sizeInGb' => $data['size'], 'diskSnapshotName' => $row['name'],
                ]))),
                $this->lightsailAction('DeleteDiskSnapshot', __('Delete'), 'trash', 'diskSnapshotName')->danger(__('Delete the snapshot?')),
            ]);
    }

    private function lightsailKeyPairs(): Section
    {
        return Section::make('lightsail-key-pairs', __('Key pairs'))
            ->icon('key')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetKeyPairs', 'keyPairs'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('fingerprint', __('Fingerprint'))->mono(),
                Column::make('createdAt', __('Created'))->date(),
            ])
            ->create(__('Import a public key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('public_key', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Key imported.'), fn () => self::lightsail($api, $scope, 'ImportKeyPair', ['keyPairName' => $data['name'], 'publicKeyBase64' => base64_encode(trim((string) $data['public_key']))])))
            ->toolbar([
                Action::make('generate', __('Generate a key pair'))->icon('sparkles')->fields([Field::text('name', __('Name'))->required()])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(
                        __('Private key of :name: save it now, AWS keeps no copy', ['name' => $data['name']]),
                        base64_decode((string) (self::lightsail($api, $scope, 'CreateKeyPair', ['keyPairName' => $data['name']])['privateKeyBase64'] ?? '')),
                    )),
                Action::make('default', __('Default key of the region'))->icon('arrow-down-tray')->confirm(__('Show the private part of the region\'s default key?'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(__('Default private key'), (string) (self::lightsail($api, $scope, 'DownloadDefaultKeyPair')['privateKeyBase64'] ?? ''))),
            ])
            ->actions([
                $this->lightsailAction('DeleteKeyPair', __('Delete'), 'trash', 'keyPairName')->danger(__('Delete the key pair? Instances keep the public key.')),
            ]);
    }

    private function lightsailDatabases(): Section
    {
        $state = fn ($row) => $row['state'] ?? '';

        return Section::make('lightsail-databases', __('Databases'))
            ->icon('circle-stack')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetRelationalDatabases', 'relationalDatabases'))
            ->columns([
                Column::make('name', __('Database')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('engine', __('Engine'))->using(fn ($row) => ($row['engine'] ?? '').' '.($row['engineVersion'] ?? '')),
                Column::make('masterEndpoint.address', __('Endpoint'))->mono(),
                Column::make('relationalDatabaseBundleId', __('Plan'))->mono(),
                Column::make('publiclyAccessible', __('Public'))->bool(),
            ])
            ->create(__('New database'), [
                Field::text('name', __('Name'))->required(),
                Field::select('blueprint', __('Engine'), fn (AwsClient $api, array $scope) => self::lightsailOptions($api, $scope, 'GetRelationalDatabaseBlueprints', 'blueprints',
                    fn ($b) => ($b['engine'] ?? '').' '.($b['engineVersion'] ?? ''), 'blueprintId'))->required(),
                Field::select('bundle', __('Plan'), fn (AwsClient $api, array $scope) => self::lightsailOptions($api, $scope, 'GetRelationalDatabaseBundles', 'bundles',
                    fn ($b) => sprintf('%s · %s GB · %d GB · $%s/mo', $b['bundleId'], $b['ramSizeInGb'] ?? 0, $b['diskSizeInGb'] ?? 0, $b['price'] ?? '?'), 'bundleId', fn ($b) => (bool) ($b['isActive'] ?? false)))->required(),
                Field::text('database', __('First database'))->required()->default('app'),
                Field::text('username', __('Master user'))->required()->default('dbmaster'),
                Field::checkbox('public', __('Reachable from the internet')),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Database :name is being created: its password is under its actions once ready.', ['name' => $data['name']]), fn () => self::lightsail($api, $scope, 'CreateRelationalDatabase', [
                'relationalDatabaseName' => $data['name'], 'relationalDatabaseBlueprintId' => $data['blueprint'], 'relationalDatabaseBundleId' => $data['bundle'],
                'masterDatabaseName' => $data['database'], 'masterUsername' => $data['username'], 'publiclyAccessible' => $data['public'],
            ])))
            ->actions([
                $this->lightsailAction('StartRelationalDatabase', __('Start'), 'play', 'relationalDatabaseName')->when(fn ($row) => $state($row) === 'stopped'),
                $this->lightsailAction('StopRelationalDatabase', __('Stop'), 'stop', 'relationalDatabaseName')->confirm(__('Stop the database? AWS starts it again after 7 days.'))->when(fn ($row) => $state($row) === 'available'),
                $this->lightsailAction('RebootRelationalDatabase', __('Reboot'), 'arrow-path', 'relationalDatabaseName')->confirm(__('Reboot the database?')),
                Action::make('password', __('Master password'))->icon('key')->confirm(__('Show the master password?'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show($row['name'], [
                        'host' => data_get($row, 'masterEndpoint.address'), 'port' => data_get($row, 'masterEndpoint.port'),
                        'database' => $row['masterDatabaseName'] ?? null, 'username' => $row['masterUsername'] ?? null,
                        'password' => self::lightsail($api, $scope, 'GetRelationalDatabaseMasterUserPassword', ['relationalDatabaseName' => $row['name']])['masterUserPassword'] ?? null,
                    ])),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('snapshot', __('Snapshot name'))->required()->default(fn ($row) => $row['name'].'-'.now()->format('Ymd-His'))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Snapshot started.'), fn () => self::lightsail($api, $scope, 'CreateRelationalDatabaseSnapshot', ['relationalDatabaseName' => $row['name'], 'relationalDatabaseSnapshotName' => $data['snapshot']]))),
                Action::make('public', __('Public access'))->icon('globe-alt')->fields([Field::checkbox('public', __('Reachable from the internet'))->default(fn ($row) => (bool) ($row['publiclyAccessible'] ?? false))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Saved.'), fn () => self::lightsail($api, $scope, 'UpdateRelationalDatabase', ['relationalDatabaseName' => $row['name'], 'publiclyAccessible' => $data['public']]))),
                Action::make('delete', __('Delete'))->icon('trash')->fields([Field::checkbox('final', __('Keep a final snapshot'))->default(true)])
                    ->danger(__('Delete the database?'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Database deleted.'), fn () => self::lightsail($api, $scope, 'DeleteRelationalDatabase', $data['final']
                        ? ['relationalDatabaseName' => $row['name'], 'skipFinalSnapshot' => false, 'finalRelationalDatabaseSnapshotName' => $row['name'].'-final-'.now()->format('Ymd-His')]
                        : ['relationalDatabaseName' => $row['name'], 'skipFinalSnapshot' => true]))),
            ]);
    }

    private function lightsailLoadBalancers(): Section
    {
        $instances = fn (AwsClient $api, array $scope) => self::lightsailInstanceOptions($api, $scope);
        $attached = fn ($row) => array_values(array_map(fn ($health) => (string) ($health['instanceName'] ?? ''), is_array($row['instanceHealthSummary'] ?? null) ? $row['instanceHealthSummary'] : []));

        return Section::make('lightsail-load-balancers', __('Load balancers'))
            ->icon('arrows-right-left')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetLoadBalancers', 'loadBalancers'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('dnsName', __('Address'))->mono(),
                Column::make('instancePort', __('Instance port')),
                Column::make('instances', __('Instances'))->using(fn ($row) => implode(', ', $attached($row))),
            ])
            ->create(__('New load balancer'), [
                Field::text('name', __('Name'))->required(),
                Field::number('port', __('Instance port'))->required()->default(80),
                Field::text('health', __('Health check path'))->default('/'),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Load balancer created: about $18 a month.'), fn () => self::lightsail($api, $scope, 'CreateLoadBalancer', ['loadBalancerName' => $data['name'], 'instancePort' => $data['port'], 'healthCheckPath' => $data['health'] ?? '/'])))
            ->actions([
                Action::make('instances', __('Instances'))->icon('server-stack')->fields([Field::multiselect('instances', __('Instances'), $instances)->default($attached)])
                    ->run(function (AwsClient $api, array $row, array $data, array $scope) use ($attached) {
                        $current = $attached($row);

                        if ($add = array_values(array_diff($data['instances'], $current))) {
                            self::lightsail($api, $scope, 'AttachInstancesToLoadBalancer', ['loadBalancerName' => $row['name'], 'instanceNames' => $add]);
                        }

                        if ($remove = array_values(array_diff($current, $data['instances']))) {
                            self::lightsail($api, $scope, 'DetachInstancesFromLoadBalancer', ['loadBalancerName' => $row['name'], 'instanceNames' => $remove]);
                        }

                        return __('Instances saved.');
                    }),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['name'], $row)),
                $this->lightsailAction('DeleteLoadBalancer', __('Delete'), 'trash', 'loadBalancerName')->danger(__('Delete the load balancer?')),
            ]);
    }

    private function lightsailContainers(): Section
    {
        $powers = ['nano' => 'Nano', 'micro' => 'Micro', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'xlarge' => 'XLarge'];

        return Section::make('lightsail-containers', __('Container services'))
            ->icon('cube')
            ->id('containerServiceName')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetContainerServices', 'containerServices'))
            ->columns([
                Column::make('containerServiceName', __('Service')),
                Column::make('state', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('power', __('Power')),
                Column::make('scale', __('Nodes')),
                Column::make('url', __('URL'))->mono(),
                Column::make('isDisabled', __('Disabled'))->bool(),
            ])
            ->create(__('New container service'), [
                Field::text('name', __('Name'))->required(),
                Field::select('power', __('Power'), $powers)->required()->default('nano'),
                Field::number('scale', __('Nodes'))->required()->default(1),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Service created: deploy containers to it from the AWS console or CLI.'), fn () => self::lightsail($api, $scope, 'CreateContainerService', ['serviceName' => $data['name'], 'power' => $data['power'], 'scale' => $data['scale']])))
            ->actions([
                Action::make('scale', __('Power and nodes'))->icon('arrows-pointing-out')->fields([
                    Field::select('power', __('Power'), $powers)->required()->default(fn ($row) => $row['power'] ?? 'nano'),
                    Field::number('scale', __('Nodes'))->required()->default(fn ($row) => $row['scale'] ?? 1),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Saved.'), fn () => self::lightsail($api, $scope, 'UpdateContainerService', ['serviceName' => $row['containerServiceName'], 'power' => $data['power'], 'scale' => $data['scale']]))),
                Action::make('disable', __('Disable'))->icon('pause')->confirm(__('Disable the service? It stops serving.'))->when(fn ($row) => empty($row['isDisabled']))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Disabled.'), fn () => self::lightsail($api, $scope, 'UpdateContainerService', ['serviceName' => $row['containerServiceName'], 'isDisabled' => true]))),
                Action::make('enable', __('Enable'))->icon('play')->when(fn ($row) => ! empty($row['isDisabled']))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Enabled.'), fn () => self::lightsail($api, $scope, 'UpdateContainerService', ['serviceName' => $row['containerServiceName'], 'isDisabled' => false]))),
                Action::make('deployment', __('Current deployment'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['containerServiceName'], $row['currentDeployment'] ?? $row)),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the container service?'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Service deleted.'), fn () => self::lightsail($api, $scope, 'DeleteContainerService', ['serviceName' => $row['containerServiceName']]))),
            ]);
    }

    private function lightsailBuckets(): Section
    {
        $bundles = fn (AwsClient $api, array $scope) => self::lightsailOptions($api, $scope, 'GetBucketBundles', 'bundles',
            fn ($b) => sprintf('%s · %d GB · $%s/mo', $b['bundleId'], $b['storagePerMonthInGb'] ?? 0, $b['price'] ?? '?'), 'bundleId', fn ($b) => (bool) ($b['isActive'] ?? false));

        return Section::make('lightsail-buckets', __('Buckets'))
            ->icon('archive-box')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetBuckets', 'buckets'))
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('state.code', __('State'))->badge(self::LIGHTSAIL_STATE),
                Column::make('url', __('URL'))->mono(),
                Column::make('bundleId', __('Plan'))->mono(),
                Column::make('accessRules.getObject', __('Objects'))->badge(['public' => 'yellow', 'private' => 'green']),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/']),
                Field::select('bundle', __('Plan'), $bundles)->required(),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Bucket created, private.'), fn () => self::lightsail($api, $scope, 'CreateBucket', ['bucketName' => $data['name'], 'bundleId' => $data['bundle']])))
            ->actions([
                Action::make('access', __('Public or private'))->icon('lock-closed')->fields([
                    Field::select('access', __('Objects are'), ['private' => __('Private'), 'public' => __('Public (anyone can read)')])->required()->default(fn ($row) => data_get($row, 'accessRules.getObject', 'private')),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Saved.'), fn () => self::lightsail($api, $scope, 'UpdateBucket', ['bucketName' => $row['name'], 'accessRules' => ['getObject' => $data['access']]]))),
                Action::make('plan', __('Change plan'))->icon('arrows-pointing-out')->when(fn ($row) => (bool) ($row['ableToUpdateBundle'] ?? true))
                    ->fields([Field::select('bundle', __('Plan'), $bundles)->required()->default(fn ($row) => $row['bundleId'] ?? null)])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Plan changed.'), fn () => self::lightsail($api, $scope, 'UpdateBucketBundle', ['bucketName' => $row['name'], 'bundleId' => $data['bundle']]))),
                Action::make('keys', __('Access keys'))->icon('key')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show($row['name'], self::lightsail($api, $scope, 'GetBucketAccessKeys', ['bucketName' => $row['name']])['accessKeys'] ?? [])),
                Action::make('new-key', __('New access key'))->icon('sparkles')->confirm(__('Create an access key for the bucket? The secret is shown once.'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(__('Access key of :bucket: save the secret now', ['bucket' => $row['name']]), self::lightsail($api, $scope, 'CreateBucketAccessKey', ['bucketName' => $row['name']])['accessKey'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->fields([Field::checkbox('force', __('Delete its objects too'))])
                    ->danger(__('Delete the bucket?'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Bucket deleted.'), fn () => self::lightsail($api, $scope, 'DeleteBucket', ['bucketName' => $row['name'], 'forceDelete' => $data['force']]))),
            ]);
    }

    private function lightsailDomains(): Section
    {
        return Section::make('lightsail-domains', __('DNS zones'))
            ->icon('globe-europe-africa')
            ->id('name')
            ->description(__('Lightsail DNS lives in us-east-1, whatever the region.'))
            ->list(fn (AwsClient $api) => self::lightsailAll($api, [], 'GetDomains', 'domains', region: 'us-east-1'))
            ->columns([
                Column::make('name', __('Domain'))->mono(),
                Column::make('entries', __('Records'))->using(fn ($row) => is_array($row['domainEntries'] ?? null) ? count($row['domainEntries']) : 0),
                Column::make('registeredDomainDelegationInfo.nameServersUpdateState.code', __('Nameservers')),
                Column::make('createdAt', __('Created'))->date(),
            ])
            ->create(__('New DNS zone'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
            ], fn (AwsClient $api, array $row, array $data) => tap(__('Zone created: point the domain to the Lightsail nameservers.'), fn () => self::lightsail($api, [], 'CreateDomain', ['domainName' => $data['name']], 'us-east-1')))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('lightsail-domain-entries', fn ($row) => ['domain' => $row['name']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the zone and its records?'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Zone deleted.'), fn () => self::lightsail($api, [], 'DeleteDomain', ['domainName' => $row['name']], 'us-east-1'))),
            ]);
    }

    private function lightsailDomainEntries(): Section
    {
        $entry = fn ($row) => array_filter(['id' => $row['id'] ?? null, 'name' => $row['name'] ?? null, 'target' => $row['target'] ?? null, 'type' => $row['type'] ?? null, 'isAlias' => $row['isAlias'] ?? null], fn ($value) => $value !== null);

        return Section::make('lightsail-domain-entries', __('DNS records'))
            ->childOf('lightsail-domains', 'domain')
            ->list(function (AwsClient $api, array $scope) {
                $domain = self::lightsail($api, $scope, 'GetDomain', ['domainName' => $scope['domain']], 'us-east-1')['domain'] ?? [];

                return array_values(array_filter(is_array($domain['domainEntries'] ?? null) ? $domain['domainEntries'] : [], 'is_array'));
            })
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('target', __('Value'))->mono(),
                Column::make('isAlias', __('Alias'))->bool(),
            ])
            ->create(__('New record'), [
                Field::select('type', __('Type'), array_combine($types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'], $types))->required()->default('A'),
                Field::text('name', __('Name'))->required()->help(__('The full name: www.example.com, or example.com for the zone itself')),
                Field::text('target', __('Value'))->required()->help(__('MX as "10 mail.example.com"; TXT in quotes')),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => self::lightsail($api, $scope, 'CreateDomainEntry', ['domainName' => $scope['domain'], 'domainEntry' => ['name' => $data['name'], 'target' => $data['target'], 'type' => $data['type']]], 'us-east-1')))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields([Field::text('target', __('Value'))->required()->default(fn ($row) => $row['target'] ?? null)])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => self::lightsail($api, $scope, 'UpdateDomainEntry', ['domainName' => $scope['domain'], 'domainEntry' => [...$entry($row), 'target' => $data['target']]], 'us-east-1'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))->when(fn ($row) => ! in_array($row['type'] ?? '', ['SOA', 'NS'], true))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => self::lightsail($api, $scope, 'DeleteDomainEntry', ['domainName' => $scope['domain'], 'domainEntry' => $entry($row)], 'us-east-1'))),
            ]);
    }

    private function lightsailDistributions(): Section
    {
        return Section::make('lightsail-distributions', __('Distributions (CDN)'))
            ->icon('bolt')
            ->id('name')
            ->list(fn (AwsClient $api) => self::lightsailAll($api, [], 'GetDistributions', 'distributions', region: 'us-east-1'))
            ->columns([
                Column::make('name', __('Distribution')),
                Column::make('status', __('Status'))->badge(self::LIGHTSAIL_STATE),
                Column::make('domainName', __('Address'))->mono(),
                Column::make('alternativeDomainNames', __('Domains'))->mono(),
                Column::make('origin.name', __('Origin')),
                Column::make('isEnabled', __('Enabled'))->bool(),
            ])
            ->actions([
                Action::make('reset', __('Reset the cache'))->icon('fire')->confirm(__('Empty the cache of the distribution?'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Cache reset.'), fn () => self::lightsail($api, [], 'ResetDistributionCache', ['distributionName' => $row['name']], 'us-east-1'))),
                Action::make('enable', __('Enable'))->icon('play')->when(fn ($row) => empty($row['isEnabled']))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Enabled.'), fn () => self::lightsail($api, [], 'UpdateDistribution', ['distributionName' => $row['name'], 'isEnabled' => true], 'us-east-1'))),
                Action::make('disable', __('Disable'))->icon('pause')->confirm(__('Disable the distribution?'))->when(fn ($row) => ! empty($row['isEnabled']))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Disabled.'), fn () => self::lightsail($api, [], 'UpdateDistribution', ['distributionName' => $row['name'], 'isEnabled' => false], 'us-east-1'))),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['name'], $row)),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the distribution?'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Distribution deleted.'), fn () => self::lightsail($api, [], 'DeleteDistribution', ['distributionName' => $row['name']], 'us-east-1'))),
            ]);
    }

    private function lightsailCertificates(): Section
    {
        return Section::make('lightsail-certificates', __('Certificates'))
            ->icon('lock-closed')
            ->id('certificateName')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetCertificates', 'certificates', ['includeCertificateDetails' => true]))
            ->columns([
                Column::make('certificateName', __('Certificate')),
                Column::make('domainName', __('Domain'))->mono(),
                Column::make('certificateDetail.status', __('Status'))->badge(['issued' => 'green', 'pending_validation' => 'yellow', 'failed' => 'red', 'expired' => 'red', '*' => 'zinc']),
                Column::make('certificateDetail.notAfter', __('Expires'))->date(),
            ])
            ->create(__('Request a certificate'), [
                Field::text('name', __('Name'))->required(),
                Field::text('domain', __('Domain'))->required()->placeholder('example.com'),
                Field::text('alternatives', __('Other names'))->placeholder('www.example.com'),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(__('Publish these records to validate it'), data_get(self::lightsail($api, $scope, 'CreateCertificate', array_filter([
                'certificateName' => $data['name'], 'domainName' => $data['domain'], 'subjectAlternativeNames' => self::lines($data['alternatives']),
            ])), 'certificate.certificateDetail.domainValidationRecords', []), __('Certificate requested.')))
            ->actions([
                Action::make('validation', __('Validation records'))->icon('finger-print')->run(fn (AwsClient $api, array $row) => Outcome::show($row['certificateName'], data_get($row, 'certificateDetail.domainValidationRecords', []))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the certificate? Detach it from distributions and load balancers first.'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Certificate deleted.'), fn () => self::lightsail($api, $scope, 'DeleteCertificate', ['certificateName' => $row['certificateName']]))),
            ]);
    }

    private function lightsailAlarms(): Section
    {
        return Section::make('lightsail-alarms', __('Alarms'))
            ->icon('bell-alert')
            ->id('name')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::lightsailAll($api, $scope, 'GetAlarms', 'alarms'))
            ->columns([
                Column::make('name', __('Alarm')),
                Column::make('state', __('State'))->badge(['ok' => 'green', 'alarm' => 'red', 'insufficient_data' => 'zinc']),
                Column::make('monitoredResourceInfo.name', __('On')),
                Column::make('metricName', __('Metric')),
                Column::make('threshold', __('Threshold'))->using(fn ($row) => ($row['comparisonOperator'] ?? '').' '.($row['threshold'] ?? '')),
            ])
            ->actions([
                $this->lightsailAction('DeleteAlarm', __('Delete'), 'trash', 'alarmName')->danger(__('Delete the alarm?')),
            ]);
    }

    private function lightsailOperations(): Section
    {
        return Section::make('lightsail-operations', __('Operations'))
            ->icon('clock')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => array_slice(self::lightsailAll($api, $scope, 'GetOperations', 'operations'), 0, 200))
            ->columns([
                Column::make('operationType', __('Operation')),
                Column::make('resourceName', __('On')),
                Column::make('status', __('Status'))->badge(['succeeded' => 'green', 'completed' => 'green', 'started' => 'blue', 'failed' => 'red', '*' => 'zinc']),
                Column::make('errorDetails', __('Error')),
                Column::make('createdAt', __('When'))->date(),
            ]);
    }
}
