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
    $check((bool) preg_match('/function disableTwoFactor[^{]+\{.*?if \(Panel::enforceTwoFactor\(\)\)/s', $settings), 'Settings: required 2FA is not turned off');
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

    $check((bool) preg_match("/VERSION = '4\\.7\\.\\d+'/", $theme) && str_contains($theme, "API = '1.45'") && str_contains((string) file_get_contents($root.'/CHANGELOG.md'), '## [4.7.0]'), 'version 4.7.0 for API 1.45');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
