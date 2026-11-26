<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ContaboClient;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;
use CipiGui\Infrastructure\Tokens;

/**
 * Contabo, with the API credentials of the Customer Control Panel: VPS and
 * VDS instances (start, stop, restart, shut down, rename), their snapshots
 * (take, roll back, delete), object storages, and the SSH keys and
 * passwords kept as secrets. Ordering a server stays in the Control Panel:
 * it is a purchase. https://api.contabo.com
 */
final class Contabo extends Connector
{
    private const STATUS = ['running' => 'green', 'stopped' => 'zinc', 'provisioning' => 'blue', 'installing' => 'blue', 'rescue' => 'yellow', 'error' => 'red', 'uninstalled' => 'zinc', '*' => 'yellow'];

    public function key(): string
    {
        return 'contabo';
    }

    public function label(): string
    {
        return 'Contabo';
    }

    public function description(): string
    {
        return __('VPS and VDS instances (power, rename), their snapshots, object storages and SSH keys. New servers are ordered in the Control Panel.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function docs(): string
    {
        return 'https://contabo.com/blog/kb/103000270527-how-can-i-access-the-contabo-api';
    }

    public function fields(): array
    {
        return [
            Field::text('client_id', __('Client ID'))->required(),
            Field::password('client_secret', __('Client secret'))->required(),
            Field::text('api_user', __('API user'))->required()->placeholder('you@example.com'),
            Field::password('api_password', __('API password'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('my.contabo.com → API: the Client ID, the Client Secret and the API User (your email).'),
            __('API Password: set it from the same page ("Send link"): it is not the password of the Control Panel.'),
        ];
    }

    public function client(array $credentials): ContaboClient
    {
        $token = Tokens::oauth('https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token', [
            'grant_type' => 'password',
            'client_id' => self::string($credentials, 'client_id'),
            'client_secret' => self::string($credentials, 'client_secret'),
            'username' => self::string($credentials, 'api_user'),
            'password' => self::string($credentials, 'api_password'),
        ]);

        return new ContaboClient('https://api.contabo.com/v1', ['Authorization' => 'Bearer '.$token]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $instances = (int) data_get($api->get('/compute/instances', ['size' => 1]), '_pagination.totalElements', 0);

        return 'ok · '.trans_choice(':count instance|:count instances', $instances);
    }

    public function sections(): array
    {
        return [
            $this->instances(),
            $this->snapshots(),
            $this->storages(),
            $this->secrets(),
        ];
    }

    /**
     * Every page of a list: Contabo answers with _pagination.totalPages.
     *
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            ['size' => 100, 'page' => 1],
            fn (array $answer) => array_values(array_filter((array) ($answer['data'] ?? []), 'is_array')),
            fn (array $answer, int $page) => $page < (int) data_get($answer, '_pagination.totalPages', 1) ? ['size' => 100, 'page' => $page + 1] : null,
        );
    }

    private function instances(): Section
    {
        $power = fn (string $action, string $done) => fn (ApiClient $api, array $row) => tap($done, fn () => $api->post('/compute/instances/'.$row['instanceId'].'/actions/'.$action));

        return Section::make('instances', __('Instances'))
            ->icon('server')
            ->id('instanceId')
            ->list(fn (ApiClient $api) => self::every($api, '/compute/instances'))
            ->columns([
                Column::make('displayName', __('Name'))->using(fn ($row) => ($row['displayName'] ?? '') ?: ($row['name'] ?? '')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('ip', __('Address'))->mono()->using(fn ($row) => ServerTargets::label(data_get($row, 'ipConfig.v4.ip'))),
                Column::make('productId', __('Product'))->mono(),
                Column::make('spec', __('Size'))->using(fn ($row) => ($row['cpuCores'] ?? '?').' vCPU · '.round(((int) ($row['ramMb'] ?? 0)) / 1024).' GB · '.round(((int) ($row['diskMb'] ?? 0)) / 1024).' GB'),
                Column::make('region', __('Region')),
                Column::make('createdDate', __('Created'))->date(),
            ])
            ->actions([
                Action::make('snapshots', __('Snapshots'))->icon('camera')
                    ->opens('snapshots', fn (array $row) => ['instance' => (string) $row['instanceId'], 'instance_name' => (string) (($row['displayName'] ?? '') ?: ($row['name'] ?? $row['instanceId']))]),
                Action::make('start', __('Start'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'stopped')->run($power('start', __('Starting.'))),
                Action::make('restart', __('Restart'))->icon('arrow-path')->when(fn ($row) => ($row['status'] ?? '') === 'running')->confirm(__('Restart the instance?'))->run($power('restart', __('Restarting.'))),
                Action::make('shutdown', __('Shut down'))->icon('power')->when(fn ($row) => ($row['status'] ?? '') === 'running')->confirm(__('Shut the instance down gracefully?'))->run($power('shutdown', __('Shutting down.'))),
                Action::make('stop', __('Power off'))->icon('stop')->when(fn ($row) => ($row['status'] ?? '') === 'running')->danger(__('Cut the power? Unsaved data is lost; prefer Shut down.'))->run($power('stop', __('Powered off.'))),
                Action::make('rename', __('Rename'))->icon('pencil')
                    ->fields([Field::text('displayName', __('Name'))->required()->default(fn ($row) => $row['displayName'] ?? '')])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Renamed.'), fn () => $api->patch('/compute/instances/'.$row['instanceId'], ['displayName' => $data['displayName']]))),
                Action::make('details', __('Details'))->icon('eye')->run(fn (ApiClient $api, array $row) => Outcome::show((string) ($row['displayName'] ?? $row['instanceId']), $row)),
            ]);
    }

    private function snapshots(): Section
    {
        $path = fn (array $scope) => '/compute/instances/'.$scope['instance'].'/snapshots';

        return Section::make('snapshots', __('Snapshots'))
            ->childOf('instances', 'instance_name')
            ->id('snapshotId')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, $path($scope)))
            ->columns([
                Column::make('name', __('Snapshot')),
                Column::make('description', __('Description')),
                Column::make('createdDate', __('Taken'))->date(),
                Column::make('autoDeleteDate', __('Deleted on'))->date(),
            ])
            ->create(__('Take a snapshot'), [
                Field::text('name', __('Name'))->required()->rules(['max:30']),
                Field::text('description', __('Description')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Snapshot started.'), fn () => $api->post($path($scope), array_filter($data))))
            ->actions([
                Action::make('rollback', __('Roll back'))->icon('arrow-uturn-left')->danger(__('Roll the instance back to this snapshot? Everything written since is lost.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rolling back.'), fn () => $api->post($path($scope).'/'.$row['snapshotId'].'/rollback'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger()
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Snapshot deleted.'), fn () => $api->delete($path($scope).'/'.$row['snapshotId']))),
            ]);
    }

    private function storages(): Section
    {
        return Section::make('object-storages', __('Object storage'))
            ->icon('archive-box')
            ->id('objectStorageId')
            ->list(fn (ApiClient $api) => self::every($api, '/object-storages'))
            ->columns([
                Column::make('displayName', __('Name')),
                Column::make('region', __('Region')),
                Column::make('s3Url', __('S3 endpoint'))->mono(),
                Column::make('status', __('Status'))->badge(['ready' => 'green', '*' => 'yellow']),
                Column::make('size', __('Size'))->using(fn ($row) => isset($row['totalPurchasedSpaceTB']) ? $row['totalPurchasedSpaceTB'].' TB' : ''),
            ]);
    }

    private function secrets(): Section
    {
        return Section::make('secrets', __('SSH keys and passwords'))
            ->icon('key')
            ->id('secretId')
            ->list(fn (ApiClient $api) => self::every($api, '/secrets'))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('type', __('Type'))->badge(['ssh' => 'blue', 'password' => 'zinc']),
                Column::make('createdAt', __('Added'))->date(),
            ])
            ->create(__('Add an SSH key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('value', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Key added: pick it when reinstalling an instance.'), fn () => $api->post('/secrets', ['name' => $data['name'], 'value' => trim((string) $data['value']), 'type' => 'ssh'])))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger()
                    ->run(fn (ApiClient $api, array $row) => tap(__('Deleted.'), fn () => $api->delete('/secrets/'.$row['secretId']))),
            ]);
    }
}
