# Changelog

## [4.1.1] — 2026-10-29

From the field: in the IP tab you press Add and nothing moves until the server answers — and so on most buttons of the panel. A handful had a `wire:loading` state of their own; the other hundred and fifty went silent for the length of the round-trip, and a second click queued a second call.

### Added

- **A time zone per account.** Settings → Profile has a time zone next to name and email (every IANA zone, grouped by region, with a *Use my browser's* shortcut), UTC until one is chosen. Every absolute time the panel shows follows it: the deploy in progress ("Started 18:32:49"), the deploy audit and the deploy log modal, the dashboard's "Updated" clock. Each server reports its timestamps with its own offset and the panel kept showing them as they came — two servers in two zones, two clocks on one page. Now one clock, the account's, named where the times are ("Times in Europe/Rome (CET, UTC+1)"). Relative times ("3 minutes ago") are unchanged. Migration: `users.timezone`, nullable; `cipi gui update` runs it.

### Changed

- **Every button that calls the server shows that it is working.** The element that fired a `wire:click` or `wire:submit` — the clicked button, or the submit button of its form — ignores further clicks as soon as the request leaves, and when the answer takes longer than 150 ms its label gives way to a spinner that keeps the button's size and colours until the response arrives. One Livewire action interceptor does it for the whole panel (`partials/busy-script`, on Livewire 4's `interceptAction`); buttons that bring their own `wire:loading` keep it, polls and model updates are left alone, and a row or card that carries a `wire:click` is not a button and is not touched. A click on a busy button is cancelled before it becomes a request. Nothing changes on Livewire 3.
- `node dev/busy-check.mjs` drives the demo panel in headless Chrome with the server's answers held back and checks all of it: the spinner after 150 ms, the cancelled second click, the cleared state after the answer, a `wire:confirm` button, a tab switch, a button with its own `wire:loading`, and polls that never mark anything busy.

### Ecosystem

- Cipi 5.8.5: `cipi api ip-whitelist add|remove|set|allow-all` write the list atomically and explain a read-only `/etc/cipi` instead of the `mv: inter-device move failed … Read-only file system` the IP tab showed; they also stop looking the server's public IP up (a `curl` of up to 5 seconds) when the panel calls them, so the tab answers faster.

## [4.1.0] — 2026-10-29

From the field: a push to the repository deploys through the webhook, and the panel showed nothing of it — no sign that a deploy was running, nothing when it ended, the ledger updated only when the tab was next opened. Someone looking at the app while a colleague's commit went live had no way to know. Cipi 5.8.4 adds `cipi deploy <app> --status`, which says whether a deploy is running right now — whoever started it — and cipi/api 1.37.0 exposes it; the panel now follows every deploy with it. Three smaller things from the same report: the app page had no link to its own server, the audit table had no way to the log of a deploy, and a post-deploy healthcheck took a user by surprise.

### Added

