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
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = process.env.CIPI_SHOTS_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = (process.env.CIPI_SHOTS_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const OUT = process.env.CIPI_SHOTS_OUT || path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'screenshots');
const PROFILE = mkdtempSync(path.join(os.tmpdir(), 'cipi-shots-'));
const ONLY = process.argv.slice(2);
const PORT = 9333;
mkdirSync(OUT, { recursive: true });

const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${PROFILE}`, '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' });
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
  await evaluate('window.scrollTo(0, 0)'); await sleep(150);
  let h = height ?? vp.height;
  if (full) h = Math.max(h, await evaluate('document.documentElement.scrollHeight'));
  const r = await send('Page.captureScreenshot', { format: 'webp', quality: 88, captureBeyondViewport: true, clip: { x: 0, y: 0, width: vp.width, height: h, scale: 1 } });
  writeFileSync(`${OUT}/${name}.webp`, Buffer.from(r.data, 'base64'));
  console.log('shot', name, `${vp.width}x${h} @${vp.scale}x`);
}
const want = (...names) => !ONLY.length || names.some((n) => ONLY.includes(n));

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

  // ── jobs last: they change the demo state ──
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
