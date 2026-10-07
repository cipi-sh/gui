# Changelog

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
