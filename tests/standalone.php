<?php
// Standalone checks for the parts of the panel that do not need the framework
// (php tests/standalone.php — no vendor/ required): the pure helpers behind
// the deploy history, the dump upload and the saved commands, and what the
// views must and must not contain. The PHPUnit suite covers the pages.
namespace CipiGui\Support {
    // The settings store, in memory.
    class Panel
    {
        public static array $store = [];
        public static function get(string $key, ?string $default = null): ?string { return self::$store[$key] ?? $default; }
        public static function set(string $key, ?string $value): void { if ($value === null) { unset(self::$store[$key]); } else { self::$store[$key] = $value; } }
        public static function flush(): void {}
    }
}
namespace {
    $root = $argv[1] ?? dirname(__DIR__);
    $GLOBALS['CFG'] = ['cipi-gui.db_upload_chunk_mb' => 4];
    function config(string $k, mixed $d = null): mixed { return $GLOBALS['CFG'][$k] ?? $d; }
    require $root.'/src/Support/DeployLog.php';
    require $root.'/src/Support/Presets.php';
    require $root.'/src/Support/Metrics.php';
    require $root.'/src/Support/Notifications.php';
    require $root.'/src/Http/Controllers/DbUploadController.php';
    require $root.'/src/Services/CipiApiException.php';
    require $root.'/src/Livewire/Concerns/ManagesBackups.php';

    use CipiGui\Http\Controllers\DbUploadController as Up;
    use CipiGui\Support\DeployLog;
    use CipiGui\Support\Panel;
    use CipiGui\Support\Presets;

    $pass = 0; $fail = 0;
    $check = function (bool $ok, string $what) use (&$pass, &$fail): void {
        if ($ok) { $pass++; echo "  OK: {$what}\n"; } else { $fail++; fwrite(STDERR, "  FAIL: {$what}\n"); }
    };

    echo "--- deploy history\n";
    $b = DeployLog::bannersFromRuns([
        ['result' => 'ok', 'kind' => 'deploy', 'release' => '31', 'duration' => 76],
        ['result' => 'ok', 'kind' => 'rollback', 'release' => '29', 'duration' => 9],
        ['result' => 'failed', 'kind' => 'deploy', 'release' => '30', 'duration' => '40'],
        ['result' => 'ok', 'kind' => 'deploy', 'release' => null, 'duration' => 5],
        ['result' => 'ok', 'kind' => 'deploy', 'release' => '28'],
        'junk',
    ]);
    $check($b === [['event' => 'failed', 'release' => '30', 'seconds' => 40], ['event' => 'rollback', 'release' => '29', 'seconds' => 9], ['event' => 'published', 'release' => '31', 'seconds' => 76]],
        'runs become banners: oldest first, the ledger\'s words, runs without a release or a duration left out');
    $check(DeployLog::bannersFromRuns([]) === [], 'no runs, no banners');
    $check(DeployLog::format(125) === '2m05s', 'durations read like the CLI');

    echo "--- dump upload\n";
    $check(Up::iniBytes('64M') === 67108864 && Up::iniBytes('2G') === 2147483648 && Up::iniBytes('8388608') === 8388608 && Up::iniBytes('512k') === 524288, 'php.ini sizes are read (K, M, G, bytes)');
    $check(Up::iniBytes('') === 0 && Up::iniBytes('-1') === 0 && Up::iniBytes('lots') === 0, 'unlimited or unreadable is 0, not a limit');
    $check(Up::humanBytes(2147483648) === '2 GB' && Up::humanBytes(524288000) === '500 MB' && Up::humanBytes(1610612736) === '1.5 GB' && Up::humanBytes(900) === '900 B', 'sizes for people');
    $chunk = Up::panelChunkBytes();
    $php = min(array_filter([Up::iniBytes((string) ini_get('upload_max_filesize')), Up::iniBytes((string) ini_get('post_max_size'))]) ?: [PHP_INT_MAX]);
    $check($chunk >= 65536 && $chunk <= 4 * 1048576 && $chunk < $php, "a chunk is at most the configured 4 MB and fits in what this PHP accepts ({$chunk} bytes here)");
    $GLOBALS['CFG']['cipi-gui.db_upload_chunk_mb'] = 0;
    $check(Up::panelChunkBytes() >= 65536, 'a chunk size of 0 in the config is not taken literally');
    $GLOBALS['CFG']['cipi-gui.db_upload_chunk_mb'] = 4;

