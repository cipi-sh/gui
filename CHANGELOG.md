# Changelog

## [3.2.0] — 2026-10-15

Aligned with Cipi API 1.34 / Cipi 5.6.0: build command at creation and the repository access check. Both need **cipi/api ≥ 1.34.0** and **Cipi ≥ 5.6.0** on the server; older servers ignore the field and send no check, and the panel shows nothing extra.

### Added

- **Build command when creating a Laravel app** (FPM and Octane): optional, placeholder `npm ci && npm run build`, sent as `node_build` on `POST /api/apps` only when filled in. Cipi runs it inside every deploy, after `composer install` and before the release goes live — the same setting as the asset build under Deploy → Deploy pipeline. Checked in the panel with the CLI's own rule before the request (`CipiGui\Support\BuildCommand`, mirroring `nodeBuildError()` in cipi/api): starts with npm, npx, yarn, pnpm, bun or node, `&&` and `;` allowed, no pipes, redirects, backticks or `$( )`, 200 characters at most. Not shown for custom and Node apps (Node apps keep their own build field).
- **Repository access in the job overlay** — after app create, and after app edit when the repository or branch changed, the `repository_access` of the job result: reachable (with the branch), reachable without the branch, or refused, with the warning that the first deploy fails until it is fixed, the line Git printed and Cipi's fix (e.g. `cipi git github-token <token> && cipi git refresh myapp`) with a copy button — the API cannot save Git tokens, so the fix runs on the server over SSH. Absent on older servers and on apps without a repository.
- The `build` of the app-create result (the Laravel asset build or the Node build command) is shown in the overlay.
- Demo API 1.34: `node_build` on app create (same validation, 422 on custom and Node apps), `build` and `repository_access` in app-create results and in app-edit results when the repository or branch changes (a repository path containing `private` is refused, an unusual branch name is missing). New demo apps no longer get an asset build unless one is given.

### Changed

- The asset build under **Deploy → Deploy pipeline** is checked with the same rule before saving (it was only limited to 500 characters, and the CLI refused the rest on save).

## [3.1.0] — 2026-10-09

Aligned with Cipi API 1.33: disk usage.

### Added

- **Server → Disk** — who is filling the disk, the figures of `cipi disk` and `cipi disk db`: the filesystem `/home` is on, every app largest first with files, database, total, share of the disk and its soft limit (apps over the limit or past 90% of it are flagged, like the monitor's `app_disk` check), the all-apps and everything-else totals, and every database per engine — MariaDB and PostgreSQL in MB, Valkey databases with keys and memory, Meilisearch indexes with documents. Reads `GET /api/disk` and `GET /api/disk/dbs` (API 1.33+; Cipi 5.5.2+ for the API sudoers) and shows "not available" on older servers. The server measures sizes when the tab opens, so these two requests get their own timeout (`http_disk_timeout`, `CIPI_GUI_HTTP_DISK_TIMEOUT`, default 180 s).
- The Overview resources card and the Databases tab link to the Disk tab instead of pointing at the host command.
- `disk-view` in the token command on Connections (`token_abilities`).
- Demo API 1.33: `/api/disk` and `/api/disk/dbs` with fixtures for the three servers (`./dev/demo.sh --reset` once to pick them up in an existing demo state).

### Changed

- `CipiApiClient` requests accept a per-call timeout.

## [3.0.0] — 2026-10-07

A redesign of the whole panel around the Cipi "paper" design system, aligned with Cipi API 1.31.

### Added

- **Global server switcher** in the header: pick the current server from any page; app links carry `?server=` so they can be shared and bookmarked.
- **Dashboard** loads after the first paint and queries every server concurrently (`Http::pool`), with a fleet summary (servers online, apps, services, Cipi versions) and per-server CPU / memory / disk thresholds.
- **Connections**: edit a server, rotate its token, disable or enable it, test it, and a ready-to-copy `cipi api token create` command with the 44 abilities of API 1.31.
- **Apps**: type filter, search, deploy and suspend from the list, a phone layout, and a create dialog with a type picker and the same Node framework presets as `cipi app create --node`.
- **One-time credentials** after app create, database create, password rotation and webhook rotation are listed with reveal and copy buttons instead of being buried in CLI output.
- **App detail**: header with status, runtime and quick actions; tabs bound to the URL (`?tab=deploy`); Node runtime card; typed confirmation before deleting.
- **Deploy audit** shows sequence, relative time, event, release and commit, origin and trigger, operator, IP and claim notes, with a period selector.
- **Environment editor**: masked secrets with reveal, key filter, highlighted changes, unsaved-change counter and discard.
- **Databases**: backup and restore (API endpoints that were not exposed before), engine summary and filter, search.
- **Server** page: new Overview (system, resources, attention list, services, PHP-FPM) and **Health** tab (`GET /api/health`); each section reports "not available" on its own instead of hiding the whole page.
- **Settings**: profile, password change (same policy as the `cipi gui` installer, other sessions signed out), redesigned 2FA setup, version information.
- Demo environment: `dev/demo.sh` and `dev/demo/api`, a stateful stand-in for `cipi api` 1.31 used for development and documentation screenshots.
- Test suite (Orchestra Testbench + PHPUnit) and a CI matrix for PHP 8.3–8.5, Laravel 12/13 and Livewire 3/4.

### Changed

- New theme: lime "signal" accent, Space Grotesk headings, complete utility layer (several layout classes used by the views were missing before, so grids collapsed to one column and the menu button showed on desktop), light and dark themes, reduced-motion support.
- Reusable Blade components (`<x-cipi::icon>`, `page-header`, `alert`, `modal`, `empty`, `copy`, `secret`).
- Job overlay with elapsed time, job type and colour-coded output; terminals copy as text or Markdown.
- Livewire `^3.6 || ^4.0`, `pragmarx/google2fa` `^8.0 || ^9.0`, Laravel 12 or 13.
- The package no longer depends on host classes (`App\Models\User`, `App\Http\Controllers\Controller`); the user model comes from `auth.providers.users.model`.
- `cipi:seed-gui-user` hashes passwords explicitly; `cipi:gui-version` prints package, Laravel and Livewire versions and migration checks.

### Fixed

- Custom PHP apps were listed as Laravel: `GET /api/apps` does not return `custom`, so the type is now derived from `engine` and `node` (reliable since Cipi 4.8).
- 404/409/422 answers no longer mark a server as broken; only connection errors, 401, IP-whitelist 403 and 5xx do.
- Monitor checks in `warn` state were shown as failures.
- The API client no longer writes to the database on every request.
- `dev/setup.sh` could fail to seed the admin right after clearing caches.

### Removed

- The unused `JobMonitor` component and the per-page server selectors (replaced by the header switcher).
- `stubs/cipi-cli/` — `cipi gui` lives in [cipi-sh/cipi](https://github.com/cipi-sh/cipi/blob/master/lib/gui.sh); see `docs/CIPI_CLI.md`.

## [2.2.2] and earlier

See the Git history.
