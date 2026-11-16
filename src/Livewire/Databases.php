<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Livewire\Concerns\ManagesAsyncJobs;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Panel;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('cipi-gui::layouts.app')]
#[Title('Databases')]
class Databases extends Component
{
    use InteractsWithCipiServer;
    use ManagesAsyncJobs;

    /**
     * `app` and `link` say which app a database belongs to (API 1.39+ / Cipi ≥
     * 5.9.0): linked (cipi db link), own (created with a Laravel app) or
     * namespace (<app>_…). `linkable` is false on a server that does not report it.
     *
     * @var array<int, array{name: string, size: ?string, engine: ?string, user: ?string, app: ?string, link: ?string, linkable: bool, server_id: int, server_name: string, can_manage: bool}>
     */
    public array $databases = [];

    /** @var array<int, list<array{engine: string, status?: string, port?: int|null, default?: bool}>> server id => installed engines */
    public array $enginesByServer = [];

    /** @var array<int, string> server id => default engine */
    public array $defaultEngineByServer = [];

    /** @var array<int, string> server id => why its databases could not be loaded */
    public array $serverErrors = [];

    /** Servers whose API has no /dbs/engines (API < 1.12). */
    public array $enginesUnsupported = [];

    public bool $loading = true;

    /** Settings → Panel: every server in one list (fleet view) or the current server only. */
    public bool $fleet = true;

    /** Server whose databases are shown — 0 for every server (`?server=`). */
    #[Url(as: 'server', except: 0)]
    public int $scopeServerId = 0;

    #[Url(as: 'engine', except: 'all')]
    public string $engineFilter = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // Create
    public bool $showCreateModal = false;

    public int $createServerId = 0;

    public string $dbName = '';

    public string $dbEngine = '';

    /** App the new database is shown as belonging to ('' = none) — API 1.39+ / Cipi ≥ 5.9.0. */
    public string $dbApp = '';

    /**
     * What a server can do beyond the list, asked when a dialog needs it:
     * server id → ['uploads' => limits of a dump upload, or null] — null on a
     * server before API 1.39 / Cipi 5.9.0, which also has no links.
     *
     * @var array<int, array{uploads: ?array{max_size: int, chunk_size: int}}>
     */
    public array $features = [];

    /** @var array<int, list<string>> server id → its app names, for the dialogs that link a database */
    public array $appsByServer = [];

    // Link to an app
    public ?string $linkName = null;

    public ?string $linkEngine = null;

    public string $linkApp = '';

    // Restore
    public ?string $restoreName = null;

    public ?string $restoreEngine = null;

    public string $restoreFile = '';

    public string $restoreConfirmation = '';

    /** file (a dump already on the server) | upload (one sent from this computer) */
    public string $restoreMode = 'file';

    /** Pending backup job: the file it returns is remembered for restore. */
    public ?string $pendingBackup = null;

    public function mount(): void
    {
        $this->ensureServerSelected();
        $this->fleet = Panel::fleetView();

        if (! $this->fleet) {
            $this->scopeServerId = (int) ($this->serverId ?? 0);
        }
    }

