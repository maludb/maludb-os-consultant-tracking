// Headless Chromium proof of slice 6 (docs/build-specs/invoicing.md, "Proof", 9): the lines editor usable on the phone; the client's secure page first-class at 375 px WITH NO SESSION (the detail collapsible per section, the claim
// button >= 44 px, the claim made, print offered); the list as cards on the phone and a table on the desktop; Issue, Send, Confirm a claim and the aging through the screens at 1280; every button >= 44 px, scrollWidth = viewport, no console
// errors; JavaScript off still drafts, issues and sends. Run through tests/phase3/slice6/run.sh (servers up, after the other proof files). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s6';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (404|422|403|429)/.test(m.text())) errors.push(m.text()); });   // a refused save answers 422, a refused screen 403, the dead link 404 — all by design
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  if (member !== null) { await page.goto(mint(member), { waitUntil: 'networkidle' }); }
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label, scope = '#page-content') => {
  const small = await page.evaluate((scope) => [...document.querySelectorAll(`${scope} a.btn, ${scope} button.btn, ${scope} select.form-select, ${scope} input.form-control:not(.form-control-sm), ${scope} summary`)]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.closest('.input-group-sm') && !e.classList.contains('btn-link') && e.type !== 'date' && e.type !== 'number' && e.type !== 'hidden' && (e.tagName !== 'SUMMARY' || e.closest('#secure-page')); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)), scope);
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const landed = async (page, re) => { try { await page.waitForURL(re, { timeout: 10000 }); } catch (e) { console.log('   (no landing on ' + re + '; url ' + page.url() + '; flash: ' + (await page.locator('#flash').innerText().catch(() => '')).slice(0, 200) + ')'); throw e; } };
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const sh = (c) => execSync(c, { encoding: 'utf8', env: process.env }).trim();
const psql = (sql) => sh(`sudo -n -u postgres psql -At -d ${process.env.DB_NAME} -c ${JSON.stringify(sql)}`);
// what ct_unbilled() answers for an engagement as the super-admin (its visibility is the acting member's, so the member is set in the same session)
const unbilledOf = (eng) => sh(`sudo -n -u postgres psql -At -d ${process.env.DB_NAME} -c "SELECT set_config('app.member_id', '1', false)" -c ${JSON.stringify(`SELECT COALESCE(sum(total_amount), 0) FROM ct_unbilled(current_date, ${eng}, NULL)`)}`).split('\n').pop();
const beta = psql("SELECT id FROM engagements WHERE name = 'SMOKE Time Beta'");
const betaClient = psql(`SELECT client_id FROM engagements WHERE id = ${beta}`);
// what is unbilled on Beta (the milestone M2 released by the JSON proof) makes the draft; nothing is invented here
const wip = unbilledOf(beta);
let draftId = null, invoiceNumber = null, token = null;

