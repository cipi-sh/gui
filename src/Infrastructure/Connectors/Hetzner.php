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
 * Hetzner Cloud, with an API token per project (an account is a project):
 * servers and their power, rescue, snapshots and types, SSH keys, firewalls,
 * volumes, floating and primary IPs, networks, load balancers, images,
 * certificates and DNS zones. https://docs.hetzner.cloud/reference/cloud
 */
final class Hetzner extends Connector
{
    private const STATUS = ['running' => 'green', 'off' => 'zinc', 'initializing' => 'blue', 'starting' => 'blue', 'stopping' => 'yellow', 'available' => 'green', 'ok' => 'green', '*' => 'yellow'];

    private const ANYWHERE = ['0.0.0.0/0', '::/0'];

    public function key(): string
    {
        return 'hetzner';
    }

    public function label(): string
    {
        return 'Hetzner';
    }

    public function description(): string
    {
        return __('Hetzner Cloud servers, firewalls, volumes, IPs, networks, load balancers, snapshots and DNS, one project per account.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://docs.hetzner.com/cloud/api/getting-started/generating-api-token/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API token'))->required()
                ->help(__('Hetzner Console → the project → Security → API tokens, with Read & Write. A token belongs to one project: add one account per project.')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('console.hetzner.com → the project → Security → API tokens → Generate API token.'),
            __('Permission: Read & Write (Read alone shows the lists; every action then answers 403).'),
            __('A token reaches one project — its servers, firewalls, volumes, networks, load balancers and DNS zones: add an account per project.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.hetzner.cloud/v1', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $servers = $api->get('/servers', ['per_page' => 1]);

        return 'ok · '.trans_choice(':count server|:count servers', (int) data_get($servers, 'meta.pagination.total_entries', 0));
    }

    public function sections(): array
    {
        return [
            $this->servers(),
            $this->sshKeys(),
            $this->firewalls(),
            $this->volumes(),
            $this->floatingIps(),
            $this->primaryIps(),
            $this->networks(),
            $this->loadBalancers(),
            $this->images(),
            $this->certificates(),
            $this->zones(),
            $this->rrsets(),
        ];
    }

    /**
     * Every page of a list: Hetzner answers with meta.pagination.next_page.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key, array $query = []): array
    {
        $query = ['per_page' => 50, ...$query];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values((array) ($answer[$key] ?? [])),
            fn (array $answer) => is_int($next = data_get($answer, 'meta.pagination.next_page')) ? [...$query, 'page' => $next] : null,
        );
    }

    /** @return array<string, string> */
    private static function serverOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/servers', 'servers'))->mapWithKeys(fn ($s) => [(string) $s['id'] => $s['name'].' · '.data_get($s, 'datacenter.location.name')])->all();
    }

    /** @return array<string, string> */
    private static function locationOptions(ApiClient $api): array
    {
        return collect(self::every($api, '/locations', 'locations'))->mapWithKeys(fn ($l) => [(string) $l['name'] => $l['name'].' · '.($l['city'] ?? '')])->all();
    }

