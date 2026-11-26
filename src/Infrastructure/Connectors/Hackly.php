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
use CipiGui\Infrastructure\Section;

/**
 * Hackly, self-hosted attack-surface and application scanning, through its
 * API (v1): the posture score; targets with their ownership TXT published
 * on Cloudflare, scans and findings; GitHub repositories and their scans;
 * findings to acknowledge, fix or mark false positive, as Markdown or as a
 * prompt for an AI; every scan with its report. https://hackly.web.ap.it
 */
final class Hackly extends Connector
{
    private const SEVERITY = ['high' => 'red', 'medium' => 'yellow', 'low' => 'zinc', 'critical' => 'red', 'info' => 'zinc'];

    private const FINDING = ['open' => 'red', 'ack' => 'yellow', 'fixed' => 'green', 'false_positive' => 'zinc'];

    private const SCAN = ['pending' => 'zinc', 'running' => 'blue', 'completed' => 'green', 'failed' => 'red', 'cancelled' => 'zinc'];

    private const PROFILES = ['quick' => 'Quick', 'standard' => 'Standard', 'deep' => 'Deep'];

    public function key(): string
    {
        return 'hackly';
    }

    public function label(): string
    {
        return 'Hackly';
    }

    public function description(): string
    {
        return __('Your scanner (hackly.web.ap.it): posture score, targets with their ownership TXT published on Cloudflare, repositories, scans with reports, findings to triage.');
    }

    public function icon(): string
    {
        return 'shield-check';
    }

    public function category(): string
    {
        return 'security';
    }

    public function docs(): string
    {
        return 'https://hackly.web.ap.it/#docs';
    }

    public function fields(): array
    {
        return [
            Field::text('url', __('Hackly address'))->required()->placeholder('https://hackly.example.com'),
            Field::password('token', __('API token'))->required()->placeholder('hk_…'),
        ];
    }

    public function instructions(): array
    {
        return [
            __('Hackly → Profile → API tokens → New token with the ability "*" (or "write": scans and triage; "read" only lists), or on its server: php artisan hackly:token.'),
            __('The address is the one you open in the browser, with https://; the panel calls /api/v1 under it.'),
            __('Targets need their ownership TXT record before Hackly scans them: with a Cloudflare account in the panel, "Publish DNS on Cloudflare" writes it.'),
        ];
    }

    public function client(array $credentials): ApiClient
    {
        $url = self::instanceUrl($credentials['url'] ?? '');

        if ($url === '') {
            throw new ConnectorException(__('The address of Hackly is missing.'));
        }

        return new ApiClient($url.'/api/v1', ['Authorization' => 'Bearer '.self::string($credentials, 'token')], 60);
    }

    public function verify(object $api): string
    {
        /** @var ApiClient $api */
        $me = (array) ($api->get('/me')['data'] ?? []);
        $posture = (array) ($api->get('/posture')['data'] ?? []);

        return 'ok · '.($me['email'] ?? $me['name'] ?? '').(isset($posture['score']) ? ' · '.__('score :score (:grade)', ['score' => $posture['score'], 'grade' => $posture['grade'] ?? '?']) : '');
    }

    public function sections(): array
    {
        return [
            $this->overview(),
            $this->targets(),
            $this->findings('target-findings', 'targets', 'target_id'),
            $this->repositories(),
            $this->findings('repo-findings', 'repositories', 'repository_id'),
            $this->findings('findings'),
            $this->scans(),
        ];
    }

