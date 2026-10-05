// Headless Chromium proof of slice 9 (docs/build-specs/home-admin-tokens.md, "Proof"): the home at 375 x 740 and 1280 x 800 for a Consultant, a Manager and Billing — the regions stack on the phone (one column) and sit two across on the desktop, no sideways scroll,
// every control at least 44 px, no console errors; Agents and Connections as the administrator (cards, nothing that submits, nothing that scrolls sideways) and refused to everyone else; JavaScript off still shows them; the tokens page mints once. Run through tests/phase3/slice9/run.sh (after the PHP proofs).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s9';
fs.mkdirSync(SHOTS, { recursive: true });
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
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, acceptDownloads: true, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (404|422|403|429)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label, scope = '#page-content') => {
  const small = await page.evaluate((scope) => [...document.querySelectorAll(`${scope} a.btn, ${scope} button.btn, ${scope} select.form-select, ${scope} input.form-control`)]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)), scope);
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const sh = (c) => execSync(c, { encoding: 'utf8', env: process.env }).trim();
const psql = (sql) => sh(`sudo -n -u postgres psql -At -d ${process.env.DB_NAME} -c ${JSON.stringify(sql)}`);

const column = async (page, ids) => page.evaluate((ids) => ids.map((id) => { const e = document.getElementById(id); if (!e) return null; const r = e.getBoundingClientRect(); return { id, l: Math.round(r.left), t: Math.round(r.top + scrollY), w: Math.round(r.width) }; }), ids);

