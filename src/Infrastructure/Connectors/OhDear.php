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
 * Oh Dear, with an API token: monitors and how each check went (uptime,
 * certificate, broken links, mixed content, DNS, scheduled tasks…), runs on
 * demand, snoozes and maintenance windows, 30 days of uptime, and the
 * updates of the status pages. https://ohdear.app/docs/api/introduction
 */
final class OhDear extends Connector
{
    private const RESULT = ['succeeded' => 'green', 'warning' => 'yellow', 'failed' => 'red', 'pending' => 'zinc', 'skipped' => 'zinc', 'errored-or-timed-out' => 'red', '*' => 'zinc'];

    public function key(): string
    {
        return 'ohdear';
    }

    public function label(): string
    {
        return 'Oh Dear';
    }

    public function description(): string
    {
        return __('Monitors and every check (uptime, certificates, broken links, DNS, scheduled tasks), runs on demand, snoozes, maintenance windows, 30-day uptime, status page updates.');
    }

    public function icon(): string
    {
        return 'heart';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function docs(): string
    {
        return 'https://ohdear.app/docs/api/introduction';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API token'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('ohdear.app → your avatar → API tokens → Create token. The token reaches every team you are in.'),
            __('New monitors go to a team: the form asks which one.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://ohdear.app/api', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $me = $api->get('/me');

        return 'ok · '.trim(($me['name'] ?? '').' · '.implode(', ', array_column((array) ($me['teams'] ?? []), 'name')), ' ·');
    }

    public function sections(): array
    {
        return [
            $this->monitors(),
            $this->checks(),
            $this->statusPages(),
        ];
    }

    /**
     * Every page of a list: Oh Dear answers with links.next.
     *
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            [],
            fn (array $answer) => array_values(array_filter((array) ($answer['data'] ?? []), 'is_array')),
            fn (array $answer) => is_string($next = data_get($answer, 'links.next')) && $next !== '' ? $next : null,
        );
    }

    /**
     * @param  array<string, mixed>  $monitor
     */
    private static function check(array $monitor, string $type): string
    {
        foreach ((array) ($monitor['checks'] ?? []) as $check) {
            if (is_array($check) && ($check['type'] ?? '') === $type) {
                return ($check['enabled'] ?? false) ? (string) ($check['latest_run_result'] ?? 'pending') : 'off';
            }
        }

        return '';
    }

    private function monitors(): Section
    {
        return Section::make('monitors', __('Monitors'))
            ->icon('heart')
            ->list(fn (ApiClient $api) => self::every($api, '/monitors'))
            ->columns([
                Column::make('url', __('Monitor'))->mono()->link('url')->using(fn ($row) => ($row['label'] ?? '') ?: ($row['url'] ?? '')),
                Column::make('summarized_check_result', __('Overall'))->badge(self::RESULT),
                Column::make('uptime', __('Uptime'))->badge(self::RESULT)->using(fn ($row) => self::check($row, 'uptime')),
                Column::make('certificate', __('Certificate'))->badge(self::RESULT)->using(fn ($row) => self::check($row, 'certificate_health')),
                Column::make('links', __('Broken links'))->badge(self::RESULT)->using(fn ($row) => self::check($row, 'broken_links')),
                Column::make('type', __('Type')),
                Column::make('latest_run_date', __('Checked'))->date(),
            ])
            ->create(__('New monitor'), [
                Field::text('url', __('Address'))->required()->placeholder('https://myapp.example.com'),
                Field::select('team_id', __('Team'), fn (ApiClient $api) => collect((array) ($api->get('/me')['teams'] ?? []))->mapWithKeys(fn ($t) => [(string) $t['id'] => (string) $t['name']])->all())->required(),
                Field::select('type', __('Type'), ['http' => __('Website (HTTP)'), 'ping' => __('Server (ping)'), 'tcp' => __('Port (TCP)')])->default('http'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Monitor added: the first checks run in a minute.'), fn () => $api->post('/monitors', ['url' => trim((string) $data['url']), 'team_id' => (int) $data['team_id'], 'type' => $data['type'] ?? 'http'])))
            ->actions([
                Action::make('checks', __('Checks'))->icon('list-bullet')
                    ->opens('checks', fn (array $row) => ['monitor' => (string) $row['id'], 'monitor_url' => (string) (($row['label'] ?? '') ?: ($row['url'] ?? ''))]),
                Action::make('uptime', __('Uptime, 30 days'))->icon('chart-bar')
                    ->run(function (ApiClient $api, array $row) {
                        $days = (array) $api->get('/monitors/'.$row['id'].'/uptime', [
                            'filter[started_at]' => gmdate('YmdHis', time() - 30 * 86400),
                            'filter[ended_at]' => gmdate('YmdHis'),
                            'split' => 'day',
                        ]);
                        $values = array_values(array_filter(array_map(fn ($d) => is_array($d) && isset($d['uptime_percentage']) ? (float) $d['uptime_percentage'] : null, $days), fn ($v) => $v !== null));
                        $lines = array_map(fn ($d) => substr((string) ($d['datetime'] ?? ''), 0, 10).'  '.number_format((float) ($d['uptime_percentage'] ?? 0), 3).'%', array_filter($days, 'is_array'));

                        return Outcome::show(__('Uptime of :monitor', ['monitor' => $row['url']]), ($values === [] ? __('No data yet.') : __('Average: :avg%', ['avg' => number_format(array_sum($values) / count($values), 3)]))."\n\n".implode("\n", $lines));
                    }),
                Action::make('certificate', __('Certificate'))->icon('lock-closed')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Certificate of :monitor', ['monitor' => $row['url']]), (array) ($api->get('/certificate-health/'.$row['id'])['certificate_details'] ?? []))),
                Action::make('broken-links', __('Broken links'))->icon('link-slash')
                    ->run(function (ApiClient $api, array $row) {
                        $links = array_values(array_filter((array) ($api->get('/broken-links/'.$row['id'])['data'] ?? []), 'is_array'));

                        return Outcome::show(__(':count broken links', ['count' => count($links)]), implode("\n", array_map(fn ($l) => ($l['status_code'] ?? '?').'  '.($l['crawled_url'] ?? '').'  ← '.($l['found_on_url'] ?? ''), array_slice($links, 0, 200))) ?: __('None.'));
                    }),
                Action::make('maintenance', __('Start maintenance'))->icon('wrench')
                    ->fields([Field::number('minutes', __('For (minutes)'))->required()->default(30)->rules(['min:1', 'max:1440'])])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Maintenance on: no alerts until it ends.'), fn () => $api->post('/monitors/'.$row['id'].'/start-maintenance', ['stop_maintenance_after_seconds' => (int) $data['minutes'] * 60]))),
                Action::make('maintenance-stop', __('End maintenance'))->icon('stop')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Maintenance over.'), fn () => $api->post('/monitors/'.$row['id'].'/stop-maintenance'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the monitor and its history?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Monitor deleted.'), fn () => $api->delete('/monitors/'.$row['id']))),
            ]);
    }

    private function checks(): Section
    {
        return Section::make('checks', __('Checks'))
            ->childOf('monitors', 'monitor_url')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter((array) ($api->get('/monitors/'.$scope['monitor'])['checks'] ?? []), 'is_array')))
            ->columns([
                Column::make('label', __('Check')),
                Column::make('enabled', __('On'))->bool(),
                Column::make('latest_run_result', __('Result'))->badge(self::RESULT),
                Column::make('summary', __('Summary')),
                Column::make('active_snooze', __('Snoozed until'))->using(fn ($row) => is_array($row['active_snooze'] ?? null) ? (string) ($row['active_snooze']['ends_at'] ?? __('yes')) : ''),
                Column::make('latest_run_ended_at', __('Ran'))->date(),
            ])
            ->actions([
                Action::make('run', __('Run now'))->icon('play')->when(fn ($row) => (bool) ($row['enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Run requested.'), fn () => $api->post('/checks/'.$row['id'].'/request-run'))),
                Action::make('snooze', __('Snooze'))->icon('clock')->when(fn ($row) => ! is_array($row['active_snooze'] ?? null) && ($row['enabled'] ?? false))
                    ->fields([Field::number('minutes', __('For (minutes)'))->required()->default(60)->rules(['min:1'])])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Snoozed.'), fn () => $api->post('/checks/'.$row['id'].'/snooze', ['minutes' => (int) $data['minutes']]))),
                Action::make('unsnooze', __('Wake up'))->icon('bell-alert')->when(fn ($row) => is_array($row['active_snooze'] ?? null))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Alerts are back.'), fn () => $api->post('/checks/'.$row['id'].'/unsnooze'))),
                Action::make('disable', __('Turn off'))->icon('pause')->when(fn ($row) => (bool) ($row['enabled'] ?? false) && ($row['type'] ?? '') !== 'performance')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Check off.'), fn () => $api->post('/checks/'.$row['id'].'/disable'))),
                Action::make('enable', __('Turn on'))->icon('play')->when(fn ($row) => ! ($row['enabled'] ?? false) && ($row['type'] ?? '') !== 'performance')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Check on.'), fn () => $api->post('/checks/'.$row['id'].'/enable'))),
            ]);
    }