    /** Called by wire:init so the page paints before the servers answer. */
    public function loadDatabases(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->databases = [];
        $this->enginesByServer = [];
        $this->defaultEngineByServer = [];
        $this->serverErrors = [];
        $this->enginesUnsupported = [];

        $servers = $this->fleet ? $this->activeServers() : collect(array_filter([$this->currentServer()]));

        if ($servers->isEmpty()) {
            $this->loading = false;

            return;
        }

        $user = $this->panelUser();
        $engines = CipiApiClient::getForMany($servers, '/dbs/engines');
        $lists = CipiApiClient::getForMany($servers, '/dbs');

        foreach ($servers as $server) {
            $id = $server->id;
            $engineResult = $engines[$id] ?? ['data' => null, 'error' => 'No response', 'status' => 503];
            $listResult = $lists[$id] ?? ['data' => null, 'error' => 'No response', 'status' => 503];

            if (is_array($engineResult['data'])) {
                $installed = array_values(array_filter(
                    is_array($engineResult['data']['engines'] ?? null) ? $engineResult['data']['engines'] : [],
                    fn ($item) => is_array($item) && is_string($item['engine'] ?? null) && in_array($item['status'] ?? '', ['installed', 'running'], true),
                ));
                $this->enginesByServer[$id] = $installed;
                $default = $engineResult['data']['default'] ?? null;
                foreach ($installed as $item) {
                    if (! is_string($default) && ! empty($item['default'])) {
                        $default = $item['engine'];
                    }
                }
                $this->defaultEngineByServer[$id] = is_string($default) && $default !== '' ? $default : (string) ($installed[0]['engine'] ?? '');
            } elseif (in_array((int) ($engineResult['status'] ?? 0), [403, 404, 405, 501], true)) {
                $this->enginesUnsupported[$id] = true;
                $this->enginesByServer[$id] = [];
            }

            if (! is_array($listResult['data'])) {
                $this->serverErrors[$id] = $this->friendlyApiError(new CipiApiException((string) ($listResult['error'] ?: 'No response'), (int) ($listResult['status'] ?? 500)));

                continue;
            }

            $canManage = Access::canManageServer($user, $id);
            foreach ($listResult['data'] as $db) {
                if (! is_array($db) || ! isset($db['name'])) {
                    continue;
                }
                $this->databases[] = [
                    'name' => (string) $db['name'],
                    'size' => isset($db['size']) ? (string) $db['size'] : null,
                    'engine' => isset($db['engine']) && is_string($db['engine']) && $db['engine'] !== '' ? $db['engine'] : null,
                    'user' => isset($db['user']) ? (string) $db['user'] : null,
                    'app' => isset($db['app']) && is_string($db['app']) && $db['app'] !== '' ? $db['app'] : null,
                    'link' => isset($db['link']) && is_string($db['link']) && $db['link'] !== '' ? $db['link'] : null,
                    'linkable' => array_key_exists('link', $db),
                    'server_id' => $id,
                    'server_name' => $server->name,
                    'can_manage' => $canManage,
                ];
            }
        }

        usort($this->databases, fn (array $a, array $b) => strcmp($a['name'], $b['name']) ?: strcmp($a['server_name'], $b['server_name']));
        $this->loading = false;
    }

    /** Show one server's databases (0 = every server). */
    public function setScope(int $serverId): void
    {
        if ($this->fleet) {
            $this->scopeServerId = $serverId;
        }
    }

    public function setEngineFilter(string $engine): void
    {
        $this->engineFilter = $engine;
    }

    // ── create ───────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->reset(['dbName', 'dbApp']);
        $this->resetErrorBag();
        $this->error = null;

