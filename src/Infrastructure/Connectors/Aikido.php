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
use CipiGui\Infrastructure\Tokens;

/**
 * Aikido Security, with a client of its public REST API in any region: the
 * open issue groups by severity and type, how to fix each, ignored or
 * snoozed; code repositories and their scans; containers; domains scanned
 * from outside; Zen firewall apps, blocking or only detecting.
 * https://apidocs.aikido.dev
 */
final class Aikido extends Connector
{
    private const REGIONS = ['eu' => 'app.aikido.dev', 'us' => 'app.us.aikido.dev', 'au' => 'app.au.aikido.dev', 'me' => 'app.me.aikido.dev'];

    private const SEVERITY = ['critical' => 'red', 'high' => 'red', 'medium' => 'yellow', 'low' => 'zinc'];

    private const TYPES = [
        '' => 'Every kind', 'open_source' => 'Dependencies (SCA)', 'leaked_secret' => 'Leaked secrets', 'sast' => 'Code (SAST)', 'iac' => 'Infrastructure as code',
        'docker_container' => 'Containers', 'cloud' => 'Cloud', 'surface_monitoring' => 'Domains (DAST)', 'malware' => 'Malware', 'eol' => 'End of life', 'license' => 'Licenses',
    ];

    public function key(): string
    {
        return 'aikido';
    }

    public function label(): string
    {
        return 'Aikido';
    }

    public function description(): string
    {
        return __('Open issues by severity with how to fix them, ignore or snooze; repositories and their scans; containers; domains scanned from outside; Zen firewall apps.');
    }

    public function icon(): string
    {
        return 'shield';
    }

    public function category(): string
    {
        return 'security';
    }

    public function docs(): string
    {
        return 'https://apidocs.aikido.dev/reference/authorization';
    }

