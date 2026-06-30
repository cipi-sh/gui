# Cipi CLI integration

This package ships ready-to-copy stubs under `stubs/cipi-cli/` for the [cipi-sh/cipi](https://github.com/cipi-sh/cipi) repository.

## Files to add in `cipi-sh/cipi`

| Action | Path |
|--------|------|
| Copy | `stubs/cipi-cli/lib/gui.sh` → `lib/gui.sh` |
| Copy package | Vendor this repo as `cipi-install/cipi-gui/` (same as `cipi-api`) |
| Edit | Main `cipi` router |
| Edit | `setup.sh` installer |
| Edit | `lib/common.sh` (optional — `ensure_cipi_gui_permissions` is in `gui.sh`) |

## 1. Router (`cipi`)

Add help text and command dispatch:

```bash
# In help / usage section:
#   gui <domain>     Configure web control panel (requires cipi api on managed servers)

# In case statement:
gui) require_root; source "${CIPI_LIB}/gui.sh"; gui_command "$@" ;;
```

## 2. Installer (`setup.sh`)

Bundle the GUI package during server install:

```bash
# Cipi GUI package (for cipi gui)
if [ -d "cipi-install/cipi-gui" ]; then
  rm -rf /opt/cipi/cipi-gui 2>/dev/null
  cp -a cipi-install/cipi-gui /opt/cipi/cipi-gui
fi
```

Sync on self-update the same way as `cipi-api`.

## 3. Server paths

| Resource | Path |
|----------|------|
| Laravel host | `/opt/cipi/gui` |
| Package source | `/opt/cipi/cipi-gui` |
| Config (vault) | `/etc/cipi/gui.json` |
| Nginx vhost | `/etc/nginx/sites-available/cipi-gui` |
| FPM pool | `/etc/php/8.5/fpm/pool.d/cipi-gui.conf` |
| Cron | `/etc/cron.d/cipi-gui` |

## 4. Usage on server

```bash
# Requires cipi api on servers you want to manage
cipi gui gui.example.com
cipi gui ssl
cipi gui status
cipi gui update
cipi gui upgrade
cipi gui fix-permissions
```

## 5. Differences from `cipi api`

| | API | GUI |
|---|-----|-----|
| Auth | Sanctum tokens | Session + optional 2FA |
| Queue worker | Required (`cipi-queue.service`) | Not required (polls remote API jobs) |
| `SESSION_DRIVER` | `array` | `file` |
| Seed command | `cipi:seed-user` | `cipi:seed-gui-user` |
| Depends on | Cipi CLI sudoers | **Remote** `cipi api` on managed servers |

## 6. PR checklist for `cipi-sh/cipi`

- [ ] Add `lib/gui.sh`
- [ ] Wire `gui` in main `cipi` script
- [ ] Bundle `cipi-gui` in `setup.sh` + `self-update.sh`
- [ ] Document in Cipi docs / changelog
- [ ] Optional migration script for existing installs

## 7. Theme not updating (still blue)?

**Diagnosis:** view page source. If you see `--color-brand-600: #2563eb` and `bg-brand-600` in the sidebar, the server is still on the **old package** (First Commit). The new theme shows:

```html
<!-- cipi-gui v2.0.0 theme:d2c4bac3... -->
```

and `--bg: #0a0a0a` (not `--color-brand-600`).

On the GUI host (`/opt/cipi/gui`):

```bash
# 1. Update package SOURCE (path repo used by Cipi)
cd /opt/cipi/cipi-gui
git pull origin main

# 2. Reinstall into Laravel host + clear caches
cd /opt/cipi/gui
composer update cipi/gui --no-interaction
php artisan cipi:gui-refresh-theme
sudo systemctl reload php8.5-fpm
```

Or with Cipi CLI (after syncing `lib/gui.sh`):

```bash
cipi gui update
```

Verify: hard refresh (Cmd+Shift+R) → view source must contain `cipi-gui v2.0.0`.

**Do not** run `vendor:publish --tag=cipi-gui-views`.
