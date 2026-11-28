// The panel's documentation screenshots, taken on the demo (see dev/README.md, "Screenshots").
//
//   ./dev/demo.sh --reset            # in another terminal, demo API + panel on :8000
//   node dev/screenshots.mjs         # every shot into docs/screenshots
//   node dev/screenshots.mjs apps    # only the named shots
//
// Headless Chrome over the DevTools protocol — no npm dependencies. Each page is
// rendered at 1440×900 (2×) and captured full height; the phone shots at 390×844 (3×).
// The last shots create an app and deploy one, so reset the demo afterwards.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Google Chrome: CIPI_SHOTS_CHROME, or where macOS and Linux keep it. CIPI_SHOTS_CHROME_FLAGS adds flags
// (a CI runner may need --no-sandbox).
const CHROME = process.env.CIPI_SHOTS_CHROME
  || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'].find((p) => existsSync(p))
  || 'google-chrome';
const CHROME_FLAGS = (process.env.CIPI_SHOTS_CHROME_FLAGS || '').split(/\s+/).filter(Boolean);
const BASE = (process.env.CIPI_SHOTS_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const OUT = process.env.CIPI_SHOTS_OUT || path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'screenshots');
const PROFILE = mkdtempSync(path.join(os.tmpdir(), 'cipi-shots-'));
const ONLY = process.argv.slice(2);
const PORT = 9333;
mkdirSync(OUT, { recursive: true });

const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${PROFILE}`, '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--window-size=1440,900', ...CHROME_FLAGS, 'about:blank'], { stdio: 'ignore' });
const bye = () => { try { chrome.kill(); } catch {} try { rmSync(PROFILE, { recursive: true, force: true }); } catch {} };
process.on('exit', bye);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => res(JSON.parse(d))); }).on('error', rej));

let targets = null;
for (let i = 0; i < 100 && !targets; i++) { try { targets = await getJson(`http://127.0.0.1:${PORT}/json`); } catch { await sleep(200); } }
const page = targets.find((t) => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((r) => (ws.onopen = r));
let seq = 0; const pending = new Map(); const listeners = new Set();
ws.onmessage = (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { const { res, rej } = pending.get(m.id); pending.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
  else if (m.method) for (const l of listeners) l(m);
};
const send = (method, params = {}) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
const once = (method) => new Promise((res) => { const l = (m) => { if (m.method === method) { listeners.delete(l); res(m.params); } }; listeners.add(l); });

await send('Page.enable'); await send('Runtime.enable');

async function evaluate(expression) {
  const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' ' + (r.exceptionDetails.exception?.description ?? ''));
  return r.result.value;
}
let vp = { width: 1440, height: 900, scale: 2, mobile: false };
async function viewport(v) { vp = { ...vp, ...v }; await send('Emulation.setDeviceMetricsOverride', { width: vp.width, height: vp.height, deviceScaleFactor: vp.scale, mobile: vp.mobile }); }
async function goto(path) { const loaded = once('Page.loadEventFired'); await send('Page.navigate', { url: BASE + path }); await loaded; }
async function waitFor(expr, timeout = 30000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeout) { if (await evaluate(expr)) return; await sleep(150); }
  throw new Error('timeout waiting for ' + expr);
}
async function ready() {
  await waitFor(`(() => { if (!window.Livewire) return false; if (document.querySelectorAll('.skeleton').length) return false; const vis = (e) => e.offsetParent !== null; if ([...document.querySelectorAll('.spinner')].some(vis)) return false; return document.fonts.status === 'loaded'; })()`);
  await sleep(500);
}
async function theme(t) { await evaluate(`localStorage.setItem('cipi-gui-theme', '${t}')`); const loaded = once('Page.loadEventFired'); await send('Page.reload'); await loaded; await ready(); }
const wire = (i = 0) => `window.Livewire.all()[${i}].$wire`;
async function call(method, ...args) { await evaluate(`${wire()}.call(${JSON.stringify(method)}${args.map((a) => ',' + JSON.stringify(a)).join('')})`); await ready(); }
async function set(prop, value) { await evaluate(`${wire()}.set(${JSON.stringify(prop)}, ${JSON.stringify(value)})`); await ready(); }
async function click(selector) { await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) throw new Error('no ' + ${JSON.stringify(selector)}); el.click(); return true; })()`); }
async function jobDone() { await waitFor(`!!document.querySelector('.job-status-icon-success')`, 60000); await sleep(800); }

async function shoot(name, { full = true, height } = {}) {
  if (ONLY.length && !ONLY.includes(name)) return;
  // An autofocused field may have scrolled the page: the fixed header and sidebar must sit at the top of the capture.
  // A toast of the action just done would sit over the page.
  await evaluate(`document.querySelectorAll('.toast').forEach((t) => (t.style.display = 'none')); window.scrollTo(0, 0)`); await sleep(150);
  let h = height ?? vp.height;
  if (full) h = Math.max(h, await evaluate('document.documentElement.scrollHeight'));
  const r = await send('Page.captureScreenshot', { format: 'webp', quality: 88, captureBeyondViewport: true, clip: { x: 0, y: 0, width: vp.width, height: h, scale: 1 } });
  writeFileSync(`${OUT}/${name}.webp`, Buffer.from(r.data, 'base64'));
  console.log('shot', name, `${vp.width}x${h} @${vp.scale}x`);
}
const want = (...names) => !ONLY.length || names.some((n) => ONLY.includes(n));

// One part of the page, with some air around it: a card, a form.
async function shootElement(name, selector, pad = 20) {
  if (ONLY.length && !ONLY.includes(name)) return;
  // A toast of the action just done would sit over the card.
  const box = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) throw new Error('no ' + ${JSON.stringify(selector)}); document.querySelectorAll('.toast').forEach((t) => (t.style.display = 'none')); el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height }; })()`);
  await sleep(200);
  const clip = { x: Math.max(0, Math.floor(box.x - pad)), y: Math.max(0, Math.floor(box.y - pad)), width: Math.ceil(box.width + 2 * pad), height: Math.ceil(box.height + 2 * pad), scale: 1 };
  const r = await send('Page.captureScreenshot', { format: 'webp', quality: 88, captureBeyondViewport: true, clip });
  writeFileSync(`${OUT}/${name}.webp`, Buffer.from(r.data, 'base64'));
  console.log('shot', name, `${clip.width}x${clip.height} @${vp.scale}x`);
}
// A page's own component, by the end of its name ('server-manage'), whatever else the layout mounts.
const wireOf = (name) => `window.Livewire.all().find((c) => c.name.endsWith(${JSON.stringify(name)})).$wire`;
async function callOn(name, method, ...args) { await evaluate(`${wireOf(name)}.call(${JSON.stringify(method)}${args.map((a) => ',' + JSON.stringify(a)).join('')})`); await ready(); }
async function setOn(name, prop, value) { await evaluate(`${wireOf(name)}.set(${JSON.stringify(prop)}, ${JSON.stringify(value)})`); await ready(); }

