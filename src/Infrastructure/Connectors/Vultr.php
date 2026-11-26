<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * Vultr, with an API key: instances, SSH keys, DNS domains and records,
 * firewall groups and their rules, snapshots, block storage and reserved
 * IPs. https://www.vultr.com/api/
 */
final class Vultr extends Connector
{
    public function key(): string
    {
        return 'vultr';
    }

    public function label(): string
    {
        return 'Vultr';
    }

    public function description(): string
    {
        return __('Instances, SSH keys, DNS domains, firewall groups, snapshots, block storage and reserved IPs.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://www.vultr.com/api/#section/Authentication';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API key'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('my.vultr.com → Account → API → Enable API: the Personal Access Token.'),
            __('Access Control: add the panel server\'s address (Vultr refuses the key from any other one), or "Allow all IPv4" and "Allow all IPv6".'),
            __('The key has every permission of the user: for a smaller one, a sub-user with the permissions you want (Account → Users) and its own key.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.vultr.com/v2', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $account = (array) ($api->get('/account')['account'] ?? []);

        return 'ok · '.trim(($account['name'] ?? '').' '.(($account['email'] ?? '') !== '' ? '· '.$account['email'] : '').(isset($account['balance']) ? ' · '.__('balance :b', ['b' => $account['balance']]) : ''));
    }

    public function sections(): array
    {
        return [
            $this->instances(),
            $this->sshKeys(),
            $this->domains(),
            $this->records(),
            $this->firewalls(),
            $this->firewallRules(),
            $this->snapshots(),
            $this->blocks(),
            $this->reservedIps(),
        ];
    }

    /**
     * Every page of a list: Vultr pages with a cursor in meta.links.next.
     *
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key): array
    {
        $query = ['per_page' => 500];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter((array) ($answer[$key] ?? []), 'is_array')),
            fn (array $answer) => ($next = (string) data_get($answer, 'meta.links.next', '')) !== '' ? [...$query, 'cursor' => $next] : null,
        );
    }

    /** @return array<string, string> */
    private static function choices(ApiClient $api, string $path, string $key, callable $label, string $id = 'id'): array
    {
        $out = [];
        foreach (self::every($api, $path, $key) as $item) {
            $out[(string) ($item[$id] ?? '')] = (string) $label($item);
        }

        return $out;
    }

    private function instances(): Section
    {
        return Section::make('instances', __('Instances'))
            ->icon('server')
            ->list(fn (ApiClient $api) => self::every($api, '/instances', 'instances'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('power_status', __('Power'))->badge(['running' => 'green', 'stopped' => 'zinc', '*' => 'yellow']),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'pending' => 'yellow', 'suspended' => 'red', 'resizing' => 'yellow', '*' => 'zinc']),
                Column::make('main_ip', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['main_ip'] ?? null)),
                Column::make('plan', __('Plan'))->mono(),
                Column::make('region', __('Region')),
                Column::make('os', __('OS')),
                Column::make('date_created', __('Created'))->date(),
            ])
            ->create(__('New instance'), [
                Field::text('label', __('Name'))->required(),
                Field::select('region', __('Region'), fn (ApiClient $api) => self::choices($api, '/regions', 'regions', fn ($r) => ($r['city'] ?? $r['id']).' ('.($r['country'] ?? '').')'))->required(),
                Field::select('plan', __('Plan'), fn (ApiClient $api) => self::choices($api, '/plans', 'plans', fn ($p) => ($p['id'] ?? '').' · '.($p['vcpu_count'] ?? '?').' vCPU · '.round(((int) ($p['ram'] ?? 0)) / 1024, 1).' GB · '.($p['disk'] ?? '?').' GB · $'.($p['monthly_cost'] ?? '?').'/mo'))->required(),
                Field::select('os_id', __('Operating system'), fn (ApiClient $api) => self::choices($api, '/os', 'os', fn ($o) => (string) ($o['name'] ?? $o['id'])))->required()->help(__('Ubuntu 24.04 LTS x64 for Cipi')),
                Field::multiselect('sshkey_id', __('SSH keys'), fn (ApiClient $api) => self::choices($api, '/ssh-keys', 'ssh_keys', fn ($k) => (string) ($k['name'] ?? $k['id']))),
                Field::checkbox('backups', __('Automatic backups (billed)')),
            ], function (ApiClient $api, array $row, array $data) {
                $instance = (array) ($api->post('/instances', array_filter([
                    'label' => $data['label'], 'hostname' => $data['label'], 'region' => $data['region'], 'plan' => $data['plan'],
                    'os_id' => (int) $data['os_id'], 'sshkey_id' => $data['sshkey_id'] ?: null, 'backups' => $data['backups'] ? 'enabled' : 'disabled',
                ], fn ($v) => $v !== null))['instance'] ?? []);

                return isset($instance['default_password']) && $instance['default_password'] !== ''
                    ? Outcome::show(__('Root password of :name', ['name' => $data['label']]), $instance['default_password'], __('Instance created. The password is shown only now.'))
                    : __('Instance created.');
            })
            ->actions([
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => ($row['power_status'] ?? '') === 'stopped')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Starting.'), fn () => $api->post('/instances/'.$row['id'].'/start'))),
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Reboot the instance?'))->when(fn ($row) => ($row['power_status'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rebooting.'), fn () => $api->post('/instances/'.$row['id'].'/reboot'))),
                Action::make('halt', __('Halt'))->icon('pause')->confirm(__('Power off the instance? Vultr still bills it while it is off.'))->when(fn ($row) => ($row['power_status'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Halted.'), fn () => $api->post('/instances/'.$row['id'].'/halt'))),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('description', __('Description'))->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Snapshot started.'), fn () => $api->post('/snapshots', ['instance_id' => $row['id'], 'description' => $data['description']]))),
                Action::make('delete', __('Destroy'))->icon('trash')->danger(__('Destroy the instance and its data? Billing stops.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Instance destroyed.'), fn () => $api->delete('/instances/'.$row['id']))),
            ]);
    }

    private function sshKeys(): Section
    {
        return Section::make('ssh-keys', __('SSH keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::every($api, '/ssh-keys', 'ssh_keys'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('ssh_key', __('Key'))->mono()->using(fn ($row) => mb_strimwidth((string) ($row['ssh_key'] ?? ''), 0, 48, '…')),
                Column::make('date_created', __('Added'))->date(),
            ])
            ->create(__('Add a key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('ssh_key', __('Public key'))->required()->help(__('ssh-ed25519 AAAA… or ssh-rsa AAAA…')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added.'), fn () => $api->post('/ssh-keys', ['name' => $data['name'], 'ssh_key' => trim((string) $data['ssh_key'])])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this key from Vultr? Instances keep the copy they have.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/ssh-keys/'.$row['id']))),
            ]);
    }

    private function domains(): Section
    {
        return Section::make('domains', __('DNS'))
            ->icon('globe-europe-africa')
            ->id('domain')
            ->list(fn (ApiClient $api) => self::every($api, '/domains', 'domains'))
            ->columns([
                Column::make('domain', __('Domain'))->mono(),
                Column::make('dns_sec', __('DNSSEC')),
                Column::make('date_created', __('Created'))->date(),
            ])
            ->create(__('Add a domain'), [
                Field::text('domain', __('Domain'))->required()->placeholder('example.com'),
                Field::suggest('ip', __('Default address'), fn () => ServerTargets::options())->help(__('An A record for the domain and www: an address, or a server of the panel by name. Empty: none.')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Domain added: point it at ns1.vultr.com and ns2.vultr.com.'), fn () => $api->post('/domains', array_filter([
                'domain' => $data['domain'], 'ip' => $data['ip'] !== null ? ServerTargets::resolve('A', $data['ip']) : null,
            ], fn ($v) => $v !== null))))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('records', fn ($row) => ['domain' => (string) $row['domain']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain and all its records?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/domains/'.$row['domain']))),
            ]);
    }

    private function records(): Section
    {
        $fields = [
            Field::select('type', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'], $t))->required()->default(fn ($row) => $row['type'] ?? 'A'),
            Field::text('name', __('Name'))->default(fn ($row) => $row['name'] ?? '')->help(__('Empty for the domain itself')),
            Field::suggest('data', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['data'] ?? null) ?? ($row['data'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA)')),
            Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? 300),
            Field::number('priority', __('Priority (MX, SRV)'))->default(fn ($row) => $row['priority'] ?? null),
        ];
        $body = fn (array $data) => array_filter([...$data, 'data' => ServerTargets::resolve($data['type'] ?? null, $data['data'] ?? null), 'name' => (string) ($data['name'] ?? '')], fn ($v) => $v !== null);

        return Section::make('records', __('DNS records'))
            ->childOf('domains', 'domain')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/domains/'.$scope['domain'].'/records', 'records'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono()->using(fn ($row) => ($row['name'] ?? '') === '' ? '@' : $row['name']),
                Column::make('data', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['data'] ?? null)),
                Column::make('priority', __('Priority')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record'), $fields, fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post('/domains/'.$scope['domain'].'/records', $body($data))))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields)
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->patch('/domains/'.$scope['domain'].'/records/'.$row['id'], $body($data)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $api->delete('/domains/'.$scope['domain'].'/records/'.$row['id']))),
            ]);
    }

    private function firewalls(): Section
    {
        return Section::make('firewalls', __('Firewall groups'))
            ->icon('shield-check')
            ->list(fn (ApiClient $api) => self::every($api, '/firewalls', 'firewall_groups'))
            ->columns([
                Column::make('description', __('Group')),
                Column::make('rule_count', __('Rules')),
                Column::make('instance_count', __('Instances')),
                Column::make('date_modified', __('Modified'))->date(),
            ])
            ->create(__('New group'), [
                Field::text('description', __('Name'))->required(),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Group created.'), fn () => $api->post('/firewalls', ['description' => $data['description']])))
            ->actions([
                Action::make('rules', __('Rules'))->icon('list-bullet')->opens('firewall-rules', fn ($row) => ['group' => (string) $row['id'], 'group_name' => (string) ($row['description'] ?: $row['id'])]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the group? Its instances lose its rules.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Group deleted.'), fn () => $api->delete('/firewalls/'.$row['id']))),
            ]);
    }

    private function firewallRules(): Section
    {
        return Section::make('firewall-rules', __('Firewall rules'))
            ->childOf('firewalls', 'group_name')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/firewalls/'.$scope['group'].'/rules', 'firewall_rules'))
            ->columns([
                Column::make('ip_type', __('IP')),
                Column::make('protocol', __('Protocol')),
                Column::make('port', __('Port'))->mono(),
                Column::make('from', __('From'))->mono()->using(fn ($row) => ($row['subnet'] ?? '').'/'.($row['subnet_size'] ?? '')),
                Column::make('notes', __('Notes')),
            ])
            ->create(__('New rule'), [
                Field::select('ip_type', __('IP'), ['v4' => 'IPv4', 'v6' => 'IPv6'])->required()->default('v4'),
                Field::select('protocol', __('Protocol'), ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'gre' => 'GRE'])->required()->default('tcp'),
                Field::text('port', __('Port'))->default('443')->help(__('443 or 8000:8100; empty for ICMP')),
                Field::text('subnet', __('From'))->required()->default('0.0.0.0')->help(__('An address; 0.0.0.0 and size 0 for anyone')),
                Field::number('subnet_size', __('Prefix size'))->required()->default(0),
                Field::text('notes', __('Notes')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule created.'), fn () => $api->post('/firewalls/'.$scope['group'].'/rules', array_filter($data, fn ($v) => $v !== null))))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this rule?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule deleted.'), fn () => $api->delete('/firewalls/'.$scope['group'].'/rules/'.$row['id']))),
            ]);
    }

    private function snapshots(): Section
    {
        return Section::make('snapshots', __('Snapshots'))
            ->icon('camera')
            ->list(fn (ApiClient $api) => self::every($api, '/snapshots', 'snapshots'))
            ->columns([
                Column::make('description', __('Description')),
                Column::make('status', __('Status'))->badge(['complete' => 'green', 'pending' => 'yellow', '*' => 'zinc']),
                Column::make('size', __('Size'))->using(fn ($row) => isset($row['size']) ? round(((int) $row['size']) / 1073741824, 1).' GB' : ''),
                Column::make('date_created', __('Created'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the snapshot?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Snapshot deleted.'), fn () => $api->delete('/snapshots/'.$row['id']))),
            ]);
    }

    private function blocks(): Section
    {
        return Section::make('blocks', __('Block storage'))
            ->icon('circle-stack')
            ->list(fn (ApiClient $api) => self::every($api, '/blocks', 'blocks'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('size_gb', __('GB')),
                Column::make('region', __('Region')),
                Column::make('status', __('Status'))->badge(['active' => 'green', '*' => 'yellow']),
                Column::make('attached_to_instance', __('Attached to'))->mono(),
            ]);
    }

    private function reservedIps(): Section
    {
        return Section::make('reserved-ips', __('Reserved IPs'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/reserved-ips', 'reserved_ips'))
            ->columns([
                Column::make('subnet', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['subnet'] ?? null)),
                Column::make('ip_type', __('Type')),
                Column::make('region', __('Region')),
                Column::make('label', __('Label')),
                Column::make('instance_id', __('Attached to'))->mono(),
            ]);
    }
}
