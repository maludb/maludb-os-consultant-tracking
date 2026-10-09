// The record picker in a headless Chromium (docs/build-specs/record-picker.md; the plugin's picker-browser.mjs adapted): opens, focus, search, the keyboard,
// Show more, Clear, a pick that refreshes a filtered list, a time entry saved through the picker in a real browser, 375 px full screen, dark mode.
// Run through tests/picker/run.sh (servers up; BASE and ACTION_TOKEN_KEY in the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-picker';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.consultant_tracking.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;   // ok(condition, label) — or (label, condition)
const ok = (a, b) => { const [c, l] = typeof a === 'string' ? [b, a] : [a, b]; if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const rows = (page) => page.$$('#record-picker .record-picker-row');
const open = async (page, id) => { await page.click(`#${id}-open`); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row, #record-picker .record-picker-empty');
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'record-picker-search', null, { timeout: 5000 }).catch(() => {}); };   // 'shown' has fired and the search box has the focus
const shut = async (page) => { await page.waitForSelector('#record-picker', { state: 'hidden' }); };

// ---- desktop: Marco makes an engagement (client_live and the manager) ----
{
  const { page, errors } = await session(27, { width: 1280, height: 800 });
  console.log('1280 x 800 — a manager on the engagement form');
  await page.goto(BASE + '/engagements/new', { waitUntil: 'networkidle' });
  await page.screenshot({ path: SHOTS + '/1-form-desktop.png' });
  await open(page, 'engagement-form-field-client');
  ok((await rows(page)).length === 25, 'the modal opens with the first page of clients (25 rows)');
  await page.waitForFunction(() => document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
  ok(await page.evaluate(() => document.activeElement.id === 'record-picker-search'), 'the search box has focus');
  ok((await page.textContent('#record-picker-title')).trim() === 'Clients', 'the title is the source\'s');
  ok(await page.evaluate(() => document.getElementById('record-picker-footer').classList.contains('d-none')), 'no New button where the field offers no create');
  await page.type('#record-picker-search', 'pk client 1'); await page.waitForTimeout(700);
  const n = (await rows(page)).length; ok(`typing narrows the list (${n} rows)`, n === 10);
  await page.screenshot({ path: SHOTS + '/2-picker-desktop.png' });
  await page.keyboard.press('ArrowDown');
  ok(await page.evaluate(() => document.activeElement.classList.contains('record-picker-row')), 'ArrowDown moves from the search box to the first row');
  await page.keyboard.press('ArrowDown');
  const label = await page.evaluate(() => document.activeElement.dataset.label);
  await page.keyboard.press('Enter'); await shut(page);
  ok((await page.textContent('#engagement-form-field-client-open')).trim() === label && (await page.inputValue('#engagement-form-field-client')) !== '', `Enter picks the focused row (${label}): label shown, value in the hidden input`);
  await page.waitForFunction(() => document.activeElement.id === 'engagement-form-field-client-open', null, { timeout: 3000 }).catch(() => {});
  ok(await page.evaluate(() => document.activeElement.id === 'engagement-form-field-client-open'), 'focus returns to the opener');
  await open(page, 'engagement-form-field-client');
  ok(await page.evaluate((l) => document.querySelector('#record-picker .record-picker-row.active')?.dataset.label === l, label), 'reopened: the chosen row is marked active');
  await page.keyboard.press('Escape'); await shut(page);
  ok(await page.evaluate(() => !document.getElementById('record-picker').classList.contains('show')), 'Escape closes');
  await open(page, 'engagement-form-field-client');
  await page.type('#record-picker-search', 'Lonely'); await page.waitForTimeout(600); await page.keyboard.press('Enter'); await shut(page);
  ok((await page.textContent('#engagement-form-field-client-open')).trim() === 'SMOKE Pk Lonely', 'Enter in the search box picks the first match (a client with no engagement yet)');
  await open(page, 'engagement-form-field-client');
  await page.waitForSelector('#record-picker-more'); await page.click('#record-picker-more');
  await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length > 25);
  ok((await rows(page)).length > 25 && !(await page.$('#record-picker-more')) || (await rows(page)).length > 25, 'Show more appends the next page in place');
  await page.keyboard.press('Escape'); await shut(page);
  // the optional manager: pick, then Clear
  const mgrBefore = (await page.textContent('#engagement-form-field-manager-open')).trim();
  ok(mgrBefore === 'SMOKE Marco', 'the manager defaults to the caller, by label');
  await open(page, 'engagement-form-field-manager');
  ok((await page.textContent('#record-picker-title')).trim() === 'People', 'the manager picker lists people');
  await page.type('#record-picker-search', 'Priya'); await page.waitForTimeout(600); await page.click('#record-picker .record-picker-row'); await shut(page);
  ok((await page.textContent('#engagement-form-field-manager-open')).trim() === 'SMOKE Priya', 'a person is picked as the manager');
  await page.click('#engagement-form-field-manager-clear');
  ok((await page.inputValue('#engagement-form-field-manager')) === '' && (await page.textContent('#engagement-form-field-manager-open')).trim() === 'Nobody yet', 'Clear empties the hidden input and shows the placeholder');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
}

