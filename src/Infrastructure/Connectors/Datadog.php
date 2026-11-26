<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;

/**
 * Datadog, with an API key and an application key on any site (US1, US3,
 * US5, EU, AP1, AP2, Gov): monitors and their state, muted with a downtime
 * and back; downtimes; hosts reporting, muted or not; dashboards; SLOs.
 * https://docs.datadoghq.com/api/latest/
 */
final class Datadog extends Connector
{
    private const SITES = [
        'datadoghq.com' => 'US1 (datadoghq.com)', 'us3.datadoghq.com' => 'US3', 'us5.datadoghq.com' => 'US5',
        'datadoghq.eu' => 'EU (datadoghq.eu)', 'ap1.datadoghq.com' => 'AP1 (Japan)', 'ap2.datadoghq.com' => 'AP2 (Australia)', 'ddog-gov.com' => 'US1-FED (ddog-gov.com)',
    ];

    private const STATE = ['ok' => 'green', 'alert' => 'red', 'warn' => 'yellow', 'no data' => 'zinc', 'skipped' => 'zinc', 'unknown' => 'zinc', 'ignored' => 'zinc', '*' => 'zinc'];

    public function key(): string
    {
        return 'datadog';
    }

    public function label(): string
    {
        return 'Datadog';
    }

    public function description(): string
    {
        return __('Monitors and their state, muted for a while with a downtime and back; downtimes; hosts reporting and muted; dashboards; SLOs.');
    }

