<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;

/**
 * The Backups tab of a server (API 1.39+ / Cipi ≥ 5.9.0): `cipi backup` from
 * the panel — which profile takes which app and which database (a matrix),
 * when each last ran and how, a run started now, and the profiles themselves.
 *
 * Destinations are shown and tested, not created: adding a bucket takes an
 * S3 secret key, and that stays on the server (`cipi backup configure`,
 * `cipi backup destination add`).
 */
trait ManagesBackups
{
    /** @var array<string, mixed> `cipi backup status --json`, as the API returns it */
    public array $backup = [];

    /** @var array<string, list<array{run: string, destinations: list<string>}>>|null stored runs per profile; null until asked (it lists the buckets) */
    public ?array $backupRuns = null;

    /** @var list<string> buckets that could not be listed */
    public array $backupRunErrors = [];

    /** @var array{ok: bool, output: string}|null result of the last destination test */
    public ?array $backupTest = null;

    // Profile form
    public bool $showBackupForm = false;

    /** Profile being edited; null for a new one. */
    public ?string $backupEditing = null;

    public string $bpName = '';

    public string $bpScope = 'all';

    public string $bpApps = '*';

    public string $bpDatabases = '*';

    public string $bpExcludeDatabases = '';

    public string $bpExcludeTables = '';

    /** every | cron */
    public string $bpSchedule = 'every';

    public string $bpEvery = '1d';

    public string $bpCron = '0 2 * * *';

    /** @var list<string> */
    public array $bpDestinations = [];

    public string $bpKeep = '0';

    public string $bpKeepDays = '28';

    public string $bpKeepWeeks = '0';

    public bool $bpEncrypt = false;

    public const BACKUP_EVERY = ['15m' => 'Every 15 minutes', '30m' => 'Every 30 minutes', '1h' => 'Every hour', '6h' => 'Every 6 hours', '12h' => 'Every 12 hours', '1d' => 'Every day (02:00)', '7d' => 'Every 7 days (02:00)'];

    protected function loadBackups(): void
    {
        $data = $this->client()->backupStatus();

        $data['configured'] = (bool) ($data['configured'] ?? false);
        foreach (['destinations', 'profiles', 'apps', 'databases'] as $list) {
            $data[$list] = array_values(array_filter((array) ($data[$list] ?? []), fn ($item) => $list === 'apps' ? is_string($item) : is_array($item)));
        }
        $data['uncovered'] = [
            'apps' => array_values(array_filter((array) ($data['uncovered']['apps'] ?? []), 'is_string')),
            'databases' => array_values(array_filter((array) ($data['uncovered']['databases'] ?? []), 'is_array')),
        ];
        $this->backup = $data;
    }

    /** While a run is in progress the tab reads the status again (wire:poll in the view). */
    public function refreshBackups(): void
    {
        if ($this->activeTab !== 'backups' || ! $this->currentServer()) {
            return;
        }

        try {
            $this->loadBackups();
        } catch (CipiApiException) {
            // A poll that fails keeps what is shown.
        }
    }