try {
  await viewport({});
  // ── sign in, make production the current server ──
  await goto('/login');
  await evaluate(`(() => { document.querySelector('input[name=email]').value = 'admin@cipi.local'; document.querySelector('input[name=password]').value = 'admin'; document.querySelector('form').submit(); return true; })()`);
  await once('Page.loadEventFired');
  await goto('/apps?server=1'); await ready();
  await theme('dark'); // headless Chrome prefers light; the docs show the dark theme first

  if (want('dashboard', 'dashboard-light')) { await goto('/'); await ready(); await shoot('dashboard'); await theme('light'); await shoot('dashboard-light'); await theme('dark'); }
  if (want('apps', 'apps-light')) { await goto('/apps'); await ready(); await shoot('apps'); await theme('light'); await shoot('apps-light'); await theme('dark'); }

  // ── phone ──
  if (want('mobile-apps', 'mobile-app', 'mobile-nav')) {
    await viewport({ width: 390, height: 844, scale: 3, mobile: true });
    await goto('/apps'); await ready(); await shoot('mobile-apps', { full: false });
    await click('button[aria-label="Open menu"]'); await sleep(500); await shoot('mobile-nav', { full: false });
    await goto('/apps/shop?server=1'); await ready(); await shoot('mobile-app', { full: false });
    await viewport({ width: 1440, height: 900, scale: 2, mobile: false });
  }

  // ── app pages (shop on production) ──
  if (want('app-overview')) { await goto('/apps/shop?server=1'); await ready(); await shoot('app-overview'); }
  if (want('app-deploy', 'app-deploy-light')) { await goto('/apps/shop?server=1&tab=deploy'); await ready(); await shoot('app-deploy'); await theme('light'); await shoot('app-deploy-light'); await theme('dark'); }
  if (want('app-routing')) { await goto('/apps/shop?server=1&tab=routing'); await ready(); await shoot('app-routing'); }
  if (want('app-domains')) { await goto('/apps/shop?server=1&tab=domains'); await ready(); await shoot('app-domains'); }
  if (want('app-env')) {
    await goto('/apps/shop?server=1&tab=env'); await ready();
    const index = await evaluate(`${wire()}.get('envRows').findIndex((r) => r.key === 'LOG_LEVEL')`);
    await set(`envRows.${index}.value`, 'info');
    await shoot('app-env');
  }
  if (want('app-logs')) {
    await goto('/apps/shop?server=1&tab=logs'); await ready();
    await evaluate(`[...document.querySelectorAll('button[role=tab]')].find((b) => b.textContent.trim() === 'Laravel').click()`); await sleep(800); await ready();
    await shoot('app-logs');
  }
  if (want('app-node')) { await goto('/apps/console?server=1'); await ready(); await shoot('app-node'); }

  // ── databases, server, connections, settings ──
  if (want('databases')) { await goto('/databases'); await ready(); await shoot('databases'); }
  if (want('server-overview', 'server-overview-light')) { await goto('/server/1'); await ready(); await shoot('server-overview'); await theme('light'); await shoot('server-overview-light'); await theme('dark'); }
  if (want('server-monitor')) { await goto('/server/1?tab=monitor'); await ready(); await shoot('server-monitor'); }
  if (want('server-api-access')) { await goto('/server/1?tab=ip'); await ready(); await shoot('server-api-access'); }
  if (want('server-firewall', 'server-firewall-light')) { await goto('/server/1?tab=firewall'); await ready(); await shoot('server-firewall'); await theme('light'); await shoot('server-firewall-light'); await theme('dark'); }
  // Zero Trust: production has a tunnel and a tailnet side by side.
  if (want('server-zero-trust', 'server-zero-trust-light')) {
    await goto('/server/1?tab=zt'); await ready(); await shoot('server-zero-trust');
    await theme('light'); await shoot('server-zero-trust-light'); await theme('dark');
  }
  if (want('connections')) { await goto('/servers'); await ready(); await shoot('connections'); }
  if (want('team', 'team-invite')) {
    // A pending invite for the Team shot, then the invite dialog itself. The demo host has the admin (and any invited users).
    await goto('/team'); await ready();
    await call('openInvite');
    await set('inviteLabel', 'Giulia — Acme agency'); await set('editorRole', 'user');
    await set('serverLevels.2', 'view'); await set('serverLevels.3', 'manage'); await set('appGrants.1.shop', ['deploy', 'logs']);
    await call('createInvite'); await call('closeInvite');
    await goto('/team'); await ready(); await shoot('team');
    await call('openInvite');
    await set('inviteLabel', 'Giulia — Acme agency'); await set('editorRole', 'user');
    await set('serverLevels.2', 'view'); await set('serverLevels.3', 'manage'); await set('appGrants.1.shop', ['deploy', 'logs']);
    await sleep(300);
    const bottom = await evaluate(`document.querySelector('.modal-content').getBoundingClientRect().bottom`);
    const h = Math.max(900, Math.ceil(bottom + 36));
    if (h !== 900) await viewport({ height: h });
    await shoot('team-invite', { full: false, height: h });
    if (h !== 900) await viewport({ height: 900 });
    await call('closeInvite');
  }
  if (want('settings-2fa')) { await goto('/settings'); await ready(); await call('startTwoFactorSetup'); await waitFor(`!!document.querySelector('.qr-box')`); await sleep(300); await shoot('settings-2fa'); }

  // ── from here on the demo state changes ──
  // The mesh network: clients (server 2) has none — the join form, then the server right after joining,
  // where nobody has signed in over the mesh yet and the panel comes from the public address.
  if (want('mesh-join', 'mesh-join-light', 'mesh-joined', 'mesh-joined-light')) {
    const fill = async () => {
      await setOn('server-manage', 'meshProvider', 'tailscale');
      await setOn('server-manage', 'meshKey', 'tskey-auth-kDEMO11CNTRL-0123456789abcdef');
      await setOn('server-manage', 'meshHostname', 'nyc1-clients');
      await setOn('server-manage', 'meshTags', 'cipi, prod');
      await sleep(300);
    };
    await goto('/server/2?tab=zt'); await ready(); await fill(); await shootElement('mesh-join', '[data-zt-mesh]');
    await theme('light'); await fill(); await shootElement('mesh-join-light', '[data-zt-mesh]'); await theme('dark');
    await fill(); await callOn('server-manage', 'joinMesh');
    await shootElement('mesh-joined', '[data-zt-mesh]');
    await theme('light'); await shootElement('mesh-joined-light', '[data-zt-mesh]'); await theme('dark');
  }

  // Transfer: production's apps to staging — the target chosen from the panel's servers and the
  // connection checked, which puts production's key on staging; taken back out after each shot.
  if (want('server-transfer', 'server-transfer-light')) {
    const checked = async () => { await goto('/server/1?tab=sync&to=3'); await ready(); await callOn('server-manage', 'checkSync'); await sleep(300); };
    await checked(); await shoot('server-transfer'); await callOn('server-manage', 'forgetSyncTrust');
    await theme('light'); await checked(); await shoot('server-transfer-light'); await callOn('server-manage', 'forgetSyncTrust'); await theme('dark');
  }

  // Page cache: blog (WordPress) with the cache on — purge some pages, the plugin, the PHP package.
  if (want('app-page-cache', 'app-page-cache-light')) {
    const on = async () => {
      await goto('/apps/blog?server=1&tab=cache'); await ready();
      if (!(await evaluate(`${wireOf('app-detail')}.get('pageCache')?.enabled`))) await callOn('app-detail', 'savePageCache');
      await setOn('app-detail', 'cachePurge', '/hello-world/\n/category/news/*\n/');
    };
    await on(); await shoot('app-page-cache');
    await theme('light'); await on(); await shoot('app-page-cache-light'); await theme('dark');
  }

  // Backups → an SFTP destination: a Hetzner Storage Box added, its key to install. Removed after each shot.
  if (want('server-backups-sftp', 'server-backups-sftp-light')) {
    const added = async () => {
      await goto('/server/1?tab=backups'); await ready();
      await callOn('server-manage', 'openSftpForm');
      await setOn('server-manage', 'sftpName', 'box'); await setOn('server-manage', 'sftpUser', 'u123456');
      await callOn('server-manage', 'sftpPresetStorageBox'); await setOn('server-manage', 'sftpPath', 'cipi');
      await callOn('server-manage', 'saveSftpDestination');
      await sleep(300);
    };
    await added(); await shootElement('server-backups-sftp', '[data-backup-destinations]'); await callOn('server-manage', 'deleteBackupDestination', 'box');
    await theme('light'); await added(); await shootElement('server-backups-sftp-light', '[data-backup-destinations]'); await callOn('server-manage', 'deleteBackupDestination', 'box'); await theme('dark');
  }

  // Backups set up from the panel: staging (server 3) has none — set up on the server first,
  // then an S3 bucket (named s3, on a par with the SFTP servers) with the nightly profile sent there too.
  if (want('server-backups-setup', 'server-backups-setup-light', 'server-backups-s3', 'server-backups-s3-light')) {
    await goto('/server/3?tab=backups'); await ready(); await shootElement('server-backups-setup', '[data-backup-unconfigured]');
    await theme('light'); await goto('/server/3?tab=backups'); await ready(); await shootElement('server-backups-setup-light', '[data-backup-unconfigured]'); await theme('dark');
    const typed = async () => {
      await goto('/server/3?tab=backups'); await ready();
      if (await evaluate(`!!document.querySelector('[data-backup-unconfigured]')`)) await callOn('server-manage', 'setUpBackups');
      await callOn('server-manage', 'openS3Form');
      await setOn('server-manage', 's3Provider', 'hetzner');
      await setOn('server-manage', 's3Bucket', 'acme-staging-backups'); await setOn('server-manage', 's3Key', 'K7Q2X9M4N1EXAMPLE');
      await setOn('server-manage', 's3Secret', 'demo-secret-not-sent');
      await sleep(300);
    };
    await typed(); await shootElement('server-backups-s3', '[data-backup-destinations]');
    await theme('light'); await typed(); await shootElement('server-backups-s3-light', '[data-backup-destinations]'); await theme('dark');
    await callOn('server-manage', 'closeS3Form');
  }

  // Updates: a 12-hour delay, with a release waiting for it. Back to no delay afterwards.
  if (want('server-updates', 'server-updates-light')) {
    const delayed = async () => { await goto('/server/1?tab=updates'); await ready(); await setOn('server-manage', 'upDelay', '12'); await callOn('server-manage', 'saveUpdatePolicy'); await sleep(300); };
    await delayed(); await shoot('server-updates');
    await theme('light'); await delayed(); await shoot('server-updates-light'); await theme('dark');
    await setOn('server-manage', 'upDelay', '0'); await callOn('server-manage', 'saveUpdatePolicy');
  }

  // ── jobs last ──
  if (want('create-app', 'job-credentials')) {
    await goto('/apps'); await ready();
    await call('openCreate'); await call('setAppKind', 'node');
    await set('user', 'storefront'); await set('domain', 'www.example.com'); await set('repository', 'git@github.com:example-org/storefront.git'); await set('nodeFramework', 'nuxt');
    await sleep(300);
    const bottom = await evaluate(`document.querySelector('.modal-content').getBoundingClientRect().bottom`);
    const h = Math.max(900, Math.ceil(bottom + 36));
    if (h !== 900) await viewport({ height: h });
    await shoot('create-app', { full: false, height: h });
    if (h !== 900) await viewport({ height: 900 });
    await call('createApp'); await jobDone(); await shoot('job-credentials', { full: false });
  }
  if (want('job-deploy')) { await goto('/apps/shop?server=1'); await ready(); await call('deploy'); await jobDone(); await shoot('job-deploy', { full: false }); }
} finally {
  ws.close(); bye();
}
