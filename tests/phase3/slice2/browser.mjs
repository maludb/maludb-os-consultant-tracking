// Headless Chromium proof of slice 2 (docs/build-specs/engagements.md, "Proof", 7): every screen of the slice at 375 x 740 and 1280 x 800 with
// scrollWidth = viewport, every button at least 44 px tall on a phone, the flows (open an engagement with Start now, the tabs, staff someone, price a role in place,
// add and deliver a milestone, the budgets screen), the field error in #flash, JavaScript off.
// Run through tests/phase3/slice2/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s2';
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

// ---- phone: Marco (a Manager) walks the slice ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  await go(page, '/engagements/');
  ok(await page.locator('#engagement-filters').count() === 1, 'the list renders');
  await widthOK(page, 'Engagements', 375); await touch(page, 'Engagements');
  await go(page, '/engagements/new');
  ok(await page.locator('#engagement-form').count() === 1, 'the form renders');
  await widthOK(page, 'New engagement', 375); await touch(page, 'New engagement');
  // the kind toggles the NTE field
  await page.check('#engagement-form-field-kind-fixed_bid');
  ok(await page.evaluate(() => document.getElementById('engagement-form-nte').classList.contains('d-none')), 'choosing fixed bid hides the not-to-exceed');
  await page.check('#engagement-form-field-kind-time_and_materials');
  ok(await page.evaluate(() => !document.getElementById('engagement-form-nte').classList.contains('d-none')), 'and T&M shows it again');
  await page.selectOption('#engagement-form-field-client', { label: 'SMOKE Acme' });
  await page.fill('#engagement-form-field-name', 'SMOKE Browser pilot');
  await page.fill('#engagement-form-field-hours', '80');
  await page.fill('#engagement-form-field-amount', '16000');
  await page.fill('#engagement-form-field-nte', '20000');
  await page.click('#engagement-form-save-btn');
  await page.waitForURL(/\/engagements\/\d+\?notice=saved$/);
  await page.waitForSelector('#engagement-view-content');
  ok((await page.locator('#engagement-view-status').innerText()) === 'Active' && (await page.locator('#notice-banner').innerText()).includes('Saved the engagement'), 'saving with Start now lands on the active engagement (' + page.url() + ')');
  const engUrl = new URL(page.url()).pathname;
  await widthOK(page, 'Engagement', 375); await touch(page, 'Engagement');
  await page.screenshot({ path: `${SHOTS}/phone-engagement.png` });
  // the tabs push their URL
  await page.click('#engagement-tab-staffing');
  await page.waitForURL(/\/staffing$/);
  await page.waitForSelector('#staffing-form');
  ok(new URL(page.url()).pathname === engUrl + '/staffing', 'the Staffing tab pushed /staffing');
  await widthOK(page, 'Staffing', 375); await touch(page, 'Staffing');
  await page.selectOption('#staffing-form-field-member', { label: 'SMOKE Priya (person)' });
  await page.selectOption('#staffing-form-field-role', { label: 'Consultant' });
  await page.click('#staffing-form-save-btn');
  await page.waitForURL(/notice=staffed/);
  await page.waitForSelector('#staffing-list');
  ok((await page.locator('#staffing-list').innerText()).includes('SMOKE Priya'), 'Priya appears in the staffing list');
  await page.screenshot({ path: `${SHOTS}/phone-staffing.png` });
  // the same again is refused in #flash
  await page.selectOption('#staffing-form-field-member', { label: 'SMOKE Priya (person)' });
  await page.selectOption('#staffing-form-field-role', { label: 'Consultant' });
  await page.click('#staffing-form-save-btn');
  await Promise.race([page.waitForSelector('#flash-message'), page.waitForURL(/notice=staffed/)]);
  await page.waitForTimeout(300);
  const flashText = (await page.locator('#flash-message').count()) ? await page.locator('#flash-message').innerText() : '(no flash; url ' + page.url() + ')';
  const rows = await page.evaluate(async (u) => { const r = await fetch(u, { headers: { Accept: 'application/json' } }); const d = await r.json(); return d.data.engagement.staffing.map((s) => s.member.member_id + ':' + s.valid_from + '-' + (s.valid_to || 'open')); }, engUrl + '/staffing');
  ok(flashText.includes('already staffed'), 'staffing her again shows "already staffed" in #flash: ' + flashText.slice(0, 120) + ' rows ' + rows.join(','));
  // a rate in place
  await go(page, engUrl + '/rates');
  await widthOK(page, 'Rates', 375); await touch(page, 'Rates');
  const row = await page.locator('#engagement-rates-table tbody tr').first().getAttribute('id');
  await page.fill('#' + row + '-field', '333');
  await page.click('#' + row + '-save-btn');
  await page.waitForURL(/notice=rate/);
  await page.waitForSelector('#' + row + '-field');
  ok((await page.locator('#' + row + '-field').inputValue()) === '333.00', 'a role rate saved in place reads 333.00');
  // a milestone, delivered
  await go(page, engUrl + '/milestones');
  await widthOK(page, 'Milestones', 375); await touch(page, 'Milestones');
  await page.fill('#milestone-form-field-name', 'SMOKE Browser M1');
  await page.fill('#milestone-form-field-amount', '5000');
  await page.click('#milestone-form-save-btn');
  await page.waitForURL(/notice=milestone/);
  await page.waitForSelector('#milestones-cards');
  const card = await page.locator('#milestones-cards .card').first().getAttribute('id');
  ok((await page.locator('#' + card + '-status').innerText()) === 'Planned', 'the milestone card appears as Planned');
  await page.click('#' + card + '-deliver-btn');
  await page.waitForURL(/notice=delivered/);
  await page.waitForSelector('#' + card + '-status');
  ok((await page.locator('#' + card + '-status').innerText()) === 'Delivered', 'Deliver moves it to Delivered');
  await page.click('#' + card + '-accept-btn');
  await page.waitForSelector('#milestone-accept-form');
  ok(await page.locator('#milestone-accept-field-note').count() === 1, 'Accept opens the acceptance form (who, how, evidence)');
  await page.screenshot({ path: `${SHOTS}/phone-milestones.png` });
  // the budgets screen
  await go(page, '/usage/budgets');
  ok(await page.locator('#budget-alerts-content').count() === 1, 'the budgets screen renders');
  await widthOK(page, 'Budgets', 375); await touch(page, 'Budgets');
  await page.screenshot({ path: `${SHOTS}/phone-budgets.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Billing on the list; Priya on her engagement ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Billing');
  await go(page, '/engagements/');
  await widthOK(page, 'Engagements', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-engagements.png` });
  await page.fill('#engagement-filter-q', 'Browser pilot');
  await page.press('#engagement-filter-q', 'Enter');
  await page.waitForURL(/q=Browser/);
  ok((await page.locator('#engagement-list-cards .card').count()) === 1, 'the search narrows to one card (HTMX, URL pushed)');
  await page.click('#engagement-list-cards .card a.btn');
  await page.waitForSelector('#engagement-view-content');
  await widthOK(page, 'Engagement', 1280);
  ok(await page.locator('#engagement-view-moves').count() === 1 && await page.locator('#budget-bars').count() === 1, 'Billing manages it (the moves) and sees the budget bars');
  await page.screenshot({ path: `${SHOTS}/desktop-engagement.png` });
  await ctx.close();
  const p = await session(26, DESK);
  await go(p.page, '/engagements/');
  const n = await p.page.locator('#engagement-list-cards .card').count();
  ok(n >= 1 && (await p.page.locator('#engagement-list-cards').innerText()).includes('Staffed'), 'Priya lists the engagements she is staffed on with the Staffed chip (' + n + ')');
  await p.page.click('#engagement-list-cards .card a.btn');
  await p.page.waitForSelector('#engagement-view-content');
  ok(await p.page.locator('#engagement-view-moves').count() === 0 && await p.page.locator('#engagement-tab-rates').count() === 0, 'she has no moves and no Rates tab');
  ok(errors.length === 0 && p.errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await p.ctx.close();
}

// ---- JavaScript off: the form posts and lands ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/engagements/new');
  await page.selectOption('#engagement-form-field-client', { label: 'SMOKE Acme' });
  await page.fill('#engagement-form-field-name', 'SMOKE No JS eng');
  await page.click('#engagement-form-save-btn');
  await page.waitForLoadState('load');
  ok(/\/engagements\/\d+\?notice=saved$/.test(page.url()) && (await page.locator('#notice-banner').count()) === 1, 'a plain form POST lands on the record with the notice');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