    echo "--- saved commands\n";
    $check(Presets::get('artisan') === Presets::DEFAULTS['artisan'] && ! Presets::isCustom('artisan'), 'until somebody edits the list, the defaults');
    $check(Presets::normalize('artisan', '  php   artisan  horizon:terminate ') === 'horizon:terminate' && Presets::normalize('run', ' npm   ci ') === 'npm ci', 'a command is stored on one line, without "php artisan"');
    Presets::add('artisan', 'php artisan horizon:terminate');
    $check(in_array('horizon:terminate', Presets::get('artisan'), true) && Presets::isCustom('artisan') && count(Presets::get('artisan')) === count(Presets::DEFAULTS['artisan']) + 1, 'a command is added to the list');
    $check(Presets::error('artisan', 'horizon:terminate') === 'That command is already in the list.', 'the same command twice is refused');
    $check(Presets::error('artisan', '   ') === 'Enter a command to save.' && Presets::error('run', str_repeat('x', 201)) !== null && Presets::error('nope', 'x') === 'Unknown list.', 'empty, too long, unknown list: refused');
    $check(Presets::error('run', "ls\nrm -rf x") === null && Presets::normalize('run', "ls\nrm -rf x") === 'ls rm -rf x', 'a pasted line break becomes a space: one line is what is stored');
    $check(Presets::error('run', "ls \x07") !== null, 'a control character is refused');
    Presets::remove('artisan', 'about');
    $check(! in_array('about', Presets::get('artisan'), true), 'a default can be removed like any other');
    foreach (Presets::get('artisan') as $c) { Presets::remove('artisan', $c); }
    $check(Presets::get('artisan') === [] && Presets::isCustom('artisan'), 'an emptied list stays empty (it is not the defaults again)');
    for ($i = 0; $i < Presets::MAX + 5; $i++) { Presets::add('run', 'echo '.$i); }
    $check(count(Presets::get('run')) === Presets::MAX && str_contains((string) Presets::error('run', 'echo last'), 'The list is full'), 'the list stops at '.Presets::MAX.' commands');
    Panel::$store['artisan_presets'] = '{"not":"a list"';
    $check(Presets::get('artisan') === Presets::DEFAULTS['artisan'], 'a stored value that is not a list falls back to the defaults');
    Panel::$store['artisan_presets'] = json_encode(['ok', 7, '', "two\nlines", 'ok', str_repeat('y', 300)]);
    $check(Presets::get('artisan') === ['ok'], 'what is read back is cleaned: strings, one line, no duplicates');
    $check(Presets::get('nope') === [] && ! Presets::isKind('nope'), 'an unknown list is empty');

