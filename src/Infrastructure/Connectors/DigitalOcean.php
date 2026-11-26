<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Client;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * DigitalOcean, with a personal access token per team: Droplets and their
 * power, snapshots and sizes, domains and records, SSH keys, firewalls,
 * volumes, reserved IPs, load balancers, managed databases, App Platform and
 * Kubernetes; Spaces buckets (S3, with a Spaces key) and Spaces keys.
 * API v2, https://docs.digitalocean.com/reference/api/
 */
final class DigitalOcean extends Connector
{
    private const SPACES_REGIONS = [
        'fra1' => 'Frankfurt (fra1)', 'ams3' => 'Amsterdam (ams3)', 'lon1' => 'London (lon1)', 'nyc3' => 'New York (nyc3)',
        'sfo2' => 'San Francisco (sfo2)', 'sfo3' => 'San Francisco (sfo3)', 'tor1' => 'Toronto (tor1)', 'atl1' => 'Atlanta (atl1)',
        'sgp1' => 'Singapore (sgp1)', 'blr1' => 'Bangalore (blr1)', 'syd1' => 'Sydney (syd1)',
    ];

    private const STATUS = ['active' => 'green', 'new' => 'blue', 'off' => 'zinc', 'archive' => 'zinc', 'online' => 'green', 'errored' => 'red', 'running' => 'green', '*' => 'yellow'];

    public function key(): string
    {
        return 'digitalocean';
    }

    public function label(): string
    {
        return 'DigitalOcean';
    }

    public function description(): string
    {
        return __('Droplets, domains and DNS, firewalls, volumes, reserved IPs, databases, App Platform, Kubernetes and Spaces, one team per account.');
    }

    public function icon(): string
    {
        return 'globe-alt';
    }

    public function docs(): string
    {
        return 'https://docs.digitalocean.com/reference/api/create-personal-access-token/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('Personal access token'))->required()->placeholder('dop_v1_…')
                ->help(__('API → Tokens → Generate New Token, with read and write scope (or full access) for the team.')),
            Field::text('spaces_access_key_id', __('Spaces access key'))->placeholder('DO00…')
                ->help(__('Only to browse Spaces buckets: a Spaces key with full access (the Spaces keys tab makes one). The token alone manages the keys.')),
            Field::password('spaces_secret', __('Spaces secret key')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('cloud.digitalocean.com → API → Tokens → Generate New Token.'),
            __('Scopes: Full Access, or Custom Scopes with read and the actions you use: droplet, domain, firewall, ssh_key, snapshot, volume, reserved_ip, load_balancer, database, app, kubernetes, spaces_key.'),
            __('A token belongs to one team: add an account per team. Set an expiry and renew it here when it ends.'),
            __('Spaces: the token manages the Spaces keys (scope spaces_key). Buckets and their files speak S3, with a Spaces key: make one with full access in the Spaces keys tab, or Spaces Object Storage → Access Keys, and paste it in the two Spaces fields.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        return new ScopedClient('https://api.digitalocean.com/v2', ['Authorization' => 'Bearer '.self::string($credentials, 'token')], [
            'spaces_key' => self::string($credentials, 'spaces_access_key_id'),
            'spaces_secret' => self::string($credentials, 'spaces_secret'),
        ]);
    }

