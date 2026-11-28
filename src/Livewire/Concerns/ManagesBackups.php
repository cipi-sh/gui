<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Livewire\Attributes\Locked;

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

    /** Paths left out of the app archives, comma-separated (Cipi 5.13.0). */
    public string $bpExcludePaths = '';

    /** The profile being edited had exclude_paths: an emptied field clears them. */
    public bool $bpHadExcludePaths = false;

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

    // ── SFTP destinations: a server over SSH, a key Cipi makes ──

    public bool $showSftpForm = false;

    #[Locked]
    public ?string $sftpEditing = null;

    public string $sftpName = '';

    public string $sftpHost = '';

    public string $sftpPort = '22';

    public string $sftpUser = '';

    public string $sftpPath = '';

    /** The destination just added or changed, with the key the remote must accept. */
    #[Locked]
    public ?array $sftpAdded = null;

    public const BACKUP_EVERY = ['15m' => 'Every 15 minutes', '30m' => 'Every 30 minutes', '1h' => 'Every hour', '6h' => 'Every 6 hours', '12h' => 'Every 12 hours', '1d' => 'Every day (02:00)', '7d' => 'Every 7 days (02:00)'];

    protected function loadBackups(): void
    {
        $data = $this->client()->backupStatus();

        // "Not configured" is something only the server can say. An answer
        // without the flag — an empty body, a proxy's page, another shape — is
        // a read that failed, and is shown as one (4.2.1: it used to be drawn
        // as a server where `cipi backup configure` was never run).
        if (! array_key_exists('configured', $data) || ! (is_bool($data['configured']) || in_array($data['configured'], [0, 1, '0', '1', 'true', 'false'], true))) {
            throw new CipiApiException('GET /api/backups answered without a backup status (no "configured" in it), so the panel cannot tell whether backups are configured. Read it on the server: cipi backup status', 502);
        }

        $data['configured'] = filter_var($data['configured'], FILTER_VALIDATE_BOOLEAN);
        // Where the answer comes from (API 1.39.1+): shown with "not configured".
        foreach (['host', 'config_file'] as $key) {
            $data[$key] = is_string($data[$key] ?? null) ? mb_substr($data[$key], 0, 255) : null;
        }
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
            $this->dispatch('notify', type: $this->backupTest['ok'] ? 'success' : 'error', message: $this->backupTest['ok'] ? 'Every destination answered.' : 'A destination could not be reached — see the output.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── SFTP destinations ─────────────────────────────────────────────

    /** A Hetzner Storage Box: its address, port 23. */
    public function sftpPresetStorageBox(): void
    {
        $this->sftpPort = '23';
        if ($this->sftpUser !== '' && $this->sftpHost === '') {
            $this->sftpHost = $this->sftpUser.'.your-storagebox.de';
        }
    }

    public function openSftpForm(string $name = ''): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }
        $this->resetErrorBag();
        $this->sftpAdded = null;
        $dest = $name === '' ? null : collect($this->backup['destinations'] ?? [])->firstWhere('name', $name);
        if ($name !== '' && (($dest['type'] ?? '') !== 'sftp')) {
            return;
        }
        $this->sftpEditing = $dest !== null ? $name : null;
        $this->sftpName = $dest !== null ? $name : '';
        $this->sftpHost = (string) ($dest['host'] ?? '');
        $this->sftpPort = (string) ($dest['port'] ?? '22');
        $this->sftpUser = (string) ($dest['user'] ?? '');
        $this->sftpPath = (string) ($dest['path'] ?? '');
        $this->showSftpForm = true;
    }

    public function closeSftpForm(): void
    {
        $this->showSftpForm = false;
        $this->sftpEditing = null;
    }

    public function saveSftpDestination(bool $newKey = false): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }
        $this->resetErrorBag();
        $this->sftpHost = trim($this->sftpHost);
        $this->sftpUser = trim($this->sftpUser);
        $this->sftpPath = trim(trim($this->sftpPath), ' ');
        $errors = \CipiGui\Support\BackupSftp::errors($this->sftpEditing ?? $this->sftpName, $this->sftpHost, $this->sftpPort, $this->sftpUser, $this->sftpPath, $this->sftpEditing === null ? array_column($this->backup['destinations'] ?? [], 'name') : []);
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return;
        }
        $payload = ['host' => $this->sftpHost, 'port' => (int) $this->sftpPort, 'user' => $this->sftpUser, 'path' => rtrim($this->sftpPath, '/')];

        try {
            $this->sftpAdded = $this->sftpEditing === null
                ? $this->client()->createSftpDestination(['name' => $this->sftpName] + $payload)
                : $this->client()->updateSftpDestination($this->sftpEditing, $payload + ($newKey ? ['new_key' => true] : []));
            $this->showSftpForm = false;
            $this->dispatch('notify', type: 'success', message: $this->sftpEditing === null
                ? "Destination {$this->sftpName} added — install its key on the remote, then test it."
                : ($newKey ? "A new key for {$this->sftpEditing} — install it on the remote." : "Destination {$this->sftpEditing} updated."));
            $this->sftpEditing = null;
            $this->loadBackups();
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 422) {
                $this->addError('sftpHost', self::plainCliText($e->getMessage()));

                return;
            }
            if (in_array($e->getStatusCode(), [404, 405], true) || $e->getStatusCode() === 503) {
                $this->addError('sftpHost', 'SFTP destinations need an up-to-date Cipi on this server (cipi self-update && cipi api update).');

                return;
            }
            $this->handleApiError($e);
        }
    }

    /** Show the key of an SFTP destination again. */
    public function showSftpKey(string $name): void
    {
        if (! $this->serverAllowed(Access::VIEW, (int) $this->serverId)) {
            return;
        }

        try {
            $dest = collect($this->backup['destinations'] ?? [])->firstWhere('name', $name);
            $this->sftpAdded = ($dest ?? ['name' => $name]) + ['public_key' => $this->client()->backupDestinationKey($name)];
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function testBackupDestination(string $name): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        try {
            $this->backupTest = $this->client()->testBackupDestinations($name);
            $this->dispatch('notify', type: $this->backupTest['ok'] ? 'success' : 'error', message: $this->backupTest['ok'] ? "{$name} answered: a file was written and removed there." : "{$name} could not be reached — see the output.");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function deleteBackupDestination(string $name): void
    {
        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId) || in_array($name, ['local', 's3'], true)) {
            return;
        }

        try {
            $this->client()->deleteBackupDestination($name);
            if (($this->sftpAdded['name'] ?? null) === $name) {
                $this->sftpAdded = null;
            }
            $this->dispatch('notify', type: 'success', message: "Destination {$name} removed — the archives on it stay there.");
            $this->loadBackups();
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 422) {
                $this->dispatch('notify', type: 'error', message: self::plainCliText($e->getMessage()));

                return;
            }
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
            $paths = array_values(array_filter((array) ($existing['exclude_paths'] ?? []), 'is_string'));
            $this->bpExcludePaths = implode(', ', $paths);
            $this->bpHadExcludePaths = $paths !== [];
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
            $this->reset(['bpName', 'bpScope', 'bpApps', 'bpDatabases', 'bpExcludeDatabases', 'bpExcludeTables', 'bpExcludePaths', 'bpHadExcludePaths', 'bpSchedule', 'bpEvery', 'bpCron', 'bpKeep', 'bpKeepDays', 'bpKeepWeeks', 'bpEncrypt']);
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
            'bpExcludePaths' => ['nullable', 'string', 'max:4000'],
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

        $excludePaths = self::backupExcludePaths($this->bpExcludePaths);
        foreach ($excludePaths as $path) {
            if (! self::validBackupExcludePath($path)) {
                $this->addError('bpExcludePaths', "“{$path}”: a path relative to the app (storage/app/uploads, *.mp4), optionally shop: first — never absolute, never the whole app.");

                return;
            }
        }

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
            // Sent only when there is something to set or to clear: a server
            // before Cipi 5.13.0 / API 1.43 knows nothing of it.
            if ($excludePaths !== [] || $this->bpHadExcludePaths) {
                $payload['exclude_paths'] = $excludePaths;
            }
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

    /**
     * The paths of the field: comma or newline separated, spaces inside a path
     * kept (tar patterns may have them), duplicates dropped.
     *
     * @return list<string>
     */
    public static function backupExcludePaths(string $value): array
    {
        $out = [];
        foreach (preg_split('/\s*[,\n]\s*/', trim($value)) ?: [] as $path) {
            $path = trim($path);
            if ($path !== '' && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** The same rule as cipi backup profile --exclude-paths and the API. */
    public static function validBackupExcludePath(string $path): bool
    {
        if (strlen($path) > 255 || ! preg_match('/^(?:[a-z0-9*?-]+:)?(?!\/)[A-Za-z0-9._*?\/@+ \[\]-]{1,255}$/', $path)) {
            return false;
        }
        $pattern = (string) preg_replace('/^[a-z0-9*?-]+:/', '', $path);

        return ! in_array($pattern, ['*', '*/', 'shared', 'htdocs'], true) && ! str_starts_with($pattern, '/');
    }
}
