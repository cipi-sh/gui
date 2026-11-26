<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\ScopedClient;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;

/**
 * Porkbun, with an API key and its secret: the domains of the account with
 * their expiry, nameservers (set by hand or to the Cloudflare zone of the
 * domain), and the DNS records of Porkbun's DNS, pointed at a server of the
 * panel by its name. https://porkbun.com/api/json/v3/documentation
 */
final class Porkbun extends Connector
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'ALIAS', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'HTTPS', 'SVCB'];

    public function key(): string
    {
        return 'porkbun';
    }

    public function label(): string
    {
        return 'Porkbun';
    }

    public function description(): string
    {
        return __('Domains with their expiry and nameservers (by hand, or to their Cloudflare zone), DNS records pointed at a server of the panel by its name.');
    }

    public function icon(): string
    {
        return 'globe';
    }

    public function category(): string
    {
        return 'dns';
    }

    public function docs(): string
    {
        return 'https://kb.porkbun.com/article/190-getting-started-with-the-porkbun-api';
    }

    public function fields(): array
    {
        return [
            Field::password('api_key', __('API key'))->required()->placeholder('pk1_…'),
            Field::password('secret_api_key', __('Secret API key'))->required()->placeholder('sk1_…'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('porkbun.com → Account → API Access → Create API Key: the API key and the secret, shown once.'),
            __('Domain Management → each domain → Details → API Access: on. Without it Porkbun answers that the domain is not opted in.'),
        ];
    }

    public function client(array $credentials): ScopedClient
    {
        $key = self::string($credentials, 'api_key');
        $secret = self::string($credentials, 'secret_api_key');

        return new ScopedClient('https://api.porkbun.com/api/json/v3', ['X-API-Key' => $key, 'X-Secret-API-Key' => $secret], ['apikey' => $key, 'secretapikey' => $secret]);
    }

    public function verify(object $api): string
    {
        /** @var ScopedClient $api */
        $ping = self::call($api, '/ping');

        return 'ok · '.__('calls from :ip', ['ip' => $ping['yourIp'] ?? '?']);
    }

    public function sections(): array
    {
        return [
            $this->domains(),
            $this->records(),
        ];
    }

    /**
     * Every call is a POST with the keys in the body; status says how it went.
     *
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private static function call(ScopedClient $api, string $path, array $data = []): array
    {
        $answer = $api->post($path, ['apikey' => $api->scope('apikey'), 'secretapikey' => $api->scope('secretapikey'), ...$data]);

        if (($answer['status'] ?? 'SUCCESS') !== 'SUCCESS') {
            throw new ConnectorException((string) ($answer['message'] ?? __('Porkbun refused the request.')));
        }

        return $answer;
    }

    /** "www" out of "www.example.com" in example.com; "" for the domain itself. */
    private static function sub(string $name, string $domain): string
    {
        $name = rtrim(strtolower($name), '.');

        return $name === $domain ? '' : (string) preg_replace('/\.'.preg_quote($domain, '/').'$/', '', $name);
    }

    private function domains(): Section
    {
        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->id('domain')
            ->list(function (ScopedClient $api) {
                $rows = [];

                for ($start = 0; $start < 10000; $start += 1000) {
                    $batch = array_values(array_filter((array) (self::call($api, '/domain/listAll', ['start' => (string) $start, 'includeLabels' => 'yes'])['domains'] ?? []), 'is_array'));
                    $rows = [...$rows, ...$batch];

                    if (count($batch) < 1000) {
                        break;
                    }
                }

                return $rows;
            })
            ->columns([
                Column::make('domain', __('Domain'))->mono(),
                Column::make('status', __('Status'))->badge(['active' => 'green', '*' => 'yellow']),
                Column::make('expireDate', __('Expires'))->date(),
                Column::make('autoRenew', __('Auto-renew'))->using(fn ($row) => (bool) (int) ($row['autoRenew'] ?? 0))->bool(),
                Column::make('securityLock', __('Locked'))->using(fn ($row) => (bool) (int) ($row['securityLock'] ?? 0))->bool(),
                Column::make('whoisPrivacy', __('Privacy'))->using(fn ($row) => (bool) (int) ($row['whoisPrivacy'] ?? 0))->bool(),
            ])
            ->actions([
                Action::make('records', __('DNS records'))->icon('list-bullet')
                    ->opens('records', fn (array $row) => ['domain' => (string) $row['domain']]),
                Action::make('ns', __('Nameservers'))->icon('server')
                    ->run(fn (ScopedClient $api, array $row) => Outcome::show(__('Nameservers of :domain', ['domain' => $row['domain']]), implode("\n", (array) (self::call($api, '/domain/getNs/'.$row['domain'])['ns'] ?? [])))),
                Action::make('ns-set', __('Set nameservers'))->icon('pencil')
                    ->fields([Field::textarea('ns', __('Nameservers'))->required()->placeholder("ns1.example.net\nns2.example.net")->help(__('One per line. Porkbun\'s own: curitiba.ns.porkbun.com, fortaleza.ns.porkbun.com, maceio.ns.porkbun.com, salvador.ns.porkbun.com.'))])
                    ->run(fn (ScopedClient $api, array $row, array $data) => tap(__('Nameservers saved.'), fn () => self::call($api, '/domain/updateNs/'.$row['domain'], ['ns' => self::lines($data['ns'] ?? '')]))),
                DnsPublisher::nameserversAction(fn (array $row) => (string) $row['domain'], fn (ScopedClient $api, array $row, array $servers) => self::call($api, '/domain/updateNs/'.$row['domain'], ['ns' => $servers])),
            ]);
    }

    private function records(): Section
    {
        $fields = fn () => [
            Field::select('type', __('Type'), array_combine(self::TYPES, self::TYPES))->required()->default(fn ($row) => $row['type'] ?? 'A'),
            Field::text('name', __('Name'))->default(fn ($row) => $row['_sub'] ?? '')->help(__('Empty for the domain itself, * for a wildcard.')),
            Field::suggest('content', __('Value'), fn () => ServerTargets::options())->required()->default(fn ($row) => ServerTargets::nameOf($row['content'] ?? null) ?? ($row['content'] ?? null))
                ->help(__('An address, a name, or a server of the panel by its name (A, AAAA).')),
            Field::number('ttl', __('TTL'))->default(fn ($row) => $row['ttl'] ?? 600)->rules(['min:600']),
            Field::number('prio', __('Priority (MX, SRV)'))->default(fn ($row) => $row['prio'] ?? null),
        ];
        $body = fn (array $data) => array_filter([
            'type' => $data['type'],
            'name' => in_array($data['name'] ?? '', ['@', null], true) ? '' : (string) $data['name'],
            'content' => ServerTargets::resolve($data['type'] ?? null, $data['content'] ?? null),
            'ttl' => (string) max(600, (int) ($data['ttl'] ?? 600)),
            'prio' => ($data['prio'] ?? null) !== null ? (string) $data['prio'] : null,
        ], fn ($v) => $v !== null);

        return Section::make('records', __('DNS records'))
            ->childOf('domains', 'domain')
            ->list(fn (ScopedClient $api, array $scope) => array_map(
                fn ($r) => [...$r, '_sub' => self::sub((string) ($r['name'] ?? ''), (string) $scope['domain'])],
                array_values(array_filter((array) (self::call($api, '/dns/retrieve/'.$scope['domain'])['records'] ?? []), 'is_array')),
            ))
            ->columns([
                Column::make('type', __('Type'))->badge(),
                Column::make('name', __('Name'))->mono(),
                Column::make('content', __('Value'))->mono()->using(fn ($row) => ServerTargets::label($row['content'] ?? null)),
                Column::make('prio', __('Priority')),
                Column::make('ttl', __('TTL')),
            ])
            ->create(__('New record'), $fields(), fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Record created.'), fn () => self::call($api, '/dns/create/'.$scope['domain'], $body($data))))
            ->actions([
                Action::make('edit', __('Edit'))->icon('pencil')->fields($fields())
                    ->run(fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => self::call($api, '/dns/edit/'.$scope['domain'].'/'.$row['id'], $body($data)))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record?'))
                    ->run(fn (ScopedClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => self::call($api, '/dns/delete/'.$scope['domain'].'/'.$row['id']))),
            ]);
    }
}
