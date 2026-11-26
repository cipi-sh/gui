<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;

/**
 * Boogle, errors and uptime of your apps (self-hosted), through its admin
 * API: projects with the .env of the client package and uptime on or off,
 * their exceptions to read, fix and close, every open exception at once,
 * and groups. https://boogle.web.ap.it
 */
final class Boogle extends Connector
{
    private const STATUS = ['OPEN' => 'Open', 'READ' => 'Read', 'FIXED' => 'Fixed', 'DONE' => 'Done'];

    private const COLORS = ['open' => 'red', 'read' => 'yellow', 'fixed' => 'blue', 'done' => 'green'];

    public function key(): string
    {
        return 'boogle';
    }

    public function label(): string
    {
        return 'Boogle';
    }

    public function description(): string
    {
        return __('Your errors and uptime (boogle.web.ap.it): projects with the .env of the client, uptime on or off, exceptions to read, fix and close.');
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
        return 'https://github.com/andreapollastri/boogle#admin-api';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Boogle address'))->required()->placeholder('https://boogle.example.com'),
            Field::password('token', __('API token'))->required()->help(__('An admin user\'s token: Boogle → Profile → API tokens.')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Boogle → Profile → API tokens → New token. The admin API answers admin users only: make the token as one.'),
            __('The address is the one you open in the browser, with https://; the panel calls /api/admin under it.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $url = self::instanceUrl($credentials['url'] ?? '');

        if ($url === '') {
            throw new ConnectorException(__('The address of Boogle is missing.'));
        }

        return new ApiClient($url.'/api/admin', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $projects = (int) ($api->get('/projects')['total'] ?? 0);
        $open = (int) ($api->get('/exceptions', ['status' => 'OPEN'])['total'] ?? 0);

        return 'ok · '.trans_choice(':count project|:count projects', $projects).' · '.trans_choice(':count open exception|:count open exceptions', $open);
    }

    public function sections(): array
    {
        return [
            $this->projects(),
            $this->exceptions('exceptions', true),
            $this->exceptions('project-exceptions', false),
            $this->groups(),
        ];
    }

    /**
     * Every page of a Laravel paginator, up to $pages.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $pages = 8): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values(array_filter((array) ($answer['data'] ?? []), 'is_array')),
            fn (array $answer) => is_string($answer['next_page_url'] ?? null) && $answer['next_page_url'] !== '' ? $answer['next_page_url'] : null,
            $pages,
        );
    }

    /** @return array<string, string> */
    private static function groupOptions(ApiClient $api): array
    {
        $out = ['' => __('No group')];

        foreach (self::every($api, '/groups') as $g) {
            $out[(string) $g['id']] = (string) $g['title'];
        }

        return $out;
    }

    private function projects(): Section
    {
        return Section::make('projects', __('Projects'))
            ->icon('apps')
            // the project's keys stay at Boogle until asked for
            ->list(fn (ApiClient $api) => array_map(fn ($p) => array_diff_key($p, ['key' => true, 'api_token' => true]), self::every($api, '/projects')))
            ->columns([
                Column::make('title', __('Project')),
                Column::make('url', __('Address'))->mono()->link('url'),
                Column::make('uptime_enabled', __('Uptime'))->bool(),
                Column::make('group.title', __('Group')),
                Column::make('total_exceptions', __('Exceptions')),
                Column::make('last_error_at', __('Last error'))->date(),
            ])
            ->create(__('New project'), [
                Field::text('title', __('Name'))->required(),
                Field::text('url', __('Address'))->placeholder('https://myapp.example.com')->rules(['nullable', 'url']),
                Field::checkbox('uptime_enabled', __('Check uptime'))->default(true),
                Field::text('uptime_url', __('Address to check'))->placeholder(__('empty: the address above'))->rules(['nullable', 'url']),
                Field::select('group_id', __('Group'), fn (ApiClient $api) => self::groupOptions($api)),
            ], function (ApiClient $api, array $row, array $data) {
                $project = $api->post('/projects', array_filter($data, fn ($v) => $v !== null && $v !== ''));

                return self::env($api, $project, __('Project created.'));
            })
            ->actions([
                Action::make('exceptions', __('Exceptions'))->icon('warning')
                    ->opens('project-exceptions', fn (array $row) => ['project' => (string) $row['id'], 'project_title' => (string) $row['title']]),
                Action::make('env', __('.env of the client'))->icon('code')
                    ->run(fn (ApiClient $api, array $row) => self::env($api, $api->get('/projects/'.$row['id']))),
                Action::make('uptime-off', __('Stop checking uptime'))->icon('pause')->when(fn ($row) => (bool) ($row['uptime_enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Uptime checks off.'), fn () => $api->patch('/projects/'.$row['id'], ['uptime_enabled' => false]))),
                Action::make('uptime-on', __('Check uptime'))->icon('play')->when(fn ($row) => ! ($row['uptime_enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Uptime checks on.'), fn () => $api->patch('/projects/'.$row['id'], ['uptime_enabled' => true]))),
                Action::make('edit', __('Edit'))->icon('pencil')
                    ->fields([
                        Field::text('title', __('Name'))->required()->default(fn ($row) => $row['title'] ?? ''),
                        Field::text('url', __('Address'))->default(fn ($row) => $row['url'] ?? '')->rules(['nullable', 'url']),
                        Field::text('uptime_url', __('Address to check'))->default(fn ($row) => $row['uptime_url'] ?? '')->rules(['nullable', 'url']),
                        Field::select('group_id', __('Group'), fn (ApiClient $api) => self::groupOptions($api))->default(fn ($row) => (string) ($row['group_id'] ?? '')),
                    ])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Project saved.'), fn () => $api->patch('/projects/'.$row['id'], ['title' => $data['title'], 'url' => $data['url'], 'uptime_url' => $data['uptime_url'], 'group_id' => ($data['group_id'] ?? '') ?: null]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the project and its exceptions?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Project deleted.'), fn () => $api->delete('/projects/'.$row['id']))),
            ]);
    }

