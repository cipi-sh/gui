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
    require $root.'/src/Support/Firewall.php';
    require $root.'/src/Support/Mesh.php';
    require $root.'/src/Services/JobOutputParser.php';

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
    preg_match("/VERSION = '([0-9.]+)'/", $theme, $tv); preg_match("/API = '([0-9.]+)'/", $theme, $ta);
    $check(version_compare($tv[1] ?? '0', '4.5.0', '>=') && version_compare($ta[1] ?? '0', '1.43', '>=') && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.5.0]'), 'version 4.5.0 or later, for API 1.43 or later');

    echo "--- 4.6.0: firewall\n";
    $FW = \CipiGui\Support\Firewall::class;
    $check($FW::normalizePort('8080') === '8080' && $FW::normalizePort(' 0080 ') === '80' && $FW::normalizePort('8000:8100') === '8000:8100', 'ports and ranges, without leading zeros');
    $check(array_filter(['0', '65536', '8000:7000', '22;id', '80,443', 'ssh', '', '-1'], fn ($p) => $FW::normalizePort($p) !== null) === [], 'not a port: 0, 65536, a range backwards, shell characters, a list, a name, nothing');
    $check($FW::isAddress('203.0.113.9') && $FW::isAddress('10.0.0.0/8') && $FW::isAddress('2001:db8::/48') && ! $FW::isAddress('1.2.3.4/33') && ! $FW::isAddress('example.com') && ! $FW::isAddress('1.2.3.4;id') && ! $FW::isAddress('') && ! $FW::isAddress('*'), 'addresses: IPv4, IPv6, CIDR — nothing else');
    $check($FW::tooWide('0.0.0.0/0') && $FW::tooWide('10.0.0.0/7') && ! $FW::tooWide('10.0.0.0/8') && $FW::tooWide('::/0') && ! $FW::tooWide('2001:db8::/32'), 'too wide to block: below IPv4 /8, IPv6 /16');
    $check($FW::portLabel(['port' => '8080', 'proto' => 'tcp']) === '8080/tcp' && $FW::portLabel(['port' => '3000', 'proto' => 'any']) === '3000' && $FW::portLabel(['port' => 'any', 'proto' => 'any']) === 'Every port', 'port labels');
    $check($FW::fromLabel(['from' => 'any']) === 'Anywhere' && $FW::familyLabel('both') === 'IPv4 + IPv6' && $FW::familyLabel('v6') === 'IPv6' && $FW::managedLabel('zero-trust') === 'Zero Trust' && $FW::managedLabel('') === null, 'from, family, the feature that owns a rule');
    $check($FW::duration(86400) === '1d' && $FW::duration(3600) === '1h' && $FW::duration(600) === '10m' && $FW::duration(45) === '45s' && $FW::duration(null) === '—', 'durations like the CLI');
    $fwData = ['fail2ban' => ['jails' => [['jail' => 'sshd', 'banned' => ['198.51.100.20', '203.0.113.66']], ['jail' => 'recidive', 'banned' => ['198.51.100.20']], 'junk']],
        'crowdsec' => ['decisions' => [['ip' => '203.0.113.66', 'reason' => 'crowdsecurity/ssh-bf', 'duration' => '3h'], ['ip' => '']]],
        'ufw' => ['rules' => [['kind' => 'block', 'from' => '192.0.2.1'], ['kind' => 'port', 'port' => '22'], ['kind' => 'other', 'port' => 'any'], 'junk']]];
    $bans = $FW::bans($fwData);
    $check(count($bans) === 2 && $bans[0]['by'] === ['fail2ban · sshd', 'fail2ban · recidive'] && $bans[1]['by'] === ['fail2ban · sshd', 'CrowdSec'] && $bans[1]['reason'] === 'crowdsecurity/ssh-bf', 'bans: one row per address, every jail and CrowdSec named');
    $sec = $FW::sections($fwData);
    $check(count($sec['blocks']) === 1 && $sec['blocks'][0]['from'] === '192.0.2.1' && count($sec['rules']) === 2, 'sections: blocks apart from the rules, in order');
    $fwc = (string) file_get_contents($root.'/src/Livewire/Firewall.php');
    $fwv = $views[$root.'/resources/views/livewire/firewall.blade.php'] ?? '';
    $check(str_contains($fwv, 'wire:init="init"') && str_contains($fwv, "@include('cipi-gui::partials.job-overlay')"), 'firewall: the tab paints first and asks its server after; CrowdSec jobs have their overlay');
    $check((bool) preg_match('/#\[Locked\]\s+public array \$firewall/', $fwc), 'firewall: what the server answered is locked — actions take only what it lists');
    foreach (['addRule', 'removeRule', 'blockAddress', 'unblock', 'saveAttempts', 'defaultAttempts', 'unban', 'enableCrowdsec', 'disableCrowdsec', 'crowdsecAllow', 'crowdsecUnallow'] as $action) {
        $check((bool) preg_match('/function '.$action.'\([^)]*\): void\s+\{\s+if \(! \$this->serverAllowed\(Access::MANAGE/', $fwc), "firewall: {$action}() needs Manage on the server");
    }
    $check(str_contains($fwc, "! (\$rule['removable'] ?? false)") && str_contains($fwc, "in_array(\$ip, array_column(F::bans(\$this->firewall), 'ip'), true)") && str_contains($fwc, "in_array(\$address, \$listed, true)"), 'firewall: a rule the server marks removable, a ban and a block the page lists');
    $sm = (string) file_get_contents($root.'/src/Livewire/ServerManage.php');
    $check(str_contains($sm, "'firewall' => 'Firewall'") && str_contains($views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '', "@livewire('cipi-gui.firewall'"), 'the server page has a Firewall tab');
    $cfg = (string) file_get_contents($root.'/config/cipi-gui.php');
    $check(str_contains($cfg, "'firewall-view', 'firewall-manage'"), 'the token command on Connections asks for firewall-view and firewall-manage');
    $check(str_contains($client, "public function firewall(): array") && str_contains($client, "'/firewall/crowdsec/enable'") && str_contains($client, "request(\$method, \$path, data: \$data)"), 'the client: the firewall routes of API 1.44');

    echo "--- 4.6.0: two-factor authentication required for every account\n";
    $mw = (string) file_get_contents($root.'/src/Http/Middleware/EnsureTwoFactorVerified.php');
    $tfs = (string) file_get_contents($root.'/src/Services/TwoFactorService.php');
    $check(str_contains($mw, "redirect()->route('cipi-gui.2fa')") && str_contains($mw, "TwoFactorService::setupRequired(\$user, \$session)") && str_contains($mw, "routeIs('cipi-gui.2fa*')"), 'the 2FA middleware: the code first, then the setup when required; its own pages pass');
    $check(str_contains($mw, "\$request->user() ?? auth()->user()") && str_contains($mw, "\$request->hasSession() ? \$request->session() : app('session.store')"), '…on a request Livewire makes for a persistent middleware, user and session come from the application');
    $check(str_contains($tfs, 'return Panel::enforceTwoFactor() && self::setupRequiredIfEnforced($user, $session);') && str_contains($tfs, '$session->get(self::SSO_SESSION)'), 'required only when an admin turned it on, never for an SSO session');
    $sp = (string) file_get_contents($root.'/src/CipiGuiServiceProvider.php');
    $check(str_contains($sp, 'addPersistentMiddleware([EnsureTwoFactorVerified::class])'), 'Livewire actions go through the 2FA middleware too');
    $authGroup = substr($routes, (int) strpos($routes, "Route::middleware(['auth'])->group"), (int) strpos($routes, "Route::middleware(['auth', 'cipi-gui.2fa'])") - (int) strpos($routes, "Route::middleware(['auth'])->group"));
    $check(str_contains($authGroup, "name('cipi-gui.2fa.setup')") && str_contains($authGroup, "name('cipi-gui.2fa.enable')"), 'the setup page is behind sign-in, outside the 2FA check (it is where the check sends)');
    $setupView = $views[$root.'/resources/views/auth/2fa-setup.blade.php'] ?? '';
    $check(str_contains($setupView, "route('cipi-gui.2fa.enable')") && str_contains($setupView, '@csrf') && str_contains($setupView, "route('cipi-gui.logout')") && ! str_contains($setupView, 'wire:'), 'the setup page: a plain form (no Livewire on the guest layout), CSRF, a way out');
    $ctl = (string) file_get_contents($root.'/src/Http/Controllers/Auth/TwoFactorController.php');
    $check(str_contains($ctl, 'TwoFactorService::SETUP_SECRET') && ! str_contains($setupView, 'name="secret"'), 'the secret being enrolled stays in the session, never in the form');
    $login = (string) file_get_contents($root.'/src/Http/Controllers/Auth/LoginController.php');
    $sso = (string) file_get_contents($root.'/src/Http/Controllers/Auth/SsoController.php');
    $inv = (string) file_get_contents($root.'/src/Http/Controllers/Auth/InviteController.php');
    $check(str_contains($login, "forget(['cipi_gui_2fa_verified', TwoFactorService::SSO_SESSION])") && str_contains($login, "redirect()->route('cipi-gui.2fa.setup')") && str_contains($sso, 'TwoFactorService::SSO_SESSION => true') && str_contains($inv, "'cipi-gui.2fa.setup'"), 'sign-in, SSO and invites: password sessions set it up, SSO sessions are marked');
    $team = (string) file_get_contents($root.'/src/Livewire/Team.php');
    $check(str_contains($team, 'public function setTwoFactorRequired(bool $required): void') && str_contains($team, 'setupRequiredIfEnforced(Auth::user()') && str_contains($team, 'public function resetTwoFactor(TwoFactorService $twoFactor): void') && str_contains($team, 'if (! $id || $id === (int) Auth::id())'), 'Team: require it (not before your own), reset another account\'s 2FA (not your own)');
    $settings = (string) file_get_contents($root.'/src/Livewire/Settings.php');
    $check((bool) preg_match('/function disableTwoFactor[^{]+\{.*?if \(Panel::enforceTwoFactor\(\)( && ! \\\\CipiGui\\\\Services\\\\Passkeys::has\(Auth::user\(\)\))?\)/s', $settings), 'Settings: required 2FA is not turned off (unless a passkey stays as the second factor)');
    $panel = (string) file_get_contents($root.'/src/Support/Panel.php');
    $check(str_contains($panel, "public const ENFORCE_2FA = 'enforce_2fa';") && str_contains($panel, "self::get(self::ENFORCE_2FA, '0') === '1'"), 'the setting enforce_2fa, off by default');
    echo "--- 4.6.0: Team → access editor\n";
    $css = (string) file_get_contents($root.'/resources/css/cipi-gui.css');
    $ae = $views[$root.'/resources/views/partials/access-editor.blade.php'] ?? '';
    $missing = [];
    preg_match_all('/class="([^"]*)"/', $ae, $cm);
    foreach ($cm[1] as $list) {
        foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $c) {
            if ($c !== '' && preg_match('/^[a-z][a-z0-9:\/-]*$/', $c) && ! preg_match('/\.'.preg_quote(str_replace(':', '\\:', $c), '/').'(?=[\s,{:.>\[)])/', $css)) {
                $missing[] = $c;
            }
        }
    }
    $check($missing === [], 'every class of the access editor is in the stylesheet (gap-x-*, sm:ml-auto were not: the abilities were glued together)'.($missing ? ' — '.implode(', ', array_unique($missing)) : ''));
    $check(str_contains($ae, 'class="access-ability"') && str_contains($ae, 'type-picker role-picker') && str_contains($css, '.access-ability:has(input:checked)'), 'abilities are toggles of their own; the two roles share the row');

    echo "--- 4.6.0: optional packages, installed and removed from the panel\n";
    $cfg = (string) file_get_contents($root.'/config/cipi-gui.php');
    $check(str_contains($cfg, "'packages-view', 'packages-manage',"), 'the token abilities include packages-manage');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    $check(str_contains($client, "return \$this->post('/packages/'.rawurlencode(\$id));") && str_contains($client, "return \$this->delete('/packages/'.rawurlencode(\$id));"), 'client: POST and DELETE /api/packages/{id}, the id URL-encoded');
    $sm = (string) file_get_contents($root.'/src/Livewire/ServerManage.php');
    $check((bool) preg_match('/function installPackage\(string \$id\): void\s*\{\s*\$pkg = \$this->knownPackage\(\$id\);\s*if \(! \$this->serverAllowed\(Access::MANAGE/s', $sm) && (bool) preg_match('/function removePackage\(string \$id\): void\s*\{\s*\$pkg = \$this->knownPackage\(\$id\);\s*if \(! \$this->serverAllowed\(Access::MANAGE/s', $sm), 'install and remove need manage access and an id the page listed');
    $check(str_contains($sm, "\$api->installPackage(\$id)") && str_contains($sm, "\$api->removePackage(\$id)"), 'both run as jobs');
    $smv = $views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '';
    $pkgTab = (string) (preg_match("/@elseif\(\\\$activeTab === 'packages'\)(.*?)@elseif\(\\\$activeTab === 'zt'\)/s", $smv, $pm) ? $pm[1] : '');
    $check($pkgTab !== '' && ! str_contains($pkgTab, 'Read-only by design') && str_contains($pkgTab, 'wire:click="installPackage(@js($pkgId))" data-confirm=') && str_contains($pkgTab, 'wire:click="removePackage(@js($pkgId))" data-confirm=') && str_contains($pkgTab, '@if($canManage && $pkgId !== \'\')'), 'the packages tab: Install and Remove, confirmed, only with manage access');
    $check(str_contains($pkgTab, "\$pkg['binaries']") && str_contains($pkgTab, "\$pkg['missing']") && str_contains($pkgTab, '>Libraries</span>'), 'it shows the commands, what is missing, and which entries are libraries');
    $check(str_contains($pkgTab, '>Upstream{{') && str_contains($pkgTab, "'Update to '.\$pkgVersion") && str_contains($pkgTab, '@if($pkgInstalled || $pkgPartial || $pkgOutdated)') && str_contains($sm, "empty(\$pkg['outdated'])"), 'upstream entries: their pinned version, Update when outdated, removable');
    $missing = [];
    preg_match_all('/class="([^"]*)"/', $pkgTab, $cm);
    foreach ($cm[1] as $list) {
        foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $c) {
            if ($c !== '' && preg_match('/^[a-z][a-z0-9:\/-]*$/', $c) && ! preg_match('/\.'.preg_quote(str_replace(':', '\\:', $c), '/').'(?=[\s,{:.>\[)])/', $css)) {
                $missing[] = $c;
            }
        }
    }
    $check($missing === [], 'every class of the packages tab is in the stylesheet'.($missing ? ' — '.implode(', ', array_unique($missing)) : ''));
    $fx = (string) file_get_contents($root.'/dev/demo/api/fixtures.php');
    $check(substr_count($fx, "        ['") >= 20 && str_contains($fx, "['browsershot', [") && str_contains($fx, "['tesseract', [") && str_contains($fx, "['sec-nuclei', [], ['nuclei']") && str_contains($fx, "'sec-zap' => '2.16.1'"), 'the demo lists the whole 5.14.0 catalog, sec entries and an outdated one included');

    echo "--- 4.6.0: the app page says where to connect and as whom\n";
    require_once $root.'/src/Support/AppEnv.php';
    require_once $root.'/src/Livewire/Concerns/InteractsWithCipiServer.php';
    $norm = new class
    {
        use \CipiGui\Livewire\Concerns\InteractsWithCipiServer { normalizeApp as public; }
    };
    $check($norm->normalizeApp(['app' => 'shop', 'user' => 'shop'])['user'] === 'shop' && $norm->normalizeApp(['app' => 'shop'])['user'] === 'shop' && $norm->normalizeApp(['app' => 'shop', 'user' => ''])['user'] === 'shop',
        'the user of an app: what the server says, or the app\'s name when it says nothing');
    $check($norm->normalizeApp(['app' => 'shop', 'user' => 'shop $(id)'])['user'] === 'shop' && $norm->normalizeApp(['app' => 'Shop;id', 'user' => '-oProxyCommand=x'])['user'] === '' && $norm->normalizeApp([])['user'] === '',
        'a user name or nothing: never an option of ssh, never a shell character');
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $adv = $views[$root.'/resources/views/livewire/app-detail.blade.php'] ?? '';
    $check(str_contains($ad, "return \$ip !== '' && filter_var(\$ip, FILTER_VALIDATE_IP) ? \$ip : null;") && str_contains($ad, 'return $user !== \'\' && $ip !== null ? "ssh {$user}@{$ip}" : null;'),
        'the address is an IP or nothing, and the ssh command needs both');
    $details = (string) (preg_match('/<h2 class="card-title">Details<\/h2>(.*?)<\/dl>/s', $adv, $dm) ? $dm[1] : '');
    $check($details !== '' && str_contains($details, '<dt>User</dt>') && str_contains($details, '<dt>Server IP</dt>') && str_contains($details, '<x-cipi::copyable :value="$appUser"') && str_contains($adv, "\$appUser = (string) (\$app['user'] ?? '');") && str_contains($details, '<x-cipi::copyable :value="$serverIp"') && str_contains($details, '<x-cipi::copyable :value="$sshCommand"'),
        'Details: User, Server IP and the ssh command, each copied with a click');
    $check(str_contains($details, 'data-server-ip-unknown') && (bool) preg_match('/@if\(\$sshCommand\)\s*<dt>SSH<\/dt>/', $details), 'an address the panel does not know is said, and no command is offered without it');
    $header = (string) (preg_match('/<x-slot:meta>(.*?)<\/x-slot:meta>/s', $adv, $hm) ? $hm[1] : '');
    $check(str_contains($header, '@if($serverIp)<x-cipi::copyable :value="$serverIp" label="Copy server IP"'), 'the address is next to the server\'s name in the header, on every tab');
    $check(! str_contains($ad, "->status()") && ! str_contains($ad, "'/status'"), 'nothing is asked of the server for it: the address is the one the panel keeps');
    $missing = [];
    preg_match_all('/class="([^"]*)"/', $details.$header, $cm);
    foreach ($cm[1] as $list) {
        foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $c) {
            if ($c !== '' && preg_match('/^[a-z][a-z0-9:\/.-]*$/', $c) && ! preg_match('/\.'.preg_quote(str_replace([':', '.'], ['\\:', '\\.'], $c), '/').'(?=[\s,{:.>\[)])/', $css)) {
                $missing[] = $c;
            }
        }
    }
    $check($missing === [], 'every class of the Details card and of the header is in the stylesheet'.($missing ? ' — '.implode(', ', array_unique($missing)) : ''));
    $check(str_contains((string) file_get_contents($root.'/dev/demo/api/index.php'), "\$out['user'] = \$out['app'];"), 'the demo API answers with the user of each app');

    echo "--- 4.6.0: the .env as text, live job output, commit titles, worker hooks, IPv6, aligned fields\n";
    require_once $root.'/src/Support/EnvText.php';
    $ET = \CipiGui\Support\EnvText::class;
    $pasted = "\xEF\xBB\xBF# from the README\r\nexport APP_NAME=\"My \\\"shop\\\" app\"\r\n\r\nMAIL_HOST=smtp.example.com   # the relay\nMAIL_PASSWORD='p@ss #1 \$x'\n```env\nEMPTY=\nURL=https://x.test/?a=1&b=2#frag\nPATH_WIN=\"C:\\\\dir\"\nAPP_NAME=Second\n  SPACED = padded value  \n```\n";
    $parsed = $ET::parse($pasted);
    $check($parsed['errors'] === [] && $parsed['vars'] === ['MAIL_HOST' => 'smtp.example.com', 'MAIL_PASSWORD' => 'p@ss #1 $x', 'EMPTY' => '', 'URL' => 'https://x.test/?a=1&b=2#frag', 'PATH_WIN' => 'C:\\dir', 'APP_NAME' => 'Second', 'SPACED' => 'padded value'] && $parsed['duplicates'] === ['APP_NAME'],
        '.env text: comments, export, quotes, an inline comment, a code fence, CRLF, a BOM; a key twice keeps the last value');
    $rowsOf = fn (array $vars) => array_map(fn ($k, $v) => ['key' => $k, 'value' => $v], array_keys($vars), $vars);
    $tricky = ['A' => 'plain', 'B' => 'two words', 'C' => 'has "quotes" and \\back', 'D' => "it's", 'E' => 'a#b', 'F' => 'x=y', 'G' => '$HOME `id`', 'H' => '', 'I' => ' padded ', 'J' => 'ünïcödé ¿'];
    $check($ET::parse($ET::dump($rowsOf($tricky)))['vars'] === $tricky && $ET::dump([['key' => 'A', 'value' => 'plain'], ['key' => 'B', 'value' => 'two words'], ['key' => '', 'value' => 'x']]) === "A=plain\nB=\"two words\"",
        '.env text: what dump() writes, parse() reads back — quoted where the CLI would quote it');
    $bad = $ET::parse("FOO\n1BAD=x\nA=\"open\nB='open\nC=\"x\" y\nD=ok\nE=a\x01b");
    $check($bad['vars'] === ['D' => 'ok'] && count($bad['errors']) === 6 && $bad['errors'][0] === 'Line 1: expected KEY=value.' && str_contains($bad['errors'][2], 'never closed — a value has to stay on one line') && str_contains($bad['errors'][5], 'control character'),
        '.env text: a line that is not KEY=value, a bad key, an open quote, text after a quote, a control character — each named with its line');
    $check($ET::parse(str_repeat('A=1', 30000))['errors'] === ['The text is too long (64 KB at most).'], '.env text: 64 KB at most');
    $merge = $ET::apply([['key' => 'A', 'value' => '1'], ['key' => 'B', 'value' => '2']], ['B' => '3', 'C' => '4'], false);
    $replace = $ET::apply([['key' => 'A', 'value' => '1'], ['key' => 'B', 'value' => '2']], ['B' => '2', 'C' => '4'], true);
    $check($merge === ['rows' => [['key' => 'A', 'value' => '1'], ['key' => 'B', 'value' => '3'], ['key' => 'C', 'value' => '4']], 'added' => 1, 'changed' => 1, 'removed' => 0]
        && $replace === ['rows' => [['key' => 'B', 'value' => '2'], ['key' => 'C', 'value' => '4']], 'added' => 1, 'changed' => 0, 'removed' => 1],
        '.env text: pasting adds and updates in place, editing the whole text replaces the list');
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $adv = $views[$root.'/resources/views/livewire/app-detail.blade.php'] ?? '';
    $check((bool) preg_match('/function openEnvText\(string \$mode\): void\s*\{\s*if \(! \$this->allowed\(\'env\'\)/', $ad) && (bool) preg_match('/function applyEnvText\(\): void\s*\{\s*if \(! \$this->allowed\(\'env\'\)/', $ad) && str_contains($ad, 'EnvText::apply($this->envRows, $parsed[\'vars\'], $replace)'),
        'the text panel needs the env ability, and changes the rows only: Save sends them');
    $check(str_contains($adv, 'wire:click="openEnvText(\'paste\')"') && str_contains($adv, 'wire:click="openEnvText(\'edit\')"') && str_contains($adv, '<form wire:submit="applyEnvText"') && str_contains($adv, "@if(\$envTextMode !== 'edit')"),
        'Environment tab: Paste variables, Edit as text; the rows give way to the text while it is edited');

    $jobs = (string) file_get_contents($root.'/src/Livewire/Concerns/ManagesAsyncJobs.php');
    $overlay = $views[$root.'/resources/views/partials/job-overlay.blade.php'] ?? '';
    $terminal = $views[$root.'/resources/views/partials/terminal.blade.php'] ?? '';
    $check(str_contains($jobs, "} elseif (is_string(\$data['output'] ?? null) && \$data['output'] !== '') {") && str_contains($jobs, 'array_slice($lines, -self::JOB_LIVE_LINES)') && str_contains($jobs, 'public function jobLiveLines(): array'),
        'a running job that says its output so far (cipi/api 1.44) is kept: its last lines');
    $check(str_contains($overlay, "\$liveLines = (\$jobRunning ?? false) ? \$this->jobLiveLines() : [];") && str_contains($overlay, 'data-job-live') && str_contains($overlay, "'follow' => true") && ! str_contains($overlay, 'output appears when the job finishes'),
        'the overlay of a running job shows that output, live');
    $check(str_contains($terminal, 'new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight })') && str_contains($terminal, 'x-on:scroll.passive="stick = '), 'the terminal follows new lines, unless the reader scrolled up');
    $check(str_contains($ad, "\$this->activeJobType === 'app-deploy' && \$this->deployActive()") && str_contains($ad, 'jobLiveLines as protected jobOutputLines;'), 'a deploy on an older API is followed through deploy.log');

    $check(str_contains($adv, "\$subject = is_string(\$record['subject'] ?? null) ? trim(\$record['subject']) : '';") && str_contains($adv, 'data-commit-subject>{{ $subject }}</span>'), 'deploy audit: the commit\'s title under its release, escaped');
    $check(str_contains($adv, "\$runsHorizon = array_key_exists('horizon', \$app) ? (bool) \$app['horizon'] : null;") && str_contains($adv, 'data-worker-hooks=') && str_contains($adv, 'Skipped: no Horizon here'), 'deploy pipeline: which of queue:restart and horizon:terminate applies to this app');
    $norm2 = new class { use \CipiGui\Livewire\Concerns\InteractsWithCipiServer { normalizeApp as public; } };
    $check($norm2->normalizeApp(['app' => 'shop', 'horizon' => 'true'])['horizon'] === true && $norm2->normalizeApp(['app' => 'shop', 'horizon' => null])['horizon'] === false && ! array_key_exists('horizon', $norm2->normalizeApp(['app' => 'shop'])),
        'horizon: true, false, or not said by the server');
    $smv = $views[$root.'/resources/views/livewire/server-manage.blade.php'] ?? '';
    $check(str_contains($smv, "filter_var(\$sys['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)") && str_contains($smv, '<dt>Public IPv6</dt>'), 'server page: its IPv6 address, when it is one');

    $check(! str_contains($all, 'items-end'), 'no row of fields is aligned to the bottom: a hint or an error under one input no longer moves its neighbours');
    $check((bool) preg_match('/\.check \{[^}]*margin: 0;[^}]*\}.*\.field-action \{ margin-top: 1\.59375rem; min-height: 2\.25rem; \}/s', $css), '.field-action comes after .check in the stylesheet: a checkbox of a row keeps its place on the line of the inputs');
    $check(substr_count($all, 'field-action') >= 5 && str_contains($adv, '<form wire:submit="saveHealth" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-start">'), 'the healthcheck form and the rows with a button line up on their labels');
    $missing = [];
    $envPanel = (string) (preg_match('/<form wire:submit="applyEnvText".*?<\/form>/s', $adv, $em) ? $em[0] : '');
    $hooks = (string) (preg_match('/<p class="field-hint" data-worker-hooks=.*?<\/p>/s', $adv, $hm2) ? $hm2[0] : '');
    preg_match_all('/class="([^"]*)"/', $envPanel.$hooks.$overlay.'<span class="block font-sans text-xs text-soft truncate commit-subject">', $cm);
    foreach ($cm[1] as $list) {
        foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $c) {
            if ($c !== '' && preg_match('/^[a-z][a-z0-9:\/.-]*$/', $c) && ! preg_match('/\.'.preg_quote(str_replace([':', '.'], ['\\:', '\\.'], $c), '/').'(?=[\s,{:.>\[)])/', $css)) {
                $missing[] = $c;
            }
        }
    }
    $check($envPanel !== '' && $hooks !== '' && $missing === [], 'every class of the text panel, the hooks note, the overlay and the commit title is in the stylesheet'.($missing ? ' — '.implode(', ', array_unique($missing)) : ''));
    $demo = (string) file_get_contents($root.'/dev/demo/api/index.php');
    $check(str_contains($demo, 'function liveOutput(string $type, float $seconds): string') && str_contains((string) file_get_contents($root.'/dev/demo/api/fixtures.php'), "'subject' => 'Fix checkout tax rounding'") && str_contains((string) file_get_contents($root.'/dev/demo/api/fixtures.php'), "'ipv6' => '2001:db8:24::1'"), 'demo API: live output of running jobs, commit titles, an IPv6 address');

    echo "--- 4.6.0: Routing → Nginx directives\n";
    $ex = \CipiGui\Services\CipiApiException::fromResponse(422, ['error' => 'nginx refused the directives', 'reasons' => ['nginx refused the directives', 5, 'line 2: unknown directive "boom"']]);
    $check($ex->getStatusCode() === 422 && $ex->getMessage() === 'nginx refused the directives' && $ex->getDetails() === ['reasons' => ['nginx refused the directives', 'line 2: unknown directive "boom"']]
        && \CipiGui\Services\CipiApiException::fromResponse(422, ['message' => 'x', 'errors' => ['config' => ['too long']]])->getDetails() === ['config' => ['too long']],
        'a refused change keeps its reasons; validation errors are what they were');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    $check(str_contains($client, 'return $this->get("/apps/{$app}/nginx")[\'data\'] ?? [];') && str_contains($client, 'return $this->put("/apps/{$app}/nginx", [\'config\' => $config])[\'data\'] ?? [];') && str_contains($client, 'return $this->request(\'delete\', "/apps/{$app}/nginx")[\'data\'] ?? [];'),
        'client: GET, PUT and DELETE /api/apps/{app}/nginx');
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $adv = $views[$root.'/resources/views/livewire/app-detail.blade.php'] ?? '';
    $check((bool) preg_match('/function saveNginx\(\): void\s*\{\s*if \(! \$this->serverAllowed\(Access::MANAGE/', $ad) && (bool) preg_match('/function resetNginx\(\): void\s*\{\s*if \(! \$this->serverAllowed\(Access::MANAGE/', $ad)
        && (bool) preg_match('/function loadNginx\(\): void\s*\{.*?if \(! \$this->canManageNginx\(\)\) \{\s*return;/s', $ad) && str_contains($ad, 'return Access::canManageServer($this->panelUser(), (int) ($this->serverId ?? 0));'),
        'nginx directives are read and changed only by who manages the server — an ability on the app is not enough');
    $check(str_contains($ad, "\$reasons = \$e->getDetails()['reasons'] ?? [];") && str_contains($ad, "'routing' => [\$this->loadRouting(), \$this->loadNginx()],"), 'a 422 becomes the list of reasons; the card loads with the Routing tab');
    $ngCard = (string) (preg_match('/@if\(\$canManageNginx\)\s*<div class="card lg:col-span-2" data-nginx-directives>.*?<\/form>\s*@endif\s*<\/div>\s*@endif/s', $adv, $nm) ? $nm[0] : '');
    $check($ngCard !== '' && str_contains($ngCard, '<form wire:submit="saveNginx">') && str_contains($ngCard, 'wire:click="resetNginx" data-confirm=') && str_contains($ngCard, 'data-nginx-refused') && str_contains($ngCard, 'data-nginx-unavailable'),
        'Routing tab: the card, only for who manages the server; Remove is confirmed; a refusal and an older server are said');
    $missing = [];
    preg_match_all('/class="([^"]*)"/', $ngCard, $cm);
    foreach ($cm[1] as $list) {
        foreach (preg_split('/\s+/', trim((string) preg_replace('/\{\{.*?\}\}/', '', $list))) as $c) {
            if ($c !== '' && preg_match('/^[a-z][a-z0-9:\/.-]*$/', $c) && ! preg_match('/\.'.preg_quote(str_replace([':', '.'], ['\\:', '\\.'], $c), '/').'(?=[\s,{:.>\[)])/', $css)) {
                $missing[] = $c;
            }
        }
    }
    $check($missing === [] && str_contains($css, '.badge-amber') && str_contains($css, '.badge-green'), 'every class of the card is in the stylesheet'.($missing ? ' — '.implode(', ', array_unique($missing)) : ''));
    $check(str_contains((string) file_get_contents($root.'/config/cipi-gui.php'), "'proxies-view', 'proxies-manage', 'nginx-view', 'nginx-manage',"), 'the token abilities include nginx-view and nginx-manage');
    $check(str_contains((string) file_get_contents($root.'/dev/demo/api/index.php'), "case \$sub === '/nginx' && \$method === 'PUT':"), 'demo API: an app\'s nginx directives');

    $check(version_compare((string) (preg_match("/VERSION = '([0-9.]+)'/", $theme, $vm) ? $vm[1] : '0'), '4.6.0', '>=') && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.6.0]'), 'version 4.6.0+ (changelog 4.6.0)');

    echo "--- 4.7.0: Cloudflare real IP, events per channel, MySQL on 3306, version-agnostic text, the heartbeat field\n";
    $fwView = (string) file_get_contents($root.'/resources/views/livewire/firewall.blade.php');
    $fwComp = (string) file_get_contents($root.'/src/Livewire/Firewall.php');
    $check(str_contains($fwView, 'data-firewall-real-ip') && str_contains($fwView, 'wire:click="enableRealIp"') && str_contains($fwView, 'wire:click="refreshRealIp"') && str_contains($fwView, 'wire:click="disableRealIp"'), 'Firewall tab: the Cloudflare real IP card — on, fetch the ranges again, off');
    $check(str_contains($fwView, "@if(! empty(\$rip['can_disable']))") && str_contains($fwView, "! empty(\$rip['bans_active'])") && str_contains($fwView, 'data-real-ip-unsupported'), '…off only when the server says it may be, the warning when fail2ban or CrowdSec run, a word for an older server');
    $check(str_contains($fwComp, "if (! (\$this->firewall['real_ip']['can_disable'] ?? false)) {") && substr_count($fwComp, "serverAllowed(Access::MANAGE") >= 12, '…the component checks can_disable and Manage on every action');
    $check(str_contains($client, "firewallChange('put', '/firewall/real-ip', [])") && str_contains($client, "firewallChange('delete', '/firewall/real-ip', [])") && str_contains($client, "firewallChange('post', '/firewall/real-ip/refresh', [])"), 'the client: the real-ip routes');
    $check(str_contains((string) file_get_contents($root.'/dev/demo/api/index.php'), "'real_ip' => ['enabled' => false"), 'demo API: real_ip');

    $N = \CipiGui\Support\Notifications::class;
    $cats = [['id' => 'security', 'label' => 'Security'], ['id' => 'deploy', 'label' => 'Deploy']];
    $check($N::events(['events' => null]) === null && $N::events(['events' => []]) === null && $N::events(['events' => ['deploy', 'all']]) === null && $N::events([]) === null, 'events: null, [], a list with all, none — everything');
    $check($N::events(['events' => ['security', 'urgent', 'security', 'Bad Id', 7]]) === ['security', 'urgent'], 'events: selectors only, once each');
    $check($N::eventsLabel(['events' => ['urgent', 'security', 'backup_fail']], $cats) === 'Urgent, Security, backup_fail' && $N::eventsLabel(['events' => null], $cats) === 'Everything', 'events: labels for people');
    $nView = (string) file_get_contents($root.'/resources/views/livewire/notifications.blade.php');
    $nComp = (string) file_get_contents($root.'/src/Livewire/Notifications.php');
    $check(str_contains($nView, 'data-channel-events-form') && str_contains($nView, "wire:model=\"formEvents.{{ N::URGENT }}\"") && str_contains($nView, '<th>Receives</th>') && str_contains($nView, '@if($this->supportsEvents())'), 'Notifications: what each channel receives — the column and the form, only where the server keeps it');
    $check(str_contains($nComp, "\$payload['events'] = \$events;") && str_contains($nComp, "'Choose at least one kind of event — or Everything.'") && str_contains($nComp, '$this->formEventsExtra'), '…sent only when it changed, never empty, single triggers set at the server kept');

    $dbComp = (string) file_get_contents($root.'/src/Livewire/Databases.php');
    $dbView = (string) file_get_contents($root.'/resources/views/livewire/databases.blade.php');
    $check(str_contains($dbComp, "'MariaDB / MySQL'") && str_contains($dbComp, "\$engineCards[\$engine]['label'] = \$engine === 'mariadb' ? \$mysqlLabel") && substr_count($dbView, "{{ \$card['label'] }}") === 2 && str_contains($dbView, '{{ $mysqlLabel }} and PostgreSQL'), 'Databases: the filter, the card and the intro say MySQL where port 3306 runs it (both names across a fleet with both)');
    eval('namespace CipiGui\\Livewire\\Probe; class D { '.(preg_match('/public static function isClientNoise\(string \$name\): bool\s*\{.*?\n    \}/s', $dbComp, $mm) ? $mm[0] : '').' }');
    $check(\CipiGui\Livewire\Probe\D::isClientNoise('mysql: [Warning] Using a password on the command line interface can be insecure.') && \CipiGui\Livewire\Probe\D::isClientNoise('WARNING: database "x" has a collation version mismatch') && ! \CipiGui\Livewire\Probe\D::isClientNoise('mysql_archive') && ! \CipiGui\Livewire\Probe\D::isClientNoise('shop'), 'Databases: a client\'s warning is not a database, a name like mysql_archive is');

    $concern = (string) file_get_contents($root.'/src/Livewire/Concerns/InteractsWithCipiServer.php');
    eval('namespace CipiGui\\Livewire\\Probe; class V { '.(preg_match('/public static function versionless\(string \$message\): string\s*\{.*?\n    \}/s', $concern, $mm) ? $mm[0] : '').' }');
    $V = \CipiGui\Livewire\Probe\V::class;
    $check($V::versionless('The firewall from the panel needs Cipi 5.14.0+ — this server runs Cipi 5.13.1') === 'The firewall from the panel needs an update of Cipi on this server (cipi self-update && cipi api update)', "versionless: the API's \"needs Cipi x+ — this server runs Cipi y\"");
    $check($V::versionless('cipi notification status --json returned no JSON (Cipi CLI ≥ 5.12.0 required)') === 'cipi notification status --json returned no JSON' && $V::versionless('PHP 8.4 is not installed') === 'PHP 8.4 is not installed' && ! preg_match('/\d+\.\d+/', $V::versionless('This server cannot edit nginx directives yet: Cipi 5.14.0+ is required (cipi self-update).')), 'versionless: a version in brackets or "is required" goes, PHP versions stay');
    $check(str_contains($concern, '$message = self::versionless(self::plainCliText($e->getMessage()));'), "friendlyApiError: the server's messages without versions");

    // No user-facing text names a version of Cipi or of its API: comments aside, nothing like "API 1.39+" or "Cipi 5.14.0".
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views', FilesystemIterator::SKIP_DOTS)) as $f) {
        foreach (file((string) $f) as $i => $line) {
            $code = (string) preg_replace('/\{\{--.*?--\}\}/', '', $line);
            if (preg_match('/^\s*(\{\{--|\/\/|\*)/', $code)) {
                continue;
            }
            if (preg_match('/\b(API|Cipi(?: CLI)?|cipi\/api|cipi\/gui)\s*(?:≥|>=)?\s*v?\d+\.\d+(?:\.\d+)?\+?/', $code)) {
                $hits[] = basename((string) $f).':'.($i + 1);
            }
        }
    }
    foreach (glob($root.'/src/Livewire/*.php') as $f) {
        foreach (file($f) as $i => $line) {
            if (! preg_match('/^\s*(\/\/|\*|\/\*)/', $line) && preg_match("/'[^']*\b(API|Cipi|cipi\/api)\s*(?:≥|>=)?\s*\d+\.\d+[^']*'/", $line)) {
                $hits[] = basename($f).':'.($i + 1);
            }
        }
    }
    $check($hits === [], 'no version of Cipi or of its API in what the panel shows'.($hits ? ': '.implode(', ', $hits) : ''));
    $check(! str_contains((string) file_get_contents($root.'/src/Livewire/Settings.php'), "'Built for Cipi API'") && ! str_contains((string) file_get_contents($root.'/resources/views/livewire/servers.blade.php'), 'abilities the panel uses with API'), 'Settings claims no API version; the token command names none');

    $ms = (string) file_get_contents($root.'/resources/views/partials/metrics-settings.blade.php');
    $check(str_contains($ms, '<label class="label" for="m-hb">Heartbeat URL</label>') && str_contains($ms, '<label class="label" for="m-hb-every">Every</label>') && str_contains($ms, '<div class="flex items-start gap-2">') && ! str_contains($ms, 'class="input-group"'), 'Metrics: the heartbeat is a field like the retention\'s — labelled URL and interval on the line of their inputs');

    $check(preg_match("/VERSION = '(\d+\.\d+\.\d+)'/", $theme, $tv47) === 1 && version_compare($tv47[1], '4.7.0', '>=') && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.7.0]'), 'version 4.7.0 or later, its changelog kept');

    echo "--- 4.8.0: connectors, passkeys, Zero Trust in full\n";
    $routes = (string) file_get_contents($root.'/routes/web.php');
    $check(preg_match("/VERSION = '(\d+\.\d+\.\d+)'/", $theme, $tv) === 1 && version_compare($tv[1], '4.8.0', '>=') && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.8.0]'), 'version 4.8.0 or later; 4.8.0 is in the changelog');
    $check(str_contains($routes, "Route::get('/infrastructure/{connector}', Infrastructure::class)") && strpos($routes, "/infrastructure/{connector}") > strpos($routes, "Route::middleware('cipi-gui.admin')"), 'Infrastructure: a route of the admin group');
    $check(str_contains($routes, "'/passkeys/login/options'") && str_contains($routes, "'/passkeys/login'") && str_contains($routes, "'/2fa/passkey'") && str_contains($routes, "'/passkeys/register'") && substr_count($routes, "middleware('throttle:10,1')") >= 3, 'passkeys: login (guests), second step and register (signed in), each rate-limited');
    $mig = implode('', array_map('file_get_contents', glob($root.'/database/migrations/2026_11_25_*.php')));
    $check(str_contains($mig, "Schema::create('cipi_connector_accounts'") && str_contains($mig, "Schema::create('cipi_passkeys'") && str_contains($mig, "'cf_access_client_secret'") && str_contains($mig, "char('credential_hash', 64)->unique()"), 'three migrations; a passkey looked up by the hash of its id');
    $model = (string) file_get_contents($root.'/src/Models/CipiConnectorAccount.php');
    $check(str_contains($model, "'credentials' => 'encrypted:array'") && str_contains($model, "protected \$hidden = ['credentials']"), 'connector credentials: encrypted, hidden');
    $srv = (string) file_get_contents($root.'/src/Models/CipiServer.php');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    $check(str_contains($srv, "Crypt::encryptString(\$value)") && str_contains($srv, "'cf_access_client_secret',\n    ];") && str_contains($client, '$server->cloudflareAccessHeaders()'), 'a server behind Cloudflare Access: the secret encrypted and hidden, the headers on every call');
    $tfs = (string) file_get_contents($root.'/src/Services/TwoFactorService.php');
    $mw = (string) file_get_contents($root.'/src/Http/Middleware/EnsureTwoFactorVerified.php');
    $check(str_contains($tfs, 'Passkeys::has($user)') && str_contains($mw, 'TwoFactorService::hasSecondFactor($user)') && str_contains((string) file_get_contents($root.'/src/Http/Controllers/Auth/LoginController.php'), 'TwoFactorService::hasSecondFactor($user)'), 'a passkey is a second factor: after the password, for the requirement, in the middleware');
    $pk = (string) file_get_contents($root.'/src/Http/Controllers/Auth/PasskeyController.php');
    $check(str_contains($pk, "Passkeys::mayRegister(\$request->user(), \$request->session())") && substr_count($pk, '->regenerate()') === 1, 'adding a passkey needs the second step first (or no second factor yet); a passkey sign-in regenerates the session');
    foreach (['infrastructure', 'connector-accounts', 'passkey-list'] as $v) {
        $text = (string) file_get_contents($root."/resources/views/livewire/{$v}.blade.php");
        $check(! preg_match('/\b(API|Cipi) \d+\.\d+/', $text), "{$v}: no version of Cipi or of its API in its text");
    }

    echo "--- 4.9.0: page cache, Octane, Valkey per app, hostname, Written as\n";
    require_once $root.'/src/Support/PageCache.php';
    $PC = \CipiGui\Support\PageCache::class;
    $theme = (string) file_get_contents($root.'/src/Support/Theme.php');
    $changelog = (string) file_get_contents($root.'/CHANGELOG.md');
    $check(preg_match("/VERSION = '(\d+\.\d+\.\d+)'/", $theme, $tv49) === 1 && version_compare($tv49[1], '4.9.0', '>=') && preg_match("/API = '(\d+\.\d+)'/", $theme, $ta49) === 1 && version_compare($ta49[1], '1.47', '>=') && str_contains($changelog, '## [4.9.0]'), 'version 4.9.0 or later, for API 1.47 or later');

    // The page cache: the values the form takes are the server's own rules.
    $check($PC::ttlValid('30s') && $PC::ttlValid('10m') && $PC::ttlValid('30d') && ! $PC::ttlValid('31d') && ! $PC::ttlValid('0s') && ! $PC::ttlValid('10') && ! $PC::ttlValid('10 m') && ! $PC::ttlValid('1w') && $PC::ttlSeconds('2h') === 7200,
        'page cache: a time is a number and s, m, h or d — one second to thirty days');
    $check($PC::sizeValid('16m') && $PC::sizeValid('100g') && ! $PC::sizeValid('15m') && ! $PC::sizeValid('101g') && ! $PC::sizeValid('1G') && ! $PC::sizeValid('256'), 'page cache: a size is 16m to 100g');
    $check($PC::ttlLabel('10m') === '10 minutes' && $PC::ttlLabel('1h') === '1 hour' && $PC::ttlLabel('45m') === '45 minutes' && $PC::sizeLabel('256m') === '256 MB' && $PC::sizeLabel('2g') === '2 GB' && $PC::bytes(5242880) === '5.0 MB' && $PC::bytes(900) === '900 B',
        'page cache: times, sizes and bytes for people');
    $check(array_filter(array_keys($PC::TTL_CHOICES), fn ($t) => ! $PC::ttlValid((string) $t)) === [] && array_filter(array_keys($PC::SIZE_CHOICES), fn ($t) => ! $PC::sizeValid((string) $t)) === [], 'every choice of the form is a value the server takes');
    $check($PC::clearCommand(['clear_command' => 'sudo /usr/local/bin/cipi-cache-clear shop'], 'shop') === 'sudo /usr/local/bin/cipi-cache-clear shop'
        && $PC::clearCommand(['clear_command' => 'sudo rm -rf / # shop'], 'shop') === 'sudo /usr/local/bin/cipi-cache-clear shop'
        && $PC::clearCommand(null, 'blog') === 'sudo /usr/local/bin/cipi-cache-clear blog',
        'the command shown for a recipe is the helper and the app, whatever the server sent');

    // The paths an app keeps out of its cache.
    $check($PC::excludeParse("cart*\n /feed ,/cart*\r\n\n/a**b  /caff%C3%A8_1.0~x") === ['/cart*', '/feed', '/a*b', '/caff%C3%A8_1.0~x'] && $PC::excludeParse(" \n ") === [] && $PC::excludeParse('//x') === ['/x'],
        'page cache paths: one per line (or commas, or spaces), each with its /, several * as one, once');
    $check($PC::excludeError(['/cart*', '/feed', '/shop/*/reviews', '/']) === null && $PC::excludeError([]) === null, 'page cache paths: letters, digits, . _ ~ % - / and *');
    $badPaths = ['/a;b', '/a"b', "/a'b", '/a$b', '/a{b}', '/a|b', '/(a)', '/a\\b', '/a?b=1', '/a#b', '/a<b', '/'.str_repeat('a', 128)];
    $check(array_filter($badPaths, fn ($b) => $PC::excludeError([$b]) === null) === [] && str_contains((string) $PC::excludeError(['/*']), 'every page') && $PC::excludeError($PC::excludeParse('*')) !== null,
        'page cache paths: ; " \' $ { | ( \\ ? # <, 129 characters and /* (every page) never leave the panel');
    $check($PC::excludeError(array_map(fn ($i) => "/p{$i}", range(1, 50))) === null && str_contains((string) $PC::excludeError(array_map(fn ($i) => "/p{$i}", range(1, 51))), 'Up to 50'), 'page cache paths: fifty at most');
    $check($PC::excludes(['exclude' => ['/cart*', 7, '/x;y', null, '/feed']]) === ['/cart*', '/feed'] && $PC::excludes(null) === [] && $PC::excludes(['exclude' => 'nope']) === [], 'page cache paths: only what is a path is shown, whatever the server sent');

    $adv = (string) file_get_contents($root.'/resources/views/livewire/app-detail.blade.php');
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $check(str_contains($adv, 'data-page-cache-exclude') && str_contains($adv, 'wire:submit="saveCacheExclude"') && str_contains($adv, 'wire:model="cacheExclude"') && str_contains($adv, 'Paths that are never cached') && str_contains($adv, "array_key_exists('exclude', \$pc)") && str_contains($adv, 'The cache is off: the list is kept for when it is turned on.'),
        'the Page cache tab has the app\'s own paths: one per line, saved on their own, kept while the cache is off — shown when the server gives them');
    $check(preg_match('/public function saveCacheExclude\(\): void\s*\{\s*if \(! \$this->allowed\(\'edit\'\)\)/', $ad) === 1 && str_contains($ad, 'PageCache::excludeError($paths)') && str_contains($ad, '$this->cacheExcludeRefused = self::plainCliText($e->getMessage());') && str_contains((string) file_get_contents($root.'/src/Services/CipiApiClient.php'), '"/apps/{$app}/cache/exclude"'),
        'saving the paths needs Edit, checks them first, and shows a refusal in the server\'s words');
    $check(str_contains($ad, "\$tabs['cache'] = 'Page cache';") && str_contains($ad, "\$tabs['octane'] = 'Octane';") && str_contains($ad, "'cache' => \$this->loadPageCache(),") && str_contains($ad, "'octane' => \$this->loadOctane(),"), 'an app has a Page cache tab (not a Node app) and, on Octane, an Octane tab');
    $check(str_contains($adv, 'OPcache is on already') && str_contains($adv, 'PHP still runs') && str_contains($adv, 'The page cache keeps the page itself.') && str_contains($adv, 'Not OPcache — the other cache'), 'the tab says what OPcache does and what the page cache adds');
    $check(str_contains($adv, 'WordPress, WooCommerce, blogs, catalogues, landing pages') && str_contains($adv, 'Traffic peaks') && str_contains($adv, 'What is never cached') && str_contains($adv, 'A Laravel app chooses its pages'), 'what one gets (WordPress and the like), what is never cached, and Laravel\'s own case');
    $check(str_contains($adv, 'Cipi empties the cache by itself after every successful deploy and rollback') && str_contains($adv, "after('deploy:symlink', function () {") && str_contains($adv, "add_action('save_post', function () {") && str_contains($adv, '$pcRecipe') && str_contains($adv, 'data-page-cache-purge'),
        'how to empty it: nothing after a Cipi deploy, one command in a recipe of one\'s own, a hook in WordPress');
    $check(str_contains($adv, 'wire:click="clearPageCache"') && str_contains($adv, 'wire:click="disablePageCache"') && str_contains($adv, 'wire:submit="savePageCache"') && str_contains($adv, 'X-Cache-Status'), 'turn on, save, empty now, turn off — and how to check a page');
    $check(preg_match('/public function savePageCache\(\): void\s*\{\s*if \(! \$this->allowed\(\'edit\'\)\)/', $ad) === 1 && preg_match('/public function disablePageCache\(\): void\s*\{\s*if \(! \$this->allowed\(\'edit\'\)\)/', $ad) === 1 && str_contains($ad, "\$this->allowed((\$this->can['deploy'] ?? false) ? 'deploy' : 'edit')"),
        'turning the cache on or off needs Edit on the app; emptying it comes with Deploy too');
    $check(preg_match('/public function switchOctanePhp\(\?string \$version = null\): void\s*\{\s*if \(! \$this->allowed\(\'edit\'\)\)/', $ad) === 1 && str_contains($ad, 'preg_match(\'/^\d\.\d$/\', $version) !== 1') && str_contains($ad, 'in_array($version, $this->installedPhpVersions, true)'),
        'Octane: a PHP version is X.Y, installed on the server, and switching needs Edit');
    $check(str_contains($adv, 'Two PHP versions in one app') && str_contains($adv, 'wire:click="switchOctanePhp"') && str_contains($adv, 'wire:click="restartOctane"') && str_contains($adv, 'wire:click="upgradeOctaneBuild"') && str_contains($adv, 'Why two PHP'), 'Octane tab: the two PHP, the mismatch and its fix, switch, restart, update the build');
    $check(! str_contains($adv, 'type="submit" class="btn btn-primary field-action" data-confirm'), 'a confirmation sits on a click, not on a form\'s submit');

    // Valkey per app, with the server's services.
    $sm = (string) file_get_contents($root.'/src/Livewire/ServerManage.php');
    $smv = (string) file_get_contents($root.'/resources/views/livewire/server-manage.blade.php');
    $check(str_contains($sm, "\$this->client()->prefetch(['/services', '/valkey']);") && str_contains($sm, 'protected function loadValkey(): void') && str_contains($smv, 'data-valkey') && str_contains($smv, 'wire:click="isolateAllValkey"') && str_contains($smv, 'wire:click="flushSharedValkey"'),
        'Services tab: Valkey per app, read with the services; isolate one, isolate all, empty the shared instance');
    foreach (['isolateValkey', 'isolateAllValkey', 'shareValkey', 'restartValkey', 'flushSharedValkey'] as $method) {
        $check(preg_match('/public function '.$method.'\([^)]*\): void\s*\{\s*if \(! \$this->serverAllowed\(Access::MANAGE, \(int\) \$this->serverId\)/', $sm) === 1, "{$method}: Manage access to the server, asked at the call");
    }
    $check(str_contains($sm, "} elseif (\$tab === 'services' && ! (\$this->loaded['valkey'] ?? false)) {") && str_contains($sm, "\$this->loaded['valkey'] = true;"), 'Valkey is read when the Services tab is opened after the Overview, which has read the services already');
    $check(str_contains($smv, 'No password travels through the panel') && ! preg_match('/valkey[^\n]*password\'\]/i', $smv), 'the Valkey card shows no password, and says so');

    // The machine's own name.
    $srv = (string) file_get_contents($root.'/src/Models/CipiServer.php');
    $mig = (string) file_get_contents($root.'/database/migrations/2026_11_26_000000_add_hostname_to_cipi_servers_table.php');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    $check(str_contains($mig, "\$table->string('hostname', 253)->nullable()->after('ip');") && str_contains($mig, "Schema::hasColumn('cipi_servers', 'hostname')"), 'cipi_servers.hostname: added once, nullable');
    $check(str_contains($srv, 'public function rememberHostname(?array $status): void') && str_contains($srv, 'public static function cleanHostname(mixed $hostname): ?string') && str_contains($client, '$server->rememberHostname($results[$server->id][\'data\']);')
        && str_contains($sm, '$this->currentServer()?->rememberHostname($this->status);') && str_contains((string) file_get_contents($root.'/src/Livewire/Servers.php'), '$server->rememberHostname($status);'),
        'the hostname is kept from every status read: the dashboard, the server page, a connection test');
    $sv = (string) file_get_contents($root.'/resources/views/livewire/servers.blade.php');
    $check(str_contains($sv, '>Hostname</th>') && str_contains($sv, '<span class="badge badge-accent badge-mono"') && str_contains($sv, '{{ $server->hostname }}') && str_contains($sv, 'data-hostname-hint'), 'Connections: a Hostname column, set apart, and what it is');
    $check(str_contains($smv, 'data-server-hostname') && str_contains($smv, '<dt>Name here</dt>') && str_contains((string) file_get_contents($root.'/resources/views/livewire/dashboard.blade.php'), '$server->machine_name')
        && str_contains((string) file_get_contents($root.'/resources/views/partials/server-switcher.blade.php'), '$s->machine_name'), 'the server page, the dashboard and the switcher show it too');

    // Notifications → Written as.
    $N = \CipiGui\Support\Notifications::class;
    $check(preg_match($N::SERVER_NAME_PATTERN, 'production') === 1 && preg_match($N::SERVER_NAME_PATTERN, 'EU West 1.a_b-c') === 1 && preg_match($N::SERVER_NAME_PATTERN, '--clear') === 0 && preg_match($N::SERVER_NAME_PATTERN, "prod\nSubject: x") === 0 && preg_match($N::SERVER_NAME_PATTERN, 'prod $(id)') === 0 && preg_match($N::SERVER_NAME_PATTERN, str_repeat('a', 41)) === 0,
        'a server name is letters, digits, spaces, dots, dashes, underscores — 40 at most, not an option, not a header');
    $check(preg_match($N::TIMEZONE_PATTERN, 'Europe/Rome') === 1 && preg_match($N::TIMEZONE_PATTERN, 'America/Argentina/Buenos_Aires') === 1 && preg_match($N::TIMEZONE_PATTERN, 'UTC') === 1 && preg_match($N::TIMEZONE_PATTERN, '../etc/passwd') === 0 && preg_match($N::TIMEZONE_PATTERN, 'Europe/Rome; id') === 0, 'a time zone is an IANA name');
    $nt = (string) file_get_contents($root.'/src/Livewire/Notifications.php');
    $ntv = (string) file_get_contents($root.'/resources/views/livewire/notifications.blade.php');
    $check(str_contains($nt, "\$this->syncSettings(\$status['settings'] ?? null);") && preg_match('/public function saveSettings\(\): void\s*\{\s*if \(! \$this->guardManage\(\)/', $nt) === 1 && str_contains($nt, 'public function usePanelName(): void'), 'Written as: read with the channels, changed with Manage access');
    $check(str_contains($ntv, 'data-notification-settings') && str_contains($ntv, 'wire:model="settingsTimezone"') && str_contains($ntv, 'wire:model="settingsServerName"') && str_contains($ntv, 'data-use-panel-name') && str_contains($ntv, '@if($settings !== null)'), 'Written as: the server name (the panel\'s own in one click) and the time zone, on a server that has them');

    foreach (['page cache and Octane tabs' => substr($adv, (int) strpos($adv, "@elseif(\$activeTab === 'cache')"), (int) strpos($adv, "@elseif(\$activeTab === 'deploy')") - (int) strpos($adv, "@elseif(\$activeTab === 'cache')"))] as $label => $text) {
        $visible = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $text);
        $check(! preg_match('/\b(API|Cipi) \d+\.\d+/', $visible), "{$label}: no version of Cipi or of its API in its text");
    }

    echo "--- 4.10.0: the mesh network (Tailscale, NetBird) in the Zero Trust tab, who holds a firewall lock\n";
    $check(str_contains($theme, "VERSION = '4.10.0'") && str_contains($theme, "API = '1.48'") && str_contains($changelog, '## [4.10.0]'), 'version 4.10.0 for API 1.48');
    $check($FW::zeroTrustLocks(['lock_http' => false, 'lock_ssh' => false, 'by' => ['http' => [], 'ssh' => []]]) === null && $FW::zeroTrustLocks([]) === null, 'no lock: no notice');
    $l = $FW::zeroTrustLocks(['lock_http' => true, 'lock_ssh' => true]);
    $check($l['text'] === 'Zero Trust keeps 80/443 to Cloudflare and SSH closed: opening them to everyone from here is refused.' && $l['commands'] === ['cipi zt unlock http', 'cipi zt ssh unlock'], 'a server that does not say who holds a lock: Cloudflare, as before');
    $l = $FW::zeroTrustLocks(['lock_http' => false, 'lock_ssh' => true, 'by' => ['http' => [], 'ssh' => ['tailscale']]]);
    $check($l['text'] === 'Zero Trust keeps SSH to the Tailscale network: opening it to everyone from here is refused.' && $l['commands'] === ['cipi zt tailscale unlock ssh'], "SSH locked by the mesh: its name, and its own unlock command");
    $l = $FW::zeroTrustLocks(['lock_http' => true, 'lock_ssh' => true, 'by' => ['http' => ['cloudflare', 'netbird'], 'ssh' => ['cloudflare', 'netbird']]]);
    $check($l['text'] === 'Zero Trust keeps 80/443 to Cloudflare and the NetBird network and SSH closed: opening them to everyone from here is refused.'
        && $l['commands'] === ['cipi zt unlock http', 'cipi zt netbird unlock http', 'cipi zt ssh unlock', 'cipi zt netbird unlock ssh'], 'both sides hold a lock: both named, a command each');
    $l = $FW::zeroTrustLocks(['lock_http' => true, 'by' => ['http' => ['tailscale; rm -rf /', ['x'], 'TAILSCALE'], 'ssh' => ['tailscale']]]);
    $check($l['commands'] === ['cipi zt tailscale unlock http'] && ! str_contains($l['text'], 'rm -rf'), 'a holder is one of three names, whatever the server sends; a lock that is off has none');
    $check($FW::mesh(null) === null && $FW::mesh('x') === null, 'a server that says nothing of a mesh: no card');
    $off = $FW::mesh(['enabled' => false, 'provider' => null, 'active' => null]);
    $check($off['on'] === false && $off['badge'] === 'Off' && $off['tone'] === 'gray', 'no mesh on: Off');
    $m = $FW::mesh(['enabled' => true, 'provider' => 'tailscale', 'connected' => true, 'key_expiry' => null, 'warnings' => [], 'ssh' => ['provider_ssh' => false]]);
    $check($m['on'] && $m['badge'] === 'Tailscale connected' && $m['tone'] === 'green' && $m['key'] === 'Does not expire' && $m['ssh'] === "OpenSSH, with the server's keys", 'Tailscale connected, a node key that does not expire, OpenSSH');
    $m = $FW::mesh(['enabled' => true, 'provider' => 'tailscale', 'connected' => true, 'key_expiry' => '2027-01-06T10:00:00Z', 'key_expires_in_days' => 40, 'warnings' => ['The node key expires in 40 days'], 'ssh' => ['provider_ssh' => true]]);
    $check($m['tone'] === 'amber' && $m['key'] === 'Expires on 2027-01-06 — in 40 days' && str_starts_with($m['ssh'], 'Tailscale SSH'), 'a node key that expires: the day, the days left, amber; Tailscale SSH said');
    $m = $FW::mesh(['enabled' => true, 'provider' => 'tailscale', 'connected' => false, 'key_expiry' => '2026-11-20T10:00:00Z', 'key_expires_in_days' => -7]);
    $check($m['tone'] === 'red' && $m['badge'] === 'Tailscale disconnected' && $m['key'] === 'Expired on 2026-11-20', 'off the network with an expired key: red, and why');
    $m = $FW::mesh(['enabled' => true, 'provider' => 'netbird', 'connected' => true, 'warnings' => []]);
    $check($m['badge'] === 'NetBird connected' && $m['key'] === null, 'NetBird: no node key line');
    $check($FW::mesh(['enabled' => true, 'provider' => ''])['on'] === false, 'a mesh with no provider is off');
    $ztTab = (string) (preg_match("/@elseif\(\\\$activeTab === 'zt'\)(.*?)@elseif\(\\\$activeTab === 'ip'\)/s", $smv, $zm) ? $zm[1] : '');
    $check($ztTab !== '' && str_contains($ztTab, 'data-zt-mesh') && str_contains($ztTab, "\\CipiGui\\Support\\Firewall::mesh(\$mesh)")
        && strpos($ztTab, 'data-zt-mesh') > strpos($ztTab, 'data-zt-details'), 'Zero Trust tab: the mesh card, in the detailed view');
    $check(! preg_match('/\b(API|Cipi) \d+\.\d+/', (string) preg_replace('/{{--(.*?)--}}/s', '', $ztTab)), 'Zero Trust tab: no version of Cipi or of its API in its text');
    $fwv = $views[$root.'/resources/views/livewire/firewall.blade.php'] ?? '';
    $check(str_contains($fwv, 'F::zeroTrustLocks($zt)') && str_contains($fwv, 'data-firewall-zt') && ! str_contains($fwv, "'80/443 to Cloudflare'"), 'Firewall tab: the lock notice comes from who holds it');

    echo "--- the mesh network managed from the Zero Trust tab: join, leave, locks, Tailscale SSH\n";
    $MS = 'CipiGui\Support\Mesh';
    $check($MS::joinErrors(['provider' => 'tailscale', 'key' => 'tskey-auth-kABC-0123456789']) === [] && $MS::joinErrors(['provider' => 'netbird', 'key' => 'A1B2C3D4-E5F6-7890-ABCD-EF1234567890']) === [], 'join: an auth key, a setup key');
    $check($MS::joinErrors(['provider' => 'tailscale', 'key' => '']) === [] && $MS::joinErrors(['provider' => 'netbird']) === [], 'join without a key: the server takes a client that is already connected');
    $check(array_keys($MS::joinErrors(['provider' => 'zerotier'])) === ['provider'] && array_keys($MS::joinErrors([])) === ['provider'], 'join: Tailscale or NetBird');
    foreach (["tskey'; id", 'short', 'has space key', 'tskey$(id)xx', "tskey\nabcdefg"] as $bad) {
        $check(isset($MS::joinErrors(['provider' => 'tailscale', 'key' => $bad])['key']), 'join: not a key '.json_encode($bad));
    }
    $e = $MS::joinErrors(['provider' => 'tailscale', 'key' => 'tskey-abcdef', 'hostname' => 'web_1', 'control_url' => 'ftp://x', 'tags' => 'ok, no way']);
    $check(array_keys($e) === ['hostname', 'control_url', 'tags'], 'join: a hostname, a control URL and a tag that are not ones, each on its field');
    $check($MS::joinErrors(['provider' => 'netbird', 'key' => 'abcdefgh', 'tags' => 'no way']) === [], 'join NetBird: tags are not its own — not read, not an error');
    $check($MS::tags('cipi, tag:prod ,cipi,') === ['tag:cipi', 'tag:prod'] && $MS::tags('') === [] && $MS::tags(null) === [] && $MS::tags("a';id") === null, 'tags: tag: added, doubles dropped; a bad one refused');
    $check($MS::joinPayload(['provider' => 'tailscale', 'key' => ' tskey-auth-k1 ', 'hostname' => 'web1', 'control_url' => '', 'tags' => 'cipi', 'direct' => true, 'accept_dns' => false, 'accept_routes' => false, 'ssh' => true])
        === ['provider' => 'tailscale', 'key' => 'tskey-auth-k1', 'hostname' => 'web1', 'direct' => true, 'tags' => ['tag:cipi'], 'ssh' => true], 'join Tailscale: only what was filled in');
    $check($MS::joinPayload(['provider' => 'netbird', 'key' => 'A1B2C3D4', 'control_url' => 'https://nb.example.com', 'tags' => 'cipi', 'ssh' => true, 'accept_routes' => true])
        === ['provider' => 'netbird', 'key' => 'A1B2C3D4', 'control_url' => 'https://nb.example.com', 'accept_routes' => true], 'join NetBird: nothing of Tailscale\'s goes');
    $check($MS::keyName('tailscale') === 'Auth key' && $MS::keyName('netbird') === 'Setup key' && $MS::label('netbird') === 'NetBird' && $MS::label(['x']) === 'Mesh', 'each provider calls its key by its own name');
    $b = $MS::blocked(['enabled' => true, 'provider' => 'tailscale']);
    $check($b === ['managed' => false, 'lock_ssh' => null, 'lock_http' => null, 'leave' => null], 'a server that does not say what the panel may do: not managed from here');
    $b = $MS::blocked(['panel' => ['via' => 'public', 'blocked' => ['lock_ssh' => ' Nobody has signed in over SSH through Tailscale yet. ', 'lock_http' => 'The panel reaches this server on its public address', 'disable' => null]]]);
    $check($b['managed'] && $b['lock_ssh'] === 'Nobody has signed in over SSH through Tailscale yet.' && str_starts_with($b['lock_http'], 'The panel reaches') && $b['leave'] === null, 'what the server would refuse, with its reason; the rest goes');
    $check($MS::blocked(['panel' => ['blocked' => ['lock_ssh' => ['x'], 'lock_http' => '', 'disable' => 5]]]) === ['managed' => true, 'lock_ssh' => null, 'lock_http' => null, 'leave' => null], 'a reason is text or nothing');
    $r = fn (array $panel, string $p = 'tailscale') => $MS::route(['provider' => $p, 'panel' => $panel]);
    $check($MS::route(['provider' => 'tailscale']) === null && $MS::route(null) === null, 'no route said: no line');
    $check(str_contains($r(['via' => 'mesh', 'from' => '100.64.0.2']), 'over Tailscale (from 100.64.0.2)') && str_contains($r(['via' => 'mesh'], 'netbird'), 'over NetBird:'), 'the panel over the mesh');
    $check(str_contains($r(['via' => 'public', 'from' => '203.0.113.9']), 'on its public address (from 203.0.113.9)') && str_contains($r(['via' => 'public', 'from' => '<b>x']), 'public address:'), 'the panel on the public address; an address that is not one is not shown');
    $check(str_contains($r(['via' => 'tunnel']), 'Cloudflare tunnel') && str_contains($r(['via' => 'unknown']), 'could not tell') && str_contains($r(['via' => 'whatever']), 'could not tell'), 'through the tunnel; unknown');
    $check(str_contains($r(['via' => 'local', 'client' => ['via' => 'public', 'from' => '198.51.100.7']]), 'You are looking at it on its public address (from 198.51.100.7)')
        && str_contains($r(['via' => 'local', 'client' => ['via' => 'public', 'from' => '198.51.100.7']]), 'on its Tailscale address first'), 'a panel on the server, looked at on the public address: said, with what to do');
    $check(str_contains($r(['via' => 'local', 'client' => ['via' => 'mesh', 'from' => '100.64.0.9']]), 'over Tailscale (from 100.64.0.9): closing 80/443 to the world keeps this page')
        && str_contains($r(['via' => 'local', 'client' => ['via' => 'tunnel']]), 'through the Cloudflare tunnel') && str_contains($r(['via' => 'local', 'client' => null]), 'runs on the server itself'), 'a panel on the server, looked at over the mesh or through the tunnel');
    $check($MS::seen(['ssh' => ['seen' => ['at' => '2026-11-27T12:00:01', 'from' => '100.99.1.2']]]) === 'Last login over the network from 100.99.1.2 · 2026-11-27T12:00:01'
        && $MS::seen(['ssh' => ['seen' => null]]) === null && $MS::seen([]) === null && $MS::seen(['ssh' => ['seen' => ['at' => 'Nov 27 12:00:01', 'from' => 'x; id']]]) === 'Last login over the network · Nov 27 12:00:01', 'the last login over the mesh; nobody yet is nothing');

    $trait = (string) file_get_contents($root.'/src/Livewire/Concerns/ManagesMesh.php');
    $actions = ['joinMesh', 'leaveMesh', 'lockMesh', 'unlockMesh', 'setMeshSsh'];
    $guarded = true;
    foreach ($actions as $a) {
        $guarded = $guarded && (bool) preg_match('/public function '.$a.'\([^)]*\): void\s*\{\s*if \(! \$this->serverAllowed\(Access::MANAGE, \(int\) \$this->serverId\)/', $trait);
    }
    $check($guarded, 'every action of the mesh needs Manage on the server, before anything else');
    $check(str_contains($trait, "finally {") && str_contains($trait, "\$this->meshKey = '';") && ! str_contains($trait, 'Log::') && ! str_contains($trait, 'session('), 'the key is cleared once sent, whatever the server answered; never logged, never in the session');
    $check(str_contains($trait, "in_array(\$port, ['ssh', 'http'], true)") && str_contains($trait, 'zt-manage') && str_contains($trait, 'cipi self-update && cipi api update'), 'a port is ssh or http; a token without zt-manage and an older server are said in words');
    $check(substr_count($trait, '$this->meshBrowserIp()') >= 6 && str_contains($trait, 'request()->ip()'), 'the browser\'s address goes with every call: the server refuses to close 80/443 under the page being looked at');
    $smc = (string) file_get_contents($root.'/src/Livewire/ServerManage.php');
    $check(str_contains($smc, 'use ManagesMesh;') && str_contains($smc, "'zt' => fn () => \$this->zt = \$this->loadZt(),"), 'the server page has the mesh actions and reads Zero Trust for this browser');
    $client = (string) file_get_contents($root.'/src/Services/CipiApiClient.php');
    foreach (["'post', '/zt/mesh'," => 'join', "'delete', '/zt/mesh'," => 'leave', "'post', '/zt/mesh/lock'," => 'lock', "'delete', '/zt/mesh/lock'," => 'unlock', "'put', '/zt/mesh/ssh'," => 'Tailscale SSH'] as $needle => $what) {
        $check(str_contains($client, $needle), "client: {$what}");
    }
    $check(str_contains($client, "max(240, (int) config('cipi-gui.http_timeout', 30))") && str_contains($client, "\$data['client'] = \$client;"), 'client: a join gets minutes, not seconds; the browser\'s address goes in the body');
    $check(str_contains($cfg, "'zt-view', 'zt-manage'"), 'the token command on Connections asks for zt-manage');
    foreach (['data-zt-mesh-join' => 'the join form', 'wire:submit="joinMesh"' => 'join', "wire:click=\"lockMesh('ssh')\"" => 'close SSH', "wire:click=\"lockMesh('http')\"" => 'close 80/443', "wire:click=\"unlockMesh('ssh')\"" => 'reopen SSH',
        "wire:click=\"unlockMesh('http')\"" => 'reopen 80/443', 'wire:click="setMeshSsh(true)"' => 'Tailscale SSH on', 'wire:click="setMeshSsh(false)"' => 'Tailscale SSH off', 'wire:click="leaveMesh"' => 'leave',
        'data-zt-mesh-why="ssh"' => 'why SSH is not closed', 'data-zt-mesh-why="http"' => 'why 80/443 are not closed', 'data-zt-mesh-why="leave"' => 'why the network is not left', 'data-zt-mesh-route' => 'how the panel reaches the server',
        'data-zt-mesh-seen' => 'the last login over the mesh', 'data-zt-mesh-update' => 'a server that needs an update', 'data-zt-mesh-shell' => 'a mesh the panel is not told about'] as $needle => $what) {
        $check(str_contains($ztTab, $needle), "Zero Trust tab: {$what}");
    }
    preg_match_all('/<button[^>]*wire:click="(lockMesh|unlockMesh|leaveMesh|setMeshSsh\(true\))[^>]*>/', $ztTab, $bm);
    $check(count($bm[0]) === 6 && ! array_filter($bm[0], fn ($b) => ! str_contains($b, 'data-confirm="')), 'closing, reopening, leaving and Tailscale SSH on all ask first');
    $check(substr_count($ztTab, '@disabled($meshBlocked[') === 3 && str_contains($ztTab, 'type="password" wire:model="meshKey"') && str_contains($ztTab, 'autocomplete="off"'), 'a button the server would refuse is greyed out; the key is typed as a secret');
    $check(str_contains($ztTab, '@elseif($canManage)') && str_contains($ztTab, '@if(! $canManage)') && str_contains($ztTab, 'needs Manage access to this server'), 'without Manage access: the status, and no form or button');
    $cfCard = (string) (preg_match('/data-zt-details>(.*?)\$mesh = is_array/s', $ztTab, $cm) ? $cm[1] : 'wire:click');
    $check(! str_contains($cfCard, 'wire:click') && ! str_contains($cfCard, 'wire:submit') && str_contains($cfCard, 'Status only'), 'Cloudflare\'s card is still status only');
    $check(! preg_match('/\b(API|Cipi) \d+\.\d+/', (string) preg_replace('/{{--(.*?)--}}/s', '', $ztTab)) && ! preg_match('/\b(API|Cipi) \d+\.\d+/', (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($root.'/src/Support/Mesh.php'))), 'the mesh in the panel: no version of Cipi or of its API in what it says');
    $l = $FW::zeroTrustLocks(['lock_http' => false, 'lock_ssh' => true, 'by' => ['http' => [], 'ssh' => ['tailscale']]]);
    $l2 = $FW::zeroTrustLocks(['lock_http' => true, 'lock_ssh' => false]);
    $check($l['mesh'] === true && $l2['mesh'] === false && str_contains($fwv, 'data-firewall-zt-tab') && str_contains($fwv, 'href="?tab=zt"'), 'Firewall tab: a lock of the mesh is reopened in the Zero Trust tab, and the notice links there');
    $demo = (string) file_get_contents($root.'/dev/demo/api/index.php');
    $check(str_contains($demo, "\$r('/zt/mesh(/lock|/ssh)?')") && str_contains($demo, 'function ztMeshRoute('), 'the demo API joins, leaves and locks too');

    $appsView = (string) file_get_contents($root.'/resources/views/livewire/apps.blade.php');
    $check(str_contains($appsView, 'data-create-coming-soon') && str_contains($appsView, "@if(\$appKind === 'custom')") && str_contains($appsView, 'Coming soon</em> page'), 'Create app, custom: the form says what the domain shows while there is no repository');

    echo "--- a deploy stopped on the clock: said as that, with hints of its own\n";
    $jp = new CipiGui\Services\JobOutputParser;
    $generic = $jp->deployHints();
    // What the job of the report carried: the build at Deployer's 300 seconds.
    $old = "[INFO] Deploying 'iceberg'...\n\ntask deploy:vendors\ntask cipi:node_build\n[localhost] Deployer\\Exception\\TimeoutException in deploy.php on line 52:\n\n"
        ."  The command \"test ! -x /home/iceberg/.deployer/node-build.sh || /home/iceberg/.deployer/node-build.sh /home/iceberg/releases/71\" exceeded the timeout of 300 seconds.\n\n"
        ."task deploy:failed\ntask deploy:unlock\n\n\e[0;31m[ERROR]\e[0m Deploy failed (exit 1)\n\e[0;33m[WARN]\e[0m Log: /var/log/cipi-apps/iceberg/deploy.log";
    $check($jp->deployTimeout($old) === ['step' => 'cipi:node_build', 'seconds' => 300, 'build' => true], 'the step and its seconds are read from the output — the step before the error, not deploy:failed after it');
    $check($jp->extractError($old) === 'The build (cipi:node_build) ran for 5 minutes, the most it is given, and was stopped.', 'the error is that sentence, not "Deploy failed (exit 1)"');
    $hints = $jp->deployHints($old);
    $check($hints !== $generic && str_contains($hints[0], 'cipi self-update') && str_contains($hints[0], '30 minutes') && ! str_contains(implode(' ', $hints), 'deploy key'), 'a build stopped at 5 minutes: update the server — not the hints about the Git repository');
    $check(preg_match('/\d+\.\d+/', implode(' ', $hints).$jp->extractError($old)) === 0, '…and no version number in any of it');
    $new = str_replace('of 300 seconds', 'of 1800 seconds', $old);
    $check($jp->extractError($new) === 'The build (cipi:node_build) ran for 30 minutes, the most it is given, and was stopped.' && str_contains($jp->deployHints($new)[0], 'short of memory') && ! str_contains(implode(' ', $jp->deployHints($new)), 'self-update'),
        'a build that used its 30 minutes: where to look, and no "update the server"');
    $node = "task node:install\n[localhost] Deployer\\Exception\\TimeoutException in deploy.php on line 80:\n\n  The command \"export PATH=/opt/cipi/node/22/bin && cd /home/web/releases/3 && npm ci --no-audit --no-fund\" exceeded the timeout of 300 seconds.\n\n[ERROR] Deploy failed (exit 1)";
    $check($jp->deployTimeout($node)['build'] === true && $jp->extractError($node) === 'The build (node:install) ran for 5 minutes, the most it is given, and was stopped.', 'a Node app: its install and its build are build steps too');
    $migrate = "➤ Executing task artisan:migrate\n[localhost] Deployer\\Exception\\TimeoutException in Client.php on line 99:\n\n  The command \"/usr/bin/php8.5 artisan migrate --force\" exceeded the timeout of 300 seconds.\n\n[ERROR] Deploy failed (exit 1)";
    $check($jp->extractError($migrate) === 'The step artisan:migrate ran for 5 minutes, the most it is given, and was stopped.' && str_contains($jp->deployHints($migrate)[0], 'Every step of a deploy has 5 minutes'), 'another step, announced the verbose way: named as a step');
    $check($jp->extractError("The command \"x\" exceeded the timeout of 45 seconds.") === 'A step of the deploy ran for 45 seconds, the most it is given, and was stopped.', 'no step in the output: said without one');
    $api = "task deploy:vendors\ntask cipi:node_build\n\n[cipi-api] Stopped after 120 minutes: the time the API gives a job of this kind. The command had not ended. On the server, `cipi deploy shop --status` says whether a deploy is still running, and the deploy log how far it went.";
    $check(str_starts_with($jp->extractError($api), 'Stopped after 120 minutes: the time the API gives a job of this kind.') && count($jp->deployHints($api)) === 2 && str_contains($jp->deployHints($api)[0], '--status') && str_contains($jp->deployHints($api)[1], 'Unlock deploy'),
        'a deploy job the API closed after its two hours: its line is the error, the hints say where to look');
    $key = "task deploy:update_code\n[ERROR] Permission denied (publickey).";
    $check($jp->deployTimeout($key) === null && $jp->extractError($key) === 'Permission denied (publickey).' && $jp->deployHints($key) === $generic && count($generic) === 5, 'every other failed deploy: the error and the hints it had');
    $trait = (string) file_get_contents($root.'/src/Livewire/Concerns/ManagesAsyncJobs.php');
    $check(str_contains($trait, "\$parser->deployHints(\$rawOutput ?? '')"), 'the overlay asks for the hints of this output');

    echo "--- the two clocks of a deploy in the Deploy pipeline card (Laravel and Node apps)\n";
    $ad = (string) file_get_contents($root.'/src/Livewire/AppDetail.php');
    $adv = (string) file_get_contents($root.'/resources/views/livewire/app-detail.blade.php');
    $check(str_contains($ad, "'dcBuildTimeout' => ['required', 'integer', 'min:60', 'max:3600']") && str_contains($ad, "'dcStepTimeout' => ['required', 'integer', 'min:60', 'max:1800']"), 'the bounds of the server: 60-3600 seconds for the build, 60-1800 for every other step');
    $check(str_contains($ad, "\$this->dcTimeouts = is_numeric(\$data['build_timeout'] ?? null) && is_numeric(\$data['step_timeout'] ?? null);") && (bool) preg_match('/#\[Locked\]\s+public bool \$dcTimeouts = false;/', $ad)
        && str_contains($ad, "if (\$this->dcTimeouts) {\n            \$payload += ['build_timeout' => (int) \$this->dcBuildTimeout, 'step_timeout' => (int) \$this->dcStepTimeout];"),
        'they are shown and sent back only to a server that reported them (the browser cannot say it did)');
    $check(str_contains($ad, "\$payload = ['keep_releases' => (int) \$this->dcKeepReleases, 'predeploy_snapshot' => \$this->dcPredeploySnapshot];\n        if (! \$node) {")
        && substr_count($ad, "\$this->isLaravelApp(\$this->app ?? []) || \$this->isNodeApp(\$this->app ?? [])") === 1, 'a Node app loads its deploy options and saves the four it takes — no artisan hook, no asset build');
    $check(str_contains($adv, '@if($isLaravel || $isNode)') && str_contains($adv, 'data-deploy-pipeline-form="node"') && substr_count($adv, 'data-dc-build-timeout') === 2 && substr_count($adv, 'data-dc-step-timeout') === 2
        && substr_count($adv, '@if($dcTimeouts)') === 2, 'the card: one form for a Laravel app, one for a Node app, the two numbers in each');
    $check(substr_count($adv, '@if(') + substr_count($adv, '@if (') === substr_count($adv, '@endif'), 'app-detail: every @if has its @endif');
    $demoApi = (string) file_get_contents($root.'/dev/demo/api/index.php');
    $check(str_contains($demoApi, "'build_timeout' => 1800, 'step_timeout' => 300") && str_contains($demoApi, "['app' => \$name, 'runtime' => 'node'] + \$a['deploy_config']"), 'the demo API answers with the clocks, and for a Node app');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