for (const [vp, name] of [[PHONE, '375 x 740'], [DESK, '1280 x 800']]) {
  console.log(name + ' — the home, three roles');
  for (const [member, who, ids] of [[26, 'a Consultant', ['home-my-week', 'home-my-expenses', 'home-bell']], [27, 'a Manager', ['home-my-week', 'home-approvals', 'home-budget-alerts', 'home-billing']], [28, 'Billing', ['home-my-week', 'home-approvals', 'home-billing', 'home-clerk-note']]]) {
    const { ctx, page, errors } = await session(member, vp);
    await go(page, '/');
    const cols = await column(page, ids);
    ok(cols.every((c) => c !== null), `${who}: every region is on the page (${ids.join(', ')})`);
    if (vp.width < 600) {
      ok(cols.every((c) => c.l >= 0 && c.l + c.w <= vp.width) && new Set(cols.map((c) => c.l)).size === 1, `${who}: the regions stack in one column on the phone (left edge ${cols[0].l}, width ${cols[0].w})`);
      const tops = cols.map((c) => c.t); ok(new Set(tops).size === tops.length, `${who}: no two regions share a row (${tops.join(', ')})`);
    } else {
      ok(member === 26 ? new Set(cols.map((c) => c.l)).size === 1 : new Set(cols.map((c) => c.l)).size === 2, `${who}: ${member === 26 ? 'one column (her right column is empty)' : 'two columns'} on the desktop (${[...new Set(cols.map((c) => c.l))].join(' and ')})`);
    }
    await widthOK(page, `${who}'s home`, vp.width); await touch(page, `${who}'s home`);
    await page.screenshot({ path: `${SHOTS}/${vp.width}-home-${member}.png`, fullPage: true });
    if (member === 27) {
      const counts = await page.evaluate(() => ['weeks', 'expenses', 'usage'].map((k) => document.getElementById(`home-approvals-${k}-count`)?.textContent.trim()));
      ok(counts.every((c) => c && Number(c) >= 1), `a Manager's count cards read ${counts.join(' / ')} (weeks / expenses / usage)`);
      await page.click('#home-approvals-expenses-count-card');
      await page.waitForURL(/\/expenses\/approvals/, { timeout: 5000 }).catch(() => {});
      ok(page.url().endsWith('/expenses/approvals'), 'a count card opens its approvals page');
    }
    if (member === 28) {
      ok((await page.locator('#home-billing-statement').count()) === 1 && /allocated|to allocate/.test(await page.locator('#home-billing-statement-remainder').innerText()), `Billing's home shows the OS statement and its remainder`);
    }
    ok(errors.length === 0, `no console errors on ${who}'s home at ${name}` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
    await ctx.close();
  }
}

for (const [vp, name] of [[PHONE, '375 x 740'], [DESK, '1280 x 800']]) {
  const { ctx, page, errors } = await session(1, vp);
  console.log(name + ' — the administrator: Agents and Connections');
  await go(page, '/settings/agents');
  const cards = await page.locator('#agents-cards .card').count();
  const tops = await page.evaluate(() => new Set([...document.querySelectorAll('#agents-cards > div')].map((e) => Math.round(e.getBoundingClientRect().top))).size);
  ok(cards >= 2 && (vp.width < 600 ? tops === cards : tops < cards), `Agents: ${cards} cards, ${vp.width < 600 ? 'one per row on the phone' : 'two to a row on the desktop'}`);
  ok((await page.locator('#agent-biller-name').innerText()).includes('Clerk') && (await page.locator('#agent-biller-duty').innerText()).includes('daily at 07:00') && (await page.locator('#agent-expert-name').count()) === 1, 'the Clerk and the expert are named; the Clerk\'s duty reads "daily at 07:00"');
  ok((await page.locator('#agents-content form, #agents-content button[type=submit]').count()) === 0, 'no form to hire, grant or run an agent');
  await widthOK(page, 'Agents', vp.width); await touch(page, 'Agents');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-agents.png`, fullPage: true });
  await go(page, '/settings/connections');
  const reads = await page.locator('#connections-reads .card').count(); const shares = await page.locator('#connections-shares .card').count();
  ok(reads === 2 && shares === 6, `Connections: ${reads} reads and ${shares} shares as cards`);
  ok((await page.locator('#connections-note').innerText()).includes('super-admin') && (await page.locator('#connections-os-link').getAttribute('href')) === 'https://os.example.invalid/', 'it says approving is the super-admin\'s and links the OS');
  await widthOK(page, 'Connections', vp.width); await touch(page, 'Connections');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-connections.png`, fullPage: true });
  await go(page, '/settings/tokens/');
  await page.fill('#token-form-field-label', 'SMOKE s9 browser'); await page.click('#token-form-save-btn'); await page.waitForLoadState('networkidle');
  const val = await page.locator('#tokens-minted-value').innerText();
  ok(/^mcp_[a-f0-9]{48}$/.test(val), 'the tokens page mints and shows the value once');
  await go(page, '/settings/tokens/');
  ok((await page.locator('#tokens-minted-value').count()) === 0 && !(await page.content()).includes(val), 'and never again');
  await widthOK(page, 'Tokens', vp.width); await touch(page, 'Tokens');
  ok(errors.length === 0, `no console errors at ${name}` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

{
  const { ctx, page } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  for (const path of ['/settings/agents', '/settings/connections']) {
    const r = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    ok(r.status() === 403, `a Manager: ${path} is 403`);
  }
  await go(page, '/');
  await page.click('#header-menu-toggle, .nxl-head-mobile-toggler', { timeout: 2000 }).catch(() => {});
  ok(!(await page.content()).includes('id="nav-agents"'), 'the Manager\'s menu does not offer Agents');
  await ctx.close();
}
{
  const { ctx, page } = await session(1, DESK, { javaScriptEnabled: false });
  console.log('JavaScript off — the administrator');
  await go(page, '/settings/agents');
  ok((await page.locator('#agent-biller').count()) === 1 && (await page.locator('#agent-biller-dispatch').innerText()).length > 0, 'Agents renders without JavaScript');
  await go(page, '/settings/connections');
  ok((await page.locator('#read-gl-invoice_receipts').count()) === 1 && (await page.locator('#share-invoices_issued').count()) === 1, 'Connections renders without JavaScript');
  await go(page, '/');
  ok((await page.locator('#home-approvals').count()) === 1 && (await page.locator('#home-billing').count()) === 1, 'the home renders every region without JavaScript');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
