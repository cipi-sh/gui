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
 * Akamai Connected Cloud (Linode), with a personal access token: Linodes,
 * domains and records, Cloud Firewalls, volumes, NodeBalancers, Object
 * Storage buckets and SSH keys. https://techdocs.akamai.com/linode-api/reference/api
 */
final class Linode extends Connector
{
    public function key(): string
    {
        return 'linode';
    }

    public function label(): string
    {
        return 'Akamai (Linode)';
    }

    public function short(): string
    {
        return 'Linode';
    }

    public function description(): string
    {
        return __('Linodes, domains and records, Cloud Firewalls, volumes, NodeBalancers, Object Storage buckets and SSH keys.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://techdocs.akamai.com/cloud-computing/docs/manage-personal-access-tokens';
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
            __('cloud.linode.com → the profile menu → API Tokens → Create a Personal Access Token.'),
            __('Access: Read/Write for Linodes, Domains, Firewalls, Volumes, NodeBalancers, Object Storage and IPs; Read Only for Account (the name and balance). Expiry: as you like — renew it here.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.linode.com/v4', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $profile = $api->get('/profile');

        return 'ok · '.($profile['username'] ?? '?').(($profile['email'] ?? '') !== '' ? ' · '.$profile['email'] : '');
    }

    public function sections(): array
    {
        return [
            $this->linodes(),
            $this->domains(),
            $this->records(),
            $this->firewalls(),
            $this->volumes(),
            $this->nodebalancers(),
            $this->buckets(),
            $this->sshKeys(),
        ];
    }

    /**
     * Every page of a list: Linode answers with page and pages.
     *
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path): array
    {
        $query = ['page_size' => 500];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter((array) ($answer['data'] ?? []), 'is_array')),
            fn (array $answer) => (int) ($answer['page'] ?? 1) < (int) ($answer['pages'] ?? 1) ? [...$query, 'page' => (int) $answer['page'] + 1] : null,
        );
    }

    /** @return array<string, string> */
    private static function choices(ApiClient $api, string $path, callable $label): array
    {
        $out = [];
        foreach (self::every($api, $path) as $item) {
            $out[(string) ($item['id'] ?? '')] = (string) $label($item);
        }

        return $out;
    }

    private function linodes(): Section
    {
        return Section::make('linodes', __('Linodes'))
            ->icon('server')
            ->list(fn (ApiClient $api) => self::every($api, '/linode/instances'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('status', __('Status'))->badge(['running' => 'green', 'offline' => 'zinc', 'booting' => 'yellow', 'rebooting' => 'yellow', 'shutting_down' => 'yellow', 'provisioning' => 'yellow', '*' => 'zinc']),
                Column::make('ipv4', __('Address'))->mono()->using(fn ($row) => collect((array) ($row['ipv4'] ?? []))->map(fn ($ip) => ServerTargets::label($ip))->implode(', ')),
                Column::make('type', __('Plan'))->mono(),
                Column::make('region', __('Region')),
                Column::make('image', __('Image')),
                Column::make('created', __('Created'))->date(),
            ])
            ->create(__('New Linode'), [
                Field::text('label', __('Name'))->required()->rules(['regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,62}[a-zA-Z0-9]$/']),
                Field::select('region', __('Region'), fn (ApiClient $api) => self::choices($api, '/regions', fn ($r) => ($r['label'] ?? $r['id']).' ('.$r['id'].')'))->required(),
                Field::select('type', __('Plan'), fn (ApiClient $api) => self::choices($api, '/linode/types', fn ($t) => ($t['label'] ?? $t['id']).' · $'.data_get($t, 'price.monthly', '?').'/mo'))->required()->default('g6-standard-1'),
                Field::select('image', __('Image'), fn (ApiClient $api) => self::choices($api, '/images', fn ($i) => (string) ($i['label'] ?? $i['id'])))->required()->default('linode/ubuntu24.04'),
                Field::password('root_pass', __('Root password'))->required()->rules(['min:12'])->help(__('12 characters or more; Linode asks for one even with keys')),
                Field::textarea('authorized_keys', __('SSH public keys'))->help(__('One per line')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Linode created: it boots in a minute.'), fn () => $api->post('/linode/instances', array_filter([
                'label' => $data['label'], 'region' => $data['region'], 'type' => $data['type'], 'image' => $data['image'],
                'root_pass' => $data['root_pass'], 'authorized_keys' => self::lines($data['authorized_keys'] ?? '') ?: null, 'booted' => true,
            ], fn ($v) => $v !== null))))
            ->actions([
                Action::make('boot', __('Boot'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'offline')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Booting.'), fn () => $api->post('/linode/instances/'.$row['id'].'/boot'))),
                Action::make('reboot', __('Reboot'))->icon('arrow-path')->confirm(__('Reboot the Linode?'))->when(fn ($row) => ($row['status'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Rebooting.'), fn () => $api->post('/linode/instances/'.$row['id'].'/reboot'))),
                Action::make('shutdown', __('Shut down'))->icon('pause')->confirm(__('Shut down the Linode? It is still billed while off.'))->when(fn ($row) => ($row['status'] ?? '') === 'running')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Shutting down.'), fn () => $api->post('/linode/instances/'.$row['id'].'/shutdown'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the Linode and its disks? Billing stops.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Linode deleted.'), fn () => $api->delete('/linode/instances/'.$row['id']))),
            ]);
    }

    private function domains(): Section
    {
        return Section::make('domains', __('Domains'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/domains'))
            ->columns([
                Column::make('domain', __('Domain'))->mono(),
                Column::make('type', __('Type')),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'disabled' => 'zinc', '*' => 'red']),
                Column::make('soa_email', __('SOA email')),
            ])
            ->create(__('Add a domain'), [
                Field::text('domain', __('Domain'))->required()->placeholder('example.com'),
                Field::text('soa_email', __('SOA email'))->required()->rules(['email']),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Domain added: point it at ns1–ns5.linode.com.'), fn () => $api->post('/domains', ['domain' => $data['domain'], 'type' => 'master', 'soa_email' => $data['soa_email']])))
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('records', fn ($row) => ['domain' => (string) $row['id'], 'domain_name' => (string) $row['domain']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain and all its records?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/domains/'.$row['id']))),
            ]);
    }

    private function records(): Section
    {
        $fields = [
            Field::select('type', __('Type'), array_combine($t = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'], $t))->required()->default(fn ($row) => $row['type'] ?? 'A'),
            Field::text('name', __('Name'))->default(fn ($row) => $row['name'] ?? '')->help(__('Empty for the domain itself')),
            Field::suggest('target', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['target'] ?? null) ?? ($row['target'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA)')),
            Field::number('ttl_sec', __('TTL'))->default(fn ($row) => $row['ttl_sec'] ?? 0)->help(__('0: the domain default')),
            Field::number('priority', __('Priority (MX, SRV)'))->default(fn ($row) => $row['priority'] ?? null),
        ];
        $body = fn (array $data) => array_filter([...$data, 'target' => ServerTargets::resolve($data['type'] ?? null, $data['target'] ?? null), 'name' => (string) ($data['name'] ?? '')], fn ($v) => $v !== null);

        return Section::make('records', __('DNS records'))
            ->childOf('domains', 'domain_name')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/domains/'.$scope['domain'].'/records'))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono()->using(fn ($row) => ($row['name'] ?? '') === '' ? '@' : $row['name']),
                Column::make('target', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['target'] ?? null)),
                Column::make('priority', __('Priority')),
                Column::make('ttl_sec', __('TTL'))->using(fn ($row) => ($row['ttl_sec'] ?? 0) ?: __('default')),
            ])
            ->create(__('New record'), $fields, fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post('/domains/'.$scope['domain'].'/records', $body($data))))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields)
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->put('/domains/'.$scope['domain'].'/records/'.$row['id'], $body($data)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $api->delete('/domains/'.$scope['domain'].'/records/'.$row['id']))),
            ]);
    }

    private function firewalls(): Section
    {
        return Section::make('firewalls', __('Cloud Firewalls'))
            ->icon('shield-check')
            ->list(fn (ApiClient $api) => self::every($api, '/networking/firewalls'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('status', __('Status'))->badge(['enabled' => 'green', 'disabled' => 'zinc', '*' => 'zinc']),
                Column::make('inbound', __('Inbound rules'))->using(fn ($row) => count((array) data_get($row, 'rules.inbound', []))),
                Column::make('inbound_policy', __('Inbound default'))->using(fn ($row) => data_get($row, 'rules.inbound_policy')),
                Column::make('entities', __('Applied to'))->using(fn ($row) => collect(self::items($row['entities'] ?? []))->pluck('label')->implode(', ')),
            ])
            ->actions([
                Action::make('disable', __('Disable'))->icon('pause')->confirm(__('Disable the firewall? Its devices take every connection.'))->when(fn ($row) => ($row['status'] ?? '') === 'enabled')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Firewall disabled.'), fn () => $api->put('/networking/firewalls/'.$row['id'], ['status' => 'disabled']))),
                Action::make('enable', __('Enable'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'disabled')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Firewall enabled.'), fn () => $api->put('/networking/firewalls/'.$row['id'], ['status' => 'enabled']))),
            ]);
    }

    private function volumes(): Section
    {
        return Section::make('volumes', __('Volumes'))
            ->icon('circle-stack')
            ->list(fn (ApiClient $api) => self::every($api, '/volumes'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('size', __('GB')),
                Column::make('region', __('Region')),
                Column::make('status', __('Status'))->badge(['active' => 'green', '*' => 'yellow']),
                Column::make('linode_label', __('Attached to')),
            ]);
    }

    private function nodebalancers(): Section
    {
        return Section::make('nodebalancers', __('NodeBalancers'))
            ->icon('arrows-right-left')
            ->list(fn (ApiClient $api) => self::every($api, '/nodebalancers'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('ipv4', __('Address'))->mono(),
                Column::make('hostname', __('Hostname'))->mono(),
                Column::make('region', __('Region')),
                Column::make('transfer.total', __('Transfer (MB)')),
            ]);
    }

    private function buckets(): Section
    {
        return Section::make('buckets', __('Object Storage'))
            ->icon('archive-box')
            ->id('label')
            ->list(fn (ApiClient $api) => self::every($api, '/object-storage/buckets'))
            ->columns([
                Column::make('label', __('Bucket'))->mono(),
                Column::make('region', __('Region')),
                Column::make('hostname', __('Endpoint'))->mono(),
                Column::make('objects', __('Objects')),
                Column::make('size', __('Size'))->using(fn ($row) => isset($row['size']) ? round(((int) $row['size']) / 1048576, 1).' MB' : ''),
            ]);
    }

    private function sshKeys(): Section
    {
        return Section::make('ssh-keys', __('SSH keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => self::every($api, '/profile/sshkeys'))
            ->columns([
                Column::make('label', __('Name')),
                Column::make('ssh_key', __('Key'))->mono()->using(fn ($row) => mb_strimwidth((string) ($row['ssh_key'] ?? ''), 0, 48, '…')),
                Column::make('created', __('Added'))->date(),
            ])
            ->create(__('Add a key'), [
                Field::text('label', __('Name'))->required(),
                Field::textarea('ssh_key', __('Public key'))->required(),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added.'), fn () => $api->post('/profile/sshkeys', ['label' => $data['label'], 'ssh_key' => trim((string) $data['ssh_key'])])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this key?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/profile/sshkeys/'.$row['id']))),
            ]);
    }
}
