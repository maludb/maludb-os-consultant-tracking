// Headless Chromium proof of slice 5 (docs/build-specs/usage-and-os-statement.md, "Proof", 7): the charge form's live billable amount on the phone; the import's three steps usable at 375 px; the statement's lines cards on the phone and a table on the
// desktop; every button >= 44 px, scrollWidth = viewport, no console errors; Pull and Allocate, Approve and Void, a schedule's Run now and a rule through the screens at 1280; JavaScript off still saves, approves and previews.
// Run through tests/phase3/slice5/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s5';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|503)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422, a refused screen 403 — both by design
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
const landed = async (page, re) => { try { await page.waitForURL(re, { timeout: 10000 }); } catch (e) { console.log('   (no landing on ' + re + '; url ' + page.url() + '; flash: ' + (await page.locator('#flash').innerText().catch(() => '')).slice(0, 200) + ')'); throw e; } };
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const sh = (c) => execSync(c, { encoding: 'utf8', env: process.env }).trim();
const stateFile = process.env.FAKE_KERNEL_STATE;
const withKernel = (f) => { const s = JSON.parse(fs.readFileSync(stateFile, 'utf8')); f(s); fs.writeFileSync(stateFile, JSON.stringify(s)); };
const line = (provider, agentId, agent, deptId, dept, amount) => ({ provider, model_id: 11, model: 'claude-test', department_id: deptId, department: dept, agent_id: agentId, agent, application_id: null, application: null, calls: 5, late_calls: 0,
  input_tokens: 800000, output_tokens: 200000, cache_read_tokens: 0, cache_write_tokens: 0, amount, currency: 'USD', note: null });
const doc = (period, status, lines) => ({ schema: 'os.ledger-period/1', business: 'SMOKE Firm', period, period_start: period + '-01', period_end: period + '-31', status, closed_at: status === 'closed' ? period + '-28T00:00:00Z' : null, rolled_up_at: period + '-28T00:00:00Z',
  currencies: ['USD'], exchange: 'none', amount_scale: 4, generated_at: '2026-10-01T00:00:00Z', lines, totals: [{ currency: 'USD', amount: '14.0000' }] });
const july = doc('2026-07', 'closed', [line('anthropic', 901, 'Researcher', 7, 'AI Strategy', '10.0000'), line('anthropic', 902, 'Writer', 8, 'Content', '4.0000')]);
withKernel((s) => { s.ledger = { docs: { '2026-07': july }, periods: [{ period: '2026-07', period_start: '2026-07-01', period_end: '2026-07-31', status: 'closed', closed_at: '2026-07-28T00:00:00Z', statement_lines: 2, ledger_calls: 0 }] }; });
const CSV = '/tmp/ct-browser-usage.csv';
fs.writeFileSync(CSV, 'usage_date_utc,model,total_tokens,cost_usd\n2027-01-01,claude-sonnet,1000000,3.00\n2027-01-02,claude-sonnet,2000000,6.00\n2027-01-03,claude-haiku,1000000,1.00\n');
const acmeValue = (await (async () => sh(`sudo -n -u postgres psql -At -d ${process.env.DB_NAME} -c "SELECT id FROM engagements WHERE name = 'SMOKE Time Acme'"`))());
const anthropicValue = sh(`sudo -n -u postgres psql -At -d ${process.env.DB_NAME} -c "SELECT id FROM usage_providers WHERE provider_key = 'anthropic'"`);
let phoneCharge = null;

