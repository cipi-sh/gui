<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * UpCloud, with an API token (or a subaccount with API access): cloud
 * servers (create from a template with an SSH key, start, stop, restart,
 * delete) and their firewall rules, storages and their backups, IP
 * addresses and their reverse DNS. https://developers.upcloud.com/1.3/
 */
final class UpCloud extends Connector
{
    private const STATE = ['started' => 'green', 'stopped' => 'zinc', 'maintenance' => 'yellow', 'error' => 'red', 'online' => 'green', 'backuponly' => 'blue', '*' => 'yellow'];

    public function key(): string
    {
        return 'upcloud';
    }

    public function label(): string
    {
        return 'UpCloud';
    }

    public function description(): string
    {
        return __('Cloud servers (create with an SSH key, power, delete) and their firewall, storages and backups, IP addresses and reverse DNS.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://upcloud.com/docs/guides/managing-api-tokens/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API token'))->placeholder('ucat_…')->help(__('Or a subaccount with API access, below.')),
            Field::text('username', __('Subaccount'))->placeholder('cipi-panel'),
            Field::password('password', __('Subaccount password')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('hub.upcloud.com → Account → API tokens → Create token: it starts with ucat_. Set an expiry and, for safety, the panel server\'s address as the only allowed range.'),
            __('Or People → Create subaccount with "API connections" allowed (and from the panel server\'s address): its username and password go in the two fields below the token.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $token = self::string($credentials, 'token');
        $user = self::string($credentials, 'username');
        $password = self::string($credentials, 'password');

        $auth = match (true) {
            $token !== '' => 'Bearer '.$token,
            $user !== '' && $password !== '' => 'Basic '.base64_encode($user.':'.$password),
            default => throw new ConnectorException(__('Paste an API token, or a subaccount and its password.')),
        };

        return new ApiClient('https://api.upcloud.com/1.3', ['Authorization' => $auth]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $account = (array) ($api->get('/account')['account'] ?? []);

        return 'ok · '.($account['username'] ?? '').(isset($account['credits']) ? ' · '.__('credits :c', ['c' => round((float) $account['credits'] / 100, 2)]) : '');
    }

    public function sections(): array
    {
        return [
            $this->servers(),
            $this->firewall(),
            $this->storages(),
            $this->addresses(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function members(array $answer, string $plural, string $singular): array
    {
        return array_values(array_filter((array) data_get($answer, $plural.'.'.$singular, []), 'is_array'));
    }

    /** @return array<string, string> */
    private static function addressesByServer(ApiClient $api): array
    {
        $out = [];

        foreach (self::members($api->get('/ip_address'), 'ip_addresses', 'ip_address') as $ip) {
            if (($ip['access'] ?? '') === 'public' && ($ip['server'] ?? '') !== '') {
                $out[(string) $ip['server']] = trim(($out[(string) $ip['server']] ?? '').', '.$ip['address'], ', ');
            }
        }

        return $out;
    }

    private function servers(): Section
    {
        return Section::make('servers', __('Servers'))
            ->icon('server')
            ->id('uuid')
            ->list(function (ApiClient $api) {
                $addresses = self::addressesByServer($api);

                return array_map(fn ($s) => [...$s, '_ips' => $addresses[(string) $s['uuid']] ?? ''], self::members($api->get('/server'), 'servers', 'server'));
            })
            ->columns([
                Column::make('title', __('Server')),
                Column::make('state', __('State'))->badge(self::STATE),
                Column::make('_ips', __('Addresses'))->mono()->using(fn ($row) => implode(', ', array_map(fn ($ip) => ServerTargets::label(trim($ip)), array_filter(explode(',', (string) ($row['_ips'] ?? '')))))),
                Column::make('plan', __('Plan'))->mono(),
                Column::make('zone', __('Zone')),
                Column::make('hostname', __('Hostname'))->mono(),
            ])
            ->create(__('New server'), [
                Field::text('title', __('Name'))->required(),
                Field::text('hostname', __('Hostname'))->required()->placeholder('web-1.example.com'),
                Field::select('zone', __('Zone'), fn (ApiClient $api) => collect(self::members($api->get('/zone'), 'zones', 'zone'))->filter(fn ($z) => ($z['public'] ?? 'yes') === 'yes')->mapWithKeys(fn ($z) => [(string) $z['id'] => $z['id'].' · '.($z['description'] ?? '')])->all())->required()->default('de-fra1'),
                Field::select('plan', __('Plan'), fn (ApiClient $api) => collect(self::members($api->get('/plan'), 'plans', 'plan'))->mapWithKeys(fn ($p) => [(string) $p['name'] => $p['name'].' · '.($p['core_number'] ?? '?').' vCPU · '.round(((int) ($p['memory_amount'] ?? 0)) / 1024, 1).' GB · '.($p['storage_size'] ?? '?').' GB'])->all())->required(),
                Field::select('template', __('Operating system'), fn (ApiClient $api) => collect(self::members($api->get('/storage/template'), 'storages', 'storage'))->mapWithKeys(fn ($t) => [(string) $t['uuid'] => (string) $t['title']])->all())->required()->help(__('Ubuntu Server 24.04 LTS for Cipi')),
                Field::number('size', __('Disk (GB)'))->required()->default(50)->rules(['min:10']),
                Field::textarea('ssh_key', __('SSH public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], function (ApiClient $api, array $row, array $data) {
                $server = (array) ($api->post('/server', ['server' => [
                    'zone' => $data['zone'],
                    'title' => $data['title'],
                    'hostname' => $data['hostname'],
                    'plan' => $data['plan'],
                    'metadata' => 'yes',
                    'login_user' => ['username' => 'root', 'create_password' => 'no', 'ssh_keys' => ['ssh_key' => [trim((string) $data['ssh_key'])]]],
                    'storage_devices' => ['storage_device' => [[
                        'action' => 'clone',
                        'storage' => $data['template'],
                        'title' => $data['title'].' disk',
                        'size' => (int) $data['size'],
                        'tier' => 'maxiops',
                    ]]],
                ]])['server'] ?? []);

                return __('Server :title is being created (:uuid).', ['title' => $data['title'], 'uuid' => $server['uuid'] ?? '?']);
            })
            ->actions([
                Action::make('firewall', __('Firewall'))->icon('shield')
                    ->opens('firewall', fn (array $row) => ['server' => (string) $row['uuid'], 'server_title' => (string) $row['title']]),
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => ($row['state'] ?? '') === 'stopped')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Starting.'), fn () => $api->post('/server/'.$row['uuid'].'/start'))),
                Action::make('restart', __('Restart'))->icon('arrow-path')->when(fn ($row) => ($row['state'] ?? '') === 'started')->confirm(__('Restart the server?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Restarting.'), fn () => $api->post('/server/'.$row['uuid'].'/restart', ['restart_server' => ['stop_type' => 'soft', 'timeout' => 60, 'timeout_action' => 'destroy']]))),
                Action::make('stop', __('Stop'))->icon('stop')->when(fn ($row) => ($row['state'] ?? '') === 'started')->confirm(__('Stop the server?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Stopping.'), fn () => $api->post('/server/'.$row['uuid'].'/stop', ['stop_server' => ['stop_type' => 'soft', 'timeout' => 60]]))),
                Action::make('details', __('Details'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show((string) $row['title'], (array) ($api->get('/server/'.$row['uuid'])['server'] ?? []))),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ($row['state'] ?? '') === 'stopped')
                    ->danger(__('Delete the server with its disks and their backups? This cannot be undone.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Server deleted.'), fn () => $api->delete('/server/'.$row['uuid'].'?storages=1&backups=delete'))),
            ]);
    }

    private function firewall(): Section
    {
        return Section::make('firewall', __('Firewall rules'))
            ->childOf('servers', 'server_title')
            ->id('position')
            ->list(fn (ApiClient $api, array $scope) => self::members($api->get('/server/'.$scope['server'].'/firewall_rule'), 'firewall_rules', 'firewall_rule'))
            ->columns([
                Column::make('position', __('#')),
                Column::make('direction', __('Direction')),
                Column::make('action', __('Action'))->badge(['accept' => 'green', 'drop' => 'red', 'reject' => 'red']),
                Column::make('protocol', __('Protocol')),
                Column::make('ports', __('Ports'))->using(fn ($row) => ($row['destination_port_start'] ?? '') === '' ? '' : $row['destination_port_start'].(($row['destination_port_end'] ?? '') !== ($row['destination_port_start'] ?? '') ? '-'.$row['destination_port_end'] : '')),
                Column::make('source', __('From'))->mono()->using(fn ($row) => ($row['source_address_start'] ?? '') === '' ? __('anywhere') : $row['source_address_start'].(($row['source_address_end'] ?? '') !== ($row['source_address_start'] ?? '') ? ' – '.$row['source_address_end'] : '')),
            ])
            ->create(__('Allow ports'), [
                Field::text('ports', __('Ports'))->required()->placeholder('22, 80, 443')->help(__('TCP unless /udp follows; icmp for ping.')),
                Field::text('from', __('From'))->placeholder(__('anywhere'))->help(__('One address, or empty for anywhere (IPv4).')),
            ], function (ApiClient $api, array $row, array $data, array $scope) {
                $from = trim((string) ($data['from'] ?? ''));

                foreach (self::ports($data['ports'] ?? '') as $rule) {
                    [$start, $end] = array_pad(explode('-', (string) $rule['port']), 2, null);
                    $api->post('/server/'.$scope['server'].'/firewall_rule', ['firewall_rule' => array_filter([
                        'direction' => 'in',
                        'action' => 'accept',
                        'family' => 'IPv4',
                        'protocol' => $rule['protocol'],
                        'destination_port_start' => $rule['protocol'] === 'icmp' || $rule['port'] === 'all' ? null : $start,
                        'destination_port_end' => $rule['protocol'] === 'icmp' || $rule['port'] === 'all' ? null : ($end ?? $start),
                        'source_address_start' => $from !== '' ? $from : null,
                        'source_address_end' => $from !== '' ? $from : null,
                    ], fn ($v) => $v !== null)]);
                }

                return __('Rules added. UpCloud filters only once the server\'s firewall is on (Hub → server → Firewall), with a last rule that drops the rest.');
            })
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this rule?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule deleted.'), fn () => $api->delete('/server/'.$scope['server'].'/firewall_rule/'.$row['position']))),
            ]);
    }

    private function storages(): Section
    {
        return Section::make('storages', __('Storages'))
            ->icon('circle-stack')
            ->id('uuid')
            ->list(fn (ApiClient $api) => self::members($api->get('/storage/private'), 'storages', 'storage'))
            ->columns([
                Column::make('title', __('Storage')),
                Column::make('type', __('Type'))->badge(['normal' => 'blue', 'backup' => 'zinc', 'template' => 'zinc']),
                Column::make('size', __('Size'))->using(fn ($row) => isset($row['size']) ? $row['size'].' GB' : ''),
                Column::make('state', __('State'))->badge(self::STATE),
                Column::make('tier', __('Tier')),
                Column::make('zone', __('Zone')),
            ])
            ->actions([
                Action::make('backup', __('Back up now'))->icon('archive-box')->when(fn ($row) => ($row['type'] ?? '') === 'normal')
                    ->fields([Field::text('title', __('Name'))->required()->default(fn ($row) => ($row['title'] ?? 'disk').' '.gmdate('Y-m-d'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Backup started.'), fn () => $api->post('/storage/'.$row['uuid'].'/backup', ['storage' => ['title' => $data['title']]]))),
                Action::make('delete', __('Delete'))->icon('trash')->when(fn ($row) => ($row['type'] ?? '') === 'backup')->danger(__('Delete this backup?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Backup deleted.'), fn () => $api->delete('/storage/'.$row['uuid']))),
            ]);
    }

    private function addresses(): Section
    {
        return Section::make('addresses', __('IP addresses'))
            ->icon('globe')
            ->id('address')
            ->list(fn (ApiClient $api) => self::members($api->get('/ip_address'), 'ip_addresses', 'ip_address'))
            ->columns([
                Column::make('address', __('Address'))->mono()->using(fn ($row) => ServerTargets::label($row['address'] ?? null)),
                Column::make('access', __('Access'))->badge(['public' => 'blue', 'private' => 'zinc', 'utility' => 'zinc']),
                Column::make('family', __('Family')),
                Column::make('ptr_record', __('Reverse DNS'))->mono(),
                Column::make('floating', __('Floating'))->bool()->using(fn ($row) => ($row['floating'] ?? 'no') === 'yes'),
                Column::make('zone', __('Zone')),
            ])
            ->actions([
                Action::make('ptr', __('Reverse DNS'))->icon('pencil')->when(fn ($row) => ($row['access'] ?? '') === 'public')
                    ->fields([Field::text('ptr_record', __('Name'))->required()->default(fn ($row) => $row['ptr_record'] ?? '')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Reverse DNS saved.'), fn () => $api->patch('/ip_address/'.$row['address'], ['ip_address' => ['ptr_record' => $data['ptr_record']]]))),
            ]);
    }
}