    public function fields(): array
    {
        return [
            Field::select('region', __('Region'), ['eu' => __('Europe (app.aikido.dev)'), 'us' => __('United States'), 'au' => __('Australia'), 'me' => __('Middle East')])->required()->default('eu'),
            Field::text('client_id', __('Client ID'))->required(),
            Field::password('client_secret', __('Client secret'))->required(),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Aikido → Settings → Integrations → Public REST API → Add client (workspace admins only; the plan must include the REST API).'),
            __('Scopes: basics:read, issues:read, issues:write, repositories:read, repositories:write, domains:read, domains:write, firewall:read, firewall:write.'),
            __('The region is the one in the address of Aikido: app.aikido.dev is Europe, app.us.aikido.dev the US.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        $host = self::REGIONS[self::string($credentials, 'region', 'eu')] ?? self::REGIONS['eu'];
        $id = self::string($credentials, 'client_id');
        $secret = self::string($credentials, 'client_secret');

        if ($id === '' || $secret === '') {
            throw new ConnectorException(__('The client ID and its secret are both needed.'));
        }

        $token = Tokens::oauth('https://'.$host.'/api/oauth/token', ['grant_type' => 'client_credentials'], [$id, $secret]);

        return new ScopedClient('https://'.$host.'/api/public/v1', ['Authorization' => 'Bearer '.$token], ['web' => 'https://'.$host]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $workspace = $api->get('/workspace');
        $counts = $api->get('/issues/counts');

        return 'ok · '.($workspace['name'] ?? '').' · '.trans_choice(':count open issue|:count open issues', (int) data_get($counts, 'issue_groups.all', 0));
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->issues('issues', false),
            $this->repositories(),
            $this->issues('repo-issues', true),
            $this->containers(),
            $this->domains(),
            $this->firewall(),
        ];
    }

    /**
     * Every page of a zero-based list, until a page comes back short.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function pages(ScopedClient $api, string $path, array $query = [], int $perPage = 100, int $pages = 5): array
    {
        $rows = [];

        for ($page = 0; $page < $pages; $page++) {
            $batch = array_values(array_filter($api->get($path, [...$query, 'page' => $page, 'per_page' => $perPage]), 'is_array'));
            $rows = [...$rows, ...$batch];

            if (count($batch) < $perPage) {
                break;
            }
        }

        return $rows;
    }

    private static function when(mixed $timestamp): string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('c', (int) $timestamp) : '';
    }

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (ScopedClient $api) {
            $counts = $api->get('/issues/counts');
            $workspace = $api->get('/workspace');

            return [
                __('Open issue groups') => array_intersect_key((array) ($counts['issue_groups'] ?? []), array_flip(['critical', 'high', 'medium', 'low', 'all'])),
                __('Open issues') => array_intersect_key((array) ($counts['issues'] ?? []), array_flip(['critical', 'high', 'medium', 'low', 'all'])),
                __('Workspace') => [
                    __('Name') => $workspace['name'] ?? '',
                    __('Git provider') => trim(($workspace['linked_provider'] ?? '').' '.($workspace['linked_provider_org_name'] ?? '')),
                ],
            ];
        });
    }

    private function issues(string $key, bool $perRepository): Section
    {
        $section = Section::make($key, $perRepository ? __('Issues of the repository') : __('Issues'))
            ->icon('shield')
            ->filters([
                Field::select('status', __('Show'), ['open' => __('Open'), 'snoozed' => __('Snoozed'), 'ignored' => __('Ignored'), 'closed' => __('Closed')])->default('open'),
                Field::select('type', __('Kind'), self::TYPES),
            ])
            ->list(fn (ScopedClient $api, array $scope) => array_map(fn ($row) => [...$row, '_status' => (string) ($scope['status'] ?? 'open')], self::pages($api, '/open-issue-groups', array_filter([
                'filter_status' => (string) ($scope['status'] ?? 'open'),
                'filter_issue_type' => (string) ($scope['type'] ?? ''),
                'filter_code_repo_id' => (string) ($scope['repo'] ?? ''),
            ]))))
            ->columns([
                Column::make('severity', __('Severity'))->badge(self::SEVERITY),
                Column::make('title', __('Issue'))->using(fn ($row) => mb_substr((string) ($row['title'] ?? ''), 0, 140)),
                Column::make('type', __('Kind'))->using(fn ($row) => self::TYPES[(string) ($row['type'] ?? '')] ?? (string) ($row['type'] ?? '')),
                Column::make('where', __('Where'))->using(fn ($row) => implode(', ', array_slice(array_column((array) ($row['locations'] ?? []), 'name'), 0, 3))),
                Column::make('time_to_fix_minutes', __('Fix'))->using(fn ($row) => isset($row['time_to_fix_minutes']) ? $row['time_to_fix_minutes'].' min' : ''),
                Column::make('first_detected_at', __('Found'))->date()->using(fn ($row) => self::when($row['first_detected_at'] ?? null)),
            ])
            ->actions([
                Action::make('fix', __('How to fix'))->icon('light-bulb')
                    ->run(fn (ScopedClient $api, array $row) => Outcome::show((string) ($row['title'] ?? ''), trim(
                        (string) ($row['description'] ?? '')."\n\n".(string) ($row['how_to_fix'] ?? '')
                        .(($row['related_cve_ids'] ?? []) !== [] ? "\n\nCVE: ".implode(', ', (array) $row['related_cve_ids']) : '')
                        ."\n\n".implode("\n", array_map(fn ($l) => '· '.($l['type'] ?? '').' '.($l['name'] ?? ''), (array) ($row['locations'] ?? []))),
                    ))),
                Action::make('snooze', __('Snooze'))->icon('clock')->when(fn ($row) => ($row['_status'] ?? 'open') === 'open')
                    ->fields([
                        Field::number('days', __('For (days)'))->required()->default(30)->rules(['min:1', 'max:365']),
                        Field::text('reason', __('Why')),
                    ])
                    ->run(fn (ScopedClient $api, array $row, array $data) => tap(__('Snoozed.'), fn () => $api->put('/issues/groups/'.$row['id'].'/snooze', array_filter(['snooze_until' => time() + (int) $data['days'] * 86400, 'reason' => $data['reason'] ?? null])))),
                Action::make('ignore', __('Ignore'))->icon('eye-off')->when(fn ($row) => in_array($row['_status'] ?? 'open', ['open', 'snoozed'], true))->confirm(__('Ignore the issue group? It leaves the open list for good.'))
                    ->fields([Field::text('reason', __('Why'))->placeholder(__('Not reachable in our code'))])
                    ->run(fn (ScopedClient $api, array $row, array $data) => tap(__('Ignored.'), fn () => $api->put('/issues/groups/'.$row['id'].'/ignore', array_filter(['reason' => $data['reason'] ?? null])))),
                Action::make('unsnooze', __('Wake up'))->icon('bell-alert')->when(fn ($row) => ($row['_status'] ?? '') === 'snoozed')
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Back in the open list.'), fn () => $api->put('/issues/groups/'.$row['id'].'/unsnooze'))),
                Action::make('unignore', __('Stop ignoring'))->icon('arrow-uturn-left')->when(fn ($row) => ($row['_status'] ?? '') === 'ignored')
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Back in the open list.'), fn () => $api->put('/issues/groups/'.$row['id'].'/unignore'))),
            ]);

        return $perRepository ? $section->childOf('repositories', 'repo_name') : $section;
    }

    private function repositories(): Section
    {
        return Section::make('repositories', __('Repositories'))
            ->icon('code')
            ->list(fn (ScopedClient $api) => self::pages($api, '/repositories/code', [], 200))
            ->columns([
                Column::make('name', __('Repository'))->mono()->link('url'),
                Column::make('provider', __('Provider')),
                Column::make('branch', __('Branch'))->mono(),
                Column::make('connectivity', __('Internet'))->badge(['connected' => 'yellow', 'not_connected' => 'zinc', '*' => 'zinc']),
                Column::make('last_scanned_at', __('Scanned'))->date()->using(fn ($row) => self::when($row['last_scanned_at'] ?? null)),
            ])
            ->actions([
                Action::make('issues', __('Issues'))->icon('shield')
                    ->opens('repo-issues', fn (array $row) => ['repo' => (string) $row['id'], 'repo_name' => (string) $row['name']]),
                Action::make('scan', __('Scan now'))->icon('play')
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Scan started: dependencies, code, IaC and secrets.'), fn () => $api->post('/repositories/code/'.$row['id'].'/scan?include_sast_scan=true&include_iac_scan=true&include_secrets_scan=true'))),
            ]);
    }

    private function containers(): Section
    {
        return Section::make('containers', __('Containers'))
            ->icon('cube')
            ->list(fn (ScopedClient $api) => self::pages($api, '/containers', ['filter_status' => 'all']))
            ->columns([
                Column::make('name', __('Image'))->mono(),
                Column::make('tag', __('Tag'))->mono(),
                Column::make('registry_name', __('Registry')),
                Column::make('distro', __('Distro'))->using(fn ($row) => trim(($row['distro'] ?? '').' '.($row['distro_version'] ?? ''))),
                Column::make('last_scanned_at', __('Scanned'))->date()->using(fn ($row) => self::when($row['last_scanned_at'] ?? null)),
            ]);
    }

    private function domains(): Section
    {
        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->list(fn (ScopedClient $api) => self::pages($api, '/domains'))
            ->columns([
                Column::make('domain', __('Domain'))->mono(),
                Column::make('kind', __('Kind'))->badge(['front_end' => 'blue', 'rest_api' => 'blue', 'graphql_api' => 'blue', 'attack_surface' => 'zinc', '*' => 'zinc']),
                Column::make('is_auth_configured', __('Signed in'))->bool(),
                Column::make('last_scanned_at', __('Scanned'))->date()->using(fn ($row) => self::when($row['last_scanned_at'] ?? null)),
            ])
            ->create(__('New domain'), [
                Field::text('domain', __('Domain'))->required()->placeholder('myapp.example.com'),
                Field::select('kind', __('What it is'), ['front_end' => __('A web app'), 'rest_api' => __('A REST API'), 'graphql_api' => __('A GraphQL API'), 'attack_surface' => __('Only its surface (ports, TLS, headers)')])->default('front_end'),
                Field::text('openapi_spec_url', __('OpenAPI spec address'))->placeholder('https://myapp.example.com/api/openapi.json')->help(__('Needed for a REST or GraphQL API.')),
            ], fn (ScopedClient $api, array $row, array $data) => tap(__('Domain added: the first scan follows.'), fn () => $api->post('/domains', array_filter($data))))
            ->actions([
                Action::make('scan', __('Scan now'))->icon('play')
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Scan started.'), fn () => $api->post('/domains/scan', ['domain_id' => (int) $row['id']]))),
                Action::make('delete', __('Remove'))->icon('trash')->danger(__('Remove the domain from Aikido? Its findings go with it.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Domain removed.'), fn () => $api->delete('/domains/'.$row['id']))),
            ]);
    }

    private function firewall(): Section
    {
        return Section::make('zen', __('Zen firewall'))
            ->icon('shield-check')
            ->description(__('The apps protected by Zen, Aikido\'s in-app firewall (a PHP extension for Laravel).'))
            ->list(fn (ScopedClient $api) => array_values(array_filter($api->get('/firewall/apps'), 'is_array')))
            ->columns([
                Column::make('name', __('App')),
                Column::make('environment', __('Environment'))->badge(['production' => 'blue', 'staging' => 'zinc', 'development' => 'zinc']),
                Column::make('blocking', __('Blocking'))->bool(),
                Column::make('code_repo_name', __('Repository'))->mono(),
                Column::make('has_token', __('Token'))->bool(),
            ])
            ->actions([
                Action::make('block', __('Block attacks'))->icon('shield-check')->when(fn ($row) => ! ($row['blocking'] ?? false))
                    ->confirm(__('Switch Zen to blocking? Requests it sees as attacks are refused from now on.'))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Zen blocks attacks.'), fn () => $api->put('/firewall/apps/'.$row['id'].'/blocking', ['block' => true]))),
                Action::make('detect', __('Only detect'))->icon('eye')->when(fn ($row) => (bool) ($row['blocking'] ?? false))
                    ->run(fn (ScopedClient $api, array $row) => tap(__('Zen only reports now.'), fn () => $api->put('/firewall/apps/'.$row['id'].'/blocking', ['block' => false]))),
            ]);
    }
}
