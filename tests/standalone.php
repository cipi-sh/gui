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

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
