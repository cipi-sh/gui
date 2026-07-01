<?php

namespace CipiGui\Console\Commands;

use CipiGui\Support\Theme;
use Illuminate\Console\Command;

class GuiVersion extends Command
{
    protected $signature = 'cipi:gui-version';

    protected $description = 'Show installed cipi/gui package version and sanity checks';

    public function handle(): int
    {
        $root = Theme::packageRoot();
        $appDetail = $root.'/src/Livewire/AppDetail.php';
        $seedUser = $root.'/src/Console/Commands/SeedGuiUser.php';

        $this->line('cipi/gui '.Theme::VERSION);
        $this->line('  Package path: '.$root);

        if (is_readable($appDetail)) {
            $src = (string) file_get_contents($appDetail);
            $ok = str_contains($src, 'function mount(string $name)')
                || str_contains($src, 'request()->route(\'name\')');
            $this->line('  AppDetail route fix: '.($ok ? 'OK' : 'MISSING — git pull /opt/cipi/cipi-gui'));
        }

        if (is_readable($seedUser)) {
            $ok = str_contains((string) file_get_contents($seedUser), '{--reset');
            $this->line('  seed-gui-user --reset: '.($ok ? 'OK' : 'MISSING'));
        }

        return self::SUCCESS;
    }
}