    echo "--- views\n";
    $views = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if (str_ends_with((string) $f, '.blade.php')) { $views[(string) $f] = (string) file_get_contents((string) $f); } }
    $all = implode("\n", $views);
    $check(! str_contains($all, 'wire:confirm'), 'no view asks with the browser\'s confirm() any more');
    $check(str_contains((string) file_get_contents($root.'/resources/views/layouts/app.blade.php'), "@include('cipi-gui::partials.confirm-dialog')"), 'the layout carries the confirmation dialog');
    preg_match_all('/<[a-z][^<>]*\sdata-confirm="[^"]*"[^<>]*>/s', $all, $m);
    $bare = array_filter($m[0], fn (string $tag) => ! preg_match('/wire:click|type="submit"|x-on:click|@click/', $tag));
    $check(count($m[0]) >= 30 && $bare === [], count($m[0]).' confirmations, each on an element that does something when confirmed');
    foreach (['dashboard' => 'loadStatuses', 'apps' => 'loadApps', 'databases' => 'loadDatabases', 'server-manage' => 'init', 'log-viewer' => 'loadLogs'] as $view => $method) {
        $check(str_contains($views[$root.'/resources/views/livewire/'.$view.'.blade.php'] ?? '', 'wire:init="'.$method.'"'), "{$view}: the page is sent first and asks its server after (wire:init=\"{$method}\")");
    }
    $check(! preg_match('/function mount\([^)]*\)[^{]*\{[^}]*\$this->loadLogs\(\)/s', (string) file_get_contents($root.'/src/Livewire/LogViewer.php')), 'the log viewer reads nothing in mount()');
    // Blade reads "@endif" as a directive only where a word could start. A closing slot tag
    // becomes " @endslot", so a directive right after it is glued to a word too.
    $glued = [];
    foreach ($views as $path => $text) {
        $text = (string) preg_replace('/{{--(.*?)--}}/s', '', $text);
        if (preg_match('/[A-Za-z0-9_]@(if|elseif|else|endif|foreach|endforeach|forelse|endforelse|empty|unless|endunless|error|enderror|isset|endisset|php|endphp|include|class|disabled|js|json)\b|<\/x-slot[^>]*>@\w/', $text, $g)) {
            $glued[] = basename($path).': '.$g[0];
        }
    }
    $check($glued === [], 'no Blade directive is glued to a word or to a closing slot tag (Blade would print it instead of compiling it)'.($glued ? ' — '.implode(', ', $glued) : ''));
    $routes = (string) file_get_contents($root.'/routes/web.php');
    $group = substr($routes, (int) strpos($routes, "Route::middleware(['auth', 'cipi-gui.2fa'])"));
    $check(str_contains($group, 'cipi-gui.db-uploads.start') && str_contains($group, 'cipi-gui.db-uploads.chunk') && str_contains($group, 'cipi-gui.db-uploads.cancel'), 'the upload routes are behind sign-in and two-factor');

    echo "--- 4.2.1: what the panel says must be what the server said\n";
    // The Backups tab's loader, with the API's answer handed to it.
    $page = new class
    {
        use \CipiGui\Livewire\Concerns\ManagesBackups;

        public mixed $answer = [];

        protected function client(): object
        {
            return new class($this->answer)
            {
                public function __construct(private mixed $answer) {}

                public function backupStatus(): array { return $this->answer; }
            };
        }

        /** @return int|null the status of the error the load raised, null when it loaded */
        public function load(mixed $answer): ?int
        {
            $this->answer = $answer;
            $this->backup = [];
            try {
                $this->loadBackups();
            } catch (\CipiGui\Services\CipiApiException $e) {
                return $e->getStatusCode();
            }

            return null;
        }
    };
    $check($page->load(['configured' => true, 'profiles' => [['name' => 'nightly']], 'destinations' => 'junk']) === null && $page->backup['configured'] === true && count($page->backup['profiles']) === 1 && $page->backup['destinations'] === [],
        'a configured server is loaded (and a list that is not one becomes empty, as before)');
    $check($page->load(['configured' => false, 'host' => 'fra1', 'config_file' => '/etc/cipi/backup.json']) === null && $page->backup['configured'] === false && $page->backup['host'] === 'fra1' && $page->backup['config_file'] === '/etc/cipi/backup.json',
        '"not configured" is taken from the server, with where it looked');
    $check($page->load(['configured' => false]) === null && $page->backup['host'] === null && $page->backup['config_file'] === null, 'an API that does not say where it looked (1.39.0) is still read');
    foreach (['an empty answer' => [], 'an answer without the flag' => ['profiles' => [], 'destinations' => []], 'a flag that is not one' => ['configured' => 'maybe'], 'a null flag' => ['configured' => null]] as $what => $answer) {
        $check($page->load($answer) === 502 && $page->backup === [], "{$what} is a read that failed, never \"not configured\"");
    }
    $manage = $views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '';
    $errorAt = strpos($manage, "@elseif(\$activeTab === 'backups' && (\$tabError['backups'] ?? '') !== '')");
    $includeAt = strpos($manage, "@include('cipi-gui::partials.server-backups')");
    $check($errorAt !== false && $includeAt !== false && $errorAt < $includeAt, 'the Backups tab shows a failed read instead of the page drawn from empty data');
    $check(str_contains($manage, '$unsupportedReason[$activeTab]') && str_contains($manage, "\$status['system']['api']"), 'a section that is not available says what the server answered; the header shows the API version the server reports');
    $manageClass = (string) file_get_contents($root.'/src/Livewire/ServerManage.php');
    $check(str_contains($manageClass, '$this->unsupportedReason[$tab] = $this->apiReason($e);') && str_contains($manageClass, '$this->tabError[$tab] = $this->apiReason($e);'), 'the reason of a refused or failed read is kept for the section');
    $check(! str_contains($all, '1.31 ready') && ! preg_match('/<dd[^>]*>\s*\d+\.\d+ ready/', $all), 'no view states an API version nobody checked');
    foreach (['app', 'guest'] as $layout) {
        $title = preg_match('/<title>.*<\/title>/', $views[$root.'/resources/views/layouts/'.$layout.'.blade.php'] ?? '', $t) ? $t[0] : '';
        $check($title !== '' && ! str_contains($title, 'app.name') && str_contains($title, 'Theme::NAME'), "the {$layout} layout's title is the panel's name, not APP_NAME");
    }
    $theme = (string) file_get_contents($root.'/src/Support/Theme.php');
    $check(str_contains($theme, "public const NAME = 'Cipi';") && (bool) preg_match("/public const API = '\d+\.\d+';/", $theme), 'the name and the API release the panel is written for are constants');
    $check(! str_contains($all, "config('app.name'"), 'no view reads APP_NAME');

    echo "--- metrics (4.3.0)\n";
    $M = 'CipiGui\Support\Metrics';
    $check($M::validRange('7d') === '7d' && $M::validRange('3w') === '24h' && $M::validRange(null) === '24h', 'a range the tab does not offer falls back to 24h');
    $check($M::pollSeconds('1h') === 30 && $M::pollSeconds('24h') === 30 && $M::pollSeconds('7d') === 120 && $M::pollSeconds('30d') === 120, 'poll every 30 s up to 24h, every 2 min beyond');
    $check($M::rangeSeconds('6h') === 21600 && $M::rangeSeconds('30d') === 2592000, 'range seconds');
    $chart = $M::chart(['step' => 600, 'from' => 100, 'to' => 900, 'series' => ['cpu' => [[100, 12.5, 30], [700, 'x', 1], [800, 20]], 'mem' => []]],
        [['metric' => 'cpu', 'name' => 'CPU', 'color' => 'accent', 'band' => true], ['metric' => 'mem', 'name' => 'Memory', 'color' => 'info', 'kind' => 'line']], '%', [], null, 100.0);
    $check($chart['series'][0]['points'] === [[100, 12.5, 30.0], [800, 20.0, 20.0]] && $chart['series'][0]['band'] === true && $chart['series'][1]['points'] === [] && $chart['yMax'] === 100.0 && ! array_key_exists('threshold', $chart),
        'chart: points kept as [t, avg, max], a point that is not numeric dropped, max defaults to avg, nulls left out');
    $check(! $M::chartEmpty($chart) && $M::chartEmpty($M::chart(['series' => []], [['metric' => 'cpu', 'name' => 'CPU', 'color' => 'accent']], '%')), 'an empty chart is told apart');
    $marks = $M::markers([
        ['ts' => 500, 'source' => 'deploy', 'subject' => 'shop', 'status' => 'ok', 'summary' => 'release 4'],
        ['ts' => 600, 'source' => 'health', 'subject' => 'shop', 'status' => 'crit', 'summary' => 'down'],
        ['ts' => 650, 'source' => 'health', 'subject' => 'blog', 'status' => 'ok', 'summary' => 'back up'],
        ['ts' => 50, 'source' => 'deploy', 'subject' => 'shop', 'status' => 'ok', 'summary' => 'too old'],
        ['ts' => 700, 'source' => 'deploy', 'subject' => 'shop', 'status' => 'crit', 'summary' => 'unhealthy release'],
    ], 100, 900, 'shop');
    $check(array_column($marks, 'kind') === ['deploy', 'crit', 'crit'] && $marks[0]['label'] === 'Deploy — release 4', 'markers: in the window, of the app, a failed deploy in red');
    $sp = $M::spark([1, null, 3, 2]);
    $check($sp !== null && str_starts_with($sp['line'], 'M0,') && str_ends_with($sp['area'], 'Z') && $M::spark([5]) === null, 'sparkline: gaps skipped, one point is no line');
    $check($M::uptimeClass(100) === 'is-ok' && $M::uptimeClass(99.9) === 'is-ok' && $M::uptimeClass(99.5) === 'is-warn' && $M::uptimeClass(98) === 'is-bad' && $M::uptimeClass(null) === 'is-none', 'uptime colours: 99.9 / 99 thresholds');
    $now = 86400 * 100 + 3600;
    $bars = $M::uptimeDays([[86400 * 99, 99.5], [86400 * 100, 100], [86400 * 50, 1]], 3, $now);
    $check(array_column($bars, 'pct') === [null, 99.5, 100.0] && $bars[2]['ts'] === 86400 * 100, 'uptime bars: one per day, oldest first, a day without probes is empty, older days ignored');
    $check($M::format(12.345, '%') === '12.3%' && $M::format(1534, 'ms') === '1.53 s' && $M::format(91.4, 'ms') === '91 ms' && $M::format(2048, 'mb') === '2.0 GB'
        && $M::format(1536, 'bps') === '1.5 KB/s' && $M::format(12345, 'count') === '12.3k' && $M::format(null) === '—' && $M::format(0.5, 'count') === '0.5', 'formatting with units');
    $mv = $views[$root.'/resources/views/livewire/metrics.blade.php'] ?? '';
    $check(str_contains($mv, 'wire:poll.{{ $poll }}s.visible="poll"') && str_contains($mv, '@if($live)'), 'the tab polls only while live and visible');
    $chartPartial = $views[$root.'/resources/views/partials/metrics-chart.blade.php'] ?? '';
    $check(str_contains($chartPartial, 'wire:ignore') && str_contains($chartPartial, 'wire:key="chart-{{ $key }}-{{ $version }}"') && str_contains($chartPartial, 'data-cipi-chart='), 'a chart is drawn by the script, and replaced when new data arrives');
    $script = $views[$root.'/resources/views/partials/chart-script.blade.php'] ?? '';
    $check(str_contains($views[$root.'/resources/views/layouts/app.blade.php'] ?? '', "@include('cipi-gui::partials.chart-script')") && ! preg_match('/<script[^>]+src=/', $script), 'the chart script is in the layout and loads nothing from elsewhere');
    $check(str_contains($views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '', "@livewire('cipi-gui.metrics', ['serverId' => \$serverId]") && str_contains($views[$root.'/resources/views/livewire/app-detail.blade.php'] ?? '', "@livewire('cipi-gui.metrics', ['serverId' => \$serverId, 'app' => \$appName]"), 'the Metrics tab is on the server page and on the app page');
    $css = (string) file_get_contents($root.'/resources/css/cipi-gui.css');
    $check(str_contains($css, 'svg.chart-svg:not([class*="h-"]):not([class*="w-"])'), 'charts are not shrunk to icon size by the base svg rule');
    $comp = (string) file_get_contents($root.'/src/Livewire/Metrics.php');
    $check(str_contains($comp, '#[Locked]') && str_contains($comp, "Access::can(\$user, 'view', \$id, \$this->app)") && str_contains($comp, 'return $this->serverAllowed(Access::MANAGE'), 'the app is locked; reading needs view, changing needs manage on the server');

    echo "--- notifications (4.4.0)\n";
    $N = 'CipiGui\Support\Notifications';
    $check(array_keys($N::TYPES) === ['smtp', 'resend', 'ses', 'slack', 'discord', 'mattermost', 'googlechat', 'telegram', 'ntfy', 'pushover', 'webhook'] && array_keys($N::FIELDS) === array_keys($N::TYPES), 'every channel type of Cipi 5.12, each with its fields');
    $check($N::target(['type' => 'smtp', 'host' => 'h.test', 'port' => '587', 'from' => 'a@x.it', 'to' => ['b@x.it', 'c@x.it']]) === 'h.test:587 · a@x.it → b@x.it, c@x.it' && $N::target(['type' => 'telegram', 'chat_id' => '-1']) === 'chat -1' && $N::target(['type' => 'webhook', 'url' => 'https://x/***', 'secret_set' => true]) === 'https://x/*** · signed', 'target: one line, nothing secret');
    $fv = $N::formValues(['type' => 'resend', 'api_key_set' => true, 'from' => 'a@x.it', 'to' => ['b@x.it', 'c@x.it']]);
    $check($fv === ['api_key' => '', 'from' => 'a@x.it', 'to' => 'b@x.it, c@x.it'], 'form values: secrets empty, recipients joined');
    $check($N::secretStored(['token_set' => true], 'token') && ! $N::secretStored(['secret_set' => false], 'secret') && $N::secretStored([], 'url'), 'a stored secret is told apart');
    $nv = $views[$root.'/resources/views/livewire/notifications.blade.php'] ?? '';
    $check(str_contains($nv, 'wire:model.live="triggerState.{{ $tid }}"') && str_contains($nv, 'wire:click="saveTriggers"') && ! preg_match('/value="\{\{\s*\$form\[/', $nv), 'triggers are switched locally and saved together; no secret is written into the form');
    $check(str_contains($views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '', "@livewire('cipi-gui.notifications', ['serverId' => \$serverId]") && str_contains($views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '', 'data-notifications-legacy'), 'the Notifications tab, and the SMTP form for an older server');
    $nc = (string) file_get_contents($root.'/src/Livewire/Notifications.php');
    $check(substr_count($nc, '$this->guardManage()') >= 6 && str_contains($nc, 'Access::canManageServer('), 'every change needs manage on the server');

    echo "--- 4.5.0: Cipi 5.13 / API 1.43\n";
    require_once $root.'/src/Livewire/Concerns/InteractsWithCipiServer.php';
    $cli = new class { use \CipiGui\Livewire\Concerns\InteractsWithCipiServer; };
    $raw = "\e[1mBasic auth — newsltrc3072\e[0m\n━━━━━━━━━━━━━━━━\n  Status     \e[2mdisabled\e[0m\n\n";
    $check(! str_contains($cli::plainCliText($raw), '[') && ! str_contains($cli::plainCliText($raw), '━') && str_contains($cli::plainCliText('[1mBasic auth — x[0m ━━━ Status [2mdisabled[0m'), 'Basic auth — x') && ! str_contains($cli::plainCliText('[1mBasic auth — x[0m ━━━ Status [2mdisabled[0m'), '[0m'),
        'CLI text without colours, escaped or bare, nor the rule under the title');
    $check($cli::basicAuthStateFromCliText($raw) === ['enabled' => false, 'users' => []]
        && $cli::basicAuthStateFromCliText('[1mBasic auth — shop[0m ━━ Status [0;32menabled[0m Users [0;36madmin,ops[0m') === ['enabled' => true, 'users' => ['admin', 'ops']]
        && $cli::basicAuthStateFromCliText('App not found') === null, 'basic auth: the state an older API answered as an error is read from its text');
    $B = get_class($page);
    $check($B::backupExcludePaths(" storage/app/uploads, *.mp4\nshop:public/my files ,storage/app/uploads,, ") === ['storage/app/uploads', '*.mp4', 'shop:public/my files'], 'exclude paths: commas and new lines, spaces inside a path kept, duplicates dropped');
    $okPaths = array_filter(['storage/app/uploads', '*.mp4', 'shop:storage/app/uploads', 'api-*:public/a[1]', 'wp-content/uploads/'], fn ($p) => $B::validBackupExcludePath($p));
    $badPaths = array_filter(['/var/www', '*', '*/', 'shared', 'htdocs', 'shop:*', 'shop:/etc', 'a;rm -rf', "a\nb", 'x$(id)', str_repeat('a', 256)], fn ($p) => $B::validBackupExcludePath($p));
    $check(count($okPaths) === 5 && $badPaths === [], 'exclude paths: the rule of the CLI — relative, never the whole app, nothing a shell would read');
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $adv = $views[$root.'/resources/views/livewire/app-detail.blade.php'] ?? '';
    $check(str_contains($ad, 'public function moveEnvRow(int $from, int $to): void') && (bool) preg_match('/function moveEnvRow[^{]+\{\s+if \(! \$this->allowed\(\'env\'\)\)/', $ad), 'moving a .env row needs the env ability');
    $check(str_contains($ad, '$this->client()->updateEnv($this->appName, $set, $unset, $order)') && str_contains($ad, 'array_keys($this->envOriginal) !== $order'), 'the new order is saved with the other changes, and an API that drops it is told apart');
    $check(str_contains($adv, 'class="env-grip" role="button"') && str_contains($adv, 'x-on:drop.prevent="drop(@js($key))"') && str_contains($adv, 'x-on:keydown.arrow-up.prevent') && str_contains($adv, "x-bind:aria-disabled=\"filter !== ''\""),
        '.env rows: a drag handle (not a <button>: Firefox), arrow keys, off while filtering');

    echo "--- 4.5.1: a moved .env row keeps its value\n";
    // Livewire does not re-read a wire: attribute that a morph changes: a row
    // kept by its key alone, moved, still bound envRows.<old place>.value.
    $check(str_contains($adv, 'wire:key="env-{{ $index }}-{{ $key }}"') && ! str_contains($adv, 'wire:key="env-{{ $key }}"'), 'every .env row is keyed by its place too: one that moves is drawn again with its new binding');
    $check(str_contains($adv, '$wire.moveEnvKey(k, toKey)') && str_contains($adv, 'regrip(@js($key), @js($envRows[$index - 1][\'key\']))') && str_contains($adv, 'wire:click="removeEnvKey(@js($key))"')
        && ! str_contains($adv, '$wire.moveEnvRow(') && ! str_contains($adv, 'removeEnvRow({{ $index }})'), 'the page tells the server keys, never positions');
    $check(str_contains($ad, 'public function moveEnvKey(string $key, string $target): void') && str_contains($ad, 'public function removeEnvKey(string $key): void'), 'moveEnvKey() and removeEnvKey()');
    $check(str_contains($adv, 'data-deploy-paused') && str_contains($adv, 'Resume automatic deploys') && str_contains($ad, "\$this->deployStatus['paused_commit']"), 'a paused commit is shown, with a way to resume');
    $check(str_contains($views[$root.'/resources/views/partials/server-backups.blade.php'] ?? '', 'wire:model="bpExcludePaths"'), 'backup profiles: paths to leave out');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    $check(str_contains($client, 'public function updateEnv(string $name, array $set = [], array $unset = [], ?array $order = null): array') && str_contains($client, "\$payload['order'] = array_values(\$order);"), 'the client sends order');
    $theme = (string) file_get_contents($root.'/src/Support/Theme.php');
    $check((bool) preg_match("/VERSION = '4\\.5\\.\\d+'/", $theme) && str_contains($theme, "API = '1.43'") && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.5.0]'), 'version 4.5.0 for API 1.43');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
