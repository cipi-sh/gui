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
use CipiGui\Infrastructure\ServerTargets;

/**
 * Cloudflare, with an API token per account: zones and their cache, SSL and
 * security settings, DNS records, and on the account Workers, Pages, R2 and
 * tunnels. https://developers.cloudflare.com/api/
 */
final class Cloudflare extends Connector
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'HTTPS', 'SVCB'];

    public function key(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function description(): string
    {
        return __('Zones, DNS records (to a server of the panel by its name), proxy, cache purge, SSL and security settings, IP access rules, Workers, Pages, R2 buckets and tunnels.');
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function category(): string
    {
        return 'dns';
    }

    public function alsoIn(): array
    {
        return ['storage' => __('R2: buckets, custom domains, the public r2.dev address, CORS and expiry rules.')];
    }

    public function docs(): string
    {
        return 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('API token'))->required()
                ->help(__('An API token, not the Global API Key. One token can reach several Cloudflare accounts: each is a filter of the page.')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('dash.cloudflare.com → My Profile → API Tokens → Create Token → Create Custom Token.'),
            __('Zone permissions: Zone — Edit (add, pause, delete zones), DNS — Edit, Zone Settings — Edit, Cache Purge — Purge, Firewall Services — Edit (IP access rules).'),
            __('Account permissions, for what you use: Account Settings — Read (the account names), Workers Scripts — Edit, Cloudflare Pages — Edit, Workers R2 Storage — Edit, Cloudflare Tunnel — Read.'),
            __('Zone Resources: All zones (or the account or the zones the panel manages). Client IP filtering: the panel server\'s address, if you want it tighter.'),
            __('Read-only is possible: the same permissions as Read; the actions that change something then answer 403.'),
            __('The servers\' Zero Trust token (cipi zt token set) is a different one: it lives on each server, this one in the panel.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.cloudflare.com/client/v4', ['Authorization' => 'Bearer '.self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $zones = $api->get('/zones', ['per_page' => 5]);
        $accounts = array_column((array) ($api->get('/accounts', ['per_page' => 5])['result'] ?? []), 'name');

        return 'ok · '.trim(implode(', ', $accounts).' · '.trans_choice(':count zone|:count zones', (int) data_get($zones, 'result_info.total_count', 0)), ' ·');
    }

    public function sections(): array
    {
        return [
            $this->zones(),
            $this->records(),
            $this->accessRules(),
            $this->workers(),
            $this->pages(),
            $this->r2(),
            $this->r2Domains(),
            $this->tunnels(),
        ];
    }

    /**
     * Every page of a list: Cloudflare answers with result_info.total_pages.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $perPage = 50): array
    {
        $query = ['per_page' => $perPage, ...$query];

        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            $query,
            fn (array $answer) => array_values((array) ($answer['result'] ?? [])),
            fn (array $answer, int $page) => $page < (int) data_get($answer, 'result_info.total_pages', 1) ? [...$query, 'page' => $page + 1] : null,
        );
    }

    /** @return array<string, string> */
    private static function accounts(ApiClient $api): array
    {
        return collect(self::every($api, '/accounts'))->mapWithKeys(fn ($a) => [(string) $a['id'] => (string) $a['name']])->all();
    }

    /**
     * The account picked in the filter, or the token's first one.
     *
     * @param  array<string, mixed>  $scope
     */
    private static function account(ApiClient $api, array $scope): string
    {
        $account = $scope['account'] ?? null;

        if (is_string($account) && $account !== '') {
            return $account;
        }

        return (string) (array_key_first(self::accounts($api)) ?? throw new ConnectorException(__('The token reaches no Cloudflare account.')));
    }

    private static function accountFilter(): Field
    {
        return Field::select('account', __('Account'), fn (ApiClient $api) => self::accounts($api))->help(__('the first one'));
    }

    /** PATCH a zone setting. */
    private static function setting(ApiClient $api, string $zone, string $setting, mixed $value): void
    {
        $api->patch('/zones/'.$zone.'/settings/'.$setting, ['value' => $value]);
    }

    private function zones(): Section
    {
        $keep = ['' => __('— keep —')];

        return Section::make('zones', __('Zones'))
            ->icon('globe-europe-africa')
            ->list(fn (ApiClient $api) => self::every($api, '/zones'))
            ->columns([
                Column::make('name', __('Zone')),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'pending' => 'yellow', 'moved' => 'red', '*' => 'zinc']),
                Column::make('paused', __('Paused'))->bool(),
                Column::make('plan.name', __('Plan')),
                Column::make('name_servers', __('Nameservers'))->mono(),
                Column::make('account.name', __('Account')),
            ])
            ->create(__('Add a zone'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::select('account', __('Account'), fn (ApiClient $api) => self::accounts($api))->required(),
                Field::checkbox('jump_start', __('Import the existing DNS records'))->default(true),
            ], function (ApiClient $api, array $row, array $data) {
                $zone = $api->post('/zones', ['name' => $data['name'], 'account' => ['id' => $data['account']], 'type' => 'full', 'jump_start' => $data['jump_start']])['result'] ?? [];

                return Outcome::show(__('Nameservers for :zone', ['zone' => $data['name']]), $zone['name_servers'] ?? [], __('Zone added: set these nameservers at the registrar.'));
            })
            ->actions([
                Action::make('records', __('DNS records'))->icon('list-bullet')->opens('records', fn ($row) => ['zone' => $row['id'], 'zone_name' => $row['name']]),
                Action::make('access-rules', __('IP access rules'))->icon('shield-check')->opens('access_rules', fn ($row) => ['zone' => $row['id'], 'zone_name' => $row['name']]),
                Action::make('purge', __('Purge everything'))->icon('fire')->confirm(__('Purge every cached file of the zone?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Cache purged.'), fn () => $api->post('/zones/'.$row['id'].'/purge_cache', ['purge_everything' => true]))),
                Action::make('purge-urls', __('Purge URLs'))->icon('fire')->fields([Field::textarea('files', __('URLs'))->required()->help(__('One per line, up to 30'))])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('URLs purged.'), fn () => $api->post('/zones/'.$row['id'].'/purge_cache', ['files' => self::lines($data['files'])]))),
                Action::make('settings', __('Settings'))->icon('adjustments-horizontal')->fields([
                    Field::select('ssl', __('SSL/TLS mode'), [...$keep, 'off' => 'Off', 'flexible' => 'Flexible', 'full' => 'Full', 'strict' => 'Full (strict)']),
                    Field::select('always_use_https', __('Always use HTTPS'), [...$keep, 'on' => __('On'), 'off' => __('Off')]),
                    Field::select('min_tls_version', __('Minimum TLS version'), [...$keep, '1.0' => '1.0', '1.1' => '1.1', '1.2' => '1.2', '1.3' => '1.3']),
                    Field::select('security_level', __('Security level'), [...$keep, 'essentially_off' => __('Essentially off'), 'low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'under_attack' => __('I\'m under attack')]),
                    Field::select('development_mode', __('Development mode (no cache, 3 hours)'), [...$keep, 'on' => __('On'), 'off' => __('Off')]),
                    Field::select('brotli', __('Brotli'), [...$keep, 'on' => __('On'), 'off' => __('Off')]),
                ])->run(function (ApiClient $api, array $row, array $data) {
                    $changed = array_filter($data, fn ($value) => $value !== null && $value !== '');

                    foreach ($changed as $setting => $value) {
                        self::setting($api, $row['id'], $setting, $value);
                    }

                    return $changed === [] ? __('Nothing changed.') : __('Settings saved.');
                }),
                Action::make('show-settings', __('Current settings'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show($row['name'], collect(self::items($api->get('/zones/'.$row['id'].'/settings')['result'] ?? []))
                        ->mapWithKeys(fn ($s) => [(string) $s['id'] => $s['value'] ?? null])->sortKeys()->all())),
                Action::make('pause', __('Pause Cloudflare'))->icon('pause')->confirm(__('Traffic goes straight to the origin, without proxy, cache or protection. Pause?'))->when(fn ($row) => empty($row['paused']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone paused.'), fn () => $api->patch('/zones/'.$row['id'], ['paused' => true]))),
                Action::make('unpause', __('Resume Cloudflare'))->icon('play')->when(fn ($row) => ! empty($row['paused']))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone resumed.'), fn () => $api->patch('/zones/'.$row['id'], ['paused' => false]))),
                Action::make('check', __('Check activation'))->icon('arrow-path')->when(fn ($row) => ($row['status'] ?? '') === 'pending')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Activation check requested.'), fn () => $api->put('/zones/'.$row['id'].'/activation_check'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Remove the zone and all its records from Cloudflare?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Zone deleted.'), fn () => $api->delete('/zones/'.$row['id']))),
            ]);
    }

    private function records(): Section
    {
        $fields = [
            Field::select('type', __('Type'), array_combine(self::TYPES, self::TYPES))->required()->default(fn ($row) => $row['type'] ?? 'A'),
            Field::text('name', __('Name'))->required()->default(fn ($row) => $row['name'] ?? '@')->help(__('@ for the zone itself')),
            Field::suggest('content', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['content'] ?? null) ?? ($row['content'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA): saved as its IP.')),
            Field::checkbox('proxied', __('Proxied (orange cloud)'))->default(fn ($row) => (bool) ($row['proxied'] ?? false)),
            Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? 1)->help(__('1 is automatic')),
            Field::number('priority', __('Priority (MX)'))->default(fn ($row) => $row['priority'] ?? null),
            Field::text('comment', __('Comment'))->default(fn ($row) => $row['comment'] ?? null),
        ];
        $body = fn (array $data) => array_filter([...$data, 'content' => ServerTargets::resolve($data['type'] ?? null, $data['content'] ?? null), 'ttl' => $data['ttl'] ?? 1], fn ($value) => $value !== null);

        return Section::make('records', __('DNS records'))
            ->childOf('zones', 'zone_name')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/zones/'.$scope['zone'].'/dns_records', perPage: 500))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('content', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['content'] ?? null)),
                Column::make('proxied', __('Proxied'))->bool(),
                Column::make('ttl', __('TTL'))->using(fn ($row) => ($row['ttl'] ?? 1) === 1 ? __('auto') : $row['ttl']),
                Column::make('comment', __('Comment')),
            ])
            ->create(__('New record'), $fields, fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => $api->post('/zones/'.$scope['zone'].'/dns_records', $body($data))))
            ->toolbar([
                Action::make('export', __('Export (BIND)'))->icon('document-arrow-down')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show(__('Zone file of :zone', ['zone' => $scope['zone_name'] ?? '']), $api->text('/zones/'.$scope['zone'].'/dns_records/export'))),
            ])
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields)
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => $api->patch('/zones/'.$scope['zone'].'/dns_records/'.$row['id'], $body($data)))),
                Action::make('proxy', __('Proxy on'))->icon('cloud')->when(fn ($row) => empty($row['proxied']) && in_array($row['type'] ?? '', ['A', 'AAAA', 'CNAME'], true))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Proxied.'), fn () => $api->patch('/zones/'.$scope['zone'].'/dns_records/'.$row['id'], ['proxied' => true]))),
                Action::make('unproxy', __('Proxy off (DNS only)'))->icon('cloud')->when(fn ($row) => ! empty($row['proxied']))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('DNS only.'), fn () => $api->patch('/zones/'.$scope['zone'].'/dns_records/'.$row['id'], ['proxied' => false]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => $api->delete('/zones/'.$scope['zone'].'/dns_records/'.$row['id']))),
            ]);
    }

    /** Allow, block or challenge an address, a range, a country or an AS on a zone. */
    private function accessRules(): Section
    {
        $targets = ['ip' => __('IP address'), 'ip_range' => __('IP range (CIDR)'), 'country' => __('Country (2 letters)'), 'asn' => __('AS number (AS13335)')];
        $modes = ['block' => __('Block'), 'challenge' => __('Challenge'), 'managed_challenge' => __('Managed challenge'), 'js_challenge' => __('JavaScript challenge'), 'whitelist' => __('Allow')];

        return Section::make('access_rules', __('IP access rules'))
            ->childOf('zones', 'zone_name')
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/zones/'.$scope['zone'].'/firewall/access_rules/rules', perPage: 100))
            ->columns([
                Column::make('mode', __('Action'))->badge(['block' => 'red', 'challenge' => 'yellow', 'managed_challenge' => 'yellow', 'js_challenge' => 'yellow', 'whitelist' => 'green', '*' => 'zinc']),
                Column::make('configuration.value', __('Who'))->mono(),
                Column::make('configuration.target', __('Kind')),
                Column::make('scope.type', __('Set on'))->using(fn ($row) => ($row['scope']['type'] ?? '') === 'zone' ? __('this zone') : __('the account')),
                Column::make('notes', __('Notes')),
                Column::make('created_on', __('Created'))->date(),
            ])
            ->create(__('New rule'), [
                Field::select('mode', __('Action'), $modes)->required()->default('block'),
                Field::select('target', __('Who'), $targets)->required()->default('ip'),
                Field::suggest('value', __('Value'), fn () => ServerTargets::options())->required()->help(__('203.0.113.7, 203.0.113.0/24, IT, AS13335 — or a server of the panel by name.')),
                Field::text('notes', __('Notes')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule created.'), fn () => $api->post('/zones/'.$scope['zone'].'/firewall/access_rules/rules', array_filter([
                'mode' => $data['mode'],
                'configuration' => ['target' => $data['target'], 'value' => in_array($data['target'], ['ip'], true) ? self::serverAddress($data['value']) : $data['value']],
                'notes' => $data['notes'],
            ], fn ($v) => $v !== null))))
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this rule?'))->when(fn ($row) => ($row['scope']['type'] ?? '') === 'zone')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Rule deleted.'), fn () => $api->delete('/zones/'.$scope['zone'].'/firewall/access_rules/rules/'.$row['id']))),
            ]);
    }

    /** A server of the panel by name, as its address; anything else as typed. */
    private static function serverAddress(mixed $value): mixed
    {
        foreach (ServerTargets::servers() as $name => $ip) {
            if (is_string($value) && strcasecmp($name, trim($value)) === 0) {
                return $ip;
            }
        }

        return $value;
    }

    private function workers(): Section
    {
        return Section::make('workers', __('Workers'))
            ->icon('code-bracket')
            ->filters([self::accountFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/accounts/'.self::account($api, $scope).'/workers/scripts', perPage: 100))
            ->columns([
                Column::make('id', __('Script'))->mono(),
                Column::make('usage_model', __('Usage model')),
                Column::make('modified_on', __('Modified'))->date(),
                Column::make('created_on', __('Created'))->date(),
            ])
            ->actions([
                Action::make('settings', __('Settings'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show($row['id'], $api->get('/accounts/'.self::account($api, $scope).'/workers/scripts/'.$row['id'].'/settings')['result'] ?? [])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the Worker?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Worker deleted.'), fn () => $api->delete('/accounts/'.self::account($api, $scope).'/workers/scripts/'.$row['id']))),
            ]);
    }

    private function pages(): Section
    {
        return Section::make('pages', __('Pages'))
            ->icon('document-duplicate')
            ->id('name')
            ->filters([self::accountFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/accounts/'.self::account($api, $scope).'/pages/projects', perPage: 10))
            ->columns([
                Column::make('name', __('Project')),
                Column::make('subdomain', __('Address'))->mono(),
                Column::make('domains', __('Domains'))->mono(),
                Column::make('production_branch', __('Branch'))->mono(),
                Column::make('latest_deployment.latest_stage.status', __('Last deploy'))->badge(['success' => 'green', 'failure' => 'red', '*' => 'yellow']),
            ])
            ->actions([
                Action::make('deploy', __('Deploy'))->icon('rocket-launch')->confirm(__('Build and deploy the production branch again? Only for projects connected to git.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Deployment started.'), fn () => $api->post('/accounts/'.self::account($api, $scope).'/pages/projects/'.$row['name'].'/deployments'))),
                Action::make('deployments', __('Recent deployments'))->icon('clock')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show($row['name'], collect(self::items($api->get('/accounts/'.self::account($api, $scope).'/pages/projects/'.$row['name'].'/deployments', ['per_page' => 10])['result'] ?? []))
                        ->map(fn ($d) => ['url' => $d['url'] ?? null, 'environment' => $d['environment'] ?? null, 'branch' => data_get($d, 'deployment_trigger.metadata.branch'), 'status' => data_get($d, 'latest_stage.status'), 'created_on' => $d['created_on'] ?? null])->all())),
            ]);
    }

    private function r2(): Section
    {
        $bucket = fn (ApiClient $api, array $row, array $scope) => '/accounts/'.self::account($api, ['account' => $row['_account'] ?? $scope['account'] ?? null]).'/r2/buckets/'.rawurlencode((string) $row['name']);

        return Section::make('r2', __('R2 buckets'))
            ->icon('archive-box')
            ->id('name')
            ->filters([self::accountFilter()])
            ->list(function (ApiClient $api, array $scope) {
                $account = self::account($api, $scope);

                return array_map(
                    fn ($b) => [...(array) $b, '_account' => $account],
                    array_values(array_filter((array) data_get($api->get('/accounts/'.$account.'/r2/buckets', ['per_page' => 1000]), 'result.buckets', []), 'is_array')),
                );
            })
            ->columns([
                Column::make('name', __('Bucket'))->mono(),
                Column::make('location', __('Location')),
                Column::make('storage_class', __('Class')),
                Column::make('creation_date', __('Created'))->date(),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/']),
                Field::select('locationHint', __('Location hint'), ['' => __('Automatic'), 'weur' => __('Western Europe'), 'eeur' => __('Eastern Europe'), 'wnam' => __('Western North America'), 'enam' => __('Eastern North America'), 'apac' => __('Asia-Pacific'), 'oc' => __('Oceania')]),
                Field::select('jurisdiction', __('Jurisdiction'), ['' => __('None'), 'eu' => __('EU (data stays in the EU)'), 'fedramp' => 'FedRAMP']),
            ], function (ApiClient $api, array $row, array $data, array $scope) {
                $body = array_filter(['name' => $data['name'], 'locationHint' => $data['locationHint'] ?? null]);
                $jurisdiction = (string) ($data['jurisdiction'] ?? '');
                $api->send('POST', '/accounts/'.self::account($api, $scope).'/r2/buckets', ['json' => $body, 'headers' => $jurisdiction !== '' ? ['cf-r2-jurisdiction' => $jurisdiction] : []]);

                return __('Bucket created.');
            })
            ->actions([
                Action::make('domains', __('Custom domains'))->icon('globe')
                    ->opens('r2-domains', fn (array $row) => ['bucket' => (string) $row['name'], 'account' => (string) ($row['_account'] ?? '')]),
                Action::make('public', __('Public r2.dev address'))->icon('link')
                    ->fields([Field::checkbox('enabled', __('Serve the bucket on its r2.dev address'))->help(__('For development: r2.dev is rate limited. Production goes on a custom domain.'))])
                    ->run(function (ApiClient $api, array $row, array $data, array $scope) use ($bucket) {
                        $managed = (array) ($api->put($bucket($api, $row, $scope).'/domains/managed', ['enabled' => (bool) ($data['enabled'] ?? false)])['result'] ?? []);

                        return ($managed['enabled'] ?? false)
                            ? Outcome::show(__('Public address of :bucket', ['bucket' => $row['name']]), 'https://'.($managed['domain'] ?? ''), __('Public on r2.dev.'))
                            : __('The r2.dev address is off.');
                    }),
                Action::make('cors', __('CORS'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show(__('CORS of :bucket', ['bucket' => $row['name']]), data_get($api->get($bucket($api, $row, $scope).'/cors'), 'result.rules', []) ?: __('No CORS rule.'))),
                Action::make('cors-set', __('Allow a site (CORS)'))->icon('globe')
                    ->fields([
                        Field::textarea('origins', __('Sites'))->required()->placeholder('https://app.example.com')->help(__('One per line. The rule replaces the bucket\'s CORS.')),
                        Field::multiselect('methods', __('Methods'), ['GET' => 'GET', 'HEAD' => 'HEAD', 'PUT' => 'PUT', 'POST' => 'POST', 'DELETE' => 'DELETE'])->default(['GET', 'HEAD', 'PUT']),
                    ])
                    ->run(function (ApiClient $api, array $row, array $data, array $scope) use ($bucket) {
                        $origins = S3Sections::origins($data['origins'] ?? '');
                        $api->put($bucket($api, $row, $scope).'/cors', ['rules' => [[
                            'allowed' => ['origins' => $origins, 'methods' => array_values((array) ($data['methods'] ?? [])) ?: ['GET', 'HEAD'], 'headers' => ['*']],
                            'exposeHeaders' => ['ETag'],
                            'maxAgeSeconds' => 3600,
                        ]]]);

                        return __('CORS set for :origins.', ['origins' => implode(', ', $origins)]);
                    }),
                Action::make('cors-delete', __('Remove CORS'))->icon('x')->confirm(__('Remove the CORS rules of the bucket?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('CORS removed.'), fn () => $api->delete($bucket($api, $row, $scope).'/cors'))),
                Action::make('lifecycle', __('Delete old objects'))->icon('clock')
                    ->fields([
                        Field::number('days', __('Delete objects after (days)'))->required()->default(30)->rules(['min:1']),
                        Field::text('prefix', __('Only under the prefix'))->placeholder('tmp/'),
                    ])
                    ->run(function (ApiClient $api, array $row, array $data, array $scope) use ($bucket) {
                        $days = max(1, (int) $data['days']);
                        $api->put($bucket($api, $row, $scope).'/lifecycle', ['rules' => [[
                            'id' => 'cipi-expire',
                            'enabled' => true,
                            'conditions' => ['prefix' => (string) ($data['prefix'] ?? '')],
                            'deleteObjectsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => $days * 86400]],
                            'abortMultipartUploadsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 7 * 86400]],
                        ]]]);

                        return __('Objects are deleted :days day(s) after upload; unfinished uploads after a week.', ['days' => $days]);
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Bucket deleted.'), fn () => $api->delete($bucket($api, $row, $scope)))),
            ]);
    }

    private function r2Domains(): Section
    {
        $path = fn (ApiClient $api, array $scope) => '/accounts/'.self::account($api, $scope).'/r2/buckets/'.rawurlencode((string) $scope['bucket']).'/domains/custom';

        return Section::make('r2-domains', __('Custom domains'))
            ->childOf('r2', 'bucket')
            ->id('domain')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter((array) data_get($api->get($path($api, $scope)), 'result.domains', []), 'is_array')))
            ->columns([
                Column::make('domain', __('Domain'))->mono()->link(fn ($row) => 'https://'.($row['domain'] ?? '')),
                Column::make('enabled', __('On'))->bool(),
                Column::make('status.ownership', __('Ownership'))->badge(['active' => 'green', 'pending' => 'yellow', '*' => 'red']),
                Column::make('status.ssl', __('Certificate'))->badge(['active' => 'green', 'pending' => 'yellow', 'initializing' => 'yellow', '*' => 'red']),
                Column::make('zoneName', __('Zone')),
            ])
            ->create(__('Connect a domain'), [
                Field::text('domain', __('Domain'))->required()->placeholder('cdn.example.com'),
                Field::select('zoneId', __('Zone'), fn (ApiClient $api) => collect(self::every($api, '/zones'))->mapWithKeys(fn ($z) => [(string) $z['id'] => (string) $z['name']])->all())->required()->help(__('The zone the domain is in: Cloudflare adds its record.')),
            ], fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Domain connected: the certificate follows in a few minutes.'), fn () => $api->post($path($api, $scope), ['domain' => strtolower(trim((string) $data['domain'])), 'zoneId' => $data['zoneId'], 'enabled' => true])))
            ->actions([
                Action::make('disable', __('Turn off'))->icon('pause')->when(fn ($row) => (bool) ($row['enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Domain off.'), fn () => $api->put($path($api, $scope).'/'.rawurlencode((string) $row['domain']), ['enabled' => false]))),
                Action::make('enable', __('Turn on'))->icon('play')->when(fn ($row) => ! ($row['enabled'] ?? false))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Domain on.'), fn () => $api->put($path($api, $scope).'/'.rawurlencode((string) $row['domain']), ['enabled' => true]))),
                Action::make('delete', __('Disconnect'))->icon('trash')->danger(__('Disconnect the domain from the bucket?'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Domain disconnected.'), fn () => $api->delete($path($api, $scope).'/'.rawurlencode((string) $row['domain'])))),
            ]);
    }

    private function tunnels(): Section
    {
        return Section::make('tunnels', __('Tunnels'))
            ->icon('arrows-right-left')
            ->filters([self::accountFilter()])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/accounts/'.self::account($api, $scope).'/cfd_tunnel', ['is_deleted' => 'false']))
            ->columns([
                Column::make('name', __('Tunnel')),
                Column::make('status', __('Status'))->badge(['healthy' => 'green', 'degraded' => 'yellow', 'down' => 'red', 'inactive' => 'zinc']),
                Column::make('connections', __('Connections'))->using(fn ($row) => count($row['connections'] ?? [])),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->create(__('New tunnel'), [
                Field::text('name', __('Name'))->required(),
            ], function (ApiClient $api, array $row, array $data, array $scope) {
                $account = self::account($api, $scope);
                $tunnel = $api->post('/accounts/'.$account.'/cfd_tunnel', ['name' => $data['name'], 'config_src' => 'cloudflare'])['result'] ?? [];

                return Outcome::show(__('Run the tunnel'), 'cloudflared tunnel run --token '.($tunnel['token'] ?? ''), __('Tunnel created.'));
            })
            ->actions([
                Action::make('token', __('Install command'))->icon('command-line')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show(__('Run the tunnel'), 'cloudflared tunnel run --token '.($api->get('/accounts/'.self::account($api, $scope).'/cfd_tunnel/'.$row['id'].'/token')['result'] ?? ''))),
                Action::make('routes', __('Public hostnames'))->icon('eye')
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => Outcome::show($row['name'], data_get($api->get('/accounts/'.self::account($api, $scope).'/cfd_tunnel/'.$row['id'].'/configurations'), 'result.config.ingress', []))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the tunnel? Stop its connectors first.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Tunnel deleted.'), fn () => $api->delete('/accounts/'.self::account($api, $scope).'/cfd_tunnel/'.$row['id']))),
            ]);
    }
}