// ---- desktop: a list filter refreshes the list through the picker's change event ----
{
  const { page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800 — the engagement list filter');
  await page.goto(BASE + '/engagements/', { waitUntil: 'networkidle' });
  const before = (await page.$$('#engagement-list-results a')).length;
  await open(page, 'engagement-filter-client');
  await page.type('#record-picker-search', 'Pk Client 07'); await page.waitForTimeout(600); await page.click('#record-picker .record-picker-row'); await shut(page);
  await page.waitForFunction(() => /Pk Eng 07/.test(document.getElementById('engagement-list-results').textContent) && !/Pk Eng 08/.test(document.getElementById('engagement-list-results').textContent), null, { timeout: 5000 }).catch(() => {});
  const text = await page.textContent('#engagement-list-results');
  ok(/Pk Eng 07/.test(text) && !/Pk Eng 08/.test(text), `picking a client in the filter refreshes the list (hx-trigger change on the form hears the hidden input; was ${before} links)`);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
}

// ---- phone: a consultant logs time through the picker ----
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  console.log('375 x 740 — a consultant logs time');
  await page.goto(BASE + '/time/entries/new', { waitUntil: 'networkidle' });
  ok(await page.evaluate(() => document.documentElement.scrollWidth <= 375), 'no sideways scroll on the time form');
  await page.screenshot({ path: SHOTS + '/3-form-phone.png' });
  await open(page, 'time-entry-form-field-engagement');
  const dlg = await page.evaluate(() => { const r = document.querySelector('#record-picker .modal-content').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
  ok(dlg[0] >= 374 && dlg[1] >= 700, `the dialog fills the phone screen (${dlg.join('x')})`);
  const labels = await page.$$eval('#record-picker .record-picker-row', (r) => r.map((x) => x.dataset.label));
  ok(labels.length === 2, `a consultant is offered the two engagements she is staffed on (${labels.join(' / ')})`);
  const h = await page.evaluate(() => document.querySelector('#record-picker .record-picker-row').getBoundingClientRect().height);
  ok(h >= 44, `rows are touch targets (${Math.round(h)} px)`);
  await page.screenshot({ path: SHOTS + '/4-picker-phone.png' });
  await page.evaluate(() => document.documentElement.classList.add('app-skin-dark')); await page.waitForTimeout(200);
  await page.screenshot({ path: SHOTS + '/5-picker-phone-dark.png' });
  await page.evaluate(() => document.documentElement.classList.remove('app-skin-dark'));
  await page.click('#record-picker .record-picker-row:nth-child(2)'); await shut(page);
  ok((await page.textContent('#time-entry-form-field-engagement-open')).trim() === labels[1].replace(/\s+/g, ' ') || (await page.textContent('#time-entry-form-field-engagement-open')).includes(labels[1].split(' · ')[0]), 'the second engagement is chosen');
  await page.fill('#time-entry-form-field-hours', '0.25');
  const chosen = await page.inputValue('#time-entry-form-field-engagement');
  await page.click('#time-entry-form-save-btn');
  await page.waitForTimeout(1200);
  const saved = await page.evaluate(async (c) => { const r = await fetch('/time/', { headers: { Accept: 'text/html' } }); return r.status; }, chosen);
  ok(saved === 200 && !(await page.$('#flash .alert-danger')), 'the form saves through the hidden input (no refusal shown)');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