    /**
     * Every page of a resource collection (links.next), up to $pages.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function every(ApiClient $api, string $path, array $query = [], int $pages = 10): array
    {
        /** @var list<array<string, mixed>> */
        return $api->all(
            $path,
            ['per_page' => 100, ...$query],
            fn (array $answer) => array_values(array_filter((array) ($answer['data'] ?? []), 'is_array')),
            fn (array $answer) => is_string($next = data_get($answer, 'links.next')) && $next !== '' ? $next : null,
            $pages,
        );
    }

    /**
     * The TXT record that proves a target is yours.
     *
     * @param  array<string, mixed>  $target
     * @return list<array<string, string>>
     */
    public static function verification(array $target): array
    {
        $txt = (array) ($target['verification'] ?? []);

        if (($txt['value'] ?? '') === '') {
            return [];
        }

        return [['type' => 'TXT', 'name' => DnsPublisher::fqdn((string) ($txt['host'] ?? ''), (string) ($target['domain'] ?? '')), 'content' => (string) $txt['value']]];
    }

    private function overview(): Section
    {
        return Section::info('overview', __('Posture'), function (ApiClient $api) {
            $p = (array) ($api->get('/posture')['data'] ?? []);

            return [
                __('Score') => [__('Score') => $p['score'] ?? null, __('Grade') => $p['grade'] ?? null, __('Method') => $p['method'] ?? null],
                __('Open findings') => (array) ($p['open'] ?? []),
                __('Acknowledged') => (array) ($p['acknowledged'] ?? []),
                __('Assets') => (array) ($p['assets'] ?? []),
                __('Scans') => (array) ($p['scans'] ?? []),
            ];
        });
    }

    /** @return list<Field> */
    private static function scanFields(string $also): array
    {
        return [
            Field::select('profile', __('Profile'), self::PROFILES)->default('standard'),
            Field::checkbox($also, $also === 'include_repositories' ? __('Scan its linked repositories too') : __('Scan its linked targets too')),
        ];
    }

    private function targets(): Section
    {
        $records = fn (ApiClient $api, array $row) => [(string) $row['domain'], self::verification((array) ($api->get('/targets/'.$row['id'])['data'] ?? []))];

        return Section::make('targets', __('Targets'))
            ->icon('globe')
            ->list(fn (ApiClient $api) => self::every($api, '/targets'))
            ->columns([
                Column::make('domain', __('Target'))->mono(),
                Column::make('verified', __('Verified'))->bool(),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'paused' => 'zinc', 'archived' => 'zinc']),
                Column::make('open_findings', __('Open findings')),
                Column::make('latest_scan.status', __('Last scan'))->badge(self::SCAN),
                Column::make('created_at', __('Added'))->date(),
            ])
            ->create(__('New target'), [
                Field::text('domain', __('Domain'))->required()->placeholder('myapp.example.com'),
                Field::textarea('authorization_note', __('Authorization'))->required()->placeholder(__('Ours: production of myapp.'))->help(__('Who allowed the scans: Hackly keeps it with the target.')),
            ], function (ApiClient $api, array $row, array $data) {
                $target = (array) ($api->post('/targets', ['domain' => strtolower(trim((string) $data['domain'])), 'authorization_note' => $data['authorization_note']])['data'] ?? []);

                return Outcome::show(__('Ownership of :domain', ['domain' => $target['domain'] ?? $data['domain']]), DnsPublisher::text(self::verification($target)), __('Target added: publish its TXT record, then check it.'));
            })
            ->actions([
                Action::make('findings', __('Findings'))->icon('shield')
                    ->opens('target-findings', fn (array $row) => ['target_id' => (string) $row['id'], 'domain' => (string) $row['domain']]),
                Action::make('txt', __('Ownership TXT'))->icon('globe')->when(fn ($row) => ! ($row['verified'] ?? false))
                    ->run(function (ApiClient $api, array $row) use ($records) {
                        [$domain, $list] = $records($api, $row);

                        return Outcome::show(__('Ownership of :domain', ['domain' => $domain]), DnsPublisher::text($list));
                    }),
                DnsPublisher::action(fn (ApiClient $api, array $row) => $records($api, $row))->when(fn ($row) => ! ($row['verified'] ?? false) && DnsPublisher::available()),
                Action::make('check', __('Check ownership'))->icon('check-circle')->when(fn ($row) => ! ($row['verified'] ?? false))
                    ->run(function (ApiClient $api, array $row) {
                        $target = (array) ($api->post('/targets/'.$row['id'].'/verification/check')['data'] ?? []);

                        return ($target['verified'] ?? false) ? __(':domain is verified: it can be scanned.', ['domain' => $row['domain']]) : throw new ConnectorException(__('Not found yet: DNS may still be propagating.'));
                    }),
                Action::make('scan', __('Scan'))->icon('play')->when(fn ($row) => (bool) ($row['verified'] ?? false) && ($row['status'] ?? '') === 'active')
                    ->fields(self::scanFields('include_repositories'))
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Scan queued.'), fn () => $api->post('/targets/'.$row['id'].'/scans', ['profile' => $data['profile'] ?? 'standard', 'include_repositories' => (bool) ($data['include_repositories'] ?? false)]))),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ($row['status'] ?? '') === 'active')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Target paused.'), fn () => $api->post('/targets/'.$row['id'].'/pause'))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'paused')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Target resumed.'), fn () => $api->post('/targets/'.$row['id'].'/resume'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the target, its scans and findings?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Target deleted.'), fn () => $api->delete('/targets/'.$row['id']))),
            ]);
    }

    private function repositories(): Section
    {
        return Section::make('repositories', __('Repositories'))
            ->icon('code')
            ->list(fn (ApiClient $api) => self::every($api, '/repositories'))
            ->columns([
                Column::make('full_name', __('Repository'))->mono()->link('html_url'),
                Column::make('default_branch', __('Branch'))->mono(),
                Column::make('status', __('Status'))->badge(['active' => 'green', 'paused' => 'zinc', '*' => 'zinc']),
                Column::make('open_findings', __('Open findings')),
                Column::make('latest_scan.status', __('Last scan'))->badge(self::SCAN),
                Column::make('last_scanned_at', __('Scanned'))->date(),
            ])
            ->create(__('New repository'), [
                Field::text('full_name', __('Repository'))->required()->placeholder('owner/repo'),
                Field::select('github_credential_id', __('GitHub token'), fn (ApiClient $api) => collect(self::every($api, '/github-credentials'))->mapWithKeys(fn ($c) => [(string) $c['id'] => trim(($c['name'] ?? '').' · '.($c['github_login'] ?? ''), ' ·')])->all())->required(),
            ], fn (ApiClient $api, array $row, array $data) => tap(__('Repository added.'), fn () => $api->post('/repositories', $data)))
            ->actions([
                Action::make('findings', __('Findings'))->icon('shield')
                    ->opens('repo-findings', fn (array $row) => ['repository_id' => (string) $row['id'], 'full_name' => (string) $row['full_name']]),
                Action::make('scan', __('Scan'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'active')
                    ->fields(self::scanFields('include_targets'))
                    ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Scan queued.'), fn () => $api->post('/repositories/'.$row['id'].'/scans', ['profile' => $data['profile'] ?? 'standard', 'include_targets' => (bool) ($data['include_targets'] ?? false)]))),
                Action::make('refresh', __('Refresh from GitHub'))->icon('arrow-path')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Refreshed.'), fn () => $api->post('/repositories/'.$row['id'].'/refresh'))),
                Action::make('pause', __('Pause'))->icon('pause')->when(fn ($row) => ($row['status'] ?? '') === 'active')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Repository paused.'), fn () => $api->post('/repositories/'.$row['id'].'/pause'))),
                Action::make('resume', __('Resume'))->icon('play')->when(fn ($row) => ($row['status'] ?? '') === 'paused')
                    ->run(fn (ApiClient $api, array $row) => tap(__('Repository resumed.'), fn () => $api->post('/repositories/'.$row['id'].'/resume'))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the repository, its scans and findings?'))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Repository deleted.'), fn () => $api->delete('/repositories/'.$row['id']))),
            ]);
    }

    private function findings(string $key, ?string $parent = null, ?string $by = null): Section
    {
        $mark = fn (string $status, string $label) => Action::make('mark-'.$status, $label)
            ->icon(match ($status) { 'ack' => 'eye', 'fixed' => 'check-circle', 'false_positive' => 'x-circle', default => 'arrow-uturn-left' })
            ->when(fn ($row) => ($row['status'] ?? '') !== $status)
            ->fields($status === 'false_positive' ? [Field::text('note', __('Why'))->required()] : [Field::text('note', __('Note'))])
            ->run(fn (ApiClient $api, array $row, array $data) => tap(__('Marked.'), fn () => $api->patch('/findings/'.$row['id'], array_filter(['status' => $status, 'note' => $data['note'] ?? null]))));

        $section = Section::make($key, __('Findings'))
            ->icon('shield')
            ->filters([
                Field::select('status', __('Show'), ['open' => __('Open'), 'ack' => __('Acknowledged'), 'fixed' => __('Fixed'), 'false_positive' => __('False positives'), '' => __('All')])->default('open'),
                Field::select('severity', __('Severity'), ['' => __('Any'), 'high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')]),
            ])
            ->list(fn (ApiClient $api, array $scope) => self::every($api, '/findings', array_filter([
                'status' => (string) ($scope['status'] ?? 'open'),
                'severity' => (string) ($scope['severity'] ?? ''),
                $by ?? 'none' => $by !== null ? (string) ($scope[$by] ?? '') : '',
            ]), 5))
            ->columns([
                Column::make('severity', __('Severity'))->badge(self::SEVERITY),
                Column::make('title', __('Finding'))->using(fn ($row) => mb_substr((string) ($row['title'] ?? ''), 0, 140)),
                Column::make('subject', __('Where'))->mono(),
                Column::make('category_label', __('Category')),
                Column::make('status', __('Status'))->badge(self::FINDING),
                Column::make('created_at', __('Found'))->date(),
            ])
            ->actions([
                Action::make('markdown', __('Details'))->icon('document-text')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show((string) ($row['title'] ?? ''), $api->text('/findings/'.$row['id'].'/markdown'))),
                Action::make('prompt', __('Prompt for an AI'))->icon('sparkles')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Fix it with an AI'), $api->text('/findings/'.$row['id'].'/prompt'))),
                $mark('ack', __('Acknowledge')),
                $mark('fixed', __('Mark fixed')),
                $mark('false_positive', __('False positive')),
                $mark('open', __('Reopen')),
                Action::make('share', __('Share link'))->icon('share')
                    ->run(function (ApiClient $api, array $row) {
                        $answer = $api->post('/findings/'.$row['id'].'/share');

                        return Outcome::show(__('Anyone with the link sees the finding'), (string) (data_get($answer, 'data.share_url') ?? data_get($answer, 'share_url') ?? ''));
                    }),
            ]);

        return $parent !== null ? $section->childOf($parent, $parent === 'targets' ? 'domain' : 'full_name') : $section;
    }

    private function scans(): Section
    {
        return Section::make('scans', __('Scans'))
            ->icon('chart-bar')
            ->id(fn (array $row) => $row['kind'].':'.$row['id'])
            ->list(function (ApiClient $api) {
                $rows = [...self::every($api, '/scans', [], 1), ...self::every($api, '/repo-scans', [], 1)];
                usort($rows, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

                return $rows;
            })
            ->columns([
                Column::make('subject', __('Of'))->mono()->using(fn ($row) => (string) (data_get($row, 'asset.domain') ?? data_get($row, 'repository.full_name') ?? '')),
                Column::make('kind', __('Kind'))->badge(['target' => 'blue', 'repository' => 'zinc']),
                Column::make('profile_label', __('Profile')),
                Column::make('status', __('Status'))->badge(self::SCAN),
                Column::make('progress_percent', __('Progress'))->using(fn ($row) => isset($row['progress_percent']) ? $row['progress_percent'].'%' : ''),
                Column::make('findings', __('Findings'))->using(fn ($row) => implode(' · ', array_map(fn ($k, $v) => $k.' '.$v, array_keys((array) ($row['findings_summary'] ?? [])), (array) ($row['findings_summary'] ?? [])))),
                Column::make('created_at', __('Started'))->date(),
            ])
            ->actions([
                Action::make('report', __('Report'))->icon('document-text')->when(fn ($row) => ($row['status'] ?? '') === 'completed')
                    ->run(fn (ApiClient $api, array $row) => Outcome::show(__('Scan report'), $api->text(($row['kind'] === 'repository' ? '/repo-scans/' : '/scans/').$row['id'].'/report/markdown'))),
                Action::make('cancel', __('Cancel'))->icon('stop')->when(fn ($row) => in_array($row['status'] ?? '', ['pending', 'running'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Cancelling.'), fn () => $api->post(($row['kind'] === 'repository' ? '/repo-scans/' : '/scans/').$row['id'].'/cancel'))),
                Action::make('rerun', __('Run again'))->icon('arrow-path')->when(fn ($row) => ! in_array($row['status'] ?? '', ['pending', 'running'], true))
                    ->run(fn (ApiClient $api, array $row) => tap(__('Scan queued again.'), fn () => $api->post(($row['kind'] === 'repository' ? '/repo-scans/' : '/scans/').$row['id'].'/rerun'))),
            ]);
    }
}