        $target = $this->scopeServerId !== 0 ? $this->scopeServerId : (int) ($this->serverId ?? 0);
        $this->selectCreateServer($target);
        $this->showCreateModal = true;
    }

    public function closeCreate(): void
    {
        $this->showCreateModal = false;
    }

    public function updatedCreateServerId(mixed $value): void
    {
        $this->selectCreateServer((int) $value);
    }

    protected function selectCreateServer(int $id): void
    {
        $servers = $this->manageableServers();
        $server = $servers->firstWhere('id', $id) ?? $servers->first();

        if (! $server) {
            $this->createServerId = 0;

            return;
        }

        $this->createServerId = $server->id;
        $this->serverId = $server->id;

        if (! array_key_exists($server->id, $this->enginesByServer) && ! isset($this->enginesUnsupported[$server->id])) {
            $this->loadEnginesOf($server->id);
        }

        $engines = array_column($this->enginesByServer[$server->id] ?? [], 'engine');
        $this->dbEngine = in_array($this->dbEngine, $engines, true) ? $this->dbEngine : (string) ($this->defaultEngineByServer[$server->id] ?? ($engines[0] ?? ''));

        // "Belongs to app": only where the server can keep the link.
        if ($this->linksSupported($server->id)) {
            $this->loadAppsOf($server->id);
        }
        if (! in_array($this->dbApp, $this->appsByServer[$server->id] ?? [], true)) {
            $this->dbApp = '';
        }
    }

    /**
     * What the action server offers beyond the list (dump upload, links), asked
     * once per page: one request, answered from the API's config.
     *
     * @return array{uploads: ?array{max_size: int, chunk_size: int}}
     */
    protected function featuresOf(int $serverId): array
    {
        if (! isset($this->features[$serverId])) {
            $limits = null;
            try {
                $data = $this->client()->dbUploadLimits();
                if ($data !== null) {
                    $limits = ['max_size' => (int) $data['max_size'], 'chunk_size' => (int) $data['chunk_size']];
                }
            } catch (CipiApiException) {
                // No route (API before 1.39), a 503 (Cipi before 5.9.0), a token without dbs-manage: not offered.
            }
            $this->features[$serverId] = ['uploads' => $limits];
        }

        return $this->features[$serverId];
    }

    /** The server keeps database ↔ app links: its list says so, or its API is 1.39+ on Cipi 5.9.0+. */
    protected function linksSupported(int $serverId): bool
    {
        foreach ($this->databases as $db) {
            if ((int) $db['server_id'] === $serverId && $db['linkable']) {
                return true;
            }
        }

        return $this->featuresOf($serverId)['uploads'] !== null;
    }

    /** App names of the action server, for a select. The user sees only the apps they may view. */
    protected function loadAppsOf(int $serverId): void
    {
        if (isset($this->appsByServer[$serverId])) {
            return;
        }

        $names = [];
        try {
            $user = $this->panelUser();
            $seesAll = Access::seesAllAppsOn($user, $serverId);
            foreach ($this->client()->listApps() as $app) {
                $name = is_array($app) ? (string) ($app['app'] ?? '') : '';
                if ($name !== '' && ($seesAll || Access::can($user, 'view', $serverId, $name))) {
                    $names[] = $name;
                }
            }
            sort($names);
        } catch (CipiApiException) {
            // The dialog works without the select.
        }
        $this->appsByServer[$serverId] = $names;
    }

    /** Engines of one server, for the create dialog when the list was loaded for another scope. */
    protected function loadEnginesOf(int $serverId): void
    {
        try {
            $data = $this->client()->listDatabaseEngines();
            $installed = array_values(array_filter(
                is_array($data['engines'] ?? null) ? $data['engines'] : [],
                fn ($item) => is_array($item) && is_string($item['engine'] ?? null) && in_array($item['status'] ?? '', ['installed', 'running'], true),
            ));
            $this->enginesByServer[$serverId] = $installed;
            $default = $data['default'] ?? null;
            $this->defaultEngineByServer[$serverId] = is_string($default) && $default !== '' ? $default : (string) ($installed[0]['engine'] ?? '');
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e)) {
                $this->enginesUnsupported[$serverId] = true;
                $this->enginesByServer[$serverId] = [];
            } else {
                $this->handleApiError($e);
            }
        }
    }

    public function createDatabase(): void
    {
        if ($this->createServerId === 0 || ! $this->useServer($this->createServerId) || ! $this->serverAllowed(Access::MANAGE, $this->createServerId)) {
            return;
        }

        $engines = array_column($this->enginesByServer[$this->createServerId] ?? [], 'engine');
        $rules = ['dbName' => ['required', 'regex:/^[a-z][a-z0-9]{2,31}$/']];
        if ($engines !== []) {
            $rules['dbEngine'] = ['required', 'in:'.implode(',', $engines)];
        }

        $this->validate($rules, ['dbName.regex' => 'Use 3–32 lowercase letters or digits, starting with a letter (same rule as app names).']);

        $name = $this->dbName;
        $engine = $this->dbEngine !== '' ? $this->dbEngine : null;

        // The app comes from the list the dialog offered, never from the request alone.
        $app = $this->dbApp !== '' && in_array($this->dbApp, $this->appsByServer[$this->createServerId] ?? [], true) ? $this->dbApp : null;
        if ($this->dbApp !== '' && $app === null) {
            $this->addError('dbApp', 'Choose one of the apps of this server.');

            return;
        }

        $label = "Create database {$name}".($app !== null ? " for {$app}" : '').$this->jobServerSuffix();
        if ($this->startJob($label, fn (CipiApiClient $api) => $api->createDatabase($name, $engine, $app))) {
            $this->showCreateModal = false;
        }
    }

    // ── link to an app ────────────────────────────────────────────────

    public function openLink(string $name, string $engine = '', int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $this->loadAppsOf((int) $this->serverId);
        $this->linkName = $name;
        $this->linkEngine = $engine !== '' ? $engine : null;
        $this->linkApp = '';
        foreach ($this->databases as $db) {
            if ($db['name'] === $name && (int) $db['server_id'] === (int) $this->serverId && ($db['engine'] ?? '') === $engine && $db['link'] === 'linked') {
                $this->linkApp = (string) $db['app'];
            }
        }
    }

    public function closeLink(): void
    {
        $this->linkName = null;
    }

    public function saveLink(): void
    {
        if ($this->linkName === null || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $apps = $this->appsByServer[(int) $this->serverId] ?? [];
        $this->validate(['linkApp' => ['required', 'in:'.implode(',', $apps)]], ['linkApp.required' => 'Choose one of the apps of this server.', 'linkApp.in' => 'Choose one of the apps of this server.']);

        try {
            $this->client()->linkDatabase($this->linkName, $this->linkApp, $this->linkEngine);
            $this->dispatch('notify', type: 'success', message: "{$this->linkName} is now shown as a database of {$this->linkApp}.");
            $this->linkName = null;
            $this->loadDatabases();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function unlink(string $name, string $engine = '', int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        try {
            $this->client()->unlinkDatabase($name, $engine !== '' ? $engine : null);
            $this->dispatch('notify', type: 'success', message: "{$name} is no longer linked to an app. The database itself is untouched.");
            $this->linkName = null;
            $this->loadDatabases();
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── row actions (each row carries its server) ─────────────────────

    public function backup(string $name, string $engine = '', int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->pendingBackup = $name;
        $this->startJob("Back up {$name}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->backupDatabase($name, $engine !== '' ? $engine : null));
    }

    public function openRestore(string $name, string $engine = '', int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->resetErrorBag();
        $this->restoreName = $name;
        $this->restoreEngine = $engine !== '' ? $engine : null;
        $this->restoreFile = $this->rememberedBackups()[$name] ?? '/home/cipi/backups/';
        $this->restoreConfirmation = '';
        $this->restoreMode = 'file';
        $this->featuresOf((int) $this->serverId); // can this server take an upload?
    }

    public function setRestoreMode(string $mode): void
    {
        $this->resetErrorBag();
        $this->restoreMode = $mode === 'upload' && ($this->features[(int) $this->serverId]['uploads'] ?? null) !== null ? 'upload' : 'file';
    }

    /**
     * Restore from a dump the browser has just finished uploading (the Restore
     * dialog sends it in chunks through DbUploadController, then calls this
     * with the id the server gave). Same confirmation, same permission.
     */
    public function restoreFromUpload(string $uploadId): void
    {
        if ($this->restoreName === null) {
            return;
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uploadId)) {
            $this->addError('restoreUpload', 'The upload did not finish. Choose the file again.');

            return;
        }

        $this->validate(
            ['restoreConfirmation' => ['required', 'in:'.$this->restoreName]],
            ['restoreConfirmation.in' => 'Type the database name to confirm.', 'restoreConfirmation.required' => 'Type the database name to confirm.'],
        );

        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $name = (string) $this->restoreName;
        $engine = $this->restoreEngine;

        if ($this->startJob("Restore {$name} from the uploaded dump".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->restoreDatabaseFromUpload($name, $uploadId, $engine))) {
            $this->restoreName = null;
        }
    }

    public function closeRestore(): void
    {
        $this->restoreName = null;
    }

    public function restore(): void
    {
        $this->validate([
            'restoreFile' => ['required', 'string', 'max:512', 'regex:#^/[\w./-]+\.sql\.gz$#'],
            'restoreConfirmation' => ['required', 'in:'.$this->restoreName],
        ], [
            'restoreFile.regex' => 'Use an absolute path to a .sql.gz dump on the server.',
            'restoreConfirmation.in' => 'Type the database name to confirm.',
        ]);

        if (! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $name = (string) $this->restoreName;
        $file = $this->restoreFile;
        $engine = $this->restoreEngine;

        if ($this->startJob("Restore {$name}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->restoreDatabase($name, $file, $engine))) {
            $this->restoreName = null;
        }
    }

    public function regeneratePassword(string $name, string $engine = '', int $serverId = 0): void
    {
        if (! $this->useServer($serverId) || ! $this->serverAllowed(Access::MANAGE, (int) $this->serverId)) {
            return;
        }

        $this->startJob("New password for {$name}".$this->jobServerSuffix(), fn (CipiApiClient $api) => $api->regenerateDbPassword($name, $engine !== '' ? $engine : null));
    }

    /** " on fra1" when more than one server is in view. */
    protected function jobServerSuffix(): string
    {
        if (! $this->fleet || $this->activeServers()->count() < 2) {
            return '';
        }

        return ' on '.($this->currentServer()?->name ?? 'server');
    }

    /** @return array<string, string> last backup file per database on the action server (this session) */
    protected function rememberedBackups(): array
    {
        return (array) (session('cipi_gui_db_backups', [])[$this->serverId] ?? []);
    }

    protected function onJobCompleted(array $data): void
    {
        $file = $data['result']['file'] ?? null;
        if ($this->pendingBackup && is_string($file) && $file !== '') {
            $all = session('cipi_gui_db_backups', []);
            $all[$this->serverId][$this->pendingBackup] = $file;
            session(['cipi_gui_db_backups' => $all]);
        }
        $this->pendingBackup = null;

        $this->loadDatabases();
    }

    protected function onJobFailed(array $data): void
    {
        $this->pendingBackup = null;
        $this->loadDatabases(); // the server may have changed partway
    }

    public function render()
    {
        $servers = $this->fleet ? $this->activeServers() : collect(array_filter([$this->currentServer()]));
        $scopeServer = $this->scopeServerId !== 0 ? $servers->firstWhere('id', $this->scopeServerId) : null;
        if (! $this->fleet) {
            $scopeServer = $servers->first();
        }
        $scopeId = $scopeServer?->id ?? 0;

        $serverCounts = [];
        foreach ($this->databases as $db) {
            $serverCounts[$db['server_id']] = ($serverCounts[$db['server_id']] ?? 0) + 1;
        }

        $scoped = $scopeId === 0 ? $this->databases : array_values(array_filter($this->databases, fn (array $db) => (int) $db['server_id'] === $scopeId));

        // Engine cards: the engines installed on the servers in view, with how many databases each holds.
        $engineCards = [];
        foreach ($servers as $server) {
            if ($scopeId !== 0 && $server->id !== $scopeId) {
                continue;
            }
            foreach ($this->enginesByServer[$server->id] ?? [] as $item) {
                $engine = (string) $item['engine'];
                $engineCards[$engine] ??= ['engine' => $engine, 'servers' => 0, 'port' => null, 'default' => false];
                $engineCards[$engine]['servers']++;
                $engineCards[$engine]['port'] = $item['port'] ?? $engineCards[$engine]['port'];
                if ($scopeId !== 0 && ($this->defaultEngineByServer[$server->id] ?? '') === $engine) {
                    $engineCards[$engine]['default'] = true;
                }
            }
        }

        $counts = ['all' => count($scoped)];
        foreach ($scoped as $db) {
            $engine = $db['engine'] ?? 'mariadb';
            $counts[$engine] = ($counts[$engine] ?? 0) + 1;
            $engineCards[$engine] ??= ['engine' => $engine, 'servers' => 0, 'port' => null, 'default' => false];
        }
        ksort($engineCards);

        $needle = mb_strtolower(trim($this->search));
        $visible = array_values(array_filter($scoped, fn (array $db) => ($this->engineFilter === 'all' || ($db['engine'] ?? 'mariadb') === $this->engineFilter)
            && ($needle === '' || str_contains(mb_strtolower($db['name'].' '.($db['user'] ?? '').' '.$db['server_name']), $needle))));

        $errors = $scopeId === 0 ? $this->serverErrors : array_intersect_key($this->serverErrors, [$scopeId => true]);
        $createServers = $this->manageableServers();
        $backups = [];
        foreach ((array) session('cipi_gui_db_backups', []) as $serverId => $files) {
            $backups[(int) $serverId] = (array) $files;
        }

        return view('cipi-gui::livewire.databases', [
            'servers' => $servers,
            'fleet' => $this->fleet,
            'multiServer' => $this->fleet && $servers->count() > 1,
            'scopeServer' => $scopeServer,
            'scopeId' => $scopeId,
            'serverCounts' => $serverCounts,
            'serverErrors' => $errors,
            'engineCards' => array_values($engineCards),
            'scopedCount' => count($scoped),
            'visible' => $visible,
            'counts' => $counts,
            'backups' => $backups,
            'createServers' => $createServers,
            'canCreate' => $createServers->isNotEmpty(),
            'createServer' => $createServers->firstWhere('id', $this->createServerId),
            'createEngines' => $this->enginesByServer[$this->createServerId] ?? [],
            'createDefaultEngine' => $this->defaultEngineByServer[$this->createServerId] ?? null,
            'createApps' => $this->showCreateModal ? ($this->appsByServer[$this->createServerId] ?? []) : [],
            'linkApps' => $this->linkName !== null ? ($this->appsByServer[(int) $this->serverId] ?? []) : [],
            'showAppColumn' => (bool) array_filter($scoped, fn (array $db) => $db['linkable']),
            'restoreUpload' => $this->restoreName !== null ? ($this->features[(int) $this->serverId]['uploads'] ?? null) : null,
            'actionServerId' => (int) ($this->serverId ?? 0),
            'uploadChunkBytes' => \CipiGui\Http\Controllers\DbUploadController::panelChunkBytes(),
            'isAdmin' => Access::isAdmin($this->panelUser()),
        ])->title($scopeServer ? 'Databases · '.$scopeServer->name : 'Databases');
    }
}
