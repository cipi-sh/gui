<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;

/**
 * Mailgun, with a private API key, in the US or the EU: sending domains with
 * their SPF, DKIM, tracking and MX records published on Cloudflare, their
 * figures of the last 30 days, a sending key and the .env of a Laravel app;
 * bounces, complaints and unsubscribes. https://documentation.mailgun.com
 */
final class Mailgun extends Connector
{
    private const SUPPRESSIONS = ['bounces' => 'bounce', 'complaints' => 'complaint', 'unsubscribes' => 'unsubscribe'];

    public function key(): string
    {
        return 'mailgun';
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    public function description(): string
    {
        return __('Sending domains with their DNS published on Cloudflare, 30-day figures, a sending key and the .env of each app; bounces, complaints and unsubscribes.');
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function category(): string
    {
        return 'email';
    }

    public function docs(): string
    {
        return 'https://help.mailgun.com/hc/en-us/articles/203380100-Where-can-I-find-my-API-keys-and-SMTP-credentials';
    }

    public function fields(): array
    {
        return [
            Field::password('api_key', __('Private API key'))->required(),
            Field::select('region', __('Region'), ['us' => __('US (api.mailgun.net)'), 'eu' => __('EU (api.eu.mailgun.net)')])->required()->default('eu'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('app.mailgun.com → your name → API Security → Add new key: a private key with the Admin or Developer role (a domain sending key only sends).'),
            __('The region is where the domains live: EU domains are on api.eu.mailgun.net, the others on api.mailgun.net. Domains of both regions need two accounts here.'),
            __('IP allowlist (API Security): add the panel server\'s address if you use one.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $host = self::string($credentials, 'region', 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        return new ApiClient($host, ['Authorization' => 'Basic '.base64_encode('api:'.self::string($credentials, 'api_key'))]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $answer = $api->get('/v4/domains', ['limit' => 1]);

        return 'ok · '.trans_choice(':count domain|:count domains', (int) ($answer['total_count'] ?? 0)).' · '.parse_url($api->baseUrl, PHP_URL_HOST);
    }

    public function sections(): array
    {
        return [
            $this->domains(),
            $this->suppressions(),
        ];
    }

    /**
     * What a domain asks of its DNS: the sending records and the MX that receive.
     *
     * @param  array<string, mixed>  $answer  GET /v4/domains/{name}
     * @return list<array<string, mixed>>
     */
    public static function records(string $domain, array $answer): array
    {
        $records = [];

        foreach ((array) ($answer['sending_dns_records'] ?? []) as $r) {
            if (is_array($r) && ($r['value'] ?? '') !== '') {
                $records[] = ['type' => strtoupper((string) ($r['record_type'] ?? '')), 'name' => DnsPublisher::fqdn((string) ($r['name'] ?? $domain), $domain), 'content' => (string) $r['value']];
            }
        }

        foreach ((array) ($answer['receiving_dns_records'] ?? []) as $r) {
            if (is_array($r) && strtoupper((string) ($r['record_type'] ?? '')) === 'MX') {
                $records[] = ['type' => 'MX', 'name' => $domain, 'content' => (string) $r['value'], 'priority' => (int) ($r['priority'] ?? 10)];
            }
        }

        return $records;
    }

    private function domains(): Section
    {
        $records = fn (ApiClient $api, array $row) => [(string) $row['name'], self::records((string) $row['name'], $api->get('/v4/domains/'.rawurlencode((string) $row['name'])))];

        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->id('name')
            ->list(fn (ApiClient $api) => array_values(array_filter((array) ($api->get('/v4/domains', ['limit' => 1000])['items'] ?? []), 'is_array')))
            ->columns([
                Column::make('name', __('Domain'))->mono(),
                Column::make('state', __('State'))->badge(['active' => 'green', 'unverified' => 'yellow', 'disabled' => 'red', '*' => 'zinc']),
                Column::make('type', __('Type'))->badge(['custom' => 'blue', 'sandbox' => 'zinc']),
                Column::make('created_at', __('Added'))->date(),
            ])
            ->create(__('New domain'), [
                Field::text('name', __('Domain'))->required()->placeholder('mg.example.com')->help(__('A subdomain (mg.example.com) keeps Mailgun\'s MX away from your own mailboxes.')),
                Field::select('dkim_key_size', __('DKIM key'), ['2048' => '2048 bit', '1024' => '1024 bit'])->default('2048'),
            ], function (ApiClient $api, array $row, array $data) {
                $name = strtolower(trim((string) $data['name']));
                $answer = $api->send('POST', '/v4/domains', ['form_params' => ['name' => $name, 'dkim_key_size' => (string) ($data['dkim_key_size'] ?? '2048')]]);

                return Outcome::show(__('DNS for :domain', ['domain' => $name]), DnsPublisher::text(self::records($name, $answer)), __('Domain added: publish its records, then verify.'));
            })
            ->actions([
                Action::make('dns', __('DNS records'))->icon('globe')
                    ->run(function (ApiClient $api, array $row) use ($records) {
                        [$domain, $list] = $records($api, $row);

                        return Outcome::show(__('DNS for :domain', ['domain' => $domain]), DnsPublisher::text($list));
                    }),
                DnsPublisher::action(fn (ApiClient $api, array $row) => $records($api, $row)),
                Action::make('verify', __('Verify'))->icon('check-circle')
                    ->run(function (ApiClient $api, array $row) {
                        $answer = $api->put('/v4/domains/'.rawurlencode((string) $row['name']).'/verify');
                        $state = (string) data_get($answer, 'domain.state', '');

                        return $state === 'active' ? __(':domain is verified.', ['domain' => $row['name']]) : throw new ConnectorException(__('Not verified yet (:state): the records may still be propagating.', ['state' => $state ?: '?']));
                    }),
                Action::make('stats', __('Last 30 days'))->icon('chart-bar')
                    ->run(function (ApiClient $api, array $row) {
                        $answer = $api->get('/v3/'.rawurlencode((string) $row['name']).'/stats/total?event=accepted&event=delivered&event=failed&event=complained&event=opened&event=clicked&duration=30d');
                        $totals = ['accepted' => 0, 'delivered' => 0, 'failed (permanent)' => 0, 'failed (temporary)' => 0, 'complained' => 0, 'opened' => 0, 'clicked' => 0];

                        foreach ((array) ($answer['stats'] ?? []) as $day) {
                            $totals['accepted'] += (int) data_get($day, 'accepted.total', 0);
                            $totals['delivered'] += (int) data_get($day, 'delivered.total', 0);
                            $totals['failed (permanent)'] += (int) data_get($day, 'failed.permanent.total', 0);
                            $totals['failed (temporary)'] += (int) data_get($day, 'failed.temporary.total', 0);
                            $totals['complained'] += (int) data_get($day, 'complained.total', 0);
                            $totals['opened'] += (int) data_get($day, 'opened.total', 0);
                            $totals['clicked'] += (int) data_get($day, 'clicked.total', 0);
                        }

                        return Outcome::show(__(':domain, last 30 days', ['domain' => $row['name']]), $totals);
                    }),
                Action::make('env', __('Sending key and .env'))->icon('key')
                    ->confirm(__('Make a sending key for the domain? It can only send from it.'))
                    ->run(function (ApiClient $api, array $row) {
                        $key = $api->send('POST', '/v1/keys', ['form_params' => [
                            'kind' => 'domain',
                            'role' => 'sending',
                            'domain_name' => (string) $row['name'],
                            'description' => 'cipi panel',
                        ]]);

                        return Outcome::show(__('.env for :domain', ['domain' => $row['name']]), S3Sections::env([
                            'MAIL_MAILER' => 'mailgun',
                            'MAILGUN_DOMAIN' => (string) $row['name'],
                            'MAILGUN_SECRET' => (string) data_get($key, 'key.secret', ''),
                            'MAILGUN_ENDPOINT' => (string) parse_url($api->baseUrl, PHP_URL_HOST),
                        ])."\n\n# ".__('The key is shown once. Laravel: composer require symfony/mailgun-mailer symfony/http-client.'), __('Sending key created.'));
                    }),
                Action::make('suppressions', __('Bounces and unsubscribes'))->icon('x-circle')
                    ->opens('suppressions', fn (array $row) => ['domain' => (string) $row['name']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain? Mail from it stops at once.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/v3/domains/'.rawurlencode((string) $row['name'])))),
            ]);
    }

    private function suppressions(): Section
    {
        return Section::make('suppressions', __('Suppressions'))
            ->childOf('domains', 'domain')
            ->id(fn (array $row) => $row['list'].':'.$row['address'])
            ->list(function (ApiClient $api, array $scope) {
                $rows = [];

                foreach (self::SUPPRESSIONS as $list => $type) {
                    foreach ((array) ($api->get('/v3/'.rawurlencode((string) $scope['domain']).'/'.$list, ['limit' => 1000])['items'] ?? []) as $item) {
                        if (is_array($item)) {
                            $rows[] = ['list' => $list, 'type' => $type, 'address' => (string) ($item['address'] ?? ''), 'why' => trim(($item['code'] ?? '').' '.($item['error'] ?? ($item['tags'][0] ?? ''))), 'created_at' => (string) ($item['created_at'] ?? '')];
                        }
                    }
                }

                return $rows;
            })
            ->columns([
                Column::make('address', __('Address'))->mono(),
                Column::make('type', __('Why'))->badge(['bounce' => 'red', 'complaint' => 'yellow', 'unsubscribe' => 'zinc']),
                Column::make('why', __('Details')),
                Column::make('created_at', __('Since'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Send to it again'))->icon('arrow-uturn-left')->confirm(__('Remove the address from the list? Mailgun sends to it again.'))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__('Removed.'), fn () => $api->delete('/v3/'.rawurlencode((string) $scope['domain']).'/'.$row['list'].'/'.rawurlencode((string) $row['address'])))),
            ]);
    }
}
