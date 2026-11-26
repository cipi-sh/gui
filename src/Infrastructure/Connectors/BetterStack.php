<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;

/**
 * Better Stack Uptime, with a team's API token: monitors (pause, resume,
 * availability of the last 30 days), heartbeats for cron jobs and queues,
 * incidents to acknowledge and resolve, status pages.
 * https://betterstack.com/docs/uptime/api/getting-started-with-uptime-api/
 */
final class BetterStack extends Connector
{
    private const STATUS = ['up' => 'green', 'down' => 'red', 'paused' => 'zinc', 'pending' => 'zinc', 'maintenance' => 'blue', 'validating' => 'yellow', '*' => 'zinc'];

    private const FREQUENCY = ['30' => '30 s', '60' => '1 min', '180' => '3 min', '300' => '5 min', '600' => '10 min', '900' => '15 min', '1800' => '30 min', '3600' => '1 h'];

    public function key(): string
    {
        return 'betterstack';
    }

    public function label(): string
    {
        return 'Better Stack';
    }

    public function description(): string
    {
        return __('Uptime monitors (pause, resume, 30-day availability), heartbeats for cron jobs and queues, incidents to acknowledge and resolve, status pages.');
    }

    public function icon(): string
    {
        return 'signal';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function docs(): string
    {
        return 'https://betterstack.com/docs/uptime/api/getting-started-with-uptime-api/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('Uptime API token'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('betterstack.com → Uptime → Settings (the team) → API tokens → Create API token: a team token reaches that team\'s monitors.'),
            __('A global token reaches every team but needs a team name on each new monitor: prefer a team token, one account per team.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://uptime.betterstack.com/api', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $monitors = count((array) ($api->get('/v2/monitors', ['per_page' => 250])['data'] ?? []));

        return 'ok · '.trans_choice(':count monitor|:count monitors', $monitors);
    }

    public function sections(): array
    {
        return [
            $this->monitors(),
            $this->heartbeats(),
            $this->incidents(),
            $this->statusPages(),
        ];
    }

    /**
     * Every page of a JSON:API list, each item flattened: its id and attributes side by side.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $pages = 20): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_map(fn ($item) => ['id' => (string) ($item['id'] ?? ''), ...(array) ($item['attributes'] ?? [])], array_filter((array) ($answer['data'] ?? []), 'is_array'))),
            fn (array $answer) => is_string($next = data_get($answer, 'pagination.next')) && $next !== '' ? $next : null,
            $pages,
        );
    }

    private function monitors(): Section
    {
        return Section::make('monitors', __('Monitors'))
            ->icon('signal')
            ->list(fn (ApiClient $api) => self::every($api, '/v2/monitors', ['per_page' => 250]))
            ->columns([
                Column::make('pronounceable_name', __('Monitor')),
                Column::make('url', __('Address'))->mono()->link('url'),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('monitor_type', __('Type')),
                Column::make('check_frequency', __('Every'))->using(fn ($row) => self::FREQUENCY[(string) ($row['check_frequency'] ?? '')] ?? (($row['check_frequency'] ?? '') !== '' ? $row['check_frequency'].' s' : '')),
                Column::make('last_checked_at', __('Checked'))->date(),
            ])
            ->create(__('New monitor'), [
                Field::text('url', __('Address'))->required()->placeholder('https://myapp.example.com'),
                Field::text('pronounceable_name', __('Name'))->placeholder(__('empty: the address')),
                Field::select('monitor_type', __('Up when'), ['status' => __('it answers 2xx'), 'expected_status_code' => __('it answers a given code'), 'keyword' => __('the page has a word'), 'keyword_absence' => __('the page lacks a word'), 'ping' => __('it answers ping'), 'tcp' => __('a port answers')])->default('status'),
                Field::text('required_keyword', __('Word'))->help(__('For the word checks.')),
                Field::select('check_frequency', __('Check every'), self::FREQUENCY)->default('180'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Monitor added.'), fn () => $api->post('/v2/monitors', array_filter([
                ...$data,
                'check_frequency' => (int) ($data['check_frequency'] ?? 180),
            ], fn ($v) => $v !== null && $v !== ''))))
            ->actions([
                Action::make('availability', __('Availability, 30 days'))->icon('chart-bar')
                    ->run(function (ApiClient $api, array $row) {
                        $sla = (array) data_get($api->get('/v2/monitors/'.$row['id'].'/sla', ['from' => gmdate('Y-m-d', time() - 30 * 86400), 'to' => gmdate('Y-m-d')]), 'data.attributes', []);

                        return Outcome::show(__('Availability of :monitor', ['monitor' => $row['pronounceable_name'] ?? $row['url']]), array_intersect_key($sla, array_flip(['availability', 'total_downtime', 'number_of_incidents', 'longest_incident', 'average_incident'])));
                    }),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ! ($row['paused'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Monitor paused.'), fn () => $api->patch('/v2/monitors/'.$row['id'], ['paused' => true]))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => (bool) ($row['paused'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Monitor resumed.'), fn () => $api->patch('/v2/monitors/'.$row['id'], ['paused' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the monitor and its history?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Monitor deleted.'), fn () => $api->delete('/v2/monitors/'.$row['id']))),
            ]);
    }

    private function heartbeats(): Section
    {
        return Section::make('heartbeats', __('Heartbeats'))
            ->icon('heart')
            ->description(__('A job calls its heartbeat address when it runs: when the calls stop, Better Stack alerts. For the scheduler: ->pingOnSuccess(\'…\').'))
            ->list(fn (ApiClient $api) => self::every($api, '/v2/heartbeats', ['per_page' => 250]))
            ->columns([
                Column::make('name', __('Heartbeat')),
                Column::make('status', __('Status'))->badge(self::STATUS),
                Column::make('period', __('Every'))->using(fn ($row) => isset($row['period']) ? round((int) $row['period'] / 60, 1).' min' : ''),
                Column::make('grace', __('Grace'))->using(fn ($row) => isset($row['grace']) ? round((int) $row['grace'] / 60, 1).' min' : ''),
                Column::make('url', __('Address'))->mono(),
            ])
            ->create(__('New heartbeat'), [
                Field::text('name', __('Name'))->required()->placeholder('myapp · schedule:run'),
                Field::number('period', __('Expected every (minutes)'))->required()->default(5)->rules(['min:1']),
                Field::number('grace', __('Grace (minutes)'))->required()->default(5)->rules(['min:0']),
            ], function (ApiClient $api, array $row, array $data) {
                $beat = (array) data_get($api->post('/v2/heartbeats', ['name' => $data['name'], 'period' => (int) $data['period'] * 60, 'grace' => (int) $data['grace'] * 60]), 'data.attributes', []);

                return Outcome::show(__('Heartbeat :name', ['name' => $data['name']]), (string) ($beat['url'] ?? ''), __('Heartbeat created: call its address from the job.'));
            })
            ->actions([
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ! ($row['paused'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Heartbeat paused.'), fn () => $api->patch('/v2/heartbeats/'.$row['id'], ['paused' => true]))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => (bool) ($row['paused'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Heartbeat resumed.'), fn () => $api->patch('/v2/heartbeats/'.$row['id'], ['paused' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger()
                    ->run(fn (ApiClient $api, array $row) => tap(__('Heartbeat deleted.'), fn () => $api->delete('/v2/heartbeats/'.$row['id']))),
            ]);
    }

    private function incidents(): Section
    {
        return Section::make('incidents', __('Incidents'))
            ->icon('bell-alert')
            ->filters([Field::select('resolved', __('Show'), ['false' => __('Open'), '' => __('All, last 30 days')])->default('false')])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/v3/incidents', array_filter([
                'per_page' => 50,
                'resolved' => ($scope['resolved'] ?? 'false') === 'false' ? 'false' : null,
                'from' => ($scope['resolved'] ?? 'false') === 'false' ? null : gmdate('Y-m-d', time() - 30 * 86400),
            ]), 6))
            ->columns([
                Column::make('name', __('Incident')),
                Column::make('cause', __('Cause')),
                Column::make('status', __('Status'))->badge(['started' => 'red', 'acknowledged' => 'yellow', 'resolved' => 'green', '*' => 'zinc']),
                Column::make('started_at', __('Started'))->date(),
                Column::make('resolved_at', __('Resolved'))->date(),
            ])
            ->actions([
                Action::make('acknowledge', __('Acknowledge'))->icon('check')->when(fn ($row) => ($row['acknowledged_at'] ?? null) === null && ($row['resolved_at'] ?? null) === null)
                    ->run(fn (ApiClient $api, array $row) => tap(__('Acknowledged: escalation stops.'), fn () => $api->post('/v3/incidents/'.$row['id'].'/acknowledge'))),
                Action::make('resolve', __('Resolve'))->icon('check-circle')->when(fn ($row) => ($row['resolved_at'] ?? null) === null)
                    ->run(fn (ApiClient $api, array $row) => tap(__('Resolved.'), fn () => $api->post('/v3/incidents/'.$row['id'].'/resolve'))),
            ]);
    }

    private function statusPages(): Section
    {
        return Section::make('status-pages', __('Status pages'))
            ->icon('globe')
            ->list(fn (ApiClient $api) => self::every($api, '/v2/status-pages', ['per_page' => 250]))
            ->columns([
                Column::make('company_name', __('Page')),
                Column::make('address', __('Address'))->mono()
                    ->using(fn ($row) => ($row['custom_domain'] ?? '') ?: (($row['subdomain'] ?? '') !== '' ? $row['subdomain'].'.betteruptime.com' : ''))
                    ->link(fn ($row) => 'https://'.(($row['custom_domain'] ?? '') ?: ($row['subdomain'] ?? '').'.betteruptime.com')),
                Column::make('aggregate_state', __('Status'))->badge(['operational' => 'green', 'degraded' => 'yellow', 'downtime' => 'red', 'maintenance' => 'blue', '*' => 'zinc']),
            ]);
    }
}