// ---- phone: Marco records a charge, imports a CSV, reads the statement ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  await go(page, '/usage/new');
  ok(await page.locator('#usage-form').count() === 1, 'the charge form renders');
  await widthOK(page, 'Record a usage charge', 375); await touch(page, 'Record a usage charge');
  await page.selectOption('#usage-form-field-engagement', acmeValue);
  await page.fill('#usage-form-field-description', 'SMOKE browser tokens');
  await page.fill('#usage-form-field-quantity', '2000000');
  await page.selectOption('#usage-form-field-unit', 'tokens');
  await page.fill('#usage-form-field-unit-cost', '0.000003');
  await page.selectOption('#usage-form-field-pricing', 'resale');
  await page.fill('#usage-form-field-resale', '15');
  ok((await page.locator('#pricing-preview-cost').innerText()).startsWith('6.00') && (await page.locator('#pricing-preview-billable').innerText()).startsWith('30.00'), 'the preview: 2,000,000 tokens at 0.000003 cost 6.00; at 15 per million → 30.00 (' + (await page.locator('#pricing-preview-billable').innerText()) + ')');
  ok(await page.locator('#usage-form-resale-group').isVisible() && !(await page.locator('#usage-form-markup-group').isVisible()), 'resale shows the resale price and hides the markup');
  await page.selectOption('#usage-form-field-pricing', 'markup');
  await page.fill('#usage-form-field-markup', '20');
  ok((await page.locator('#pricing-preview-billable').innerText()).startsWith('7.20') && await page.locator('#usage-form-markup-group').isVisible() && !(await page.locator('#usage-form-resale-group').isVisible()), 'markup 20 %: 7.20, the markup field shows and the resale price hides');
  await page.selectOption('#usage-form-field-unit', 'months'); await page.fill('#usage-form-field-quantity', '1'); await page.fill('#usage-form-field-unit-cost', '180');
  ok((await page.locator('#pricing-preview-billable').innerText()).startsWith('216.00'), 'one month at 180 +20 %: 216.00');
  await page.screenshot({ path: `${SHOTS}/phone-usage-new.png` });
  await page.click('#usage-form-save-btn');
  await landed(page, /\/usage\/\d+\?notice=saved/);
  phoneCharge = /\/usage\/(\d+)/.exec(page.url())[1];
  ok((await page.locator('#usage-view-status').innerText()) === 'Draft' && (await page.locator('#usage-money-billable').innerText()).startsWith('216.00') && (await page.locator('#usage-money-cost').innerText()).includes('180.00'), 'saved: a Draft, billable 216.00, cost 180.00');
  await widthOK(page, 'the charge', 375); await touch(page, 'the charge');
  await page.screenshot({ path: `${SHOTS}/phone-usage-view.png` });
  // the list: cards on the phone, the table hidden
  await go(page, '/usage/');
  ok(await page.locator('#usage-list-cards').isVisible() && !(await page.locator('#usage-list-table').isVisible()) && await page.locator('#charge-card-' + phoneCharge).isVisible(), 'the list on the phone: cards, the desktop table hidden');
  await widthOK(page, 'AI usage', 375); await touch(page, 'AI usage');
  await page.screenshot({ path: `${SHOTS}/phone-usage-list.png` });
  // the import, three steps
  await go(page, '/usage/import');
  ok(await page.locator('#import-step-1.bg-primary').count() === 1, 'step 1 is the current step');
  await widthOK(page, 'the import (step 1)', 375); await touch(page, 'the import (step 1)');
  await page.selectOption('#import-field-provider', anthropicValue);
  await page.selectOption('#import-field-engagement', acmeValue);
  await page.setInputFiles('#import-field-file', CSV);
  await page.click('#import-preview-btn');
  await page.waitForSelector('#import-preview');
  ok(await page.locator('#import-step-2.bg-primary').count() === 1 && await page.locator('#import-mapping').count() === 1, 'step 2: the mapping and the preview');
  ok((await page.locator('#import-field-map-date').inputValue()) === 'usage_date_utc' && (await page.locator('#import-field-map-quantity').inputValue()) === 'total_tokens' && (await page.locator('#import-field-map-cost').inputValue()) === 'cost_usd', 'the provider\'s saved mapping is prefilled');
  ok((await page.locator('#import-count-new').innerText()) === '3' && (await page.locator('#import-count-duplicate').innerText()) === '0' && await page.locator('#import-preview tbody tr').count() === 3, 'the preview lists the three rows; 3 new, 0 duplicates');
  await widthOK(page, 'the import (step 2)', 375); await touch(page, 'the import (step 2)');
  await page.screenshot({ path: `${SHOTS}/phone-usage-import-preview.png` });
  await page.click('#import-run-btn');
  await landed(page, /\/usage\/\?import=\d+&notice=imported/);
  ok(await page.locator('#usage-list-import').count() === 1 && await page.locator('#usage-list-cards .card').count() === 3 && (await page.locator('#notice-banner').innerText()).includes('Imported'), 'step 3: three draft charges on the list, the import named above it');
  await widthOK(page, 'the imported list', 375);
  await page.screenshot({ path: `${SHOTS}/phone-usage-imported.png` });
  // the statement
  await go(page, '/usage/os');
  ok(await page.locator('#os-months-cards').isVisible() && !(await page.locator('#os-months-table').isVisible()), 'the OS statement on the phone: a card per month, the table hidden');
  await page.click('#os-pull-btn');
  await landed(page, /\/usage\/os\?notice=pulled/);
  ok(await page.locator('#os-months-cards .card').count() >= 3 && (await page.locator('#os-months-cards').innerText()).includes('2026-07'), 'Pull: July appears, closed in the OS');
  await go(page, '/usage/os?period=2026-07');
  ok(await page.locator('#statement-lines-cards').isVisible() && !(await page.locator('#statement-lines-table').isVisible()) && await page.locator('#statement-lines-cards .card').count() === 2, 'the lines as cards on the phone (the table hidden)');
  ok(await page.locator('#os-allocate-btn').isVisible(), 'Allocate is offered on a closed month');
  await widthOK(page, 'the OS statement', 375); await touch(page, 'the OS statement');
  await page.screenshot({ path: `${SHOTS}/phone-usage-os.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the statement's table, Allocate, Approve, Void, a schedule, a rule ----
{
  const { ctx, page, errors } = await session(27, DESK);
  console.log('1280 x 800 — a Manager');
  await go(page, '/usage/os?period=2026-07');
  ok(await page.locator('#statement-lines-table').isVisible() && !(await page.locator('#statement-lines-cards').isVisible()) && await page.locator('#statement-lines-table tbody tr').count() === 2, 'the statement\'s lines are a table on the desktop (the cards hidden)');
  await widthOK(page, 'the OS statement', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-usage-os.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#os-allocate-btn');
  await landed(page, /notice=allocated/);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#os-allocate-btn').textContent()).trim() === 'Re-allocate' && await page.locator('#os-charges .list-group-item[id^="os-charge-"]').count() >= 2, 'Allocate: the month is allocated (the button says Re-allocate), its charges are listed');
  ok((await page.locator('[id$="-remainder"]').first().innerText()).length > 0, 'the remainders show');
  await page.screenshot({ path: `${SHOTS}/desktop-usage-allocated.png` });
  // approve one of the charges the statement made
  const firstCharge = await page.locator('#os-charges .list-group-item[id^="os-charge-"] a').first().getAttribute('href');
  await go(page, firstCharge.split('?')[0]);
  ok(await page.locator('#usage-pieces').count() === 1 && (await page.locator('#usage-source-statement').innerText()).includes('2026-07'), 'a charge from the statement shows the statement and its pieces');
  page.once('dialog', (d) => d.accept());
  await page.click('#usage-view-approve-btn');
  await landed(page, /notice=approved/);
  ok((await page.locator('#usage-view-status').innerText()) === 'Approved' && await page.locator('#usage-view-approve-btn').count() === 0, 'Approve: Approved, no Approve button any more');
  // void the phone's draft
  await go(page, '/usage/' + phoneCharge);
  await page.fill('#usage-view-void-reason-field', 'SMOKE browser void');
  await page.click('#usage-view-void-btn');
  await landed(page, /notice=voided/);
  ok((await page.locator('#usage-view-status').innerText()) === 'Void' && (await page.locator('#usage-view-void-reason').innerText()).includes('SMOKE browser void'), 'Void with a reason: Void, the reason shown');
  await widthOK(page, 'the charge', 1280);
  // the list as a table
  await go(page, '/usage/');
  ok(await page.locator('#usage-list-table').isVisible() && !(await page.locator('#usage-list-cards').isVisible()) && await page.locator('#usage-list-table tbody tr').count() >= 5, 'the list on the desktop: a table of rows, the cards hidden');
  ok(await page.locator('#usage-total-USD').count() === 1, 'with the totals per currency');
  await page.selectOption('#usage-filter-status', 'void');
  await page.click('#usage-filter-btn');
  await landed(page, /status=void/);
  ok(await page.locator('#charge-row-' + phoneCharge).count() === 1 && await page.locator('#usage-list-table tbody tr').count() === await page.locator('#usage-list-table tbody tr [id$="-status"]:text-is("Void")').count(), 'the filter: only void charges, the one just voided among them');
  await widthOK(page, 'AI usage', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-usage-list.png` });
  // a schedule through the screens
  await go(page, '/settings/schedules/new');
  await page.selectOption('#schedule-form-field-engagement', acmeValue);
  await page.fill('#schedule-form-field-description', 'SMOKE browser VM');
  await page.fill('#schedule-form-field-unit-cost', '75');
  const lastMonth = new Date(); lastMonth.setDate(1); lastMonth.setMonth(lastMonth.getMonth() - 1);
  await page.fill('#schedule-form-field-next', lastMonth.toISOString().slice(0, 10));
  await page.click('#schedule-form-save-btn');
  await landed(page, /settings\/schedules\/\?notice=saved/);
  ok(await page.locator('div.card[id^="schedule-row-"]', { hasText: 'SMOKE browser VM' }).locator('[id$="-due"]').count() === 1, 'a schedule added: its card says it is due');
  await page.screenshot({ path: `${SHOTS}/desktop-schedules.png` });
  await page.locator('div.card[id^="schedule-row-"]', { hasText: 'SMOKE browser VM' }).locator('button[id$="-run-btn"]').click();
  await landed(page, /notice=ran/);
  ok(await page.locator('#notice-banner').count() === 1 && (await page.locator('div.card[id^="schedule-row-"]', { hasText: 'SMOKE browser VM' }).innerText()).includes('1 run'), 'Run now: a draft was made, the card says it ran once');
  // a rule through the screens
  await go(page, '/settings/allocation-rules/new');
  ok(await page.locator('#rule-form-field-agent option').count() >= 3 && (await page.locator('#rule-form-field-agent').innerText()).includes('Researcher'), 'the rule form offers the agents the statement carried, by name');
  await page.fill('#rule-form-field-name', 'SMOKE browser rule');
  await page.selectOption('#rule-form-field-agent', { label: 'Writer (#902)' });
  await page.selectOption('#rule-form-field-engagement', acmeValue);
  await page.click('#rule-form-save-btn');
  await landed(page, /allocation-rules\/\?notice=saved/);
  ok((await page.locator('#allocation-rule-list-cards').innerText()).includes('SMOKE browser rule') && (await page.locator('#allocation-rule-list-cards').innerText()).includes('Agent Writer'), 'a rule added: a card naming the agent');
  await widthOK(page, 'the rules', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-rules.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, PHONE);
  console.log('A Consultant');
  const r = await page.goto(BASE + '/usage/', { waitUntil: 'networkidle' });
  ok(r.status() === 403 && (await page.locator('body').innerText()).includes('You may not record usage charges.'), 'Priya opens /usage/: 403 in words');
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/usage/new');
  await page.selectOption('#usage-form-field-engagement', acmeValue);
  await page.fill('#usage-form-field-description', 'SMOKE no-js charge');
  await page.fill('#usage-form-field-unit-cost', '42');
  await page.locator('#usage-form-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/\/usage\/\d+\?notice=saved/.test(page.url()) && (await page.locator('#usage-view-status').innerText()) === 'Draft', 'a plain form POST saves the draft and lands on it');
  await page.locator('#usage-view-approve-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok((await page.locator('#usage-view-status').innerText()) === 'Approved', 'and a plain Approve approves');
  await go(page, '/usage/import');
  await page.selectOption('#import-field-provider', anthropicValue);
  await page.selectOption('#import-field-engagement', acmeValue);
  fs.writeFileSync(CSV, 'usage_date_utc,model,total_tokens,cost_usd\n2027-02-01,claude-sonnet,1000000,3.00\n');
  await page.setInputFiles('#import-field-file', CSV);
  await page.locator('#import-preview-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(await page.locator('#import-preview').count() === 1 && (await page.locator('#import-count-new').innerText()) === '1', 'a plain Preview shows step 2 with the preview');
  await page.locator('#import-run-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/notice=imported/.test(page.url()), 'and a plain Import makes the draft');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
