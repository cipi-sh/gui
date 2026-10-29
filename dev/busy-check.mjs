// Browser check of the busy state (partials/busy-script, 4.1.1) on the demo panel.
//
//   ./dev/demo.sh              # in another terminal, demo API + panel on :8000
//   node dev/busy-check.mjs    # headless Chrome over the DevTools protocol, no npm dependencies
//
// Livewire requests are held back 1.5 s by a fetch wrapper installed before Livewire
// boots, so the busy state is observable: the Add button of the IP tab (wire:submit),
// a Remove button behind wire:confirm (wire:click), a tab switch, a button with its own
// wire:loading (left alone) and the polls on the app page (never busy). Adds and removes
// one 198.51.100.x entry on the demo's first server.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://127.0.0.1:8000';
const PORT = 9334;
const PROFILE = mkdtempSync(path.join(os.tmpdir(), 'cipi-busy-'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const status = (url) => new Promise((res) => http.get(url, (r) => { r.resume(); res(r.statusCode); }).on('error', () => res(0)));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => res(JSON.parse(d))); }).on('error', rej));

let ok = 0, ko = 0;
const pass = (m) => { ok++; console.log('  OK:', m); };
const fail = (m) => { ko++; console.log('  FAIL:', m); };
const check = (cond, m) => (cond ? pass(m) : fail(m));

// wait for the panel
let up = false;
for (let i = 0; i < 150 && !up; i++) { up = (await status(BASE + '/login')) === 200; if (!up) await sleep(400); }
if (!up) { console.log('panel not reachable on :8000'); process.exit(2); }

