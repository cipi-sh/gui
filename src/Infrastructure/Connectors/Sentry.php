<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;

/**
 * Sentry (sentry.io in the US or the EU, or self-hosted), with a personal
 * token and an organization: issues by project and state to resolve, ignore
 * or reopen; projects with the DSN of a Laravel app; releases and their
 * deploys. https://docs.sentry.io/api/
 */
final class Sentry extends Connector
{
    private const LEVEL = ['fatal' => 'red', 'error' => 'red', 'warning' => 'yellow', 'info' => 'blue', 'debug' => 'zinc'];

    public function key(): string
    {
        return 'sentry';
    }

    public function label(): string
    {
        return 'Sentry';
    }

    public function description(): string
    {
        return __('Issues by project and state to resolve, ignore or reopen; projects with the DSN of each app; releases and deploys.');
    }

    public function icon(): string
    {
        return 'warning';
    }

    public function category(): string
    {
        return 'monitoring';
    }

    public function docs(): string
    {
        return 'https://docs.sentry.io/api/auth/';
    }

    public function fields(): array
    {
        return [
            Field::select('url', __('Sentry'), ['https://sentry.io' => __('sentry.io, US'), 'https://de.sentry.io' => __('sentry.io, EU (de.sentry.io)')])->default('https://de.sentry.io')
                ->help(__('Self-hosted: type its address in "Self-hosted address" instead.')),
            Field::text('self_hosted', __('Self-hosted address'))->placeholder('https://sentry.example.com'),
            Field::text('organization', __('Organization slug'))->required()->placeholder('my-company')->help(__('In the address of Sentry: /organizations/<slug>/.')),
            Field::password('token', __('Personal token'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Sentry → User settings → Personal Tokens → Create New Token.'),
            __('Scopes: org:read, team:read, project:read, project:write, project:releases, event:read, event:write (event:admin to delete issues).'),
            __('An organization token (Settings → Developer Settings → Organization Tokens) only reads and makes releases: it cannot resolve issues.'),
            __('EU organizations live on de.sentry.io: pick it, or the calls answer 404.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        $base = self::instanceUrl(self::string($credentials, 'self_hosted') ?: self::string($credentials, 'url', 'https://sentry.io'));
        $org = self::string($credentials, 'organization');

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $org)) {
            throw new ConnectorException(__('The organization is its slug: letters, digits and dashes, as in the address of Sentry.'));
        }

        return new ScopedClient($base.'/api/0', ['Authorization' => 'Bearer '.self::string($credentials, 'token')], ['org' => $org, 'web' => $base]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $org = $api->get(self::org($api));
        $projects = count($api->get(self::org($api).'projects/', ['per_page' => 100]));

        return 'ok · '.($org['name'] ?? $api->scope('org')).' · '.trans_choice(':count project|:count projects', $projects);
    }

    public function sections(): array
    {
        return [
            $this->issues(),
            $this->projects(),
            $this->releases(),
        ];
    }

    private static function org(ScopedClient $api): string
    {
        return '/organizations/'.rawurlencode($api->scope('org')).'/';
    }

    /** @return array<string, string> */
    private static function projectOptions(ScopedClient $api, bool $any = true): array
    {
        $out = $any ? ['' => __('Every project')] : [];

        foreach ($api->get(self::org($api).'projects/', ['per_page' => 100]) as $p) {
            if (is_array($p)) {
                $out[(string) ($any ? $p['id'] : $p['slug'])] = (string) ($p['name'] ?? $p['slug']);
            }
        }

        return $out;
    }

    private function issues(): Section
    {
        $status = fn (string $status, array $details = []) => fn (ScopedClient $api, array $row) => tap(
            match ($status) { 'resolved' => __('Resolved.'), 'ignored' => __('Ignored.'), default => __('Reopened.') },
            fn () => $api->put(self::org($api).'issues/'.$row['id'].'/', array_filter(['status' => $status, 'statusDetails' => $details ?: null])),
        );

        return Section::make('issues', __('Issues'))
            ->icon('warning')
            ->filters([
                Field::select('project', __('Project'), fn (ScopedClient $api) => self::projectOptions($api)),
                Field::select('query', __('Show'), [
                    'is:unresolved' => __('Unresolved'),
                    'is:unresolved is:for_review' => __('For review'),
                    'is:unresolved level:error' => __('Unresolved errors'),
                    'is:ignored' => __('Ignored'),
                    'is:resolved' => __('Resolved'),
                ])->default('is:unresolved'),
                Field::select('period', __('Seen in'), ['24h' => __('24 hours'), '14d' => __('14 days'), '90d' => __('90 days')])->default('14d'),
            ])
            ->list(fn (ScopedClient $api, array $scope) => array_values(array_filter($api->get(self::org($api).'issues/', array_filter([
                'query' => (string) ($scope['query'] ?? 'is:unresolved'),
                'statsPeriod' => (string) ($scope['period'] ?? '14d'),
                'project' => (string) ($scope['project'] ?? ''),
                'limit' => 100,
                'sort' => 'date',
            ])), 'is_array')))
            ->columns([
                Column::make('shortId', __('Issue'))->mono()->link('permalink'),
                Column::make('title', __('Title'))->using(fn ($row) => mb_substr((string) ($row['title'] ?? ''), 0, 140)),
                Column::make('culprit', __('Where'))->mono()->using(fn ($row) => mb_substr((string) ($row['culprit'] ?? ''), 0, 80)),
                Column::make('level', __('Level'))->badge(self::LEVEL),
                Column::make('count', __('Events')),
                Column::make('userCount', __('Users')),
                Column::make('lastSeen', __('Last seen'))->date(),
            ])
            ->actions([
                Action::make('latest', __('Latest event'))->icon('eye')
                    ->run(function (ScopedClient $api, array $row) {
                        $event = $api->get(self::org($api).'issues/'.$row['id'].'/events/latest/');
                        $frames = [];

                        foreach ((array) ($event['entries'] ?? []) as $entry) {
                            if (($entry['type'] ?? '') === 'exception') {
                                foreach ((array) data_get($entry, 'data.values', []) as $value) {
                                    $frames[] = ($value['type'] ?? '').': '.($value['value'] ?? '');
                                    foreach (array_slice(array_reverse((array) data_get($value, 'stacktrace.frames', [])), 0, 12) as $frame) {
                                        $frames[] = '  at '.($frame['filename'] ?? $frame['module'] ?? '?').':'.($frame['lineNo'] ?? '?').' '.($frame['function'] ?? '');
                                    }
                                }
                            }
                        }

                        return Outcome::show((string) ($row['shortId'] ?? ''), trim(($event['title'] ?? '')."\n".($event['dateCreated'] ?? '')."\n\n".implode("\n", $frames)."\n\n".implode("\n", array_map(fn ($t) => ($t['key'] ?? '').': '.($t['value'] ?? ''), (array) ($event['tags'] ?? [])))));
                    }),
                Action::make('resolve', __('Resolve'))->icon('check-circle')->when(fn ($row) => ($row['status'] ?? '') !== 'resolved')->run($status('resolved')),
                Action::make('resolve-next', __('Resolve in the next release'))->icon('rocket')->when(fn ($row) => ($row['status'] ?? '') !== 'resolved')->run($status('resolved', ['inNextRelease' => true])),
                Action::make('ignore', __('Ignore'))->icon('eye-off')->when(fn ($row) => ($row['status'] ?? '') === 'unresolved')->run($status('ignored')),
                Action::make('reopen', __('Reopen'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['status'] ?? '') !== 'unresolved')->run($status('unresolved')),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the issue and its events?'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Issue deleted.'), fn () => $api->delete(self::org($api).'issues/'.$row['id'].'/'))),
            ]);
    }

