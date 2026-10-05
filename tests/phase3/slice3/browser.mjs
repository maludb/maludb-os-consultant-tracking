// Headless Chromium proof of slice 3 (docs/build-specs/time-core.md, "Proof", 11): the day list on the phone and the grid on the desktop show the same hours; the stepper
// and chips produce only increments; every button >= 44 px, scrollWidth = viewport, no console errors; the timer bar; a submit and an approve through the screens; JavaScript off.
// Run through tests/phase3/slice3/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s3';
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

const monday = (weeksAgo) => { const d = new Date(); const dow = (d.getUTCDay() + 6) % 7; d.setUTCDate(d.getUTCDate() - dow - 7 * weeksAgo); return d.toISOString().slice(0, 10); };
const W = monday(6);
// ---- phone: Priya logs her week ----
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — a Consultant');
  await go(page, '/time/?week=' + W);
  ok(await page.locator('#week-header').count() === 1 && await page.locator('#timer-bar').count() === 1, 'the week renders with the timer bar');
  await widthOK(page, 'My week', 375); await touch(page, 'My week');
  ok(await page.locator('#timesheet-grid').isVisible() === false && await page.locator('#week-days').first().isVisible(), 'on a phone the day list shows, not the grid');
  await page.screenshot({ path: `${SHOTS}/phone-week-empty.png` });
  await page.click('#day-' + W + '-add');
  await page.waitForSelector('#time-entry-form');
  await widthOK(page, 'Log time', 375); await touch(page, 'Log time');
  ok((await page.locator('#time-entry-form-field-date-' + W).isChecked()), 'the form opens on the day tapped');
  // the stepper and chips produce only increments
  await page.click('#time-entry-form-chip-2');
  ok((await page.locator('#time-entry-form-field-hours').inputValue()) === '2.00', 'the 2 h chip sets 2.00');
  await page.click('#time-entry-form-hours-plus');
  await page.click('#time-entry-form-hours-plus');
  ok((await page.locator('#time-entry-form-field-hours').inputValue()) === '2.50', 'the stepper adds the increment twice: 2.50');
  await page.click('#time-entry-form-hours-minus');
  ok((await page.locator('#time-entry-form-field-hours').inputValue()) === '2.25', 'and takes one: 2.25');
  await page.fill('#time-entry-form-field-hours', '4');
  await page.selectOption('#time-entry-form-field-activity', { label: 'Travel' });
  ok(!(await page.isChecked('#time-entry-form-field-billable')), 'choosing Travel unticks billable (the activity\'s default)');
  await page.selectOption('#time-entry-form-field-activity', { label: 'Build' });
  ok(await page.isChecked('#time-entry-form-field-billable'), 'and Build ticks it again');
  await page.fill('#time-entry-form-field-note', 'SMOKE browser note');
  await page.click('#time-entry-form-save-btn');
  await page.waitForURL(/notice=saved/);
  await page.waitForSelector('#day-' + W + '-total');
  ok((await page.locator('#day-' + W + '-total').innerText()).includes('4 h') && (await page.locator('#week-totals').innerText()).includes('4 h'), 'the entry lands in the day list: Monday 4 h, the week 4 h');
  await page.screenshot({ path: `${SHOTS}/phone-week.png` });
  // a refused entry shows in #flash and the form stays
  await page.click('#day-' + W + '-add');
  await page.waitForSelector('#time-entry-form');
  await page.fill('#time-entry-form-field-hours', '0.3');
  await page.evaluate(() => document.getElementById('time-entry-form-field-hours').setAttribute('step', 'any'));   // the browser's own check would stop 0.3 first; the database's sentence is what we prove
  await page.click('#time-entry-form-save-btn');
  await Promise.race([page.waitForSelector('#flash-message'), page.waitForURL(/notice=saved/), page.waitForTimeout(8000)]);
  const flash = (await page.locator('#flash-message').count()) ? await page.locator('#flash-message').innerText() : '(no flash; url ' + page.url() + '; valid ' + (await page.evaluate(() => document.getElementById('time-entry-form-field-hours').validity.valid + ' ' + document.getElementById('time-entry-form').checkValidity())) + ')';
  ok(flash.includes('steps of 0.25') && await page.locator('#time-entry-form').count() === 1, 'a typed 0.3 is refused in #flash with the increment\'s sentence; the form stays: ' + flash.slice(0, 160));
  await page.screenshot({ path: `${SHOTS}/phone-entry-refused.png` });
  // the timer
  await go(page, '/time/?week=' + W);
  await page.click('#timer-bar-start-btn');
  await page.waitForSelector('#timer-bar-stop-btn');
  ok((await page.locator('#timer-bar-elapsed').innerText()).includes('elapsed'), 'the timer bar shows the elapsed time live');
  await page.click('#timer-bar-stop-btn');
  await page.waitForURL(/notice=timer_stopped/);
  ok(await page.locator('#timer-bar-form').count() === 1, 'Stop returns the bar to Start');
  // submit
  await go(page, '/time/?week=' + W);
  page.once('dialog', (d) => d.accept());
  await page.click('#week-submit-btn');
  await page.waitForURL(/notice=submitted/);
  await page.waitForSelector('#week-status');
  ok((await page.locator('#week-status').innerText()) === 'Submitted' && await page.locator('#week-submit-btn').count() === 0, 'Submit turns the week Submitted; Submit is gone');
  await page.screenshot({ path: `${SHOTS}/phone-week-submitted.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Marco approves; the grid ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — a Manager');
  await go(page, '/time/approvals');
  await widthOK(page, 'Approvals', 1280);
  ok(await page.locator('#approvals-list .card').count() >= 1, 'a week card awaits him');
  await page.screenshot({ path: `${SHOTS}/desktop-approvals.png` });
  const card = await page.locator('#approvals-list .card').first().getAttribute('id');
  await page.click('#' + card + '-open-btn');
  await page.waitForSelector('#timesheet-view-content');
  await widthOK(page, 'Week view', 1280);
  ok((await page.locator('#timesheet-view-content').innerText()).includes('will apply'), 'the week view says the rate that will apply');
  page.once('dialog', (d) => d.accept());
  await page.click('#week-approve-btn');
  await page.waitForURL(/notice=approved/);
  await page.waitForSelector('#week-status');
  ok((await page.locator('#week-status').innerText()) === 'Approved' && (await page.locator('#timesheet-view-content').innerText()).includes('USD/h'), 'Approve turns it Approved with the snapshotted rate shown');
  await page.screenshot({ path: `${SHOTS}/desktop-week-approved.png` });
  await go(page, '/time/?week=' + W + '&member=26');
  ok(await page.locator('#timesheet-grid').isVisible(), 'on a desktop the grid shows');
  const gridTotal = await page.locator('#grid-week-total').innerText();
  const listTotal = await page.evaluate(() => [...document.querySelectorAll('[id^="day-"][id$="-total"]')].reduce((s, el) => s + (parseFloat(el.innerText) || 0), 0));
  ok(parseFloat(gridTotal) === listTotal && listTotal > 0, 'the grid and the day list show the same hours (' + gridTotal + ')');
  await go(page, '/time/team?week=' + W);
  await widthOK(page, 'Team time', 1280);
  ok(await page.locator('#team-time-table').count() === 1, 'the team page renders');
  await go(page, '/time/missing?week=' + W);
  await widthOK(page, 'Missing time', 1280);
  ok(await page.locator('#missing-time-content').count() === 1, 'missing time renders');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: log, submit ----
{
  const { ctx, page } = await session(30, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const W2 = monday(7);
  await go(page, '/time/entries/new?date=' + W2);
  await page.fill('#time-entry-form-field-hours', '3');
  await page.click('#time-entry-form-save-btn');
  await page.waitForLoadState('load');
  ok(/\/time\/\?week=/.test(page.url()) && (await page.locator('#notice-banner').count()) === 1, 'a plain form POST logs and lands on the week');
  await page.locator('#week-submit-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok((await page.locator('#week-status').innerText()) === 'Submitted', 'and a plain Submit submits');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
