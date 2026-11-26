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
 * Postmark, with the account API token: servers (each one app's mail) with
 * the .env of a Laravel app, their outbound figures and bounces; sending
 * domains with their DKIM and Return-Path records, published on Cloudflare;
 * sender signatures. https://postmarkapp.com/developer/api/overview
 */
final class Postmark extends Connector
{
    public function key(): string
    {
        return 'postmark';
    }

    public function label(): string
    {
        return 'Postmark';
    }

    public function description(): string
    {
        return __('Servers with the .env of each app, outbound figures and bounces; domains with their DKIM and Return-Path published on Cloudflare; sender signatures.');
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
        return 'https://postmarkapp.com/developer/api/overview#authentication';
    }

    public function fields(): array
    {
        return [
            Field::password('token', __('Account API token'))->required()
                ->help(__('The account token, not a server token: it reaches every server and domain.')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('account.postmarkapp.com → Account → API Tokens: the Account API token (only account owners and admins see it).'),
            __('A server token opens one server only and is not enough here: the panel reads each server\'s token from the account when it needs it.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        return new ApiClient('https://api.postmarkapp.com', ['X-Postmark-Account-Token' => self::string($credentials, 'token')]);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $servers = (int) ($api->get('/servers', ['count' => 1, 'offset' => 0])['TotalCount'] ?? 0);
        $domains = (int) ($api->get('/domains', ['count' => 1, 'offset' => 0])['TotalCount'] ?? 0);

        return 'ok · '.trans_choice(':count server|:count servers', $servers).' · '.trans_choice(':count domain|:count domains', $domains);
    }

    public function sections(): array
    {
        return [
            $this->servers(),
            $this->bounces(),
            $this->domains(),
            $this->senders(),
        ];
    }

    /**
     * Every page of a list: Postmark pages with count and offset.
     *
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, string $key): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            ['count' => 500, 'offset' => 0],
            fn (array $answer) => array_values(array_filter((array) ($answer[$key] ?? []), 'is_array')),
            fn (array $answer, int $page) => $page * 500 < (int) ($answer['TotalCount'] ?? 0) ? ['count' => 500, 'offset' => $page * 500] : null,
        );
    }

    /** The server's own API, with its first token. */
    private static function server(ApiClient $api, string $id): ApiClient
    {
        $token = (string) (((array) ($api->get('/servers/'.rawurlencode($id))['ApiTokens'] ?? []))[0] ?? '');

        return new ApiClient($api->baseUrl, ['X-Postmark-Server-Token' => $token]);
    }

    /**
     * What a domain asks of its DNS: DKIM (the pending key while it rotates) and the Return-Path CNAME.
     *
     * @param  array<string, mixed>  $domain  GET /domains/{id}
     * @return list<array<string, string>>
     */
    public static function records(array $domain): array
    {
        $name = (string) ($domain['Name'] ?? '');
        $records = [];
        $host = (string) (($domain['DKIMPendingHost'] ?? '') ?: ($domain['DKIMHost'] ?? ''));
        $value = (string) (($domain['DKIMPendingTextValue'] ?? '') ?: ($domain['DKIMTextValue'] ?? ''));

        if ($host !== '' && $value !== '') {
            $records[] = ['type' => 'TXT', 'name' => DnsPublisher::fqdn($host, $name), 'content' => $value];
        }

        if (($domain['ReturnPathDomain'] ?? '') !== '') {
            $records[] = ['type' => 'CNAME', 'name' => DnsPublisher::fqdn((string) $domain['ReturnPathDomain'], $name), 'content' => (string) (($domain['ReturnPathDomainCNAMEValue'] ?? '') ?: 'pm.mtasv.net')];
        }

        return $records;
    }

    private function servers(): Section
    {
        return Section::make('servers', __('Servers'))
            ->icon('server')
            ->id('ID')
            ->list(fn (ApiClient $api) => array_map(fn ($s) => array_diff_key($s, ['ApiTokens' => true]), self::every($api, '/servers', 'Servers')))
            ->columns([
                Column::make('Name', __('Server')),
                Column::make('DeliveryType', __('Delivery'))->badge(['live' => 'green', 'sandbox' => 'yellow']),
                Column::make('TrackOpens', __('Opens'))->bool(),
                Column::make('ID', __('ID'))->mono(),
            ])
            ->create(__('New server'), [
                Field::text('Name', __('Name'))->required()->placeholder(__('myapp — production')),
                Field::select('Color', __('Color'), ['Blue' => 'Blue', 'Green' => 'Green', 'Grey' => 'Grey', 'Orange' => 'Orange', 'Purple' => 'Purple', 'Red' => 'Red', 'Turquoise' => 'Turquoise', 'Yellow' => 'Yellow'])->default('Blue'),
                Field::select('DeliveryType', __('Delivery'), ['Live' => __('Live'), 'Sandbox' => __('Sandbox (nothing leaves)')])->default('Live'),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Server created.'), fn () => $api->post('/servers', $data)))
            ->actions([
                Action::make('env', __('.env of the app'))->icon('code')
                    ->run(function (ApiClient $api, array $row) {
                        $token = (string) (((array) ($api->get('/servers/'.$row['ID'])['ApiTokens'] ?? []))[0] ?? '');

                        return Outcome::show(__('.env for :server', ['server' => $row['Name']]), S3Sections::env([
                            'MAIL_MAILER' => 'postmark',
                            'POSTMARK_TOKEN' => $token,
                            'MAIL_FROM_ADDRESS' => 'hello@example.com',
                        ])."\n\n# ".__('Laravel: composer require symfony/postmark-mailer symfony/http-client. MAIL_FROM_ADDRESS must be a confirmed signature or on a verified domain.'));
                    }),
                Action::make('stats', __('Last 30 days'))->icon('chart-bar')
                    ->run(function (ApiClient $api, array $row) {
                        $stats = self::server($api, (string) $row['ID'])->get('/stats/outbound', ['fromdate' => gmdate('Y-m-d', time() - 30 * 86400), 'todate' => gmdate('Y-m-d')]);

                        return Outcome::show(__(':server, last 30 days', ['server' => $row['Name']]), array_intersect_key($stats, array_flip(['Sent', 'Bounced', 'SMTPApiErrors', 'BounceRate', 'SpamComplaints', 'SpamComplaintsRate', 'Opens', 'UniqueOpens', 'Tracked', 'WithLinkTracking', 'TotalClicks'])));
                    }),
                Action::make('bounces', __('Bounces'))->icon('x-circle')
                    ->opens('bounces', fn (array $row) => ['server' => (string) $row['ID'], 'server_name' => (string) $row['Name']]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the server, its messages and its tokens? Postmark allows it only if support turned it on for the account.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Server deleted.'), fn () => $api->delete('/servers/'.$row['ID']))),
            ]);
    }

    private function bounces(): Section
    {
        return Section::make('bounces', __('Bounces'))
            ->childOf('servers', 'server_name')
            ->id('ID')
            ->list(fn (ApiClient $api, array $scope) => array_values(array_filter((array) (self::server($api, (string) $scope['server'])->get('/bounces', ['count' => 500, 'offset' => 0])['Bounces'] ?? []), 'is_array')))
            ->columns([
                Column::make('Email', __('Address'))->mono(),
                Column::make('Type', __('Type'))->badge(['hardbounce' => 'red', 'spamcomplaint' => 'red', 'softbounce' => 'yellow', '*' => 'zinc']),
                Column::make('Inactive', __('Blocked'))->bool(),
                Column::make('MessageStream', __('Stream')),
                Column::make('BouncedAt', __('When'))->date(),
            ])
            ->actions([
                Action::make('details', __('Why'))->icon('info')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show((string) $row['Email'], (string) (($row['Details'] ?? '') ?: ($row['Description'] ?? '')))),
                Action::make('activate', __('Send to it again'))->icon('arrow-uturn-left')->when(fn ($row) => (bool) ($row['CanActivate'] ?? false) && (bool) ($row['Inactive'] ?? false))
                    ->run(fn (ApiClient $api, array $row, array $data, array $scope) => tap(__(':address can receive again.', ['address' => $row['Email']]), fn () => self::server($api, (string) $scope['server'])->put('/bounces/'.$row['ID'].'/activate'))),
            ]);
    }

    private function domains(): Section
    {
        $records = fn (ApiClient $api, array $row) => [(string) $row['Name'], self::records($api->get('/domains/'.$row['ID']))];

        return Section::make('domains', __('Domains'))
            ->icon('globe')
            ->id('ID')
            ->list(fn (ApiClient $api) => self::every($api, '/domains', 'Domains'))
            ->columns([
                Column::make('Name', __('Domain'))->mono(),
                Column::make('DKIMVerified', __('DKIM'))->bool(),
                Column::make('ReturnPathDomainVerified', __('Return-Path'))->bool(),
                Column::make('WeakDKIM', __('Weak DKIM'))->bool(),
            ])
            ->create(__('New domain'), [
                Field::text('Name', __('Domain'))->required()->placeholder('example.com'),
                Field::text('ReturnPathDomain', __('Return-Path'))->placeholder('pm-bounces.example.com')->help(__('Empty: pm-bounces. and the domain.')),
            ], function (ApiClient $api, array $row, array $data) {
                $name = strtolower(trim((string) $data['Name']));
                $domain = $api->post('/domains', ['Name' => $name, 'ReturnPathDomain' => ($data['ReturnPathDomain'] ?? '') ?: 'pm-bounces.'.$name]);

                return Outcome::show(__('DNS for :domain', ['domain' => $name]), DnsPublisher::text(self::records($domain)), __('Domain added: publish its records, then verify.'));
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
                        $dkim = $api->put('/domains/'.$row['ID'].'/verifyDkim');
                        $path = $api->put('/domains/'.$row['ID'].'/verifyReturnPath');

                        return __('DKIM: :dkim · Return-Path: :path', [
                            'dkim' => ($dkim['DKIMVerified'] ?? false) ? __('verified') : __('not yet'),
                            'path' => ($path['ReturnPathDomainVerified'] ?? false) ? __('verified') : __('not yet'),
                        ]);
                    }),
                Action::make('rotate', __('Rotate DKIM'))->icon('arrow-path')->confirm(__('Make a new DKIM key? Publish its record: the old key works until the new one is verified.'))
                    ->run(function (ApiClient $api, array $row) {
                        $domain = $api->post('/domains/'.$row['ID'].'/rotatedkim');

                        return Outcome::show(__('New DKIM for :domain', ['domain' => $row['Name']]), DnsPublisher::text(self::records($domain)), __('DKIM rotation started.'));
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the domain? Its sender signatures go with it.'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Domain deleted.'), fn () => $api->delete('/domains/'.$row['ID']))),
            ]);
    }

    private function senders(): Section
    {
        return Section::make('senders', __('Sender signatures'))
            ->icon('user')
            ->id('ID')
            ->list(fn (ApiClient $api) => self::every($api, '/senders', 'SenderSignatures'))
            ->columns([
                Column::make('EmailAddress', __('Address'))->mono(),
                Column::make('Name', __('Name')),
                Column::make('Domain', __('Domain')),
                Column::make('Confirmed', __('Confirmed'))->bool(),
            ])
            ->create(__('New signature'), [
                Field::text('FromEmail', __('Address'))->required()->rules(['email']),
                Field::text('Name', __('Name'))->required(),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Postmark mailed a confirmation link to the address.'), fn () => $api->post('/senders', $data)))
            ->actions([
                Action::make('resend', __('Send the confirmation again'))->icon('mail')->when(fn ($row) => ! ($row['Confirmed'] ?? false))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Confirmation sent again.'), fn () => $api->post('/senders/'.$row['ID'].'/resend'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger()
                    ->run(fn (ApiClient $api, array $row) => tap(__('Signature deleted.'), fn () => $api->delete('/senders/'.$row['ID']))),
            ]);
    }
}
