# How `cipi gui` installs the panel

The panel is installed and maintained by the Cipi CLI. The implementation lives in [`lib/gui.sh`](https://github.com/cipi-sh/cipi/blob/master/lib/gui.sh) of [cipi-sh/cipi](https://github.com/cipi-sh/cipi) — that file is the source of truth; this page summarises what it does so changes to this package stay compatible with it.

## Commands

```bash
cipi gui <domain>                 # install: Laravel host app + cipi/gui, FPM pool, vhost, scheduler, first admin
cipi gui ssl                      # Let's Encrypt certificate for the panel domain
cipi gui update                   # composer update cipi/gui, migrations, theme refresh
cipi gui upgrade                  # rebuild the host app from scratch (keeps .env and the SQLite database)
cipi gui status                   # domain, host app path, Laravel and cipi/gui versions
cipi gui fix-permissions          # open_basedir and file permissions (alias: repair)
cipi gui refresh-theme            # clear compiled views so the theme reloads
cipi gui reset-user [--email= --password= --name=]   # recover an admin, clears 2FA (alias: reset-password)
cipi gui remove [--force]         # uninstall (alias: uninstall) — managed servers are not touched
```

## Layout on the server

| Resource | Path |
|----------|------|
| Laravel host app | `/opt/cipi/gui` |
| Package checkout (Composer path repository) | `/opt/cipi/cipi-gui` |
| Configuration | `/etc/cipi/gui.json` |
| Nginx vhost | `/etc/nginx/sites-available/cipi-gui` |
| PHP-FPM pool | `cipi-gui` pool of the system PHP, `open_basedir` limited to `/opt/cipi/gui/` and `/opt/cipi/cipi-gui/` |
| Scheduler | `/etc/cron.d/cipi-gui` |
| Database | SQLite in `/opt/cipi/gui/database/database.sqlite` (WAL) |

## What this means for the package

- **`cipi gui update` reinstalls the package without updating the lock file** (`composer reinstall cipi/gui`, falling back to `composer update cipi/gui`). Existing panels therefore keep the Livewire version they were installed with: the views and components must keep working on Livewire 3.6+ *and* 4. CI runs both.
- **No asset build step.** The theme is `resources/css/cipi-gui.css`, inlined by `partials/styles.blade.php`; JavaScript comes from Livewire's bundled Alpine. Fonts are loaded from Bunny Fonts.
- **Published views are deleted on boot** (`CipiGuiServiceProvider::purgePublishedViews`) so an update always ships the new UI; `cipi gui refresh-theme` clears compiled views.
- **Migrations must be idempotent** — `cipi gui update` runs `php artisan migrate --force` on every update.
- **Admin users**: the installer and `reset-user` write the user directly (`lib/gui-reset-admin.php`); `php artisan cipi:seed-gui-user` is the equivalent for manual installs and hashes the password itself.
- **The panel only talks to servers through their API** (`cipi api` on each managed server, Bearer token, optional IP whitelist). It never needs SSH or root on the managed servers.