    private function servers(): Section
    {
        $types = fn (ApiClient $api) => collect(self::every($api, '/server_types', 'server_types'))
            ->reject(fn ($t) => ! empty($t['deprecated']) || ! empty($t['deprecation']))
            ->mapWithKeys(fn ($t) => [(string) $t['name'] => sprintf('%s · %d vCPU · %s GB · %d GB disk%s', strtoupper((string) $t['name']), $t['cores'] ?? 0, $t['memory'] ?? 0, $t['disk'] ?? 0,
                ($price = data_get($t, 'prices.0.price_monthly.gross')) ? ' · €'.number_format((float) $price, 2).'/mo' : '')])->all();
        $images = fn (ApiClient $api) => collect(self::every($api, '/images', 'images', ['type' => 'system']))
            ->mapWithKeys(fn ($i) => [(string) $i['name'] => ($i['description'] ?? $i['name']).' · '.($i['architecture'] ?? '')])->all();

        return Section::make('servers', __('Servers'))
            ->icon('server')
            ->list(fn (ApiClient $api) => self::every($api, '/servers', 'servers'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('public_net.ipv4.ip', __('IPv4'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'public_net.ipv4.ip'))),
                Column::make('server_type.name', __('Type'))->mono(),
                Column::make('datacenter.location.name', __('Location')),
                Column::make('image.description', __('Image')),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New server'), [
                Field::text('name', __('Name'))->required()->placeholder('web-1')->rules(['regex:/^[a-zA-Z0-9.-]+$/']),
                Field::select('server_type', __('Type'), $types)->required(),
                Field::select('location', __('Location'), fn (ApiClient $api) => self::locationOptions($api))->required(),
                Field::select('image', __('Image'), $images)->required()->default('ubuntu-24.04'),
                Field::multiselect('ssh_keys', __('SSH keys'), fn (ApiClient $api) => collect(self::every($api, '/ssh_keys', 'ssh_keys'))->mapWithKeys(fn ($k) => [(string) $k['id'] => (string) $k['name']])->all())
                    ->help(__('Without a key, Hetzner sets a root password and the panel shows it once.')),
                Field::multiselect('firewalls', __('Firewalls'), fn (ApiClient $api) => collect(self::every($api, '/firewalls', 'firewalls'))->mapWithKeys(fn ($f) => [(string) $f['id'] => (string) $f['name']])->all()),
                Field::checkbox('enable_ipv4', __('Public IPv4'))->default(true),
                Field::checkbox('enable_ipv6', __('Public IPv6'))->default(true),
                Field::checkbox('backups', __('Backups (+20%)')),
                Field::textarea('user_data', __('User data (cloud-init)')),
            ], function (ApiClient $api, array $row, array $data) {
                $answer = $api->post('/servers', array_filter([
                    'name' => $data['name'],
                    'server_type' => $data['server_type'],
                    'location' => $data['location'],
                    'image' => $data['image'],
                    'ssh_keys' => array_map('intval', $data['ssh_keys']),
                    'firewalls' => array_map(fn ($id) => ['firewall' => (int) $id], $data['firewalls']),
                    'public_net' => ['enable_ipv4' => $data['enable_ipv4'], 'enable_ipv6' => $data['enable_ipv6']],
                    'user_data' => $data['user_data'],
                    'start_after_create' => true,
                ], fn ($value) => $value !== null && $value !== []));

                if ($data['backups'] && isset($answer['server']['id'])) {
                    $api->post('/servers/'.$answer['server']['id'].'/actions/enable_backup');
                }

                return isset($answer['root_password'])
                    ? Outcome::show(__('Root password of :name', ['name' => $data['name']]), ['ipv4' => data_get($answer, 'server.public_net.ipv4.ip'), 'root_password' => $answer['root_password']], __('Server created.'))
                    : __('Server :name is being created.', ['name' => $data['name']]);
            })
            ->actions([
                $this->action('poweron', __('Power on'), 'play')->when(fn ($row) => ($row['status'] ?? '') === 'off'),
                $this->action('shutdown', __('Shut down'), 'stop')->confirm(__('Shut the server down gracefully (ACPI)?'))->when(fn ($row) => ($row['status'] ?? '') === 'running'),
                $this->action('reboot', __('Reboot'), 'arrow-path')->confirm(__('Reboot the server?')),
                $this->action('reset', __('Reset'), 'bolt')->confirm(__('Hard reset, like pressing the reset button? Unsaved data may be lost.')),
                $this->action('poweroff', __('Power off'), 'power')->confirm(__('Cut the power? Unsaved data may be lost.'))->when(fn ($row) => ($row['status'] ?? '') === 'running'),
                $this->action('create_image', __('Take a snapshot'), 'camera')->fields([Field::text('description', __('Description'))->required()->default(fn ($row) => ($row['name'] ?? 'server').'-'.now()->format('Ymd-His'))]),
                $this->action('change_type', __('Change type'), 'arrows-pointing-out')->fields([
                    Field::select('server_type', __('New type'), $types)->required()->default(fn ($row) => data_get($row, 'server_type.name')),
                    Field::checkbox('upgrade_disk', __('Grow the disk too (no way back to a smaller type)')),
                ])->confirm(__('The server must be off to change its type. Go ahead?')),
                Action::make('rename', __('Rename'))->icon('pencil')->fields([Field::text('name', __('Name'))->required()->default(fn ($row) => $row['name'] ?? '')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Server renamed.'), fn () => $api->put('/servers/'.$row['id'], ['name' => $data['name']]))),
                $this->action('enable_rescue', __('Rescue mode'), 'lifebuoy')->confirm(__('Boot into the rescue system on the next reboot? the panel shows its root password once.')),
                $this->action('disable_rescue', __('Leave rescue mode'), 'lifebuoy')->when(fn ($row) => ! empty($row['rescue_enabled'])),
                $this->action('reset_password', __('Reset the root password'), 'key')->confirm(__('Needs the qemu guest agent running on the server. Reset the root password?')),
                $this->action('rebuild', __('Rebuild from an image'), 'arrow-uturn-left')->fields([Field::select('image', __('Image'), $images)->required()])
                    ->danger(__('Rebuild erases the server\'s disk. Go ahead?')),
                $this->action('enable_backup', __('Enable backups'), 'archive-box')->when(fn ($row) => empty($row['backup_window'])),
                $this->action('disable_backup', __('Disable backups'), 'archive-box-x-mark')->confirm(__('Disable backups? Existing backups are deleted.'))->when(fn ($row) => ! empty($row['backup_window'])),
                $this->action('change_protection', __('Protection'), 'lock-closed')->fields([
                    Field::checkbox('delete', __('Protect from deletion'))->default(fn ($row) => (bool) data_get($row, 'protection.delete')),
                    Field::checkbox('rebuild', __('Protect from rebuild'))->default(fn ($row) => (bool) data_get($row, 'protection.rebuild')),
                ]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the server and its disk? This cannot be undone.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Server deleted.'), fn () => $api->delete('/servers/'.$row['id']))),
            ]);
    }

    /** A server action: POST /servers/{id}/actions/{action} with the form's fields; a new root password is shown once. */
    private function action(string $action, string $label, string $icon): Action
    {
        return Action::make($action, $label)->icon($icon)->run(function (ApiClient $api, array $row, array $data) use ($action, $label) {
            $answer = $api->post('/servers/'.$row['id'].'/actions/'.$action, $action === 'enable_rescue' ? ['type' => 'linux64'] : $data);

            return isset($answer['root_password'])
                ? Outcome::show(__('Root password of :name', ['name' => $row['name'] ?? '']), ['root_password' => $answer['root_password']], __(':action: sent.', ['action' => $label]))
                : __(':action: sent to :name.', ['action' => $label, 'name' => $row['name'] ?? $row['id']]);
        });
    }

    private function sshKeys(): Section
    {
        return Section::make('ssh-keys', __('SSH keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::every($api, '/ssh_keys', 'ssh_keys'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('fingerprint', __('Fingerprint'))->mono(),
                Column::make('created', __('Added'))->date(),
            ])
            ->create(__('Add a key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('public_key', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added.'), fn () => $api->post('/ssh_keys', $data)))
            ->actions([
                Action::make('show', __('Public key'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $row['public_key'] ?? '')),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Remove the key from the project? Existing servers keep it.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key removed.'), fn () => $api->delete('/ssh_keys/'.$row['id']))),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function inboundRules(mixed $ports): array
    {
        return array_map(fn ($rule) => array_filter([
            'direction' => 'in',
            'protocol' => $rule['protocol'],
            'port' => $rule['port'] === 'all' ? '1-65535' : $rule['port'],
            'source_ips' => self::ANYWHERE,
        ], fn ($value) => $value !== null), self::ports($ports));
    }

    private function firewalls(): Section
    {
        $servers = fn (ApiClient $api) => self::serverOptions($api);
        $applied = fn ($row) => collect(self::items($row['applied_to'] ?? []))->where('type', 'server')->pluck('server.id')->map(fn ($id) => (string) $id)->values()->all();

        return Section::make('firewalls', __('Firewalls'))
            ->icon('shield-check')
            ->list(fn (ApiClient $api) => self::every($api, '/firewalls', 'firewalls'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('inbound', __('Inbound'))->mono()->using(fn ($row) => collect(self::items($row['rules'] ?? []))->where('direction', 'in')->map(fn ($r) => ($r['port'] ?? '').'/'.($r['protocol'] ?? ''))->implode(', ')),
                Column::make('servers', __('Servers'))->using(fn ($row) => count($applied($row))),
            ])
            ->create(__('New firewall'), [
                Field::text('name', __('Name'))->required(),
                Field::text('ports', __('Open inbound ports'))->required()->default('22, 80, 443')->help(__('From anywhere: 22, 80, 443, 8000-8100, 53/udp, icmp. Outbound stays open.')),
                Field::multiselect('servers', __('Apply to'), $servers),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Firewall created.'), fn () => $api->post('/firewalls', [
                'name' => $data['name'],
                'rules' => self::inboundRules($data['ports']),
                'apply_to' => array_map(fn ($id) => ['type' => 'server', 'server' => ['id' => (int) $id]], $data['servers']),
            ])))
            ->actions([
                Action::make('rules', __('Inbound ports'))->icon('adjustments-horizontal')
                    ->fields([Field::text('ports', __('Open inbound ports'))->required()->default(fn ($row) => collect(self::items($row['rules'] ?? []))->where('direction', 'in')->map(fn ($r) => $r['protocol'] === 'icmp' ? 'icmp' : ($r['port'] ?? '').($r['protocol'] === 'udp' ? '/udp' : ''))->implode(', '))
                        ->help(__('Replaces every rule, from anywhere.'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Rules saved.'), fn () => $api->post('/firewalls/'.$row['id'].'/actions/set_rules', ['rules' => self::inboundRules($data['ports'])]))),
                Action::make('apply', __('Apply to servers'))->icon('link')->fields([Field::multiselect('servers', __('Servers'), $servers)->default($applied)])
                    ->run(function (ApiClient $api, array $row, array $data) use ($applied) {
                        $current = $applied($row);
                        $to = fn (array $ids) => ['apply_to' => array_map(fn ($id) => ['type' => 'server', 'server' => ['id' => (int) $id]], array_values($ids))];

                        if ($add = array_diff($data['servers'], $current)) {
                            $api->post('/firewalls/'.$row['id'].'/actions/apply_to_resources', $to($add));
                        }

                        if ($remove = array_diff($current, $data['servers'])) {
                            $api->post('/firewalls/'.$row['id'].'/actions/remove_from_resources', ['remove_from' => $to($remove)['apply_to']]);
                        }

                        return __('Firewall applied.');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the firewall? It must not be applied to any server.'))
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
                Column::make('size', __('Size (GB)')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('location.name', __('Location')),
                Column::make('server', __('Server id'))->mono(),
                Column::make('linux_device', __('Device'))->mono(),
            ])
            ->create(__('New volume'), [
                Field::text('name', __('Name'))->required(),
                Field::number('size', __('Size (GB)'))->required()->default(10),
                Field::select('location', __('Location'), fn (ApiClient $api) => self::locationOptions($api))->help(__('Or attach it to a server right away')),
                Field::select('server', __('Attach to'), fn (ApiClient $api) => self::serverOptions($api)),
                Field::select('format', __('Filesystem'), ['ext4' => 'ext4', 'xfs' => 'xfs'])->default('ext4'),
                Field::checkbox('automount', __('Mount it on the server'))->default(true),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Volume created.'), fn () => $api->post('/volumes', array_filter([
                ...$data,
                'server' => $data['server'] ? (int) $data['server'] : null,
                'location' => $data['server'] ? null : $data['location'],
                'automount' => $data['server'] ? $data['automount'] : null,
            ], fn ($value) => $value !== null))))
            ->actions([
                Action::make('attach', __('Attach'))->icon('link')->fields([
                    Field::select('server', __('Server'), fn (ApiClient $api) => self::serverOptions($api))->required(),
                    Field::checkbox('automount', __('Mount it'))->default(true),
                ])->when(fn ($row) => empty($row['server']))
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Attaching.'), fn () => $api->post('/volumes/'.$row['id'].'/actions/attach', ['server' => (int) $data['server'], 'automount' => $data['automount']]))),
                Action::make('detach', __('Detach'))->icon('link-slash')->confirm(__('Unmount it on the server first. Detach?'))->when(fn ($row) => ! empty($row['server']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Detaching.'), fn () => $api->post('/volumes/'.$row['id'].'/actions/detach'))),
                Action::make('resize', __('Resize'))->icon('arrows-pointing-out')->fields([Field::number('size', __('New size (GB)'))->required()->default(fn ($row) => $row['size'] ?? null)])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Resizing: grow the filesystem on the server afterwards.'), fn () => $api->post('/volumes/'.$row['id'].'/actions/resize', ['size' => $data['size']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the volume and its data? Detach it first.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Volume deleted.'), fn () => $api->delete('/volumes/'.$row['id']))),
            ]);
    }

    private function floatingIps(): Section
    {
        return Section::make('floating-ips', __('Floating IPs'))
            ->icon('map-pin')
            ->list(fn (ApiClient $api) => self::every($api, '/floating_ips', 'floating_ips'))
            ->columns([
                Column::make('ip', __('IP'))->mono()->using(fn ($row) => ServerTargets::label($row['ip'] ?? null)),
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name')),
                Column::make('home_location.name', __('Home')),
                Column::make('server', __('Server id'))->mono(),
            ])
            ->create(__('New floating IP'), [
                Field::select('type', __('Type'), ['ipv4' => 'IPv4', 'ipv6' => 'IPv6'])->required()->default('ipv4'),
                Field::select('home_location', __('Location'), fn (ApiClient $api) => self::locationOptions($api))->required(),
                Field::text('description', __('Description')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Floating IP created.'), fn () => $api->post('/floating_ips', array_filter($data))))
            ->actions([
                Action::make('assign', __('Assign'))->icon('link')->fields([Field::select('server', __('Server'), fn (ApiClient $api) => self::serverOptions($api))->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Assigning: configure the IP on the server too.'), fn () => $api->post('/floating_ips/'.$row['id'].'/actions/assign', ['server' => (int) $data['server']]))),
                Action::make('unassign', __('Unassign'))->icon('link-slash')->confirm(__('Unassign the IP?'))->when(fn ($row) => ! empty($row['server']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Unassigning.'), fn () => $api->post('/floating_ips/'.$row['id'].'/actions/unassign'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Release the IP? It may never come back.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('IP released.'), fn () => $api->delete('/floating_ips/'.$row['id']))),
            ]);
    }

    private function primaryIps(): Section
    {
        return Section::make('primary-ips', __('Primary IPs'))
            ->icon('map-pin')
            ->list(fn (ApiClient $api) => self::every($api, '/primary_ips', 'primary_ips'))
            ->columns([
                Column::make('ip', __('IP'))->mono()->using(fn ($row) => ServerTargets::label($row['ip'] ?? null)),
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name')),
                Column::make('datacenter.name', __('Datacenter')),
                Column::make('assignee_id', __('Server id'))->mono(),
                Column::make('auto_delete', __('Deleted with the server'))->bool(),
            ])
            ->actions([
                Action::make('unassign', __('Unassign'))->icon('link-slash')->confirm(__('The server must be off. Unassign?'))->when(fn ($row) => ! empty($row['assignee_id']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Unassigning.'), fn () => $api->post('/primary_ips/'.$row['id'].'/actions/unassign'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Release the IP? It must be unassigned.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('IP released.'), fn () => $api->delete('/primary_ips/'.$row['id']))),
            ]);
    }

    private function networks(): Section
    {
        return Section::make('networks', __('Networks'))
            ->icon('share')
            ->list(fn (ApiClient $api) => self::every($api, '/networks', 'networks'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('ip_range', __('Range'))->mono(),
                Column::make('subnets', __('Subnets'))->mono()->using(fn ($row) => collect(self::items($row['subnets'] ?? []))->pluck('ip_range')->implode(', ')),
                Column::make('servers', __('Servers'))->using(fn ($row) => count($row['servers'] ?? [])),
            ])
            ->create(__('New network'), [
                Field::text('name', __('Name'))->required(),
                Field::text('ip_range', __('Range'))->required()->default('10.0.0.0/16'),
                Field::text('subnet', __('Subnet'))->required()->default('10.0.1.0/24'),
                Field::select('network_zone', __('Zone'), ['eu-central' => 'eu-central', 'us-east' => 'us-east', 'us-west' => 'us-west', 'ap-southeast' => 'ap-southeast'])->default('eu-central'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Network created.'), fn () => $api->post('/networks', [
                'name' => $data['name'],
                'ip_range' => $data['ip_range'],
                'subnets' => [['type' => 'cloud', 'network_zone' => $data['network_zone'], 'ip_range' => $data['subnet']]],
            ])))
            ->actions([
                Action::make('attach', __('Attach a server'))->icon('link')->fields([Field::select('server', __('Server'), fn (ApiClient $api) => self::serverOptions($api))->required()])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Attaching.'), fn () => $api->post('/servers/'.$data['server'].'/actions/attach_to_network', ['network' => $row['id']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the network?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Network deleted.'), fn () => $api->delete('/networks/'.$row['id']))),
            ]);
    }

    private function loadBalancers(): Section
    {
        return Section::make('load-balancers', __('Load balancers'))
            ->icon('arrows-right-left')
            ->list(fn (ApiClient $api) => self::every($api, '/load_balancers', 'load_balancers'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('public_net.ipv4.ip', __('IPv4'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'public_net.ipv4.ip'))),
                Column::make('load_balancer_type.name', __('Type'))->mono(),
                Column::make('location.name', __('Location')),
                Column::make('targets', __('Targets'))->using(fn ($row) => count($row['targets'] ?? [])),
            ])
            ->actions([
                Action::make('show', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], ['services' => $row['services'] ?? [], 'targets' => $row['targets'] ?? []])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the load balancer?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Load balancer deleted.'), fn () => $api->delete('/load_balancers/'.$row['id']))),
            ]);
    }

    private function images(): Section
    {
        return Section::make('images', __('Snapshots & backups'))
            ->icon('camera')
            ->list(function (ApiClient $api) {
                // type takes one value; snapshot,backup is refused with 422 invalid type value.
                $images = [
                    ...self::every($api, '/images', 'images', ['type' => 'snapshot']),
                    ...self::every($api, '/images', 'images', ['type' => 'backup']),
                ];

                return collect($images)->sortByDesc('created')->values()->all();
            })
            ->columns([
                Column::make('description', __('Description')),
                Column::make('type', __('Type'))->badge(['snapshot' => 'blue', 'backup' => 'purple']),
                Column::make('created_from.name', __('Of')),
                Column::make('image_size', __('Size (GB)'))->using(fn ($row) => isset($row['image_size']) ? round((float) $row['image_size'], 1) : ''),
                Column::make('created', __('Created'))->date(),
            ])
            ->actions([
                Action::make('rename', __('Describe'))->icon('pencil')->fields([Field::text('description', __('Description'))->required()->default(fn ($row) => $row['description'] ?? '')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Saved.'), fn () => $api->put('/images/'.$row['id'], ['description' => $data['description']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the image?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Image deleted.'), fn () => $api->delete('/images/'.$row['id']))),
            ]);
    }

    private function certificates(): Section
    {
        return Section::make('certificates', __('Certificates'))
            ->icon('lock-closed')
            ->list(fn (ApiClient $api) => self::every($api, '/certificates', 'certificates'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('domain_names', __('Domains'))->mono(),
                Column::make('type', __('Type'))->badge(),
                Column::make('not_valid_after', __('Expires'))->date(),
            ])
            ->create(__('New managed certificate'), [
                Field::text('name', __('Name'))->required(),
                Field::text('domain_names', __('Domains'))->required()->placeholder('example.com, www.example.com')->help(__('Their DNS must be at Hetzner.')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Certificate requested.'), fn () => $api->post('/certificates', ['name' => $data['name'], 'type' => 'managed', 'domain_names' => self::lines($data['domain_names'])])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the certificate?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Certificate deleted.'), fn () => $api->delete('/certificates/'.$row['id']))),
            ]);
    }

    private function zones(): Section
    {
        return Section::make('zones', __('DNS zones'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/zones', 'zones'))
            ->columns([
                Column::make('name', __('Zone')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('mode', __('Mode'))->badge(),
                Column::make('record_count', __('Records')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New zone'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::number('ttl', __('Default TTL'))->default(3600),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Zone created: point the domain to Hetzner\'s nameservers.'), fn () => $api->post('/zones', ['name' => $data['name'], 'mode' => 'primary', 'ttl' => $data['ttl'] ?? 3600])))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('rrsets', fn ($row) => ['zone' => (string) $row['id'], 'zone_name' => $row['name']]),
                Action::make('nameservers', __('Nameservers'))->icon('server-stack')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], data_get($row, 'authoritative_nameservers', []))),
                Action::make('export', __('Zone file'))->icon('document-text')->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], $api->get('/zones/'.$row['id'].'/zonefile')['zonefile'] ?? '')),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the zone and its records?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone deleted.'), fn () => $api->delete('/zones/'.$row['id']))),
            ]);
    }