const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${PROFILE}`, '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
const bye = () => { try { chrome.kill(); } catch {} try { rmSync(PROFILE, { recursive: true, force: true }); } catch {} };
process.on('exit', bye);

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
async function goto(p) { const loaded = once('Page.loadEventFired'); await send('Page.navigate', { url: BASE + p }); await loaded; }
async function waitFor(expr, timeout = 30000) { const t0 = Date.now(); while (Date.now() - t0 < timeout) { if (await evaluate(expr)) return; await sleep(150); } throw new Error('timeout waiting for ' + expr); }
async function ready() { await waitFor(`!!window.Livewire && !document.querySelector('.skeleton')`); await sleep(400); }

// dialogs (wire:confirm) are accepted
listeners.add((m) => { if (m.method === 'Page.javascriptDialogOpening') send('Page.handleJavaScriptDialog', { accept: true }); });

// Livewire requests held back so the busy state can be observed: a fetch wrapper
// installed before Livewire boots (window.__held counts them, window.__delay holds them)
await send('Page.addScriptToEvaluateOnNewDocument', { source: `
  window.__held = 0; window.__delay = 1500; window.__urls = [];
  const __fetch = window.fetch.bind(window);
  window.fetch = function (u, o) {
    const url = String(u instanceof Request ? u.url : u); window.__urls.push(url);
    if (/\\/livewire[^/]*\\/update/.test(url)) { window.__held++; return new Promise((r) => setTimeout(r, window.__delay)).then(() => __fetch(u, o)); }
    return __fetch(u, o);
  };` });
const heldCount = () => evaluate('window.__held');
const resetHeld = () => evaluate('window.__held = 0');
const setDelay = (ms) => evaluate(`window.__delay = ${ms}`);

// ── sign in ──
await goto('/login');
await evaluate(`(() => { document.querySelector('input[name=email]').value = 'admin@cipi.local'; document.querySelector('input[name=password]').value = 'admin'; document.querySelector('form').submit(); })()`);
await once('Page.loadEventFired');
await goto('/server/1?tab=ip'); await ready();
check(await evaluate(`!!document.querySelector('script[data-cipi-busy-script]')`), 'busy-script is on the page');
check(await evaluate(`typeof Livewire.interceptAction === 'function'`), 'Livewire exposes interceptAction (v4)');

// ── 1. wire:submit — the Add form of the IP tab ──
console.log('--- wire:submit (IP tab, Add)');
const BTN = `document.querySelector('form[wire\\\\:submit="addIpWhitelistEntry"] button[type=submit]')`;
check(await evaluate(`!!${BTN}`), 'Add button found');
const ip = '198.51.100.' + (10 + Math.floor(Math.random() * 200));
await evaluate(`(() => { const i = document.querySelector('input[wire\\\\:model="ipWhitelistEntry"]'); i.value = ${JSON.stringify(ip)}; i.dispatchEvent(new Event('input', { bubbles: true })); })()`);
await resetHeld();
await evaluate(`${BTN}.click()`);
await sleep(80);
check(await evaluate(`${BTN}.hasAttribute('data-busy')`), 'right after the click the button is busy (data-busy)');
check(await evaluate(`!${BTN}.classList.contains('busy-visible')`), 'no spinner yet within the 150 ms grace');
await sleep(350);
check(await evaluate(`${BTN}.classList.contains('busy-visible') && !!${BTN}.querySelector(':scope > .busy-spinner') && ${BTN}.getAttribute('aria-busy') === 'true'`), 'after 150 ms: spinner shown, aria-busy=true');
check(await evaluate(`getComputedStyle(${BTN}).color === 'rgba(0, 0, 0, 0)' && getComputedStyle(${BTN}).pointerEvents === 'none'`), 'label transparent, pointer events off');
const w = await evaluate(`[${BTN}.offsetWidth, ${BTN}.offsetHeight]`);
await evaluate(`${BTN}.click()`);   // second click while busy: cancelled before it becomes a request
await sleep(2200);
{ const held = await heldCount(); check(held === 1, `one Livewire request left the page, the second click was cancelled (held=${held})`); }
check(await evaluate(`!${BTN}.hasAttribute('data-busy') && !${BTN}.classList.contains('busy-visible') && !${BTN}.querySelector('.busy-spinner') && !${BTN}.hasAttribute('aria-busy')`), 'after the answer: busy state fully cleared');
check(await evaluate(`[...document.querySelectorAll('.list-row')].some((r) => r.textContent.includes(${JSON.stringify(ip)}))`), `the entry ${ip} is in the list (the request did its job)`);
const w2 = await evaluate(`[${BTN}.offsetWidth, ${BTN}.offsetHeight]`);
check(w[0] === w2[0] && w[1] === w2[1], `the button kept its size while busy (${w.join('x')})`);

// ── 2. wire:click + wire:confirm — Remove that entry ──
console.log('--- wire:click with wire:confirm (Remove)');
const RM = `[...document.querySelectorAll('.list-row')].find((r) => r.textContent.includes(${JSON.stringify(ip)}))?.querySelector('button')`;
await waitFor(`!!(${RM})`, 5000).catch(async () => console.log('  rows now:', await evaluate(`[...document.querySelectorAll('.list-row')].map((r) => r.textContent.trim().slice(0, 60))`)));
await resetHeld();
await evaluate(`${RM}.click()`);   // confirm() is accepted by the dialog listener
await sleep(500);
check(await evaluate(`(() => { const b = ${RM}; return !!b && b.classList.contains('busy-visible'); })()`), 'the Remove button shows the spinner after the confirm');
await sleep(2200);
check((await heldCount()) === 1 && await evaluate(`![...document.querySelectorAll('.list-row')].some((r) => r.textContent.includes(${JSON.stringify(ip)}))`), 'entry removed, one request');

// ── 3. a tab button (wire:click) gets the busy state too ──
console.log('--- wire:click (tab switch)');
const TAB = `[...document.querySelectorAll('button')].find((b) => (b.getAttribute('wire:click') || '').startsWith('setTab') && !b.getAttribute('wire:click').includes("'ip'"))`;
check(await evaluate(`!!${TAB}`), 'a tab button with wire:click="setTab(…)" found');
const tabExpr = await evaluate(`${TAB}.getAttribute('wire:click')`);
await evaluate(`${TAB}.click()`); await sleep(400);
check(await evaluate(`(() => { const b = [...document.querySelectorAll('button')].find((b) => b.getAttribute('wire:click') === ${JSON.stringify(tabExpr)}); return !!b && b.hasAttribute('data-busy'); })()`), `tab button busy while the tab loads (${tabExpr})`);
await sleep(2200);

// ── 4. a button with its own wire:loading is left alone ──
console.log('--- own wire:loading (Servers → Test)');
await goto('/servers'); await ready();
const TEST = `[...document.querySelectorAll('button')].find((b) => (b.getAttribute('wire:click') || '').startsWith('testConnection'))`;
check(await evaluate(`!!${TEST} && !!${TEST}.querySelector('[wire\\\\:target]')`), 'Test button found, with its own wire:target markup');
await resetHeld(); await evaluate(`${TEST}.click()`); await sleep(400);
check(await evaluate(`!${TEST}.hasAttribute('data-busy') && !${TEST}.querySelector('.busy-spinner')`), 'not touched by the interceptor');
check(await evaluate(`${TEST}.disabled === true`), 'its own wire:loading.attr="disabled" is at work');
await sleep(2200);

// ── 5. polls never mark anything busy ──
console.log('--- polls');
await goto('/apps/shop?server=1').catch(() => {}); await ready();   // deploy status polls every 10 s
await resetHeld(); await setDelay(300); await sleep(12500);
{ const held = await heldCount(); check(held >= 1, `polling happened (${held} requests)`); }
check(await evaluate(`document.querySelectorAll('[data-busy], .busy-spinner').length === 0`), 'nothing busy during polls');

console.log(`=== e2e: ${ok} passed, ${ko} failed ===`);
ws.close(); bye(); process.exit(ko ? 1 : 0);