    public function icon(): string
    {
        return 'chart';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function docs(): string
    {
        return 'https://docs.datadoghq.com/account_management/api-app-keys/';
    }

    public function fields(): array
    {
        return [
            Field::select('site', __('Site'), self::SITES)->required()->default('datadoghq.eu'),
            Field::password('api_key', __('API key'))->required(),
            Field::password('application_key', __('Application key'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Datadog → Organization Settings → API Keys → New Key: the API key.'),
            __('Organization Settings → Application Keys → New Key, with scopes monitors_read, monitors_write, monitors_downtime, hosts_read, dashboards_read, slos_read (or no scope: the key gets your permissions).'),
            __('The site is the one in the address of Datadog: app.datadoghq.eu is EU, us5.datadoghq.com is US5.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        $site = self::string($credentials, 'site', 'datadoghq.com');

        if (! isset(self::SITES[$site])) {
            throw new ConnectorException(__('Unknown Datadog site.'));
        }

        return new ScopedClient('https://api.'.$site, [
            'DD-API-KEY' => self::string($credentials, 'api_key'),
            'DD-APPLICATION-KEY' => self::string($credentials, 'application_key'),
        ], ['web' => in_array($site, ['datadoghq.com', 'datadoghq.eu', 'ddog-gov.com'], true) ? 'https://app.'.$site : 'https://'.$site]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        if (! ($api->get('/api/v1/validate')['valid'] ?? false)) {
            throw new ConnectorException(__('Datadog does not take the API key.'));
        }

        $monitors = count($api->get('/api/v1/monitor', ['page' => 0, 'page_size' => 1000]));

        return 'ok · '.parse_url($api->baseUrl, PHP_URL_HOST).' · '.trans_choice(':count monitor|:count monitors', $monitors);
    }

    public function sections(): array
    {
        return [
            $this->monitors(),
            $this->downtimes(),
            $this->hosts(),
            $this->dashboards(),
            $this->slos(),
        ];
    }

    private function monitors(): Section
    {
        return Section::make('monitors', __('Monitors'))
            ->icon('bell-alert')
            ->filters([Field::select('state', __('Show'), ['' => __('All'), 'Alert' => __('Alerting'), 'Warn' => __('Warning'), 'No Data' => __('No data'), 'OK' => 'OK'])])
            ->list(function (ScopedClient $api, array $scope) {
                $rows = [];

                for ($page = 0; $page < 10; $page++) {
                    $batch = array_values(array_filter($api->get('/api/v1/monitor', ['page' => $page, 'page_size' => 1000]), 'is_array'));
                    $rows = [...$rows, ...$batch];

                    if (count($batch) < 1000) {
                        break;
                    }
                }

                $state = (string) ($scope['state'] ?? '');

                return $state === '' ? $rows : array_values(array_filter($rows, fn ($m) => ($m['overall_state'] ?? '') === $state));
            })
            ->columns([
                Column::make('name', __('Monitor')),
                Column::make('overall_state', __('State'))->badge(self::STATE),
                Column::make('type', __('Type')),
                Column::make('muted', __('Muted'))->bool()->using(fn ($row) => ! empty(data_get($row, 'matching_downtimes')) || ! empty(data_get($row, 'options.silenced'))),
                Column::make('tags', __('Tags'))->using(fn ($row) => implode(', ', (array) ($row['tags'] ?? []))),
                Column::make('modified', __('Changed'))->date(),
            ])
            ->actions([
                Action::make('open', __('Open in Datadog'))->icon('external')
                    ->run(fn (ScopedClient $api, array $row) => Outcome::show((string) $row['name'], $api->scope('web').'/monitors/'.$row['id'])),
                Action::make('query', __('Query'))->icon('code')
                    ->run(fn (ScopedClient $api, array $row) => Outcome::show((string) $row['name'], (string) ($row['query'] ?? '')."\n\n".(string) ($row['message'] ?? ''))),
                Action::make('mute', __('Mute'))->icon('pause')
                    ->fields([
                        Field::select('for', __('For'), ['3600' => __('1 hour'), '14400' => __('4 hours'), '86400' => __('1 day'), '604800' => __('1 week'), '' => __('Until unmuted')])->default('3600'),
                        Field::text('message', __('Why'))->placeholder(__('Maintenance')),
                    ])
                    ->run(function (ScopedClient $api, array $row, array $data) {
                        $for = (string) ($data['for'] ?? '3600');
                        $api->post('/api/v2/downtime', ['data' => ['type' => 'downtime', 'attributes' => array_filter([
                            'scope' => '*',
                            'monitor_identifier' => ['monitor_id' => (int) $row['id']],
                            'message' => (string) ($data['message'] ?? '') ?: 'Muted from the Cipi panel',
                            'schedule' => $for === '' ? null : ['end' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) $for)],
                        ], fn ($v) => $v !== null)]]);

                        return __('Muted.');
                    }),
                Action::make('unmute', __('Unmute'))->icon('play')
                    ->when(fn ($row) => ! empty(data_get($row, 'matching_downtimes')) || ! empty(data_get($row, 'options.silenced')))
                    ->run(function (ScopedClient $api, array $row) {
                        $cancelled = 0;

                        foreach ((array) ($api->get('/api/v2/downtime', ['current_only' => 'true'])['data'] ?? []) as $downtime) {
                            if ((int) data_get($downtime, 'attributes.monitor_identifier.monitor_id', 0) === (int) $row['id']) {
                                $api->delete('/api/v2/downtime/'.$downtime['id']);
                                $cancelled++;
                            }
                        }

                        return $cancelled > 0 ? __('Unmuted.') : __('No downtime of this monitor alone: it is muted by a wider one (Downtimes).');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the monitor?'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Monitor deleted.'), fn () => $api->delete('/api/v1/monitor/'.$row['id']))),
            ]);
    }

    private function downtimes(): Section
    {
        return Section::make('downtimes', __('Downtimes'))
            ->icon('pause')
            ->list(fn (ScopedClient $api) => array_values(array_map(fn ($d) => ['id' => (string) $d['id'], ...(array) ($d['attributes'] ?? [])], array_filter((array) ($api->get('/api/v2/downtime', ['current_only' => 'true'])['data'] ?? []), 'is_array'))))
            ->columns([
                Column::make('scope', __('Scope'))->mono(),
                Column::make('monitor', __('Monitor'))->using(fn ($row) => data_get($row, 'monitor_identifier.monitor_id') ?? implode(', ', (array) data_get($row, 'monitor_identifier.monitor_tags', []))),
                Column::make('status', __('Status'))->badge(['active' => 'blue', 'scheduled' => 'zinc', 'ended' => 'zinc', 'canceled' => 'zinc', '*' => 'zinc']),
                Column::make('message', __('Why')),
                Column::make('end', __('Ends'))->date()->using(fn ($row) => data_get($row, 'schedule.end') ?? data_get($row, 'schedule.current_downtime.end') ?? ''),
            ])
            ->create(__('Mute a scope'), [
                Field::text('scope', __('Scope'))->required()->default('*')->placeholder('env:production or host:web-1'),
                Field::text('tags', __('Monitors with tags'))->placeholder('service:myapp')->help(__('Empty: every monitor in the scope.')),
                Field::select('for', __('For'), ['3600' => __('1 hour'), '14400' => __('4 hours'), '86400' => __('1 day'), '604800' => __('1 week')])->default('3600'),
                Field::text('message', __('Why')),
            ], function (ScopedClient $api, array $row, array $data) {
                $tags = self::lines($data['tags'] ?? '');
                $api->post('/api/v2/downtime', ['data' => ['type' => 'downtime', 'attributes' => [
                    'scope' => (string) $data['scope'],
                    'monitor_identifier' => ['monitor_tags' => $tags ?: ['*']],
                    'message' => (string) ($data['message'] ?? '') ?: 'Muted from the Cipi panel',
                    'schedule' => ['end' => gmdate('Y-m-d\TH:i:s\Z', time() + (int) ($data['for'] ?? 3600))],
                ]]]);

                return __('Downtime scheduled.');
            })
            ->actions([
                Action::make('cancel', __('Cancel'))->icon('x')->confirm(__('Cancel the downtime? Its monitors alert again.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Downtime cancelled.'), fn () => $api->delete('/api/v2/downtime/'.$row['id']))),
            ]);
    }

    private function hosts(): Section
    {
        return Section::make('hosts', __('Hosts'))
            ->icon('server')
            ->id('name')
            ->list(fn (ScopedClient $api) => array_values(array_filter((array) ($api->get('/api/v1/hosts', ['count' => 1000, 'include_muted_hosts_data' => 'true'])['host_list'] ?? []), 'is_array')))
            ->columns([
                Column::make('name', __('Host'))->mono(),
                Column::make('up', __('Up'))->bool(),
                Column::make('is_muted', __('Muted'))->bool(),
                Column::make('apps', __('Integrations'))->using(fn ($row) => implode(', ', array_slice((array) ($row['apps'] ?? []), 0, 8))),
                Column::make('agent', __('Agent'))->using(fn ($row) => (string) data_get($row, 'meta.agent_version', '')),
                Column::make('reported', __('Reported'))->date()->using(fn ($row) => isset($row['last_reported_time']) ? gmdate('c', (int) $row['last_reported_time']) : ''),
            ])
            ->actions([
                Action::make('mute', __('Mute'))->icon('pause')->when(fn ($row) => ! ($row['is_muted'] ?? false))
                    ->fields([Field::select('for', __('For'), ['3600' => __('1 hour'), '14400' => __('4 hours'), '86400' => __('1 day'), '' => __('Until unmuted')])->default('3600')])
                    ->run(fn (ScopedClient $api, array $row, array $data) => tap(__('Host muted.'), fn () => $api->post('/api/v1/host/'.rawurlencode((string) $row['name']).'/mute', array_filter(['end' => ($data['for'] ?? '') !== '' ? time() + (int) $data['for'] : null, 'message' => 'Muted from the Cipi panel'])))),
                Action::make('unmute', __('Unmute'))->icon('play')->when(fn ($row) => (bool) ($row['is_muted'] ?? false))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Host unmuted.'), fn () => $api->post('/api/v1/host/'.rawurlencode((string) $row['name']).'/unmute'))),
            ]);
    }

    private function dashboards(): Section
    {
        return Section::make('dashboards', __('Dashboards'))
            ->icon('chart-bar')
            ->list(fn (ScopedClient $api) => array_map(fn ($d) => [...$d, '_url' => $api->scope('web').($d['url'] ?? '')], array_values(array_filter((array) ($api->get('/api/v1/dashboard')['dashboards'] ?? []), 'is_array'))))
            ->columns([
                Column::make('title', __('Dashboard'))->link('_url'),
                Column::make('author_handle', __('Author')),
                Column::make('modified_at', __('Changed'))->date(),
            ]);
    }

    private function slos(): Section
    {
        return Section::make('slos', __('SLOs'))
            ->icon('chart')
            ->list(fn (ScopedClient $api) => array_values(array_filter((array) ($api->get('/api/v1/slo', ['limit' => 1000])['data'] ?? []), 'is_array')))
            ->columns([
                Column::make('name', __('SLO')),
                Column::make('type', __('Type')),
                Column::make('target', __('Target'))->using(fn ($row) => ($t = data_get($row, 'thresholds.0')) ? ($t['target'] ?? '').'% / '.($t['timeframe'] ?? '') : ''),
                Column::make('tags', __('Tags'))->using(fn ($row) => implode(', ', (array) ($row['tags'] ?? []))),
            ]);
    }
}