    private function rrsets(): Section
    {
        $records = fn (mixed $values, ?string $type = null) => array_map(fn ($value) => ['value' => $value], ServerTargets::resolveAll($type, self::lines($values)));

        return Section::make('rrsets', __('DNS records'))
            ->childOf('zones', 'zone_name')
            ->id(fn ($row) => ($row['name'] ?? '').'/'.($row['type'] ?? ''))
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/zones/'.$scope['zone'].'/rrsets', 'rrsets'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('records', __('Values'))->mono()->using(fn ($row) => collect(self::items($row['records'] ?? []))->pluck('value')->map(fn ($v) => ServerTargets::label($v))->implode(', ')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record set'), [
                Field::select('type', __('Type'), array_combine($types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'DS', 'TLSA'], $types))->required()->default('A'),
                Field::text('name', __('Name'))->required()->default('@')->help(__('@ for the zone itself')),
                Field::textarea('values', __('Values'))->required()->help(__('One per line — an address, or a server of the panel by its name (A, AAAA). TXT values in quotes; MX as "10 mail.example.com."')),
                Field::number('ttl', __('TTL'))->help(__('Empty: the zone default')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Records created.'), fn () => $api->post('/zones/'.$scope['zone'].'/rrsets', array_filter([
                'name' => $data['name'], 'type' => $data['type'], 'ttl' => $data['ttl'], 'records' => $records($data['values'], $data['type']),
            ], fn ($value) => $value !== null))))
            ->actions([
                Action::make('edit', __('Edit values'))->icon('pencil')->fields([
                    Field::textarea('values', __('Values'))->required()->default(fn ($row) => collect(self::items($row['records'] ?? []))->pluck('value')->implode("\n"))->help(__('One per line')),
                ])->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Records saved.'), fn () => $api->post('/zones/'.$scope['zone'].'/rrsets/'.$row['name'].'/'.$row['type'].'/actions/set_records', ['records' => $records($data['values'], $row['type'] ?? null)]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete these records?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Records deleted.'), fn () => $api->delete('/zones/'.$scope['zone'].'/rrsets/'.$row['name'].'/'.$row['type']))),
            ]);
    }
}