    public function backupRunning(): bool
    {
        foreach ($this->backup['profiles'] ?? [] as $profile) {
            if (! empty($profile['running'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> names of the loaded profiles */
    protected function backupProfileNames(): array
    {
        return array_values(array_filter(array_map(fn ($p) => is_string($p['name'] ?? null) ? $p['name'] : '', $this->backup['profiles'] ?? [])));
    }

    /** @return array<string, mixed>|null */
    protected function backupProfile(string $name): ?array
    {
        foreach ($this->backup['profiles'] ?? [] as $profile) {
            if (($profile['name'] ?? null) === $name) {
                return $profile;
            }
        }

        return null;
    }

    /** May change backups, and the profile is one the page lists ('' = every profile, when allowed). */
    protected function backupAllowed(string $profile = '', bool $allowAll = false): bool
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return false;
        }

        return $profile === '' ? $allowAll : in_array($profile, $this->backupProfileNames(), true);
    }

    /** Start a run in the background: one profile, or every enabled one. */
    public function runBackup(string $profile = ''): void
    {
        if (! $this->backupAllowed($profile, allowAll: true)) {
            return;
        }

        try {
            $this->client()->backupRun($profile !== '' ? $profile : null);
            $this->dispatch('notify', type: 'success', message: ($profile !== '' ? "Backup '{$profile}'" : 'Backup of every enabled profile').' started on the server. This tab follows it; its result shows under Last run.');
            usleep(400000); // give the run the moment it takes to show up in the process table
            $this->loadBackups();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    /** Read the newest run back and check that every archive opens (a job). */
    public function verifyBackup(string $profile = ''): void
    {
        if (! $this->backupAllowed($profile, allowAll: true)) {
            return;
        }

        $this->startJob($profile !== '' ? "Verify backup '{$profile}'" : 'Verify the newest backup of every profile', fn (CipiApiClient $api) => $api->backupVerify($profile !== '' ? $profile : null));
    }

    public function toggleBackupProfile(string $profile, bool $enabled): void
    {
        if (! $this->backupAllowed($profile)) {
            return;
        }

        try {
            $this->client()->toggleBackupProfile($profile, $enabled);
            $this->dispatch('notify', type: 'success', message: "Profile '{$profile}' ".($enabled ? 'is back in the schedule.' : 'is out of the schedule. Its archives stay.'));
            $this->loadBackups();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteBackupProfile(string $profile): void
    {
        if (! $this->backupAllowed($profile)) {
            return;
        }

        try {
            $data = $this->client()->deleteBackupProfile($profile);
            $kept = is_string($data['message'] ?? null) && preg_match('/\d+ archive run\(s\) kept[^\n]*/', $data['message'], $m) ? ' '.trim($m[0]) : '';
            $this->dispatch('notify', type: 'success', message: "Profile '{$profile}' removed.".$kept);
            $this->backupRuns = null;
            $this->loadBackups();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    /** The runs each destination holds — asked on demand: it lists every bucket. */
    public function loadBackupRuns(): void
    {
        if (! $this->currentServer()) {
            return;
        }

        try {
            $data = $this->client()->backupRuns();
            $this->backupRuns = $data['profiles'];
            $this->backupRunErrors = $data['errors'];
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function testBackupDestinations(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        try {
            $this->backupTest = $this->client()->testBackupDestinations();
            $this->dispatch('notify', type: $this->backupTest['ok'] ? 'success' : 'error', message: $this->backupTest['ok'] ? 'Every S3 destination answered.' : 'A destination could not be reached — see the output.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── profile form ──────────────────────────────────────────────────

    public function openBackupForm(string $profile = ''): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $existing = $profile !== '' ? $this->backupProfile($profile) : null;
        $destinations = array_values(array_filter(array_map(fn ($d) => is_string($d['name'] ?? null) ? $d['name'] : '', $this->backup['destinations'] ?? [])));

        if ($existing) {
            $this->backupEditing = $profile;
            $this->bpName = $profile;
            $this->bpScope = in_array($existing['scope'] ?? '', ['all', 'files', 'db'], true) ? $existing['scope'] : 'all';
            $this->bpApps = implode(', ', array_filter((array) ($existing['apps'] ?? []), 'is_string'));
            $this->bpDatabases = implode(', ', array_filter((array) ($existing['databases'] ?? []), 'is_string'));
            $this->bpExcludeDatabases = implode(', ', array_filter((array) ($existing['exclude_databases'] ?? []), 'is_string'));
            $this->bpExcludeTables = implode(', ', array_filter((array) ($existing['exclude_tables'] ?? []), 'is_string'));
            $every = self::backupEveryFor($existing);
            $this->bpSchedule = $every !== null ? 'every' : 'cron';
            $this->bpEvery = $every ?? '1d';
            $this->bpCron = is_string($existing['cron'] ?? null) ? $existing['cron'] : '0 2 * * *';
            $this->bpDestinations = array_values(array_intersect($destinations, array_filter((array) ($existing['destinations'] ?? []), 'is_string')));
            $this->bpKeep = (string) (int) ($existing['retention']['keep'] ?? 0);
            $this->bpKeepDays = (string) (int) ($existing['retention']['days'] ?? 0);
            $this->bpKeepWeeks = (string) (int) ($existing['retention']['weeks'] ?? 0);
            $this->bpEncrypt = (bool) ($existing['encrypt'] ?? false);
        } else {
            $this->backupEditing = null;
            $this->reset(['bpName', 'bpScope', 'bpApps', 'bpDatabases', 'bpExcludeDatabases', 'bpExcludeTables', 'bpSchedule', 'bpEvery', 'bpCron', 'bpKeep', 'bpKeepDays', 'bpKeepWeeks', 'bpEncrypt']);
            // The default bucket when there is one, this server's disk otherwise.
            $this->bpDestinations = in_array('s3', $destinations, true) ? ['s3'] : ['local'];
        }

        $this->showBackupForm = true;
    }

    public function closeBackupForm(): void
    {
        $this->showBackupForm = false;
    }

    public function saveBackupProfile(): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $isEdit = $this->backupEditing !== null;
        if ($isEdit && ! in_array($this->backupEditing, $this->backupProfileNames(), true)) {
            return;
        }

        $destinations = array_values(array_filter(array_map(fn ($d) => is_string($d['name'] ?? null) ? $d['name'] : '', $this->backup['destinations'] ?? [])));
        $glob = 'regex:/^[A-Za-z0-9_.*?\[\]-]{1,64}(\s*[,\s]\s*[A-Za-z0-9_.*?\[\]-]{1,64})*$/';
        $tableGlob = 'regex:/^[A-Za-z0-9_.*?\[\]-]{1,128}(\s*[,\s]\s*[A-Za-z0-9_.*?\[\]-]{1,128})*$/';

        $this->validate([
            'bpName' => $isEdit ? ['required'] : ['required', 'regex:/^[a-z][a-z0-9-]{1,31}$/', 'not_in:'.implode(',', $this->backupProfileNames())],
            'bpScope' => ['required', 'in:all,files,db'],
            'bpApps' => [$this->bpScope === 'db' ? 'nullable' : 'required', $glob],
            'bpDatabases' => [$this->bpScope === 'files' ? 'nullable' : 'required', $glob],
            'bpExcludeDatabases' => ['nullable', $glob],
            'bpExcludeTables' => ['nullable', $tableGlob],
            'bpSchedule' => ['required', 'in:every,cron'],
            'bpEvery' => ['required_if:bpSchedule,every', 'in:'.implode(',', array_keys(self::BACKUP_EVERY))],
            'bpCron' => ['required_if:bpSchedule,cron', 'regex:/^[0-9*\/,-]+( [0-9*\/,-]+){4}$/'],
            'bpDestinations' => ['required', 'array', 'min:1'],
            'bpDestinations.*' => ['in:'.implode(',', $destinations)],
            'bpKeep' => ['required', 'integer', 'min:0', 'max:100000'],
            'bpKeepDays' => ['required', 'integer', 'min:0', 'max:100000'],
            'bpKeepWeeks' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [
            'bpName.regex' => 'Lowercase letters, digits and dashes, 2–32 characters, starting with a letter.',
            'bpName.not_in' => 'A profile with this name exists already.',
            'bpApps.regex' => 'App names or globs separated by commas: shop, blog, api-*  (* for every app).',
            'bpApps.required' => 'Say which apps: * for every app.',
            'bpDatabases.regex' => 'Database names or globs separated by commas: shop, tenant_*  (* for every database).',
            'bpDatabases.required' => 'Say which databases: * for every database.',
            'bpExcludeDatabases.regex' => 'Database names or globs separated by commas.',
            'bpExcludeTables.regex' => 'Patterns like database.table separated by commas: *.jobs, shop.telescope_*',
            'bpCron.regex' => 'Five fields of digits and * / , - : for example 0 2 * * *',
            'bpDestinations.required' => 'Choose where the backup goes.',
            'bpDestinations.min' => 'Choose where the backup goes.',
        ]);

        if ((int) $this->bpKeep + (int) $this->bpKeepDays + (int) $this->bpKeepWeeks === 0) {
            $this->addError('bpKeepDays', 'Set at least one: a profile that keeps everything grows without end.');

            return;
        }

        $list = fn (string $value) => array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: [], fn ($v) => $v !== ''));
        $payload = [
            'scope' => $this->bpScope,
            'exclude_databases' => $list($this->bpExcludeDatabases),
            'exclude_tables' => $list($this->bpExcludeTables),
            'destinations' => array_values($this->bpDestinations),
            'keep' => (int) $this->bpKeep,
            'keep_days' => (int) $this->bpKeepDays,
            'keep_weeks' => (int) $this->bpKeepWeeks,
            'encrypt' => $this->bpEncrypt,
        ];
        if ($this->bpScope !== 'db') {
            $payload['apps'] = $list($this->bpApps);
        }
        if ($this->bpScope !== 'files') {
            $payload['databases'] = $list($this->bpDatabases);
        }
        if ($this->bpSchedule === 'every') {
            $payload['every'] = $this->bpEvery;
        } else {
            $payload['cron'] = $this->bpCron;
        }

        try {
            if ($isEdit) {
                $this->client()->updateBackupProfile((string) $this->backupEditing, $payload);
            } else {
                $this->client()->createBackupProfile(['name' => $this->bpName] + $payload);
            }
            $this->showBackupForm = false;
            $this->dispatch('notify', type: 'success', message: "Profile '{$this->bpName}' ".($isEdit ? 'updated.' : 'created — its first run follows its schedule, or start it now.'));
            $this->loadBackups();
        } catch (CipiApiException $e) {
            // What the CLI refused (an unknown destination, nothing to take): shown in the form.
            $this->addError('bpName', $this->friendlyApiError($e));
        }
    }

    /**
     * The `every` value a profile's schedule corresponds to, when it is one of
     * the choices of the form; null for any other cron expression.
     *
     * @param  array<string, mixed>  $profile
     */
    protected static function backupEveryFor(array $profile): ?string
    {
        $cron = is_string($profile['cron'] ?? null) ? trim($profile['cron']) : '';

        return match (true) {
            $cron === '*/15 * * * *' => '15m',
            $cron === '*/30 * * * *' => '30m',
            $cron === '0 * * * *' || $cron === '0 */1 * * *' => '1h',
            $cron === '0 */6 * * *' => '6h',
            $cron === '0 */12 * * *' => '12h',
            $cron === '0 2 * * *' => '1d',
            $cron === '0 2 */7 * *' => '7d',
            default => null,
        };
    }

    /**
     * "every 30 min", "every 6 h", "daily", "weekly" from a profile's interval —
     * the cron expression itself is the tooltip.
     *
     * @param  array<string, mixed>  $profile
     */
    public function backupScheduleLabel(array $profile): string
    {
        $seconds = is_numeric($profile['interval_seconds'] ?? null) ? (int) $profile['interval_seconds'] : 0;

        return match (true) {
            $seconds <= 0 => (string) ($profile['cron'] ?? '—'),
            $seconds < 3600 => 'every '.max(1, intdiv($seconds, 60)).' min',
            $seconds < 86400 => 'every '.intdiv($seconds, 3600).' h',
            $seconds === 86400 => 'daily',
            $seconds === 604800 => 'weekly',
            $seconds % 86400 === 0 => 'every '.intdiv($seconds, 86400).' days',
            default => (string) ($profile['cron'] ?? '—'),
        };
    }

    /** @param  array<string, mixed>  $profile */
    public function backupRetentionLabel(array $profile): string
    {
        $r = is_array($profile['retention'] ?? null) ? $profile['retention'] : [];
        $parts = [];
        if ((int) ($r['keep'] ?? 0) > 0) {
            $parts[] = (int) $r['keep'].' runs';
        }
        if ((int) ($r['days'] ?? 0) > 0) {
            $parts[] = (int) $r['days'].' days';
        }
        if ((int) ($r['weeks'] ?? 0) > 0) {
            $parts[] = (int) $r['weeks'].' weeks';
        }

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    /**
     * State of a profile for its column header: running, disabled, overdue,
     * failed, ok, or never (no run yet).
     *
     * @param  array<string, mixed>  $profile
     */
    public function backupProfileState(array $profile): string
    {
        return match (true) {
            ! empty($profile['running']) => 'running',
            empty($profile['enabled']) => 'disabled',
            ! empty($profile['overdue']) => 'overdue',
            ($profile['last']['status'] ?? null) === 'error' => 'failed',
            ($profile['last']['status'] ?? null) === 'ok' => 'ok',
            default => 'never',
        };
    }
}