- **A deploy in progress is shown, whoever started it.** App detail polls `GET /api/apps/{name}/deploy/status` (cipi/api 1.37+, Cipi 5.8.4+; every 10 seconds, every 3 while something is on) and shows a banner under the header on every tab: *Deploying — started by a Git push* (or the command line, the panel, a rollback, cipi/agent), the branch, how long it has been running, which phase — Deployer building the release, with the release that stays live until then, or the post-deploy steps, cipi.yml and the healthcheck once the new release is live — and the last lines of the deploy log, live, in a collapsible terminal. A push the webhook accepted and the per-minute cron has not started yet shows as *Deploy queued*. While a deploy runs the Deploy buttons are off (Deployer's lock would refuse them anyway) and the Deploy tab's audit table opens with an *in progress* row. **When the run ends, a toast says how it went** — *Deploy by a Git push finished in 1m35s: release 63 is live*, or failed with the release still live — and the page reloads exactly as after one of its own jobs: the app, the ledger, the tab in view, the Logs tab. A deploy the panel itself started is reported once, by its job. A run that never wrote its end banner — a reboot, a killed process — is a warning with the Unlock action. An older API (no route) or an older Cipi (503) turns the feature off quietly: nothing is shown, nothing is polled.
- **The log of each deploy, from the audit table.** Every row has a Log action that opens that deploy's lines of the deploy log in a modal — from its start banner to the post-deploy steps, cipi.yml apply and healthcheck verdict that followed its end banner. The panel reads the last 1000 lines of the log, and the 1000 before them when the deploy is not in the first page; an older one says so and gives the `cipi deploy <app> --log=5000` to run on the server. `CipiGui\Support\DeployLog::sections()` delimits the deploys a log holds, `matchIndexes()` joins them to the ledger as the Took column already did, `slice()` cuts one out.
- **A link to the server** from the app page: next to the domain in the header, and *Server: <name>* in the Actions menu, both to the server's own page. The eyebrow still goes to the apps of that server.

### Changed

- **The HTTP healthcheck card says what enabling it means**: probed every 5 minutes *and right after every deploy*, with the deploy's verdict reporting it — a user who turned it on expecting a periodic check found a *Healthcheck FAILED* in the next deploy's log. Under the URL: Laravel answers `/up` only with the health route on (`bootstrap/app.php`: `health: '/up'`), otherwise the check gets a 404. The server page's healthcheck table says the same.
- The demo API (`dev/demo`) answers `GET /apps/{name}/deploy/status`: running while a demo deploy or rollback job of the app is in flight, idle with the last run otherwise.

## [4.0.2] — 2026-10-28

### Added

- **Single sign-on over OpenID Connect**, opt-in — "Sign in with Microsoft Entra ID" (or Google Workspace, Okta, Keycloak, Authentik: anything that speaks OIDC) on the login page, next to the password form or instead of it. The panel runs the authorization-code flow with PKCE against the provider's discovery document (`<issuer>/.well-known/openid-configuration`, cached for an hour), exchanges the code server-side and reads the identity from the **userinfo endpoint** — a server-to-server call over TLS — after checking the ID token's nonce, audience and issuer; no JWT library, no new dependency (`CipiGui\Services\OidcClient`, `SsoController`). Who gets in: the account already linked to the identity (`users.sso_subject`, new column), else the account with the same email, linked on first sign-in (an email linked to another identity is refused), else — only when the email's domain is in `CIPI_GUI_SSO_DOMAINS` — a new **user** with no access, for an admin to grant in Team; anyone else is told to ask for an invite link. An email the provider marks unverified is refused, and `preferred_username` is never taken as an email. **The identity provider is the authority for MFA**: after SSO the panel's own 2FA step is not asked for. Configured from the server, where the panel's `.env` is written: `cipi gui sso set --issuer=… --client-id=… --client-secret=…` (`CIPI_GUI_SSO_ISSUER`, `CIPI_GUI_SSO_CLIENT_ID`, `CIPI_GUI_SSO_CLIENT_SECRET`; optional `CIPI_GUI_SSO_LABEL`, `CIPI_GUI_SSO_DOMAINS`, `CIPI_GUI_SSO_SCOPES` — default `openid email profile`), `cipi gui sso only on|off` turns password sign-in off or back on for everyone (`CIPI_GUI_SSO_ONLY`; `cipi gui reset-user` on the server stays the break-glass), `cipi gui sso status`, `cipi gui sso disable` — Cipi 5.8.2+; on an older server the same keys go into the panel's `.env` by hand. The redirect URI to register at the provider is `{APP_URL}/auth/sso/callback` (routes `/auth/sso` and `/auth/sso/callback`, 404 while SSO is off). Settings → Panel shows the state read-only — provider, SSO-only, auto-provisioned domains, the redirect URI — and Team marks SSO-linked accounts. `cipi gui update` runs the migration.

### Fixed

- **The header's server switcher did not show a server just added** — nor one renamed, removed, enabled or disabled, nor a test that changed its state (the dot) — until the page was reloaded; in fleet view the same went for the sidebar's entry per server. Both were plain partials of the layout, outside every Livewire component. They are now components of their own (`cipi-gui.header`, `cipi-gui.nav`) that re-render on the `servers-changed` event the Connections page dispatches after every such change; the markup is the same.
- **The name in the header** did not follow Settings → Profile until a reload; the header re-renders on `profile-changed`.
- **A job closed with "Run in background" was forgotten**: polling stopped with the overlay, so the list, card or tab behind it never learned that the job had finished. The job is now polled on while hidden; when it finishes the page reloads its data and shows the usual toast, and the overlay comes back only when a toast cannot carry the outcome — the job failed, or it returned one-time credentials (a database password, a new app's SSH access).
- **A failed job left the page as it was** on Apps, Databases and the Server page, although a create, delete, install or restart may have changed the server partway. They now reload what they show after a failed job, as after a completed one.
- **App → Deploy kept an old ledger** when a deploy was started from the header while another tab was open: the tab had its audit cached and did not read it again. Every finished job — completed or failed, the ledger records failed deploys too — invalidates it: the Deploy tab reloads it when open and reads it again when next opened.
- **App → Logs** did not refresh after a deploy or a command run from the same page; the log viewer reloads when a job of the app finishes.
- **App → Deploy, the By column** was empty for every deploy the panel or a Git push started: it printed only the login user root could establish (an SSH session), and read the actor an app reports under keys that do not exist. It now shows that user when there is one, otherwise the actor the app's own code reported (the pusher of a webhook, an MCP client), marked as reported and with its source, and "—" only when nothing is known.
- **Connections → Create a token on the server** showed two lines (`$ cipi api`, then the token command) and the terminal's Copy button copied both with their `$ ` prompts. One line now, and Copy (and MD) give the command alone, ready to paste.

### Changed

- **Connections**: whenever a connection test succeeds, the panel makes sure the address it calls the API from (the `client_ip` the server reports) is on that server's API IP whitelist (`cipi api ip-whitelist`), adding it when missing, so restricting the list later never locks the panel out; the test result says when it did. An API that cannot answer (older release, token without `ip-whitelist-manage`) leaves the test result as it is. The form's "IP address" is now labelled "Server IP" with a hint: it is the address shown in the list, not an access rule.

## [4.0.1] — 2026-10-27

### Fixed

- **A deploy that published and whose command then failed** is told apart from a failed deploy. Cipi before 5.8.1 exited 1 after every successful deploy started from the API, so the job overlay showed **Failed** with `✓ Deploy completed` quoted as the error and the Git-key hints under it. When the output says the release went live (`Successfully deployed!`), the overlay now says so as a warning, quotes the post-deploy error or the exit code, lists what to check (the post-deploy steps of cipi.yml, the deploy log, `cipi self-update` on a server before 5.8.1), and the Deploy tab reloads its audit so the new release shows. The job keeps the status the API reports.

## [4.0.0] — 2026-10-27

Two things change for everyone who signs in, hence the major version: the panel has **roles** (admins and users with granted access) and a **fleet view** across every server, both set from Settings. Existing accounts become admins when the migration runs (`cipi gui update` runs it); nothing else is needed. Everything works with cipi/api 1.31+; deploy times need **Cipi 5.6.0+** on the server. The **environment** label needs **cipi/api 1.36.0+** (and Cipi 5.8.0+) to be stored and to pick `cipi-<env>.yml`; older servers ignore the field and the panel shows every app as production — nothing else changes for them.

### Added

- **Environment label** on apps (`env`): `production` by default, or `staging`, `development`, `testing` or any word of up to 32 lowercase letters, digits, `-` and `_` (`CipiGui\Support\AppEnv`, the API's own rule). Deploys read `cipi-<env>.yml` at the root of the release before `cipi.yml`.
- **Create app** — an Environment field with the usual labels suggested (a datalist; anything matching the rule is accepted), sent as `env` on `POST /api/apps`. A non-default label counts as a draft when the dialog is closed.
- **App → Overview → Configuration** — the same field, pre-filled; sent on `PUT /api/apps/{name}` only when it changed, like every other field. The Details card names the environment and the files a deploy reads.
- **Badges** — the environment of every app in the Apps list (fleet view and single server, desktop and phone) and in the app page header: production muted, anything else highlighted so a staging app stands out. The label is searchable in the list.
- **App → cipi.yml** — says which environment the plan is for and which file the server reads first (`cipi-staging.yml`, then `cipi.yml`), from the `env` the endpoint returns next to `file` (API 1.36+; the app's label on older servers). The help text mentions `deploy.branch`, the branch the app deploys from, among what the file can set — and what auto-apply hands to whoever can commit.
- Demo API 1.36: `env` on app create (validated, 422 when it does not match), on app edit, in app objects and in the cipi.yml plan; the staging server's apps are `staging` and `shopstg` ships a `cipi-staging.yml`. `./dev/demo.sh --reset` once to pick them up in an existing demo state.
- **Roles and granted access** — every account is an *admin* (sees and does everything: connections, API tokens, the team, the panel settings) or a *user* with what an admin granted: a level on whole servers — **View** (the server page read-only, its apps and databases listed, apps opened read-only) or **Manage** (everything on that server, apps included) — and abilities on single apps: **View, Deploy, Edit, Environment** (.env and Composer auth.json), **Logs, Commands** (Artisan and whitelisted commands) and **Delete**. Pages, tabs, buttons and actions follow the grants; a refused action says so. Tables `cipi_server_grants`, `cipi_app_grants`; column `users.role` (`CipiGui\Services\Access`).
- **Team** (admins): the accounts with role, 2FA and a summary of their access; edit anyone's role and grants; remove accounts. An admin cannot take the admin role from themselves or remove their own account, so a panel always keeps one.
- **Invite links** — Team → Invite picks the role and the grants and gives a link to copy (`/invite/{token}`): whoever opens it creates the account with that access. One account per link, links expire after 7 days, pending ones can be revoked. Nothing is emailed.
- **Settings → Panel** (admins, for everyone): **Fleet view** on by default — Apps and Databases list every connected server in one place, with a server column, a server filter and search by server; no server switcher in the header. Off, the panel works as before: one server at a time, picked from the header. And how the **Server page** is reached in fleet view: one sidebar entry per server (default) or a single entry with a picker on the page. Table `cipi_settings` (`CipiGui\Support\Panel`).
- Apps and Databases in fleet view fetch every server concurrently (`CipiApiClient::getForMany`, like the dashboard). A server that does not answer is reported above the list, with the reason, without hiding the others. `?server=` narrows a list to one server — the Dashboard cards, the Server page and the app pages' breadcrumb link there. The pages paint first and load after (`wire:init`).
- Row actions run on the row's server, and job titles say which one ("Deploy shop on fra1") when more than one is in view. The create dialogs (app, database) have a server picker among the servers the user manages; PHP versions, database engines and Node runtimes are the chosen server's.
- **Deploy time** on App → Deploy: the audit table has a **Took** column, and the Deploy card says how long the last deploy took and the average over the loaded ledger. Cipi 5.6+ ends every deploy in the deploy log with `===== deploy OK  app=x release=N duration=54s exit=0`; the panel reads the most recent 1000 lines of `GET /api/apps/{name}/logs?type=deploy` with the ledger and joins them by release (`CipiGui\Support\DeployLog`). A failed deploy's banner names the release that stayed live, so it is matched to the release the ledger shows as live just before it; every banner is used once, newest first. Deploys older than the log, and servers before 5.6, show "—" with a note on what is needed.
- **Click to copy an IP** on the Dashboard cards, the Connections table, and the Server page header and System card (`x-cipi::copyable`).
- Demo API: the deploy log of every app is derived from its ledger, with the Cipi 5.6 banners, so Took and the deploy times show on the demo (no reset needed).
- `dev/screenshots.mjs` takes the README screenshots on the demo (headless Chrome over the DevTools protocol, no dependencies).

### Changed

- Dialogs with a form — create app, create or restore a database, add or edit a connection, invite, edit access — no longer close on a click outside them; Cancel, the X and Escape do. The create-app form keeps what was typed when it is closed, until the app is created or the form is cleared from the dialog; after a failed create job the draft stays too, so a refused name or domain can be fixed and resent.
- Sidebar in fleet view: Dashboard, Apps and Databases under **Fleet**, the servers under **Servers**, then Connections and Team (admins) and Settings. With fleet view off: the sections of 3.x.
- Switching server from the header (fleet view off) on an app page opens that server's apps (`/apps?server=`); on a list, a fleet-wide list stays fleet-wide and one narrowed to the old server is narrowed to the new one.
- App changes remembered in the session between the app page and the list are keyed by server as well as name, so the same app name on two servers no longer shares them.
- An app the API answers without a body is reported as not found instead of a broken page.
- `cipi:seed-gui-user` creates and resets the admin with the admin role.

## [3.3.0] — 2026-10-22

Aligned with Cipi API 1.35 / Cipi 5.6.4: cipi.yml from the panel. Needs **cipi/api ≥ 1.35.0** and **Cipi ≥ 5.6.4** on the server (5.6.4 adds the `--json` outputs the API reads, and is also the release where auto-apply works on Git webhook deploys), plus the new token abilities `yml-view` and `yml-manage`, which existing tokens do not get automatically. Older servers and narrower tokens get an explanation on the tab instead.

### Added

- **App → cipi.yml** — the `cipi.yml` of the live release next to what this server has now, from `GET /api/apps/{name}/yml` (`cipi yml plan --json`): the file path (or that the release has none, with the `cipi yml generate` command to start one), whether it is valid with the parser's errors and warnings, the plan — every change `apply` would make by its summary, the blockers that stop it, the after-deploy notes — and the in-sync state.
- **Apply now** (`POST /api/apps/{name}/yml/apply`, job `app-yml-apply`) runs in the job overlay like a deploy; the result shows the outcome — applied, unchanged, partial with the changes that failed, blocked with what blocks it, invalid with the errors, no file — stays on the tab after the overlay closes, and the plan is reloaded. A partial, blocked or invalid apply fails the job but still shows its result. The button is disabled without a file, with an invalid file, while blockers remain and when everything is in sync.
- **Auto-apply** on and off (`POST /api/apps/{name}/yml/auto/enable` and `…/disable`): apply cipi.yml after every successful deploy — CLI, panel and Git webhook. Turning it on asks for confirmation and says what it means: anyone who can commit to the repository can then change the app's aliases, PHP settings, limits, workers, deploy recipe, scheduled commands, databases and backup schedule (for Node apps also mode, version, build and start). A missing sudo rule for webhook deploys is flagged.
- `yml-view` and `yml-manage` in the token command on Connections (`token_abilities`). A 403 on the tab names the missing ability; a 404 or 503 says the server needs Cipi 5.6.4+ and cipi/api 1.35+, with the server's own message when there is one.
- Demo API 1.35: `GET /api/apps/{name}/yml`, apply and auto on/off, with plans for six apps — pending changes (shop, shopstg), in sync (shopapi), blocked by a missing Node major (console), invalid (launch), and a change that fails so the apply ends partial (bakery); the other apps have no file. `./dev/demo.sh --reset` once to pick them up in an existing demo state.

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
