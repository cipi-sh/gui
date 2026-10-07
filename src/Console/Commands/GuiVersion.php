<?php

namespace CipiGui\Console\Commands;

use CipiGui\Models\CipiServer;
use CipiGui\Support\Theme;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class GuiVersion extends Command
{
    protected $signature = 'cipi:gui-version';

    protected $description = 'Show the installed cipi/gui version and a few sanity checks';

    public function handle(): int
    {
        $this->line('cipi/gui '.Theme::VERSION);
        $this->line('  Package path:  '.Theme::packageRoot());
        $this->line('  Laravel:       '.app()->version());
        $this->line('  Livewire:      '.(InstalledVersions::isInstalled('livewire/livewire') ? InstalledVersions::getPrettyVersion('livewire/livewire') : 'missing'));
        $this->line('  Theme:         '.Theme::fingerprint());

        $migrated = Schema::hasTable('cipi_servers') && Schema::hasColumn('cipi_servers', 'ip');
        $this->line('  Migrations:    '.($migrated ? 'OK' : 'MISSING — run php artisan migrate'));

        if ($migrated) {
            $active = CipiServer::where('is_active', true)->count();
            $this->line('  Servers:       '.$active.' active / '.CipiServer::count().' total');
        }

        $this->line('  2FA columns:   '.(Schema::hasColumn('users', 'two_factor_secret') ? 'OK' : 'MISSING'));

        return self::SUCCESS;
    }
}