// ---- phone: the manager drafts and edits ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  await go(page, '/invoices/new?engagement=' + beta);
  ok(await page.locator('#invoice-form').count() === 1 && (await page.locator('#invoice-form-field-engagement').inputValue()) === beta, 'the draft form renders with the engagement prefilled');
  ok(await page.locator('#invoice-form-field-add').isChecked(), 'Add the unbilled items is checked');
  await widthOK(page, 'Draft an invoice', 375); await touch(page, 'Draft an invoice');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-new.png` });
  await page.fill('#invoice-form-field-period-end', '2026-09-30');
  await page.click('#invoice-form-save-btn');
  await landed(page, /\/invoices\/\d+$/);
  draftId = /\/invoices\/(\d+)/.exec(page.url())[1];
  ok((await page.locator('#invoice-view-status').innerText()).trim() === 'Draft' && (await page.locator('#totals-total').innerText()).startsWith(Number(wip).toLocaleString('en-US', { minimumFractionDigits: 2 })), 'drafted: a Draft, the total is the unbilled work (' + wip + ')');
  ok(await page.locator('#invoice-lines-table tbody tr[id^="line-"]').count() >= 1, 'the lines are a table with the pulled line');
  ok(await page.locator('#invoice-view-issue-btn').count() === 0 && await page.locator('#invoice-view-edit-btn').count() === 1, 'a Manager is offered Edit the lines, never Issue');
  await widthOK(page, 'the draft', 375); await touch(page, 'the draft');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-view.png` });
  await go(page, '/invoices/' + draftId + '/edit');
  ok(await page.locator('#totals-strip').isVisible() && await page.locator('#invoice-edit-add-unbilled-btn').isVisible() && await page.locator('#invoice-line-form').isVisible(), 'the lines editor: the totals strip, Pull the unbilled items and Add a line');
  await widthOK(page, 'the lines editor', 375); await touch(page, 'the lines editor');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-edit.png` });
  const before = await page.locator('#totals-total').innerText();
  await page.fill('#invoice-line-field-description', 'SMOKE browser fixed line');
  await page.fill('#invoice-line-field-price', '250');
  await page.click('#invoice-line-save-btn');
  await landed(page, /\/invoices\/\d+\/edit\?notice=line_saved/);
  ok((await page.locator('#invoice-lines-table').innerText()).includes('SMOKE browser fixed line') && (await page.locator('#totals-total').innerText()) !== before, 'a fixed line added: it is in the table and the totals strip moved (the database added it)');
  await widthOK(page, 'the lines editor after a line', 375);
  // the list: cards on the phone, the table hidden
  await go(page, '/invoices/');
  ok(await page.locator('#invoice-list-cards').isVisible() && !(await page.locator('#invoice-list-table').isVisible()) && await page.locator('#invoice-card-' + draftId).isVisible(), 'the list on the phone: cards, the desktop table hidden');
  await widthOK(page, 'the invoice list', 375); await touch(page, 'the invoice list');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-list.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Billing issues and sends ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Billing');
  await go(page, '/invoices/' + draftId);
  ok(await page.locator('#invoice-view-issue-btn').isVisible(), 'Billing is offered Issue on the draft');
  await page.screenshot({ path: `${SHOTS}/desktop-invoice-draft.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#invoice-view-issue-btn');
  await landed(page, /\/invoices\/\d+\?notice=issued/);
  invoiceNumber = (await page.locator('#invoice-view-number').innerText()).trim();
  ok(/^INV-\d{5}$/.test(invoiceNumber) && (await page.locator('#invoice-view-status').innerText()).trim() === 'Issued', 'Issue: numbered ' + invoiceNumber + ', status Issued');
  ok((await page.locator('#link-state-badge').innerText()).trim() === 'Live' && !(await page.content()).match(/\/i\/[a-f0-9]{48}/), 'the link is live — and no link to /i/ is anywhere on the page (only its state)');
  ok(await page.locator('#invoice-view-edit-btn').count() === 0 && await page.locator('#invoice-view-send-btn').isVisible(), 'issued: no Edit, Send offered');
  page.once('dialog', (d) => d.accept());
  await page.fill('#invoice-view-send-message', 'SMOKE browser hello');
  await page.click('#invoice-view-send-btn');
  await landed(page, /\/invoices\/\d+\?notice=sent/);
  ok((await page.locator('#invoice-view-status').innerText()).trim() === 'Sent', 'Send: queued for the contacts, the status is Sent');
  await widthOK(page, 'the issued invoice', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-invoice-view.png` });
  token = /\/i\/([a-f0-9]{48})/.exec(psql(`SELECT body FROM notification_outbox WHERE kind = 'invoice' AND record_id = ${draftId} ORDER BY id DESC LIMIT 1`))?.[1] ?? null;
  ok(token !== null, 'the mail the worker will send holds the link (read from the outbox)');
  // the preview is the client's page
  await go(page, '/invoices/' + draftId + '/preview');
  ok(await page.locator('#secure-invoice').isVisible() && (await page.locator('#secure-invoice-number').innerText()).includes(invoiceNumber), 'the preview is the client\'s document');
  // the list as a table
  await go(page, '/invoices/');
  ok(await page.locator('#invoice-list-table').isVisible() && !(await page.locator('#invoice-list-cards').isVisible()) && await page.locator('#invoice-row-' + draftId).count() === 1, 'the list on the desktop: a table of rows, the cards hidden');
  ok(await page.locator('#invoice-total-USD').count() === 1, 'with the totals footer per currency');
  await widthOK(page, 'the invoice list', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-invoice-list.png` });
  await go(page, '/reports/ar-aging');
  ok(await page.locator('#ar-aging-table').isVisible() && await page.locator('#aging-total-USD').count() === 1, 'who owes us: the five buckets as a table with a total row');
  await widthOK(page, 'the aging', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-aging.png` });
  await go(page, '/');
  ok(await page.locator('#home-billing').isVisible() && await page.locator('#home-billing-unbilled').count() === 1, 'the home\'s Billing region');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- the client: no session, 375 px first ----
{
  const { ctx, page, errors } = await session(null, PHONE);
  console.log('375 x 740 — the client, no session');
  await page.goto(BASE + '/i/' + token, { waitUntil: 'networkidle' });
  ok(await page.locator('#secure-invoice').isVisible() && (await page.locator('#secure-invoice-number').innerText()).includes(invoiceNumber), 'the invoice opens from the link');
  ok(await page.locator('#app-tabbar').count() === 0 && await page.locator('#left-sidenav').count() === 0, 'no application chrome: no menu, no tab bar');
  await widthOK(page, 'the secure page', 375);
  await touch(page, 'the secure page', '#secure-page');
  const det = page.locator('#secure-detail-milestones');
  if (await det.count() === 1) {
    ok(!(await det.evaluate((d) => d.open)), 'the detail is collapsed by default, a section at a time');
    await det.locator('summary').click();
    ok(await det.evaluate((d) => d.open) && await det.locator('table').isVisible(), 'a tap on the section opens it');
  } else {
    ok(await page.locator('details[id^="secure-detail-"]').count() >= 1, 'the backing detail is in collapsible sections');
  }
  const btn = await page.locator('#claim-submit-btn').boundingBox();
  ok(btn !== null && btn.height >= 44, 'the claim button is at least 44 px tall (' + Math.round(btn?.height ?? 0) + ')');
  ok(await page.locator('#secure-print-btn').isVisible(), 'Print or save as PDF is offered');
  await page.screenshot({ path: `${SHOTS}/phone-secure-invoice.png`, fullPage: true });
  await page.fill('#claim-field-reference', 'SMOKE-BROWSER-WIRE');
  await page.click('#claim-submit-btn');
  await landed(page, /\/i\/[a-f0-9]{48}\?thanks=1/);
  ok(await page.locator('#claim-thanks').isVisible() && (await page.locator('#claim-thanks').innerText()).includes('we will confirm when it shows'), 'the claim: "Thank you — we will confirm when it shows."');
  await widthOK(page, 'the page after the claim', 375);
  await page.screenshot({ path: `${SHOTS}/phone-secure-claimed.png` });
  const printPage = await ctx.newPage();
  await printPage.goto(BASE + '/i/' + token + '/print', { waitUntil: 'networkidle' });
  ok(await printPage.locator('#secure-invoice').isVisible() && await printPage.locator('#claim-form').count() === 0, 'the print page: the invoice, no claim form');
  await printPage.emulateMedia({ media: 'print' });
  ok(!(await printPage.locator('#print-now-btn').isVisible()), 'in print media the Print button is gone');
  await printPage.close();
  const dead = await ctx.newPage();
  const r = await dead.goto(BASE + '/i/' + 'ab'.repeat(24), { waitUntil: 'networkidle' });
  ok(r.status() === 404 && (await dead.locator('body').innerText()).includes('This link has expired.'), 'a dead link: the one page');
  await widthOK(dead, 'the dead-link page', 375);
  await dead.close();
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(null, DESK);
  console.log('1280 x 800 — the client on a desktop');
  await page.goto(BASE + '/i/' + token, { waitUntil: 'networkidle' });
  ok(await page.locator('#secure-invoice').isVisible(), 'the page at 1280');
  await widthOK(page, 'the secure page', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-secure-invoice.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- Billing confirms the claim ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — Billing decides the claim');
  await go(page, '/billing/claims');
  const card = page.locator('div.card[id^="claim-"]', { hasText: 'SMOKE-BROWSER-WIRE' });
  ok(await card.count() === 1, 'the claim is in the inbox');
  await page.screenshot({ path: `${SHOTS}/desktop-claims.png` });
  page.once('dialog', (d) => d.accept());
  await card.locator('button[id$="-confirm-btn"]').click();
  await landed(page, /\/billing\/claims\?notice=confirmed/);
  ok(await page.locator('#claims-empty').count() === 1, 'Confirm: the inbox is empty');
  await go(page, '/invoices/' + draftId);
  ok((await page.locator('#invoice-view-status').innerText()).trim() === 'Paid' && await page.locator('#invoice-payments [id^="payment-"]').count() >= 1, 'the invoice is Paid (derived), with its payment listed');
  await go(page, '/payments/');
  ok(await page.locator('#payment-list-table').isVisible(), 'the payments list');
  await widthOK(page, 'payments', 1280);
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}
{
  const { ctx, page } = await session(26, PHONE);
  console.log('A Consultant');
  const r = await page.goto(BASE + '/invoices/', { waitUntil: 'networkidle' });
  ok(r.status() === 403, 'Priya opens /invoices/: 403');
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  q2: {
    const next = unbilledOf(beta);
    await go(page, '/invoices/new?engagement=' + beta);
    await page.fill('#invoice-form-field-memo', 'SMOKE no-js draft');
    await page.locator('#invoice-form-save-btn').click({ force: true });
    await page.waitForLoadState('load');
    ok(/\/invoices\/\d+/.test(page.url()) && (await page.locator('#invoice-view-status').innerText()).trim() === 'Draft', 'a plain form POST drafts the invoice and lands on it (unbilled now ' + next + ')');
    const id = /\/invoices\/(\d+)/.exec(page.url())[1];
    await go(page, '/invoices/' + id + '/edit');
    ok(await page.locator('#invoice-line-form').count() === 1, 'the lines editor works without JavaScript');
    await page.fill('#invoice-line-field-description', 'SMOKE no-js line');
    await page.fill('#invoice-line-field-price', '10');
    await page.locator('#invoice-line-save-btn').click({ force: true });
    await page.waitForLoadState('load');
    ok((await page.locator('#invoice-lines-table').innerText()).includes('SMOKE no-js line'), 'and a plain Add a line adds it');
    await page.locator('#invoice-edit-delete-btn').click({ force: true });
    await page.waitForLoadState('load');
    ok(/\/invoices\/\?notice=deleted/.test(page.url()), 'and a plain Delete removes the draft');
  }
  await ctx.close();
}
{
  const { ctx, page } = await session(28, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off — Billing');
  await go(page, '/invoices/new?engagement=' + psql("SELECT id FROM engagements WHERE name = 'SMOKE Time Acme'"));
  await page.fill('#invoice-form-field-po', 'PO-NOJS');
  await page.uncheck('#invoice-form-field-add');
  await page.locator('#invoice-form-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  const id = /\/invoices\/(\d+)/.exec(page.url())[1];
  await go(page, '/invoices/' + id + '/edit');
  await page.fill('#invoice-line-field-description', 'SMOKE no-js fee');
  await page.fill('#invoice-line-field-price', '100');
  await page.locator('#invoice-line-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  await go(page, '/invoices/' + id);
  await page.locator('#invoice-view-issue-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/notice=issued/.test(page.url()) && /^INV-\d{5}$/.test((await page.locator('#invoice-view-number').innerText()).trim()), 'a plain Issue numbers the invoice');
  await page.locator('#invoice-view-send-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/notice=sent/.test(page.url()) && (await page.locator('#invoice-view-status').innerText()).trim() === 'Sent', 'and a plain Send queues it');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