    /** @param  array<string, mixed>  $project */
    private static function env(ApiClient $api, array $project, ?string $message = null): Outcome
    {
        return Outcome::show(__('.env of :project', ['project' => $project['title'] ?? '']), S3Sections::env([
            'BOOGLE_KEY' => (string) ($project['api_token'] ?? ''),
            'BOOGLE_PROJECT_KEY' => (string) ($project['key'] ?? ''),
            'BOOGLE_SERVER' => (string) preg_replace('#/api/admin$#', '/api/log', $api->baseUrl),
        ])."\n\n# ".__('In the app: composer require andreapollastri/boogle-client && php artisan boogle:install.'), $message);
    }

    private function exceptions(string $key, bool $all): Section
    {
        $statusFilter = Field::select('status', __('Status'), ['' => __('Any'), ...self::STATUS])->default('OPEN');
        $section = Section::make($key, $all ? __('Open exceptions') : __('Exceptions'))
            ->icon('warning')
            ->filters([$statusFilter])
            ->list(function (ApiClient $api, array $scope) use ($all) {
                $query = array_filter(['status' => (string) ($scope['status'] ?? '')]);
                $rows = $all ? self::every($api, '/exceptions', $query, 4) : self::every($api, '/projects/'.$scope['project'].'/exceptions', $query, 4);

                return array_map(fn ($e) => array_diff_key($e, ['raw_exception' => true]), $rows);
            })
            ->columns(array_values(array_filter([
                Column::make('issue_code', __('Issue'))->mono(),
                $all ? Column::make('project.title', __('Project')) : null,
                Column::make('class', __('Exception'))->mono()->using(fn ($row) => class_basename((string) (($row['class'] ?? '') ?: ($row['exception'] ?? '')))),
                Column::make('error', __('Message'))->using(fn ($row) => mb_substr((string) ($row['error'] ?? ''), 0, 160)),
                Column::make('where', __('Where'))->mono()->using(fn ($row) => ($row['file'] ?? '') !== '' ? basename((string) $row['file']).':'.($row['line'] ?? '') : ''),
                Column::make('status', __('Status'))->badge(self::COLORS),
                Column::make('created_at', __('When'))->date(),
            ])))
            ->actions([
                Action::make('details', __('Details'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show((string) ($row['issue_code'] ?? $row['id']), array_intersect_key($api->get('/projects/'.$row['project_id'].'/exceptions/'.$row['id']), array_flip(['exception', 'class', 'error', 'file', 'line', 'full_url', 'method', 'host', 'status', 'executor', 'user', 'http', 'created_at'])))),
                ...array_map(fn (string $status) => Action::make('mark-'.strtolower($status), __('Mark :status', ['status' => strtolower(__(self::STATUS[$status]))]))
                    ->icon(match ($status) { 'OPEN' => 'arrow-uturn-left', 'READ' => 'eye', 'FIXED' => 'wrench', default => 'check-circle' })
                    ->when(fn ($row) => ($row['status'] ?? '') !== $status)
                    ->fields([Field::text('comment', __('Comment'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Marked :status.', ['status' => strtolower(__(self::STATUS[$status]))]), fn () => $api->patch('/projects/'.$row['project_id'].'/exceptions/'.$row['id'].'/status', array_filter(['status' => $status, 'comment' => $data['comment'] ?? null])))), array_keys(self::STATUS)),
            ]);

        return $all ? $section : $section->childOf('projects', 'project_title');
    }

    private function groups(): Section
    {
        return Section::make('groups', __('Groups'))
            ->icon('squares-2x2')
            ->list(fn (ApiClient $api) => self::every($api, '/groups'))
            ->columns([
                Column::make('title', __('Group')),
                Column::make('issue_prefix', __('Issue prefix'))->mono(),
                Column::make('projects_count', __('Projects')),
                Column::make('description', __('Description')),
            ])
            ->create(__('New group'), [
                Field::text('title', __('Name'))->required(),
                Field::text('issue_prefix', __('Issue prefix'))->placeholder('APP'),
                Field::text('description', __('Description')),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Group created.'), fn () => $api->post('/groups', array_filter($data))))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the group? Its projects stay, without a group.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Group deleted.'), fn () => $api->delete('/groups/'.$row['id']))),
            ]);
    }
}
