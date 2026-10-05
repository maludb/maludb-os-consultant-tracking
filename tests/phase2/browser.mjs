// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof before Phase 3"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar on a phone and the sidebar on a desktop,
//   the command bar and tab bar thumb-reachable, HTMX navigation pushing URL and title, no console errors, the dashboard
//   whole with JavaScript off, the web app manifest installable (no service worker); the menu per role.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the
// kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots';
// A single-use hand-off URL, signed as the kernel signs one (needs the scratch ACTION_TOKEN_KEY in the environment).
import crypto from 'node:crypto';
import fs from 'node:fs';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.consultant_tracking.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get consultant() { return mint(26); }, get manager() { return mint(27); }, get admin() { return mint(1); } };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const groupsOf = async (page) => (await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());   // the theme upper-cases captions by CSS

// ---- phone: a manager (Marco) ----
{
  const { ctx, page, errors } = await session(urls.manager, { width: 375, height: 740 });
  console.log('375 x 740 — a manager');
  ok(page.url() === BASE + '/', 'signed on and landed on the dashboard (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the dashboard (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar'), 'the bottom tab bar is shown on a phone');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').count();
  ok(tabs === 5, `five tabs: Home, Time, Expenses, Engagements, More (${tabs})`);
  const small = await page.evaluate(() => [...document.querySelectorAll('#app-tabbar .app-tab')].filter((a) => a.getBoundingClientRect().height < 44 || a.getBoundingClientRect().width < 44).length);
  ok(small === 0, 'every tab is at least 44 x 44 px');
  ok(await shown(page, '#home-my-week') && await shown(page, '#home-approvals') && await shown(page, '#home-budget-alerts') && await shown(page, '#dashboard-log-btn'), 'the home regions (My week, Approvals waiting, Budget alerts) and the Log time button are shown');
  ok((await page.locator('#header-role-badge').innerText()).includes('Manager'), 'the header badge reads Manager');
  await page.screenshot({ path: `${SHOTS}/phone-dashboard-manager.png` });
  // HTMX navigation: a tab swaps #page-content, pushes the URL and sets the title
  await page.click('#tab-timesheet');
  await page.waitForURL(BASE + '/time/');
  await page.waitForSelector('#timesheet-coming');
  ok(page.url() === BASE + '/time/', 'the Time tab pushed /time/ without a page load');
  ok((await page.title()).startsWith('My timesheet'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-timesheet').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => !document.querySelector('#nav-home .nxl-link').classList.contains('active') && document.querySelector('#nav-timesheet .nxl-link').classList.contains('active')), 'and the sidebar highlights the screen, not Home');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'timesheet'), 'and re-stamped #page-content data-screen for the command bar');
  await page.screenshot({ path: `${SHOTS}/phone-timesheet-placeholder.png` });
  await page.click('#tab-expenses');
  await page.waitForURL(BASE + '/expenses/');
  await page.waitForSelector('#expenses-coming');
  ok(true, 'the Expenses tab opens its placeholder (slice 4 builds it)');
  // More opens the sidebar with the groups the member's rights give
  await page.click('#tab-more');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  ok(await shown(page, '#nav-time-approvals'), 'More opens the menu; a manager sees Time approvals');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'work,manage,billing,reports,settings,me', 'a manager\'s groups: ' + groups.join(', '));
  ok((await page.locator('#nav-general').count()) === 0 && (await page.locator('#nav-my-settings').count()) === 1 && (await page.locator('#nav-schedules').count()) === 1, 'Settings holds My settings and Hosting schedules for a manager (no firm settings)');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOTS}/phone-more-menu.png` });
  await page.evaluate(() => { const l = document.querySelector('.nxl-navbar'); l.parentElement.scrollTop = l.parentElement.scrollHeight; });
  const lastAfter = await page.locator('.nxl-navbar .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await page.click('#nav-tokens .nxl-link');
  await page.waitForSelector('#tokens-connect');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Tokens');
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await shown(page, '#prefs-save-btn'), 'no sideways scroll on My settings; the Save button is there');
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  await page.goto(BASE + '/activity', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Activity');
  await page.screenshot({ path: `${SHOTS}/phone-activity.png` });
  // the command bar: the fake expert answers in place
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.fill('#assistant-input', 'What is unbilled on Acme?');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a consultant (Priya) ----
{
  const { ctx, page, errors } = await session(urls.consultant, { width: 375, height: 740 });
  console.log('375 x 740 — a consultant');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'work,reports,settings,me', 'a consultant\'s groups: ' + groups.join(', ') + ' (no Manage, no Billing)');
  ok((await page.locator('#nav-time-approvals').count()) === 0 && (await page.locator('#nav-my-settings').count()) === 1, 'no Time approvals; My settings is there');
  ok(!(await shown(page, '#home-approvals')) && await shown(page, '#home-my-week-empty'), 'home: no "Approvals waiting", the empty "My week"');
  ok((await page.locator('#header-role-badge').innerText()).includes('Consultant'), 'the badge reads Consultant');
  ok((await page.locator('#app-tabbar .app-tab').count()) === 5, 'five tabs for a consultant too (time, expenses, engagements)');
  await page.screenshot({ path: `${SHOTS}/phone-dashboard-consultant.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- desktop: the sidebar, the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-general') && await shown(page, '#nav-reports'), 'the sidebar is shown, with the firm\'s settings and Reports');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'work,manage,billing,reports,settings,me', 'the admin\'s groups: ' + groups.join(', '));
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  await page.screenshot({ path: `${SHOTS}/desktop-dashboard.png` });
  await page.click('#nav-engagements .nxl-link');                 // a placeholder (slice 2) — HTMX navigation pushes its URL
  await page.waitForURL(BASE + '/engagements/');
  await page.waitForSelector('#engagements-coming');
  ok((await page.title()).startsWith('Engagements'), 'HTMX navigation: /engagements/ pushed, title "' + (await page.title()) + '"');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  // the settings form saves over HTMX and lands back with the notice
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  await page.uncheck('#prefs-field-kind-missing_time');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved how you are told'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-kind-missing_time')), 'and the unchecked kind stayed unchecked');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the dashboard is whole ----
{
  const { ctx, page } = await session(urls.manager, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(['My week', 'Approvals waiting', 'Budget alerts'].every((t) => text.includes(t)), 'the home regions are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-timesheet').getAttribute('href')) === '/time/', 'the tabs are plain links');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.consultant, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Consultant Tracking', 'standalone, start_url /, named Consultant Tracking');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