    public function alsoIn(): array
    {
        return ['storage' => __('Spaces: buckets by region, their files with links that expire, CORS, and Spaces keys scoped to a bucket.')];
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $account = $api->get('/account')['account'] ?? [];

        return 'ok · '.(data_get($account, 'team.name') ?: data_get($account, 'email', ''));
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->droplets(),
            $this->domains(),
            $this->records(),
            $this->sshKeys(),
            $this->firewalls(),
            $this->volumes(),
            $this->snapshots(),
            $this->reservedIps(),
            $this->loadBalancers(),
            $this->databases(),
            $this->apps(),
            $this->kubernetes(),
            $this->spaces(),
            S3Sections::objects('spaces-objects', 'spaces', fn (ScopedClient $api) => self::s3($api), fn (ScopedClient $api, array $scope) => (string) ($scope['region'] ?? '') !== '' ? (string) $scope['region'] : 'fra1'),
            $this->spacesKeys(),
        ];
    }

    /**
     * Every page of a list: DigitalOcean answers with links.pages.next.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key, array $query = []): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            ['per_page' => 200, ...$query],
            fn (array $answer) => array_values((array) ($answer[$key] ?? [])),
            fn (array $answer) => is_string($next = data_get($answer, 'links.pages.next')) ? $next : null,
        );
    }

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (ApiClient $api) {
            $account = (array) ($api->get('/account')['account'] ?? []);
            $balance = $api->get('/customers/my/balance');

            return [
                __('Account') => [
                    __('Team') => data_get($account, 'team.name'),
                    __('Email') => $account['email'] ?? null,
                    __('Status') => $account['status'] ?? null,
                    __('Droplet limit') => $account['droplet_limit'] ?? null,
                ],
                __('Billing') => [
                    __('Usage this month') => '$'.($balance['month_to_date_usage'] ?? '0'),
                    __('Balance this month') => '$'.($balance['month_to_date_balance'] ?? '0'),
                    __('Account balance') => '$'.($balance['account_balance'] ?? '0'),
                ],
            ];
        });
    }

    private function droplets(): Section
    {
        $sizes = fn (ApiClient $api) => collect(self::every($api, '/sizes', 'sizes'))
            ->filter(fn ($size) => $size['available'] ?? true)
            ->mapWithKeys(fn ($size) => [(string) $size['slug'] => sprintf('%s · %d vCPU · %s GB · %d GB disk · $%s/mo', $size['slug'], $size['vcpus'] ?? 0, round(($size['memory'] ?? 0) / 1024, 1), $size['disk'] ?? 0, $size['price_monthly'] ?? '?')])
            ->all();

        return Section::make('droplets', __('Droplets'))
            ->icon('server')
            ->list(fn (ApiClient $api) => self::every($api, '/droplets', 'droplets'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('ip', __('Public IP'))->mono()->using(fn ($row) => ServerTargets::label(collect(self::items(data_get($row, 'networks.v4', [])))->firstWhere('type', 'public')['ip_address'] ?? null)),
                Column::make('size_slug', __('Size'))->mono(),
                Column::make('region.slug', __('Region')),
                Column::make('image', __('Image'))->using(fn ($row) => trim(data_get($row, 'image.distribution', '').' '.data_get($row, 'image.name', ''))),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->create(__('New Droplet'), [
                Field::text('name', __('Name'))->required()->placeholder('web-1'),
                Field::select('region', __('Region'), fn (ApiClient $api) => collect(self::every($api, '/regions', 'regions'))
                    ->filter(fn ($region) => $region['available'] ?? false)
                    ->mapWithKeys(fn ($region) => [(string) $region['slug'] => $region['name'].' ('.$region['slug'].')'])->all())->required(),
                Field::select('size', __('Size'), $sizes)->required(),
                Field::select('image', __('Image'), fn (ApiClient $api) => collect(self::every($api, '/images', 'images', ['type' => 'distribution']))
                    ->mapWithKeys(fn ($image) => [(string) ($image['slug'] ?? $image['id']) => trim(($image['distribution'] ?? '').' '.($image['name'] ?? ''))])->all())->required(),
                Field::multiselect('ssh_keys', __('SSH keys'), fn (ApiClient $api) => collect(self::every($api, '/account/keys', 'ssh_keys'))
                    ->mapWithKeys(fn ($key) => [(string) $key['id'] => (string) $key['name']])->all()),
                Field::checkbox('backups', __('Weekly backups')),
                Field::checkbox('ipv6', __('IPv6')),
                Field::checkbox('monitoring', __('Monitoring agent'))->default(true),
                Field::text('tags', __('Tags'))->placeholder('web, production'),
                Field::textarea('user_data', __('User data (cloud-init)')),
            ], function (ApiClient $api, array $row, array $data) {
                $droplet = $api->post('/droplets', array_filter([
                    ...$data,
                    'ssh_keys' => array_map('intval', $data['ssh_keys']),
                    'tags' => self::lines($data['tags']),
                ], fn ($value) => $value !== null && $value !== []))['droplet'] ?? [];

                return __('Droplet :name is being created.', ['name' => $droplet['name'] ?? $data['name']]);
            })
            ->actions([
                $this->power('power_on', __('Power on'), 'play')->when(fn ($row) => ($row['status'] ?? '') === 'off'),
                $this->power('shutdown', __('Shut down'), 'stop')->confirm(__('Shut the Droplet down gracefully?'))->when(fn ($row) => ($row['status'] ?? '') === 'active'),
                $this->power('reboot', __('Reboot'), 'arrow-path')->confirm(__('Reboot the Droplet?')),
                $this->power('power_cycle', __('Power cycle'), 'bolt')->confirm(__('Cut the power and turn it on again? Like pulling the plug.')),
                $this->power('power_off', __('Power off'), 'power')->confirm(__('Cut the power? Unsaved data may be lost.'))->when(fn ($row) => ($row['status'] ?? '') === 'active'),
                $this->power('snapshot', __('Take a snapshot'), 'camera')->fields([Field::text('name', __('Snapshot name'))->required()->default(fn ($row) => ($row['name'] ?? 'droplet').'-'.now()->format('Ymd-His'))]),
                $this->power('resize', __('Resize'), 'arrows-pointing-out')->fields([
                    Field::select('size', __('New size'), $sizes)->required()->default(fn ($row) => $row['size_slug'] ?? null),
                    Field::checkbox('disk', __('Grow the disk too (cannot be undone)')),
                ])->confirm(__('The Droplet must be off to resize. Go ahead?')),
                $this->power('rename', __('Rename'), 'pencil')->fields([Field::text('name', __('Name'))->required()->default(fn ($row) => $row['name'] ?? '')]),
                $this->power('rebuild', __('Rebuild from an image'), 'arrow-uturn-left')->fields([
                    Field::text('image', __('Image slug or id'))->required()->default(fn ($row) => data_get($row, 'image.slug') ?? data_get($row, 'image.id'))->placeholder('ubuntu-24-04-x64'),
                ])->danger(__('Rebuild erases the Droplet\'s disk. Go ahead?')),
                $this->power('enable_backups', __('Enable backups'), 'archive-box')->when(fn ($row) => ! in_array('backups', (array) ($row['features'] ?? []), true)),
                $this->power('disable_backups', __('Disable backups'), 'archive-box-x-mark')->when(fn ($row) => in_array('backups', (array) ($row['features'] ?? []), true)),
                $this->power('password_reset', __('Reset the root password'), 'key')->confirm(__('The Droplet reboots and the new password is emailed to the team.')),
                Action::make('delete', __('Destroy'))->icon('trash')->danger(__('Destroy the Droplet and its disk? This cannot be undone.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Droplet destroyed.'), fn () => $api->delete('/droplets/'.$row['id']))),
            ]);
    }

    /** A Droplet action: POST /droplets/{id}/actions with its type and the form's fields. */
    private function power(string $type, string $label, string $icon): Action
    {
        return Action::make($type, $label)->icon($icon)->run(function (ApiClient $api, array $row, array $data) use ($type, $label) {
            $api->post('/droplets/'.$row['id'].'/actions', array_filter(['type' => $type, ...$data], fn ($value) => $value !== null));

            return __(':action: sent to :name.', ['action' => $label, 'name' => $row['name'] ?? $row['id']]);
        });
    }

    private function domains(): Section
    {
        return Section::make('domains', __('Domains'))
            ->icon('globe-europe-africa')
            ->id('name')
            ->list(fn (ApiClient $api) => self::every($api, '/domains', 'domains'))
            ->columns([
                Column::make('name', __('Domain')),
                Column::make('ttl', __('TTL'))->mono(),
            ])
            ->create(__('Add a domain'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::text('ip_address', __('IP for the apex A record'))->placeholder(__('optional')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Domain added: point its nameservers to ns1–ns3.digitalocean.com.'), fn () => $api->post('/domains', array_filter($data))))
            ->actions([
                Action::make('records', __('DNS records'))->icon('list-bullet')->opens('records', fn ($row) => ['domain' => $row['name']]),
                Action::make('zone', __('Zone file'))->icon('document-text')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row['zone_file'] ?? '')),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain and all its records?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/domains/'.$row['name']))),
            ]);
    }

    private function records(): Section
    {
        $fields = fn (bool $editing) => [
            Field::select('type', __('Type'), array_combine($types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'], $types))->required()->default(fn ($row) => $row['type'] ?? 'A'),
            Field::text('name', __('Name'))->required()->default(fn ($row) => $row['name'] ?? '@')->help(__('@ for the domain itself')),
            Field::suggest('data', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['data'] ?? null) ?? ($row['data'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA).')),
            Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? 1800),
            Field::number('priority', __('Priority (MX, SRV)'))->default(fn ($row) => $row['priority'] ?? null),
            Field::number('port', __('Port (SRV)'))->default(fn ($row) => $row['port'] ?? null),
            Field::number('weight', __('Weight (SRV)'))->default(fn ($row) => $row['weight'] ?? null),
        ];

        return Section::make('records', __('DNS records'))
            ->childOf('domains', 'domain')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/domains/'.$scope['domain'].'/records', 'domain_records'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('data', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['data'] ?? null)),
                Column::make('priority', __('Priority')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record'), $fields(false), fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post('/domains/'.$scope['domain'].'/records', array_filter([...$data, 'data' => ServerTargets::resolve($data['type'] ?? null, $data['data'] ?? null)], fn ($v) => $v !== null))))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields(true))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->put('/domains/'.$scope['domain'].'/records/'.$row['id'], array_filter([...$data, 'data' => ServerTargets::resolve($data['type'] ?? null, $data['data'] ?? null)], fn ($v) => $v !== null)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $api->delete('/domains/'.$scope['domain'].'/records/'.$row['id']))),
            ]);
    }

    private function sshKeys(): Section
    {
        return Section::make('ssh-keys', __('SSH keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::every($api, '/account/keys', 'ssh_keys'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('fingerprint', __('Fingerprint'))->mono(),
            ])
            ->create(__('Add a key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('public_key', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added.'), fn () => $api->post('/account/keys', $data)))
            ->actions([
                Action::make('show', __('Public key'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row['public_key'] ?? '')),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Remove the key from the team? Existing Droplets keep it.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key removed.'), fn () => $api->delete('/account/keys/'.$row['id']))),
            ]);
    }

    private function firewalls(): Section
    {
        $droplets = fn (ApiClient $api) => collect(self::every($api, '/droplets', 'droplets'))->mapWithKeys(fn ($d) => [(string) $d['id'] => (string) $d['name']])->all();

        return Section::make('firewalls', __('Firewalls'))
            ->icon('shield-check')
            ->list(fn (ApiClient $api) => self::every($api, '/firewalls', 'firewalls'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(['succeeded' => 'green', 'waiting' => 'yellow', 'failed' => 'red']),
                Column::make('inbound', __('Inbound'))->mono()->using(fn ($row) => collect(self::items($row['inbound_rules'] ?? []))->map(fn ($rule) => ($rule['ports'] ?? '').'/'.($rule['protocol'] ?? ''))->implode(', ')),
                Column::make('droplets', __('Droplets'))->using(fn ($row) => count($row['droplet_ids'] ?? [])),
            ])
            ->create(__('New firewall'), [
                Field::text('name', __('Name'))->required()->placeholder('web'),
                Field::text('ports', __('Open inbound ports'))->required()->default('22, 80, 443')->help(__('From anywhere: 22, 80, 443, 8000-8100, 53/udp, icmp. Outbound stays open.')),
                Field::multiselect('droplet_ids', __('Droplets'), $droplets),
            ], function (ApiClient $api, array $row, array $data) {
                $anywhere = ['addresses' => ['0.0.0.0/0', '::/0']];
                $api->post('/firewalls', [
                    'name' => $data['name'],
                    'inbound_rules' => array_map(fn ($rule) => ['protocol' => $rule['protocol'], 'ports' => $rule['port'] ?? '0', 'sources' => $anywhere], self::ports($data['ports'])),
                    'outbound_rules' => array_map(fn ($protocol) => ['protocol' => $protocol, 'ports' => $protocol === 'icmp' ? '0' : 'all', 'destinations' => $anywhere], ['tcp', 'udp', 'icmp']),
                    'droplet_ids' => array_map('intval', $data['droplet_ids']),
                ]);

                return __('Firewall created.');
            })
            ->actions([
                Action::make('assign', __('Apply to Droplets'))->icon('link')->fields([Field::multiselect('droplet_ids', __('Droplets'), $droplets)->required()->default(fn ($row) => array_map('strval', $row['droplet_ids'] ?? []))])
                    ->run(function (ApiClient $api, array $row, array $data) {
                        $wanted = array_map('intval', $data['droplet_ids']);
                        $current = array_map('intval', $row['droplet_ids'] ?? []);

                        if ($add = array_values(array_diff($wanted, $current))) {
                            $api->post('/firewalls/'.$row['id'].'/droplets', ['droplet_ids' => $add]);
                        }

                        if ($remove = array_values(array_diff($current, $wanted))) {
                            $api->delete('/firewalls/'.$row['id'].'/droplets', ['droplet_ids' => $remove]);
                        }

                        return __('Firewall applied.');
                    }),
                Action::make('rules', __('Rules'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], ['inbound' => $row['inbound_rules'] ?? [], 'outbound' => $row['outbound_rules'] ?? []])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the firewall? Its Droplets lose its rules.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Firewall deleted.'), fn () => $api->delete('/firewalls/'.$row['id']))),
            ]);
    }

    private function volumes(): Section
    {
        return Section::make('volumes', __('Volumes'))
            ->icon('circle-stack')
            ->list(fn (ApiClient $api) => self::every($api, '/volumes', 'volumes'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('size_gigabytes', __('Size (GB)')),
                Column::make('region.slug', __('Region')),
                Column::make('filesystem_type', __('Filesystem'))->mono(),
                Column::make('droplet_ids', __('Attached to'))->mono(),
            ])
            ->create(__('New volume'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9-]+$/']),
                Field::number('size_gigabytes', __('Size (GB)'))->required()->default(10),
                Field::text('region', __('Region'))->required()->placeholder('fra1'),
                Field::select('filesystem_type', __('Filesystem'), ['ext4' => 'ext4', 'xfs' => 'xfs'])->default('ext4'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Volume created.'), fn () => $api->post('/volumes', $data)))
            ->actions([
                Action::make('attach', __('Attach to a Droplet'))->icon('link')
                    ->fields([Field::select('droplet_id', __('Droplet'), fn (ApiClient $api) => collect(self::every($api, '/droplets', 'droplets'))->mapWithKeys(fn ($d) => [(string) $d['id'] => $d['name'].' · '.data_get($d, 'region.slug')])->all())->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Attaching.'), fn () => $api->post('/volumes/'.$row['id'].'/actions', ['type' => 'attach', 'droplet_id' => (int) $data['droplet_id'], 'region' => data_get($row, 'region.slug')]))),
                Action::make('detach', __('Detach'))->icon('link-slash')->confirm(__('Unmount it on the Droplet first. Detach?'))->when(fn ($row) => ($row['droplet_ids'] ?? []) !== [])
                    ->run(fn (ApiClient $api, array $row) => tap(__('Detaching.'), fn () => $api->post('/volumes/'.$row['id'].'/actions', ['type' => 'detach', 'droplet_id' => $row['droplet_ids'][0], 'region' => data_get($row, 'region.slug')]))),
                Action::make('resize', __('Resize'))->icon('arrows-pointing-out')->fields([Field::number('size_gigabytes', __('New size (GB)'))->required()->default(fn ($row) => $row['size_gigabytes'] ?? null)])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Resizing: grow the filesystem on the Droplet afterwards.'), fn () => $api->post('/volumes/'.$row['id'].'/actions', ['type' => 'resize', 'size_gigabytes' => $data['size_gigabytes'], 'region' => data_get($row, 'region.slug')]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the volume and its data?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Volume deleted.'), fn () => $api->delete('/volumes/'.$row['id']))),
            ]);
    }

    private function snapshots(): Section
    {
        return Section::make('snapshots', __('Snapshots'))
            ->icon('camera')
            ->list(fn (ApiClient $api) => self::every($api, '/snapshots', 'snapshots'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('resource_type', __('Of'))->badge(),
                Column::make('size_gigabytes', __('Size (GB)')),
                Column::make('regions', __('Regions')),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the snapshot?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Snapshot deleted.'), fn () => $api->delete('/snapshots/'.$row['id']))),
            ]);
    }

    private function reservedIps(): Section
    {
        $droplets = fn (ApiClient $api) => collect(self::every($api, '/droplets', 'droplets'))->mapWithKeys(fn ($d) => [(string) $d['id'] => $d['name'].' · '.data_get($d, 'region.slug')])->all();

        return Section::make('reserved-ips', __('Reserved IPs'))
            ->icon('map-pin')
            ->id('ip')
            ->list(fn (ApiClient $api) => self::every($api, '/reserved_ips', 'reserved_ips'))
            ->columns([
                Column::make('ip', __('IP'))->mono()->using(fn ($row) => ServerTargets::label($row['ip'] ?? null)),
                Column::make('region.slug', __('Region')),
                Column::make('droplet.name', __('Droplet')),
            ])
            ->create(__('Reserve an IP'), [
                Field::select('droplet_id', __('Assign to'), $droplets)->help(__('Or leave empty and give a region')),
                Field::text('region', __('Region'))->placeholder('fra1'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('IP reserved.'), fn () => $api->post('/reserved_ips', $data['droplet_id'] ? ['droplet_id' => (int) $data['droplet_id']] : ['region' => $data['region']])))
            ->actions([
                Action::make('assign', __('Assign'))->icon('link')->fields([Field::select('droplet_id', __('Droplet'), $droplets)->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Assigning.'), fn () => $api->post('/reserved_ips/'.$row['ip'].'/actions', ['type' => 'assign', 'droplet_id' => (int) $data['droplet_id']]))),
                Action::make('unassign', __('Unassign'))->icon('link-slash')->confirm(__('Unassign the IP?'))->when(fn ($row) => ! empty($row['droplet']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Unassigning.'), fn () => $api->post('/reserved_ips/'.$row['ip'].'/actions', ['type' => 'unassign']))),
                Action::make('delete', __('Release'))->icon('trash')->danger(__('Release the IP? It may never come back.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('IP released.'), fn () => $api->delete('/reserved_ips/'.$row['ip']))),
            ]);
    }

    private function loadBalancers(): Section
    {
        return Section::make('load-balancers', __('Load balancers'))
            ->icon('arrows-right-left')
            ->list(fn (ApiClient $api) => self::every($api, '/load_balancers', 'load_balancers'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('ip', __('IP'))->mono()->using(fn ($row) => ServerTargets::label($row['ip'] ?? null)),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('region.slug', __('Region')),
                Column::make('droplets', __('Droplets'))->using(fn ($row) => count($row['droplet_ids'] ?? [])),
            ])
            ->actions([
                Action::make('show', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row)),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the load balancer?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Load balancer deleted.'), fn () => $api->delete('/load_balancers/'.$row['id']))),
            ]);
    }

    private function databases(): Section
    {
        return Section::make('databases', __('Databases'))
            ->icon('circle-stack')
            ->list(fn (ApiClient $api) => array_values((array) ($api->get('/databases')['databases'] ?? [])))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('engine', __('Engine'))->using(fn ($row) => ($row['engine'] ?? '').' '.($row['version'] ?? '')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('size', __('Size'))->mono(),
                Column::make('num_nodes', __('Nodes')),
                Column::make('region', __('Region')),
            ])
            ->actions([
                Action::make('connection', __('Connection details'))->icon('key')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row['connection'] ?? [])),
                Action::make('backups', __('Backups'))->icon('archive-box')->run(fn (ApiClient $api, array $row) => Outcome::show(__('Backups of :name', ['name' => $row['name']]), $api->get('/databases/'.$row['id'].'/backups')['backups'] ?? [])),
                Action::make('dbs', __('Databases inside'))->icon('list-bullet')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], array_column((array) ($api->get('/databases/'.$row['id'].'/dbs')['dbs'] ?? []), 'name'))),
                Action::make('adddb', __('Add a database'))->icon('plus')->fields([Field::text('name', __('Name'))->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Database added.'), fn () => $api->post('/databases/'.$row['id'].'/dbs', ['name' => $data['name']]))),
                Action::make('delete', __('Destroy'))->icon('trash')->danger(__('Destroy the cluster and every database in it?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Cluster destroyed.'), fn () => $api->delete('/databases/'.$row['id']))),
            ]);
    }

    private function apps(): Section
    {
        return Section::make('apps', __('App Platform'))
            ->icon('cube')
            ->list(fn (ApiClient $api) => self::every($api, '/apps', 'apps'))
            ->columns([
                Column::make('spec.name', __('Name')),
                Column::make('live_url', __('URL'))->mono(),
                Column::make('active_deployment.phase', __('Deployment'))->badge(['active' => 'green', 'error' => 'red', '*' => 'yellow']),
                Column::make('region.slug', __('Region')),
                Column::make('updated_at', __('Updated'))->date(),
            ])
            ->actions([
                Action::make('deploy', __('Deploy'))->icon('rocket-launch')->confirm(__('Start a new deployment?'))->fields([Field::checkbox('force_build', __('Force a clean build'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Deployment started.'), fn () => $api->post('/apps/'.$row['id'].'/deployments', ['force_build' => $data['force_build']]))),
                Action::make('deployments', __('Recent deployments'))->icon('clock')->run(fn (ApiClient $api, array $row) => Outcome::show(data_get($row, 'spec.name', ''), collect(self::items($api->get('/apps/'.$row['id'].'/deployments', ['per_page' => 10])['deployments'] ?? []))
                    ->map(fn ($d) => ['id' => $d['id'] ?? null, 'phase' => $d['phase'] ?? null, 'cause' => $d['cause'] ?? null, 'created_at' => $d['created_at'] ?? null])->all())),
                Action::make('spec', __('Spec'))->icon('document-text')->run(fn (ApiClient $api, array $row) => Outcome::show(data_get($row, 'spec.name', ''), $row['spec'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the app?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('App deleted.'), fn () => $api->delete('/apps/'.$row['id']))),
            ]);
    }

    private function kubernetes(): Section
    {
        return Section::make('kubernetes', __('Kubernetes'))
            ->icon('cube-transparent')
            ->list(fn (ApiClient $api) => self::every($api, '/kubernetes/clusters', 'kubernetes_clusters'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status.state', __('Status'))->badge(self::STATUS),
                Column::make('version', __('Version'))->mono(),
                Column::make('region', __('Region')),
                Column::make('nodes', __('Nodes'))->using(fn ($row) => collect(self::items($row['node_pools'] ?? []))->sum('count')),
            ])
            ->actions([
                Action::make('kubeconfig', __('kubeconfig'))->icon('document-arrow-down')->run(fn (ApiClient $api, array $row) => Outcome::show('kubeconfig · '.$row['name'], $api->text('/kubernetes/clusters/'.$row['id'].'/kubeconfig'))),
                Action::make('pools', __('Node pools'))->icon('server-stack')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row['node_pools'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the cluster and its nodes?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Cluster deleted.'), fn () => $api->delete('/kubernetes/clusters/'.$row['id']))),
            ]);
    }

    /** The S3 endpoint of Spaces, signed with the account's Spaces key. */
    private static function s3(ScopedClient $api): S3Client
    {
        if ($api->scope('spaces_key') === '' || $api->scope('spaces_secret') === '') {
            throw new ConnectorException(__('Buckets speak S3 and need a Spaces key: make one in the Spaces keys tab (full access), then paste it in this account (Settings → Connectors → DigitalOcean → Edit).'));
        }

        return S3Client::make($api->scope('spaces_key'), $api->scope('spaces_secret'), 'https://{region}.digitaloceanspaces.com', 'fra1');
    }

    private function spaces(): Section
    {
        $nameOf = fn (array $row) => (string) $row['Name'];
        $regionOf = fn (ScopedClient $api, array $row) => (string) ($row['_region'] ?? 'fra1');

        return Section::make('spaces', __('Spaces'))
            ->icon('archive-box')
            ->id('Name')
            ->filters([Field::select('region', __('Region'), self::SPACES_REGIONS)->default('fra1')])
            ->list(function (ScopedClient $api, array $scope) {
                $region = (string) ($scope['region'] ?? 'fra1');

                return array_map(fn ($b) => [...$b, '_region' => $region, '_cdn' => 'https://'.($b['Name'] ?? '').'.'.$region.'.cdn.digitaloceanspaces.com'], self::s3($api)->buckets($region));
            })
            ->columns([
                Column::make('Name', __('Bucket'))->mono(),
                Column::make('_region', __('Region')),
                Column::make('CreationDate', __('Created'))->date(),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/']),
            ], function (ScopedClient $api, array $row, array $data, array $scope) {
                $region = (string) ($scope['region'] ?? 'fra1');
                self::s3($api)->createBucket((string) $data['name'], $region);

                return __('Bucket created in :region, private.', ['region' => $region]);
            })
            ->actions([
                ...S3Sections::bucketActions('spaces-objects', fn (ScopedClient $api) => self::s3($api), $regionOf, $nameOf),
                Action::make('app-key', __('Key for an app'))->icon('key')
                    ->fields([Field::checkbox('read_only', __('Read only'))])
                    ->run(function (ScopedClient $api, array $row, array $data) use ($regionOf) {
                        $region = $regionOf($api, $row);
                        $key = (array) ($api->post('/spaces/keys', [
                            'name' => 'cipi-'.$row['Name'],
                            'grants' => [['bucket' => $row['Name'], 'permission' => ($data['read_only'] ?? false) ? 'read' : 'readwrite']],
                        ])['key'] ?? []);

                        return Outcome::show(__('.env of an app on :bucket', ['bucket' => $row['Name']]), S3Sections::env([
                            'AWS_ACCESS_KEY_ID' => (string) ($key['access_key'] ?? ''),
                            'AWS_SECRET_ACCESS_KEY' => (string) ($key['secret_key'] ?? ''),
                            'AWS_DEFAULT_REGION' => $region,
                            'AWS_BUCKET' => (string) $row['Name'],
                            'AWS_ENDPOINT' => 'https://'.$region.'.digitaloceanspaces.com',
                            'AWS_URL' => 'https://'.$row['Name'].'.'.$region.'.digitaloceanspaces.com',
                            'AWS_USE_PATH_STYLE_ENDPOINT' => 'false',
                        ])."\n\n# ".__('The secret is shown once. The key opens :bucket only.', ['bucket' => $row['Name']]), __('Key created.'));
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Bucket deleted.'), fn () => self::s3($api)->deleteBucket((string) $row['Name'], (string) ($row['_region'] ?? 'fra1')))),
            ]);
    }

    private function spacesKeys(): Section
    {
        return Section::make('spaces-keys', __('Spaces keys'))
            ->icon('key')
            ->id('access_key')
            ->list(fn (ApiClient $api) => self::every($api, '/spaces/keys', 'keys'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('access_key', __('Access key'))->mono(),
                Column::make('grants', __('Grants'))->using(fn ($row) => implode(', ', array_map(fn ($g) => (($g['bucket'] ?? '') ?: '*').': '.($g['permission'] ?? ''), (array) ($row['grants'] ?? [])))),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->create(__('New key'), [
                Field::text('name', __('Name'))->required(),
                Field::select('permission', __('Access'), ['fullaccess' => __('Full access, every bucket'), 'readwrite' => __('Read and write, the buckets below'), 'read' => __('Read only, the buckets below')])->default('fullaccess'),
                Field::textarea('buckets', __('Buckets'))->placeholder("uploads\nbackups")->help(__('One per line; ignored for full access.')),
            ], function (ApiClient $api, array $row, array $data) {
                $permission = (string) ($data['permission'] ?? 'fullaccess');
                $grants = $permission === 'fullaccess'
                    ? [['bucket' => '', 'permission' => 'fullaccess']]
                    : array_map(fn ($b) => ['bucket' => $b, 'permission' => $permission], self::lines($data['buckets'] ?? '') ?: throw new ConnectorException(__('Name the buckets the key opens.')));
                $key = (array) ($api->post('/spaces/keys', ['name' => $data['name'], 'grants' => $grants])['key'] ?? []);

                return Outcome::show(__('Spaces key :name', ['name' => $data['name']]), __('access key').': '.($key['access_key'] ?? '')."\n".__('secret key').': '.($key['secret_key'] ?? '')."\n\n# ".__('The secret is shown once.'), __('Key created.'));
            })
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? Whatever uses it loses the buckets at once.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/spaces/keys/'.rawurlencode((string) $row['access_key'])))),
            ]);
    }
}
