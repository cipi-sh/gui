# Local development

`dev/setup.sh` creates a Laravel host app in `dev/host` (ignored by Git) that loads `cipi/gui` from this working tree through a Composer path repository, so changes to the package show up on the next request.

## Requirements

- PHP 8.3+ and Composer 2
- SQLite (the host uses Laravel's default SQLite database)

## Setup

```bash
./dev/setup.sh
```

## Run against the demo API

```bash
./dev/demo.sh            # demo API on :8787, panel on http://127.0.0.1:8000
./dev/demo.sh --reset    # forget every change made in the demo
```

Sign in with `admin@cipi.local` / `admin` (an admin). To try a user with limited access, invite one from Team: the link opens a sign-up form.

`dev/demo/api/index.php` answers the paths, payloads and status codes of `cipi api` 1.36 — it is what the panel's screenshots are taken from:

- **Three servers** in one process: `production` (fra1), `clients` (nyc1) and `staging`. The profile comes from the first label of the host (`fra1.cipi-demo.test`) or a path prefix (`http://127.0.0.1:8787/fra1`).
- **Realistic data** (`fixtures.php`): Laravel apps on FPM and Octane, Node SPA/static/SSR apps, a custom PHP site, redirects and proxies, `.env`, deploy ledgers, healthchecks, monitor checks, Meilisearch, Zero Trust, IP whitelist, disk usage per app and per database (`cipi disk`), and a `cipi.yml` plan per app (pending changes, in sync, blocked, invalid, a change that fails on apply, no file). Every app has an environment label (`env`, API 1.36): the staging server's apps are `staging` — `shopstg` ships a `cipi-staging.yml`, which the plan names — and the others are `production`. Domains use `example.com/org/net`, IPs the RFC 5737 documentation ranges.
- **Deploy logs** follow each app's ledger: one block per audit record, closed with the banner Cipi 5.6 writes (`===== deploy OK  app=x release=N duration=54s exit=0`) that the panel reads deploy times from. A failed deploy's banner names the release that stayed live, as on a real server.
- **Async jobs** move from `pending` to `running` to `completed` in about three seconds and return CLI-like output plus the parsed `result` (credentials on app create, backup file on database backup, …). App create, and app edit with a new repository or branch, also report `repository_access` like Cipi 5.6: a repository whose path contains `private` is refused (GitHub, GitLab and Bitbucket hosts get the `cipi git <forge>-token <token> && cipi git refresh <app>` hint), a branch other than `main`, `master`, `develop`, `staging` or `production` is missing, anything else is reachable. App create accepts `node_build` for Laravel apps with the CLI's rule and stores it as the app's asset build, and `env` (app edit too) with the API's rule — 422 when it does not match. A cipi.yml apply (`app-yml-apply`) returns the `outcome` of Cipi 5.6.4 (`applied`, `unchanged`, `partial`, `blocked`, `invalid`, `no-file`): applied changes leave the plan, and a change marked to fail makes the job partial once, then goes through on the next apply.
- **State** is stored in `dev/demo/storage/state.json`; delete it, or use `--reset`, to start over. A state saved by an older demo has no `disk`, `yml` or `env` data: reset once after updating.

It never runs `cipi` and never touches the machine — it only exists for development, demos and documentation.

### Nicer hostnames with Laravel Herd

```bash
(cd dev/demo/api && herd link cipi-demo && herd secure cipi-demo)
CIPI_DEMO_URL='https://{profile}.cipi-demo.test' ./dev/demo.sh
```

`herd unlink cipi-demo` removes the site again.

### Re-seed only

```bash
php dev/demo/seed.php 'https://{profile}.cipi-demo.test'
```

Registers the three demo servers (ids 1–3) and resets the admin user.

## Screenshots

The images in `docs/screenshots` (and the copies on cipi.sh) come from the demo, taken by `dev/screenshots.mjs` with the Google Chrome installed on the machine, headless, over the DevTools protocol — no npm dependencies. With the demo running (`./dev/demo.sh --reset`, ideally with the Herd hostnames so the server hosts read `fra1.cipi-demo.test`):

```bash
node dev/screenshots.mjs              # every screenshot, into docs/screenshots
node dev/screenshots.mjs apps apps-light app-deploy   # only these
```

It signs in as the demo admin, makes `production` the current server, and renders each page at 1440×900 (2×), full height, dark and light, plus three phone shots at 390×844 (3×). The last shots create an app and deploy one, so the demo state changes: `./dev/demo.sh --reset` afterwards. `CIPI_SHOTS_CHROME` points at another Chrome binary, `CIPI_SHOTS_URL` at a panel other than `http://127.0.0.1:8000`. Copy the files you want on the website to `website/img/gui` by hand.

## Against a real server

Run `./dev/setup.sh`, then `cd dev/host && php artisan serve`, sign in, and add the server under **Connections** with a token from `cipi api token create` (the page shows the full command).

## Tests

```bash
composer install
composer test
```

The suite uses Orchestra Testbench with an in-memory SQLite database and `Http::fake()`; it does not need `dev/host`.

## Reset the host

```bash
rm -rf dev/host
./dev/setup.sh
```
