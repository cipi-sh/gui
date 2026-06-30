<?php

namespace CipiGui\Console\Commands;

use CipiGui\Support\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class RefreshTheme extends Command
{
    protected $signature = 'cipi:gui-refresh-theme';

    protected $description = 'Clear view cache and remove stale published cipi-gui views so the theme reloads from the package';

    public function handle(): int
    {
        $published = resource_path('views/vendor/cipi-gui');

        if (is_dir($published)) {
            File::deleteDirectory($published);
            $this->info('Removed published views: '.$published);
        }

        Cache::forget('cipi-gui.theme-css-hash');
        Artisan::call('view:clear');
        Artisan::call('optimize:clear');

        $this->info('Theme version: '.Theme::VERSION);
        $this->info('Theme fingerprint: '.Theme::fingerprint());
        $this->info('CSS path: '.Theme::cssPath());
        $this->info('Done. Hard-refresh the browser (Cmd+Shift+R).');

        return self::SUCCESS;
    }
}
