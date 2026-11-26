<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\ApiClient;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;

/**
 * Resend, with a full-access API key: domains with their DKIM, SPF and MX
 * records published on Cloudflare, verification and tracking; API keys for
 * the apps, sending only and per domain, with the .env of a Laravel app.
 * https://resend.com/docs/api-reference/introduction
 */
final class Resend extends Connector
{
    public function key(): string
    {
        return 'resend';
    }

    public function label(): string
    {
        return 'Resend';
    }

    public function description(): string
    {
        return __('Domains with their DNS published on Cloudflare, verification and tracking; sending keys per domain with the .env of each app.');
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
        return 'https://resend.com/docs/dashboard/api-keys/introduction';
    }

    public function fields(): array
    {
        return [
            Field::password('api_key', __('API key'))->required()->placeholder('re_…'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('resend.com → API Keys → Create API Key, permission Full access (a Sending access key cannot read domains).'),
            __('The key is shown once. The apps get keys of their own, sending only, from the API keys tab here.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.resend.com', ['Authorization' => 'Bearer '.self::string($credentials, 'api_key')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        return 'ok · '.trans_choice(':count domain|:count domains', count((array) ($api->get('/domains')['data'] ?? [])));
    }

    public function sections(): array
    {
        return [
            $this->domains(),
            $this->keys(),
        ];
    }

    /**
     * What a domain asks of its DNS, with full names (Resend gives them relative).
     *
     * @param  array<string, mixed>  $domain  GET /domains/{id}
     * @return list<array<string, mixed>>
     */
    public static function records(array $domain): array
    {
        $name = (string) ($domain['name'] ?? '');

        return array_values(array_map(fn ($r) => [
            'type' => strtoupper((string) ($r['type'] ?? '')),
            'name' => DnsPublisher::fqdn((string) ($r['name'] ?? ''), $name),
            'content' => (string) ($r['value'] ?? ''),
            'priority' => $r['priority'] ?? null,
        ], array_filter((array) ($domain['records'] ?? []), 'is_array')));
    }

    /** @return array<string, string> */
    private static function domainOptions(ApiClient $api): array
    {
        $out = ['' => __('Every domain')];

        foreach ((array) ($api->get('/domains')['data'] ?? []) as $d) {
            $out[(string) $d['id']] = (string) $d['name'];
        }

        return $out;
    }

    private function domains(): Section
    {
        $records = fn (ApiClient $api, array $row) => [(string) $row['name'], self::records($api->get('/domains/'.$row['id']))];

        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->list(fn (ApiClient $api) => array_values(array_filter((array) ($api->get('/domains')['data'] ?? []), 'is_array')))
            ->columns([
                Column::make('name', __('Domain'))->mono(),
                Column::make('status', __('Status'))->badge(['verified' => 'green', 'pending' => 'yellow', 'not_started' => 'zinc', 'failed' => 'red', 'temporary_failure' => 'yellow', '*' => 'zinc']),
                Column::make('region', __('Region')),
                Column::make('created_at', __('Added'))->date(),
            ])
            ->create(__('New domain'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::select('region', __('Region'), ['eu-west-1' => 'eu-west-1 (Ireland)', 'us-east-1' => 'us-east-1 (N. Virginia)', 'sa-east-1' => 'sa-east-1 (São Paulo)', 'ap-northeast-1' => 'ap-northeast-1 (Tokyo)'])->default('eu-west-1'),
            ], function (ApiClient $api, array $row, array $data) {
                $domain = $api->post('/domains', ['name' => strtolower(trim((string) $data['name'])), 'region' => $data['region'] ?? 'eu-west-1']);

                return Outcome::show(__('DNS for :domain', ['domain' => $domain['name'] ?? $data['name']]), DnsPublisher::text(self::records($domain)), __('Domain added: publish its records, then verify.'));
            })
            ->actions([
                Action::make('dns', __('DNS records'))->icon('globe')
                    ->run(function (ApiClient $api, array $row) use ($records) {
                        [$domain, $list] = $records($api, $row);

                        return Outcome::show(__('DNS for :domain', ['domain' => $domain]), DnsPublisher::text($list));
                    }),
                DnsPublisher::action(fn (ApiClient $api, array $row) => $records($api, $row)),
                Action::make('verify', __('Verify'))->icon('check-circle')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Verification started: the status changes in a few minutes.'), fn () => $api->post('/domains/'.$row['id'].'/verify'))),
                Action::make('tracking', __('Tracking'))->icon('eye')
                    ->fields([
                        Field::checkbox('open_tracking', __('Track opens')),
                        Field::checkbox('click_tracking', __('Track clicks')),
                    ])
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Tracking saved.'), fn () => $api->patch('/domains/'.$row['id'], ['open_tracking' => (bool) ($data['open_tracking'] ?? false), 'click_tracking' => (bool) ($data['click_tracking'] ?? false)]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain? Mail from it stops at once.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/domains/'.$row['id']))),
            ]);
    }

    private function keys(): Section
    {
        return Section::make('keys', __('API keys'))
            ->icon('key')
            ->list(fn (ApiClient $api) => array_values(array_filter((array) ($api->get('/api-keys')['data'] ?? []), 'is_array')))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('id', __('ID'))->mono(),
                Column::make('created_at', __('Created'))->date(),
            ])
            ->create(__('Key for an app'), [
                Field::text('name', __('Name'))->required()->placeholder('myapp production'),
                Field::select('permission', __('Permission'), ['sending_access' => __('Sending only'), 'full_access' => __('Full access')])->default('sending_access'),
                Field::select('domain_id', __('Domain'), fn (ApiClient $api) => self::domainOptions($api))->help(__('Sending only: the key sends from this domain alone.')),
            ], function (ApiClient $api, array $row, array $data) {
                $key = $api->post('/api-keys', array_filter([
                    'name' => $data['name'],
                    'permission' => $data['permission'] ?? 'sending_access',
                    'domain_id' => ($data['permission'] ?? 'sending_access') === 'sending_access' ? ($data['domain_id'] ?? null) : null,
                ]));

                return Outcome::show(__('.env of :name', ['name' => $data['name']]), S3Sections::env([
                    'MAIL_MAILER' => 'resend',
                    'RESEND_KEY' => (string) ($key['token'] ?? ''),
                ])."\n\n# ".__('The key is shown once. Laravel: composer require resend/resend-php.'), __('Key created.'));
            })
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key? Apps using it stop sending at once.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Key deleted.'), fn () => $api->delete('/api-keys/'.$row['id']))),
            ]);
    }
}
