# Cipi GUI

Laravel package that provides a web control panel for managing one or more [Cipi](https://cipi.sh) servers via the [Cipi REST API](https://github.com/cipi-sh/api).

## Requirements

- PHP 8.3+
- Laravel 12+
- **Cipi API** enabled on each managed server (`cipi api`)
- MySQL/SQLite (host Laravel app database for GUI users and server registry)

## Installation

This package is automatically installed and configured by `cipi gui`. No manual setup is needed when using the Cipi CLI.

For manual installation in a Laravel host app:

```bash
composer require cipi/gui
php artisan vendor:publish --tag=cipi-gui-config
php artisan migrate
php artisan cipi:seed-gui-user
```

## Features

- **Multi-server** — Register N Cipi servers with API tokens; switch between them from any page
- **Dashboard** — Live server status (CPU, memory, disk, services, app count) via `GET /api/status`
- **Apps** — Create, edit, deploy Laravel, Node (SPA/static/SSR), and custom apps; manage aliases, WWW/apex redirects, path redirects, prefix proxies, SSL (install + force HTTPS), basic auth, suspend/unsuspend, fix-permissions
- **Env & auth.json** — View/edit Laravel `.env` key/values; create, edit, and delete shared Composer `auth.json` (API 1.14+ / Cipi CLI ≥ 5.0.3)
- **Artisan & commands** — Run Artisan (presets + custom) and whitelisted app commands such as `composer` / `npm` with job overlay output
- **Deploy** — Deploy / rollback / unlock; structured `deploy.php` options; hash-chained deploy audit ledger (API 1.31+ / Cipi ≥ 5.4.0)
- **Search** — Meilisearch / Laravel Scout status and per-app enable/disable (API 1.31+ / Cipi ≥ 5.2.2)
- **Databases** — Multi-engine MariaDB/PostgreSQL: list, create, regenerate passwords (API 1.12+ / Cipi 4.8+)
- **Laravel Octane** — optional FrankenPHP runtime at app create (API 1.13+ / Cipi 5.0+); list/detail show FPM vs Octane
- **Server insights** — PHP/Node runtimes, optional packages, system monitor, Cloudflare Zero Trust (read-only), API IP whitelist
- **Async jobs** — Interactive job overlay with spinner and terminal output while polling `GET /api/jobs/{id}`
- **Logs** — Terminal-style log viewer with type filter, pagination, and auto-refresh
- **Security** — Password login with optional TOTP two-factor authentication (Google Authenticator compatible)
- **Production-ready** — Encrypted token storage, connection error handling, configurable timeouts and poll intervals

## Authentication

### Admin user

```bash
php artisan cipi:seed-gui-user
php artisan cipi:seed-gui-user --email=admin@example.com --password='your-secure-password'
```

### Two-factor authentication

Enable 2FA from **Settings** after first login. When enabled, a TOTP code is required on each new session.

## Connecting servers

1. Ensure **Cipi API** is installed on the target server: `cipi api`
2. Create an API token with the required abilities:

```bash
cipi api token create --name=gui --abilities=apps-view,apps-create,apps-edit,apps-delete,apps-suspend,apps-basicauth,apps-env,apps-auth,apps-artisan,apps-run,apps-deploy-config,aliases-view,aliases-create,aliases-delete,www-manage,redirects-view,redirects-manage,proxies-view,proxies-manage,node-view,node-manage,search-view,search-manage,deploy-manage,ssl-manage,dbs-view,dbs-create,dbs-manage,php-view,php-manage,ssh-view,ssh-manage,services-view,services-manage,smtp-view,smtp-manage,health-view,health-manage,packages-view,monitor-view,zt-view,ip-whitelist-view,ip-whitelist-manage,status-view
```

3. In the GUI, go to **Servers → Add Server** and enter:
   - **Name** — A short identifier (e.g. `production`)
   - **URL** — Base URL of the Cipi API host (e.g. `https://vps.example.com`)
   - **Token** — The bearer token from step 2

Tokens are encrypted at rest using Laravel's `Crypt` facade.

## Configuration

Publish and edit `config/cipi-gui.php`:

| Key | Description | Default |
|-----|-------------|---------|
| `route_prefix` | URL prefix for all GUI routes | `''` (root) |
| `job_poll_interval_ms` | Job status poll interval | `1500` |
| `job_poll_max_attempts` | Max poll attempts before timeout | `120` |
| `http_timeout` | API request timeout (seconds) | `30` |

Environment variables: `CIPI_GUI_PREFIX`, `CIPI_GUI_JOB_POLL_MS`, `CIPI_GUI_HTTP_TIMEOUT`, `CIPI_GUI_ADMIN_EMAIL`.

## API coverage

The GUI consumes the full [Cipi API OpenAPI spec](https://vps.deploying.it/docs):

| Area | Endpoints |
|------|-----------|
| Server | `GET /api/status`; Manage page: PHP / DB engines / Node runtimes / SSH / services / SMTP / search / packages / monitor / Zero Trust / IP whitelist (API 1.15–1.31, Cipi ≥ 5.0.6; Node/redirects need ≥ 5.4.1) |
| Apps | CRUD, suspend/unsuspend, fix-permissions, basic auth, logs; create accepts `engine`, `octane`, and Node (`node`, `framework`, `node_version`, `build`, `start`, `output`, `health_path`); list/show expose `node` / `node_mode` / `node_version` / `redirect` / `redirects` / `proxies`; wildcard primary domains (`*.example.com`); webhook recreate + secret rotate; per-app HTTP healthcheck |
| Env | `GET`/`PUT /api/apps/{name}/env` (`apps-env`, API 1.14+) |
| Auth.json | `GET`/`POST`/`PUT`/`DELETE /api/apps/{name}/auth` — Composer shared credentials, not HTTP Basic Auth (`apps-auth`) |
| Artisan | `POST /api/apps/{name}/artisan` — async job (`apps-artisan`) |
| App run | `GET /api/run-commands`, `POST /api/apps/{name}/run` — whitelisted composer/npm/… (`apps-run`) |
| Aliases | List, add, remove |
| WWW | Status, add counterpart, force-to-root / force-from-root, clear (`www-manage`) |
| Routing | Whole-app + path redirects (`/redirect*`); prefix reverse proxies (`/proxies`) — API 1.31+ / Cipi ≥ 5.3.1 |
| Node | `GET /api/node`, `GET /api/apps/{name}/node`, `POST /api/apps/{name}/node/restart` (SSR blue/green) |
| Search | `GET /api/search`; `POST /api/apps/{name}/search/enable` and `.../disable` |
| Deploy | Deploy, rollback, unlock; `GET`/`PUT /api/apps/{name}/deploy-config`; `GET /api/apps/{name}/deploy/audit` |
| SSL | Install Let's Encrypt, force HTTPS redirect |
| Databases | Engines + list (sync); create/backup/restore/password with optional `engine` (async). Database deletion is host-CLI only (API 1.19+) |
| Jobs | Poll status and CLI output |

## Architecture

Same integration model as [`cipi/api`](https://github.com/cipi-sh/api): a Laravel **library** package bootstrapped by `CipiGuiServiceProvider` into a host runtime provisioned by `cipi gui`.

See [`docs/CIPI_CLI.md`](docs/CIPI_CLI.md) for integrating `cipi gui` into the Cipi server CLI, and [`dev/README.md`](dev/README.md) for local development.

```
cipi/gui/
├── config/cipi-gui.php
├── database/migrations/     # cipi_servers, users 2FA columns
├── resources/views/         # Blade + Livewire UI
├── routes/web.php
└── src/
    ├── CipiGuiServiceProvider.php
    ├── Livewire/            # Dashboard, Apps, Databases, …
    ├── Services/            # CipiApiClient, JobPoller, TwoFactorService
    └── Models/CipiServer.php
```

## License

MIT
