// Headless Chromium proof of slice 4 (docs/build-specs/expenses-receipts.md, "Proof", 7): the camera field first and large on the phone, the thumbnail, the live billable amount, cards; the receipt rule's sentence in #flash;
// every button >= 44 px, scrollWidth = viewport, no console errors; an approve, a reimbursement through the screens at 1280; JavaScript off still saves and submits.
// Run through tests/phase3/slice4/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s4';
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

import { execSync } from 'node:child_process';
const sh = (c) => execSync(c, { encoding: 'utf8', env: process.env }).trim();
sh(`sudo -n -u postgres psql -q -d ${process.env.DB_NAME} -c "UPDATE ct_settings SET default_markup_pct = 10 WHERE id = 1"`);
const JPG = '/tmp/ct-browser-receipt.jpg';
sh(`php -r '$im = imagecreatetruecolor(240, 320); imagefill($im, 0, 0, imagecolorallocate($im, 245, 245, 235)); imagestring($im, 4, 10, 10, "SMOKE receipt", imagecolorallocate($im, 10, 10, 10)); imagejpeg($im, "${JPG}", 85);'`);
const today = new Date().toISOString().slice(0, 10);
let phoneExpense = null;

// ---- phone: Priya snaps a receipt ----
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — a Consultant');
  await go(page, '/expenses/new');
  ok(await page.locator('#expense-form').count() === 1, 'the expense form renders');
  const first = await page.evaluate(() => { const f = document.querySelector('#expense-form .card-body input, #expense-form .card-body select, #expense-form .card-body textarea'); return f ? f.id : null; });
  ok(first === 'expense-form-field-file', 'the camera field is the first field of the form');
  const attrs = await page.evaluate(() => { const f = document.getElementById('expense-form-field-file'); const r = f.getBoundingClientRect(); return { capture: f.getAttribute('capture'), accept: f.getAttribute('accept'), h: r.height, w: r.width }; });
  ok(attrs.capture === 'environment' && attrs.accept.includes('image/*') && attrs.accept.includes('application/pdf') && attrs.h >= 44 && attrs.w > 250, `capture="environment", images and PDFs, large on the phone (${Math.round(attrs.w)} x ${Math.round(attrs.h)})`);
  await widthOK(page, 'Record an expense', 375); await touch(page, 'Record an expense');
  await page.screenshot({ path: `${SHOTS}/phone-expense-new.png` });
  await page.fill('#expense-form-field-amount', '120.00');
  await page.selectOption('#expense-form-field-category', { label: 'Meals' });
  await page.fill('#expense-form-field-description', 'SMOKE browser dinner');
  ok((await page.locator('#expense-form-threshold-value').innerText()) === '75.00', 'the form says the threshold: "A receipt is required at or above 75.00"');
  ok((await page.locator('#expense-money-live-value').innerText()).startsWith('132.00'), 'the billable amount shows live with the policy markup: 132.00 (' + (await page.locator('#expense-money-live-value').innerText()) + ')');
  ok((await page.locator('#expense-form-field-markup').getAttribute('readonly')) !== null, 'the markup is read-only for a Consultant');
  // Save and submit without the file: the database's sentence in #flash, the form stays
  await page.click('#expense-form-submit-btn');
  await page.waitForSelector('#flash-message');
  const flash = await page.locator('#flash-message').innerText();
  ok(flash.includes('A receipt is required at or above 75.00') && await page.locator('#expense-form').count() === 1, 'Save and submit without the receipt: the sentence in #flash, the form stays: ' + flash.slice(0, 120));
  await page.screenshot({ path: `${SHOTS}/phone-expense-refused.png` });
  // the camera
  await page.setInputFiles('#expense-form-field-file', JPG);
  await page.waitForSelector('#expense-form-thumb-image');
  ok(await page.locator('#expense-form-thumb-image').isVisible(), 'the thumbnail shows once the file is chosen');
  await page.screenshot({ path: `${SHOTS}/phone-expense-thumb.png` });
  await page.click('#expense-form-submit-btn');
  await page.waitForURL(/notice=submitted/);
  await page.waitForSelector('#expense-view-status');
  phoneExpense = /\/expenses\/(\d+)/.exec(page.url())[1];
  ok((await page.locator('#expense-view-status').innerText()) === 'Submitted' && await page.locator('#receipt-' + phoneExpense + '-image').isVisible(), 'Save and submit with the file: Submitted, the receipt shown large');
  ok((await page.locator('#expense-money-amount').innerText()).startsWith('120.00') && (await page.locator('#expense-money-billable').innerText()).includes('132.00'), 'the money card: 120.00, billable 132.00');
  await widthOK(page, 'the expense', 375); await touch(page, 'the expense');
  await page.screenshot({ path: `${SHOTS}/phone-expense-view.png` });
  // the list
  await go(page, '/expenses/');
  await widthOK(page, 'My expenses', 375); await touch(page, 'My expenses');
  ok(await page.locator('#expense-card-' + phoneExpense).count() === 1 && await page.locator('#receipt-' + phoneExpense).count() === 1 && (await page.locator('#expense-card-' + phoneExpense + '-status').innerText()) === 'Submitted', 'the card: the receipt chip and the status');
  ok(await page.locator('#expense-list-tabs a').count() === 5, 'the status tabs');
  await page.click('#expense-tab-submitted');
  await page.waitForURL(/status=submitted/);
  ok(await page.locator('#expense-list-cards .card').count() >= 1, 'the Submitted tab lists it');
  await page.screenshot({ path: `${SHOTS}/phone-expense-list.png` });
  // a receipt-needed chip on a draft
  await go(page, '/expenses/new');
  await page.fill('#expense-form-field-amount', '99.00'); await page.fill('#expense-form-field-description', 'SMOKE browser draft');
  await page.click('#expense-form-save-btn');
  await page.waitForURL(/notice=saved/);
  await go(page, '/expenses/?status=draft');
  ok((await page.locator('[id^="receipt-"]').first().innerText()).includes('Receipt needed'), 'a draft at or above the threshold with no receipt carries the danger chip "Receipt needed"');
  await go(page, '/');
  await widthOK(page, 'Home', 375);
  ok(await page.locator('#home-my-expenses').count() === 1 && (await page.locator('#home-my-expenses-needs').count()) === 1, 'the home\'s My expenses region says one awaits a receipt');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Marco approves; Bea reimburses ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — a Manager');
  await go(page, '/expenses/approvals');
  await widthOK(page, 'Expense approvals', 1280);
  ok(await page.locator('#expense-card-' + phoneExpense + '-receipt-thumb img').count() === 1, 'the receipt thumbnail sits beside the amount on the approvals card');
  await page.screenshot({ path: `${SHOTS}/desktop-approvals.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#expense-card-' + phoneExpense + '-approve-btn');
  await page.waitForURL(/notice=approved/);
  ok(await page.locator('#expense-card-' + phoneExpense).count() === 0, 'Approve: it leaves the list, the notice says so');
  await go(page, '/expenses/' + phoneExpense);
  ok((await page.locator('#expense-view-status').innerText()) === 'Approved' && await page.locator('#expense-view-reject-form').count() === 1 && await page.locator('#expense-view-approve-btn').count() === 0, 'the page: Approved, Send back offered, no Approve');
  await widthOK(page, 'the expense', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-expense-approved.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Billing');
  await go(page, '/expenses/reimbursements');
  await widthOK(page, 'Reimbursements', 1280);
  ok(await page.locator('#reimbursements-26').count() === 1 && await page.locator('#reimbursements-row-' + phoneExpense).count() === 1, 'Priya is owed; the expense is a row');
  await page.screenshot({ path: `${SHOTS}/desktop-reimbursements.png` });
  await page.fill('#reimbursements-26-reference', 'PAYRUN-BROWSER');
  page.once('dialog', (d) => d.accept());
  await page.click('#reimbursements-26-pay-btn');
  await page.waitForURL(/notice=reimbursed/);
  ok(await page.locator('#reimbursements-row-' + phoneExpense).count() === 0, 'Mark reimbursed: the rows leave the page');
  await go(page, '/expenses/' + phoneExpense);
  ok((await page.locator('#expense-view-reimbursed').count()) === 1 && (await page.locator('#expense-view-dates').innerText()).includes('PAYRUN-BROWSER'), 'the expense says Reimbursed (PAYRUN-BROWSER)');
  await go(page, '/expenses/company-card');
  await widthOK(page, 'Company card', 1280);
  ok(await page.locator('#company-card-content').count() === 1, 'the company-card page renders');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: record, submit ----
{
  const { ctx, page } = await session(30, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/expenses/new');
  await page.fill('#expense-form-field-amount', '20.00');
  await page.fill('#expense-form-field-description', 'SMOKE no-js coffee');
  await page.locator('#expense-form-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/\/expenses\/\d+\?notice=saved/.test(page.url()) && (await page.locator('#notice-banner').count()) === 1, 'a plain form POST saves and lands on the expense');
  await page.locator('#expense-view-submit-btn').click();
  await page.waitForLoadState('load');
  ok((await page.locator('#expense-view-status').innerText()) === 'Submitted', 'and a plain Submit submits');
  await go(page, '/expenses/new');
  await page.fill('#expense-form-field-amount', '30.00');
  await page.fill('#expense-form-field-description', 'SMOKE no-js save and submit');
  await page.locator('#expense-form-submit-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/notice=submitted/.test(page.url()) && (await page.locator('#expense-view-status').innerText()) === 'Submitted', 'Save and submit in one plain POST: Submitted');
  await go(page, '/expenses/new');
  await page.fill('#expense-form-field-amount', '200.00');
  await page.fill('#expense-form-field-description', 'SMOKE no-js refused');
  await page.locator('#expense-form-submit-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(((await page.locator('body').innerText()).includes('A receipt is required at or above 75.00')), 'a plain Save and submit without the receipt: the sentence on the page');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