    private function statusPages(): Section
    {
        return Section::make('status-pages', __('Status pages'))
            ->icon('signal')
            ->list(fn (ApiClient $api) => self::every($api, '/status-pages'))
            ->columns([
                Column::make('title', __('Page')),
                Column::make('full_url', __('Address'))->mono()->link('full_url')->using(fn ($row) => ($row['full_url'] ?? '') ?: ($row['domain'] ?? '')),
                Column::make('summarized_status', __('Status'))->badge(['up' => 'green', 'warning' => 'yellow', 'down' => 'red', '*' => 'zinc']),
                Column::make('monitors', __('Monitors'))->using(fn ($row) => count((array) ($row['monitors'] ?? $row['sites'] ?? []))),
            ])
            ->actions([
                Action::make('update', __('Post an update'))->icon('signal')
                    ->fields([
                        Field::text('title', __('Title'))->required(),
                        Field::textarea('text', __('Text'))->required(),
                        Field::select('severity', __('Severity'), ['info' => __('Information'), 'warning' => __('Warning'), 'high' => __('Outage'), 'resolved' => __('Resolved'), 'scheduled' => __('Scheduled maintenance')])->default('info'),
                        Field::checkbox('pinned', __('Pin it to the top')),
                    ])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Update posted.'), fn () => $api->post('/status-page-updates', [
                        'status_page_id' => (int) $row['id'],
                        'title' => $data['title'],
                        'text' => $data['text'],
                        'severity' => $data['severity'] ?? 'info',
                        'pinned' => (bool) ($data['pinned'] ?? false),
                        'time' => gmdate('Y-m-d H:i'),
                    ]))),
            ]);
    }
}