    private function projects(): Section
    {
        return Section::make('projects', __('Projects'))
            ->icon('apps')
            ->id('slug')
            ->list(fn (ScopedClient $api) => array_values(array_filter($api->get(self::org($api).'projects/', ['per_page' => 100]), 'is_array')))
            ->columns([
                Column::make('name', __('Project')),
                Column::make('slug', __('Slug'))->mono(),
                Column::make('platform', __('Platform')),
                Column::make('dateCreated', __('Created'))->date(),
            ])
            ->create(__('New project'), [
                Field::text('name', __('Name'))->required(),
                Field::select('team', __('Team'), fn (ScopedClient $api) => collect($api->get(self::org($api).'teams/'))->mapWithKeys(fn ($t) => [(string) $t['slug'] => (string) ($t['name'] ?? $t['slug'])])->all())->required(),
                Field::select('platform', __('Platform'), ['php-laravel' => 'Laravel', 'php' => 'PHP', 'javascript' => 'JavaScript', 'javascript-vue' => 'Vue', 'javascript-react' => 'React', 'node' => 'Node.js', 'python' => 'Python'])->default('php-laravel'),
            ], function (ScopedClient $api, array $row, array $data) {
                $project = $api->post('/teams/'.rawurlencode($api->scope('org')).'/'.rawurlencode((string) $data['team']).'/projects/', ['name' => $data['name'], 'platform' => $data['platform'] ?? 'php-laravel']);

                return self::dsn($api, (string) ($project['slug'] ?? ''), __('Project created.'));
            })
            ->actions([
                Action::make('dsn', __('.env of the app'))->icon('code')
                    ->run(fn (ScopedClient $api, array $row) => self::dsn($api, (string) $row['slug'])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the project, its issues and events?'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Project deleted.'), fn () => $api->delete('/projects/'.rawurlencode($api->scope('org')).'/'.rawurlencode((string) $row['slug']).'/'))),
            ]);
    }

    private static function dsn(ScopedClient $api, string $project, ?string $message = null): Outcome
    {
        $keys = $api->get('/projects/'.rawurlencode($api->scope('org')).'/'.rawurlencode($project).'/keys/');
        $dsn = (string) data_get($keys, '0.dsn.public', '');

        return Outcome::show(__('.env of :project', ['project' => $project]), S3Sections::env([
            'SENTRY_LARAVEL_DSN' => $dsn,
            'SENTRY_TRACES_SAMPLE_RATE' => '0.1',
        ])."\n\n# ".__('In the app: composer require sentry/sentry-laravel && php artisan sentry:publish --dsn=…'), $message);
    }

    private function releases(): Section
    {
        return Section::make('releases', __('Releases'))
            ->icon('rocket')
            ->id('version')
            ->list(fn (ScopedClient $api) => array_values(array_filter($api->get(self::org($api).'releases/', ['per_page' => 50]), 'is_array')))
            ->columns([
                Column::make('shortVersion', __('Release'))->mono()->using(fn ($row) => ($row['shortVersion'] ?? '') ?: ($row['version'] ?? '')),
                Column::make('projects', __('Projects'))->using(fn ($row) => implode(', ', array_column((array) ($row['projects'] ?? []), 'slug'))),
                Column::make('newGroups', __('New issues')),
                Column::make('lastDeploy.environment', __('Deployed to')),
                Column::make('dateCreated', __('Created'))->date(),
            ])
            ->create(__('New release'), [
                Field::text('version', __('Version'))->required()->placeholder('myapp@1.4.0 or a commit'),
                Field::multiselect('projects', __('Projects'), fn (ScopedClient $api) => self::projectOptions($api, false))->required(),
            ], fn (ScopedClient $api, array $row, array $data) => tap(__('Release created.'), fn () => $api->post(self::org($api).'releases/', ['version' => $data['version'], 'projects' => array_values((array) $data['projects'])])))
            ->actions([
                Action::make('deploy', __('Mark deployed'))->icon('rocket')
                    ->fields([Field::text('environment', __('Environment'))->required()->default('production')])
                    ->run(fn (ScopedClient $api, array $row, array $data) => tap(__('Deploy recorded.'), fn () => $api->post(self::org($api).'releases/'.rawurlencode((string) $row['version']).'/deploys/', ['environment' => $data['environment']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the release? Issues keep their events.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Release deleted.'), fn () => $api->delete(self::org($api).'releases/'.rawurlencode((string) $row['version']).'/'))),
            ]);
    }
}
