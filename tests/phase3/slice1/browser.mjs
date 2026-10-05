// Headless Chromium proof of slice 1 (docs/build-specs/clients-rates-settings.md, "Proof", 7): every screen of the slice at 375 x 740 and 1280 x 800 with
// scrollWidth = viewport, every button at least 44 px tall on a phone, the flows through the real screens (add a client, add a contact, add a rate card
// and price a role in place, a cost-rate range, a consulting role, the settings), the field error shown in #flash, JavaScript off.
// Run through tests/phase3/slice1/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s1';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.consultant_tracking.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422, a refused screen 403 — both by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control:not(.form-control-sm)')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.closest('.input-group-sm') && !e.classList.contains('btn-link') && e.type !== 'date' && e.type !== 'number'; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });

// ---- phone: the admin walks the slice ----
{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the admin');
  for (const [path, label, sel] of [['/clients/', 'Clients', '#client-filters'], ['/clients/new', 'Add client', '#client-form'], ['/settings/rate-cards/', 'Rate cards', '#rate-card-list-content'], ['/settings/rate-cards/new', 'Add card', '#rate-card-form'],
      ['/settings/cost-rates/', 'Cost rates', '#cost-rate-form'], ['/settings/capacity/', 'Capacity', '#capacity-form'], ['/settings/roles/', 'Roles', '#role-form'], ['/settings/activity-types/', 'Activity types', '#activity-form'],
      ['/settings/categories/', 'Categories', '#category-form'], ['/settings/providers/', 'Providers', '#provider-form'], ['/settings/tax-rates/', 'Tax rates', '#tax-rate-form'], ['/settings/general', 'Settings', '#settings-form'], ['/settings/numbering', 'Numbering', '#numbering-form-invoice']]) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders (${sel})`);
    await widthOK(page, label, 375);
    await touch(page, label);
  }
  // add a client through the form (HTMX): lands on the client with the notice
  await go(page, '/clients/new');
  await page.fill('#client-form-field-name', 'SMOKE Browser Co');
  await page.fill('#client-form-field-code', 'brow');
  await page.fill('#client-form-field-email', 'ap@browser.invalid');
  await page.click('#client-form-save-btn');
  await page.waitForSelector('#client-view-content');
  ok(/\/clients\/\d+\?notice=saved$/.test(page.url()) && (await page.locator('#notice-banner').innerText()).includes('Saved the client') && (await page.locator('#client-view-code').innerText()) === 'BROW', 'saving a client lands on it with the notice and the code uppercased (' + page.url() + ')');
  await page.screenshot({ path: `${SHOTS}/phone-client-view.png` });
  // add a contact inline
  await page.fill('#client-contact-form-field-name', 'SMOKE Jo Browser');
  await page.fill('#client-contact-form-field-email', 'jo@browser.invalid');
  await page.check('#client-contact-form-field-primary');
  await page.click('#client-contact-form-save-btn');
  await page.waitForURL(/notice=contact/);
  await page.waitForSelector('#client-contacts-list');
  ok((await page.locator('#client-contacts-list').innerText()).includes('SMOKE Jo Browser') && (await page.locator('#client-contacts-list .badge').first().innerText()) === 'Primary', 'the contact appears with the Primary badge');
  // a field error shows in #flash and the form stays
  await go(page, '/clients/new');
  await page.fill('#client-form-field-name', 'SMOKE Browser Co');
  await page.click('#client-form-save-btn');
  await page.waitForSelector('#flash-message');
  ok((await page.locator('#flash-message').innerText()).includes('already exists') && await page.locator('#client-form').count() === 1, 'a refused save shows the sentence in #flash and keeps the form');
  await page.screenshot({ path: `${SHOTS}/phone-client-refused.png` });
  // a rate card, priced in place
  await go(page, '/settings/rate-cards/new');
  await page.fill('#rate-card-form-field-name', 'SMOKE Browser card');
  await page.click('#rate-card-form-save-btn');
  await page.waitForSelector('#rate-card-view-content');
  ok(/\/settings\/rate-cards\/\d+\?notice=saved$/.test(page.url()), 'saving a card lands on it (' + page.url() + ')');
  const firstRow = await page.locator('#rate-card-view-table tbody tr').first().getAttribute('id');
  await page.fill('#' + firstRow + '-field', '250');
  await page.click('#' + firstRow + '-save-btn');
  await page.waitForURL(/notice=rate/);
  await page.waitForSelector('#' + firstRow + '-field');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the rate') && (await page.locator('#' + firstRow + '-field').inputValue()) === '250.00', 'a rate saved in place lands back on the card with 250.00');
  await page.screenshot({ path: `${SHOTS}/phone-rate-card.png` });
  // a cost-rate range
  await go(page, '/settings/cost-rates/');
  await page.selectOption('#cost-rate-form-field-member', { label: 'SMOKE Dana' });
  await page.fill('#cost-rate-form-field-cost', '65');
  await page.fill('#cost-rate-form-field-from', '2027-01-01');          // after any range the JSON proof gave her: this one ends it
  await page.click('#cost-rate-form-save-btn');
  await page.waitForURL(/notice=saved/);
  await page.waitForSelector('#cost-rate-30');
  ok((await page.locator('#cost-rate-30').innerText()).includes('65.00 USD/h'), 'Dana\'s range appears on her card');
  await page.screenshot({ path: `${SHOTS}/phone-cost-rates.png` });
  // a consulting role
  await go(page, '/settings/roles/');
  await page.fill('#role-form-field-label', 'SMOKE Browser Architect');
  await page.click('#role-form-save-btn');
  await page.waitForURL(/notice=saved/);
  await page.waitForSelector('#role-table');
  ok((await page.locator('#role-table').innerText()).includes('SMOKE Browser Architect') && (await page.locator('#role-table').textContent()).includes('smoke_browser_architect'), 'the role appears with its key made from the title (the key column is hidden on a phone)');
  // the settings save and land with the notice
  await go(page, '/settings/general');
  const firm = 'SMOKE Browser Firm ' + Date.now();
  await page.fill('#settings-form-field-name', firm);
  await page.selectOption('#settings-form-field-increment', '0.50');
  await page.click('#settings-form-save-btn');
  await page.waitForURL(/notice=saved/);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the settings') && (await page.locator('#settings-form-field-name').inputValue()) === firm && (await page.locator('#settings-form-field-increment').inputValue()) === '0.50', 'the settings save and land back with the values');
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Billing on clients and cards; a Manager refused the cost rates ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Billing');
  for (const [path, label] of [['/clients/', 'Clients'], ['/settings/rate-cards/', 'Rate cards'], ['/settings/cost-rates/', 'Cost rates']]) { await go(page, path); await widthOK(page, label, 1280); }
  await go(page, '/clients/');
  await page.screenshot({ path: `${SHOTS}/desktop-clients.png` });
  await page.fill('#client-filter-q', 'browser');
  await page.press('#client-filter-q', 'Enter');
  await page.waitForURL(/q=browser/);
  ok((await page.locator('#client-list-cards .card').count()) === 1, 'the search narrows to one card (HTMX, URL pushed)');
  await go(page, '/settings/cost-rates/');
  ok(await page.locator('#cost-rate-30').count() === 1 && await page.locator('#cost-rate-form').count() === 0, 'Billing reads the cost rates without the form');
  await ctx.close();
  const m = await session(27, DESK);
  await go(m.page, '/settings/cost-rates/');
  ok((await m.page.locator('#message-text').innerText()).includes('You may not see the cost-rate table'), 'Marco (Manager) is refused the cost rates in the right\'s words');
  ok(errors.length === 0 && m.errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await m.ctx.close();
}

// ---- JavaScript off: the forms post and land ----
{
  const { ctx, page } = await session(1, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/clients/new');
  await page.fill('#client-form-field-name', 'SMOKE No JS Co');
  await page.click('#client-form-save-btn');
  await page.waitForLoadState('load');
  ok(/\/clients\/\d+\?notice=saved$/.test(page.url()) && (await page.locator('#notice-banner').count()) === 1, 'a plain form POST lands on the record with the notice');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
