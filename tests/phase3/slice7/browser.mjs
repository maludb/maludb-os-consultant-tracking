// Headless Chromium proof of slice 7 (docs/build-specs/reports.md, "Proof", 5): the reports index as cards, every report page at 375 x 740 and 1280 x 800 — the table inside its scrolling wrapper (the PAGE never scrolls sideways), the filter row wrapping,
// every control at least 44 px, no console errors — the CSV button downloads exactly the page's cells, a Consultant sees one card and her own row, the period presets land, and a refused period says so. Run through tests/phase3/slice7/run.sh
// (servers up, after the other proof files). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s7';
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
const monday = (weeksAgo) => psql(`SELECT ct_week_start(current_date) - ${7 * weeksAgo}`);
const w1 = monday(3);
const addDays = (d, n) => { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
const whole = `from=2026-09-01&to=${addDays(monday(0), 6)}`;
const reports = [
  ['utilization', `from=${w1}&to=${addDays(w1, 6)}`, 'SMOKE Priya'], ['realization', whole, 'ENG-0001'], ['wip', '', 'ENG-0001'], ['revenue', whole + '&by=kind', 'Usage'], ['margin', whole, '1,500.00 USD'],
  ['budget-burn', '', 'ENG-0002'], ['ai-passthrough', whole, 'Anthropic'], ['expenses', whole, 'Meals'], ['missing-time', `week=${w1}`, 'SMOKE Bea'],
];

// ---- the owner at both sizes ----
for (const [vp, name] of [[PHONE, '375 x 740'], [DESK, '1280 x 800']]) {
  const { ctx, page, errors } = await session(1, vp);
  console.log(name + ' — the owner');
  await go(page, '/reports/');
  const cards = await page.locator('#reports-cards .card[id^="report-card-"]').count();
  const rows = await page.evaluate(() => { const tops = [...document.querySelectorAll('#reports-cards > div')].map((e) => Math.round(e.getBoundingClientRect().top)); return new Set(tops).size; });
  ok(cards === 10 && (vp.width < 600 ? rows === 10 : rows <= 4), `the index: ten cards, ${vp.width < 600 ? 'one per row on the phone' : 'in rows on the desktop'} (${rows} rows)`);
  await widthOK(page, 'the index', vp.width); await touch(page, 'the index');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-index.png` });
  for (const [report, q, needle] of reports) {
    await go(page, '/reports/' + report + (q ? '?' + q : ''));
    const title = (await page.locator('#report-header h5').innerText()).trim();
    const has = await page.locator('#report-table').count();
    const text = await page.locator('#report-content').innerText();
    ok(has === 1 && text.includes(needle) && title.length > 0, `${report}: the table renders (${title}) and shows "${needle}"`);
    await widthOK(page, report, vp.width); await touch(page, report);
    const wrap = await page.evaluate(() => { const w = document.querySelector('.table-responsive'); const t = document.querySelector('#report-table'); return w && t ? { cw: w.clientWidth, sw: w.scrollWidth, tw: t.scrollWidth, ov: getComputedStyle(w).overflowX } : null; });
    ok(wrap !== null && wrap.tw <= wrap.sw && ['auto', 'scroll'].includes(wrap.ov) && (vp.width >= 1200 || wrap.sw >= wrap.cw), `${report}: the table sits in a scrolling wrapper (overflow-x ${wrap && wrap.ov}; ${wrap && wrap.sw} wide in ${wrap && wrap.cw})`);
    await page.screenshot({ path: `${SHOTS}/${vp.width}-${report}.png`, fullPage: true });
  }
  if (vp.width < 600) {
    await go(page, '/reports/realization?' + whole);
    const o = await page.evaluate(() => { const w = document.querySelector('.table-responsive'); return { sw: w.scrollWidth, cw: w.clientWidth }; });
    ok(o.sw > o.cw, `on the phone the wide realization table scrolls INSIDE its wrapper (${o.sw} > ${o.cw}) while the page does not`);
    const filt = await page.evaluate(() => { const f = document.querySelector('#report-filters'); return { h: f.getBoundingClientRect().height, w: f.getBoundingClientRect().width }; });
    ok(filt.w <= 375, `the filter row wraps inside the phone (${Math.round(filt.w)} px wide, ${Math.round(filt.h)} px tall)`);
  }
  ok(errors.length === 0, `no console errors at ${name}` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- the download (desktop) ----
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the CSV and the presets');
  await go(page, '/reports/realization?' + whole);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#report-csv-btn')]);
  const body = fs.readFileSync(await dl.path(), 'utf8');
  const cells = await page.evaluate(() => [...document.querySelectorAll('#report-table thead th, #report-table tbody tr, #report-table tfoot tr')].map((r) => r.tagName === 'TH' ? r.innerText.trim() : [...r.querySelectorAll('td')].map((c) => c.innerText.trim())));
  const lines = body.trim().split('\n');
  ok(dl.suggestedFilename() === `realization-2026-09-01_${addDays(monday(0), 6)}.csv`, `the file is named for the report and the period: ${dl.suggestedFilename()}`);
  ok(lines[0] === '"Engagement",Client,Approved,Billed,"Written off",Unbilled,"No rate",Standard,"Billed value","Realization (hours)","Realization (amount)"' || lines[0].startsWith('Engagement,Client,Approved'), 'the first line is the page\'s header');
  ok(body.includes('ENG-0001 SMOKE Time Acme') && body.includes('2,925.00 USD') && body.includes('73.0 %') && lines.length === 3, 'the file holds the page\'s row and totals with their currency (' + lines.length + ' lines)');
  ok(page.url().includes('/reports/realization'), 'the page stays where it was');
  // the presets
  await go(page, '/reports/utilization');
  const first = await page.inputValue('#report-f-from'), last = await page.inputValue('#report-f-to');
  const now = new Date(); const y = now.getUTCFullYear(), m = now.getUTCMonth();
  const ymd = (d) => d.toISOString().slice(0, 10);
  ok(first === ymd(new Date(Date.UTC(y, m, 1))) && last === ymd(new Date(Date.UTC(y, m + 1, 0))), `the default period is this month (${first} to ${last})`);
  await page.click('#report-preset-last_month'); await page.waitForLoadState('networkidle');
  ok(await page.inputValue('#report-f-from') === ymd(new Date(Date.UTC(y, m - 1, 1))) && await page.inputValue('#report-f-to') === ymd(new Date(Date.UTC(y, m, 0))), 'Last month lands on the first and last day of last month');
  await page.click('#report-preset-ytd'); await page.waitForLoadState('networkidle');
  ok(await page.inputValue('#report-f-from') === `${y}-01-01` && await page.inputValue('#report-f-to') === ymd(now), 'Year to date: January 1 to today');
  await page.click('#report-preset-this_quarter'); await page.waitForLoadState('networkidle');
  ok(await page.inputValue('#report-f-from') === ymd(new Date(Date.UTC(y, Math.floor(m / 3) * 3, 1))) && await page.inputValue('#report-f-to') === ymd(new Date(Date.UTC(y, Math.floor(m / 3) * 3 + 3, 0))), 'This quarter: its first and last day');
  // a filter changes the table
  await go(page, '/reports/utilization?' + `from=${w1}&to=${addDays(w1, 6)}`);
  const before = await page.locator('#report-table tbody tr').count();
  await page.selectOption('#report-f-member', { label: 'SMOKE Priya' });
  await page.click('#report-show-btn'); await page.waitForLoadState('networkidle');
  ok(before === 6 && await page.locator('#report-table tbody tr').count() === 1, `choosing a person narrows ${before} rows to 1`);
  // a period that is too long
  const resp = await page.goto(BASE + '/reports/realization?from=2024-01-01&to=2026-10-02', { waitUntil: 'networkidle' });
  ok(resp.status() === 422 && (await page.content()).includes('at most 24 months'), 'a 25-month period: the page says so (422)');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- a Consultant on the phone ----
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — a Consultant');
  await go(page, '/reports/');
  ok(await page.locator('#reports-cards .card[id^="report-card-"]').count() === 1 && await page.locator('#report-card-utilization').count() === 1, 'the index shows one card');
  await go(page, `/reports/utilization?from=${w1}&to=${addDays(w1, 6)}`);
  const text = await page.locator('#report-content').innerText();
  ok(text.includes('SMOKE Priya') && !text.includes('SMOKE Bea') && text.includes('17.5 %') && await page.locator('#report-csv-btn').count() === 0, 'her own row, 17.5 %, no one else, no CSV button');
  await widthOK(page, 'her utilization', 375); await touch(page, 'her utilization');
  await page.screenshot({ path: `${SHOTS}/375-priya-utilization.png`, fullPage: true });
  const r = await page.goto(BASE + '/reports/realization', { waitUntil: 'networkidle' });
  ok(r.status() === 403 && (await page.content()).includes('You may not see reports.'), 'another report: 403 in words');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- a Manager: his scope ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  await go(page, '/reports/margin?' + whole);
  ok((await page.locator('#report-scope').innerText()).includes('Your engagements') && (await page.locator('#report-content').innerText()).includes('1,500.00 USD') && await page.locator('#report-csv-btn').count() === 0, 'margin says "Your engagements", shows his figures, offers no CSV');
  await widthOK(page, 'his margin', 375); await touch(page, 'his margin');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: a plain GET form still filters ----
{
  const { ctx, page } = await session(28, DESK, { javaScriptEnabled: false });
  console.log('JavaScript off — Billing');
  await go(page, '/reports/expenses?' + whole);
  await page.selectOption('#report-f-status', 'all');
  await page.locator('#report-show-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(page.url().includes('status=all') && await page.locator('#report-table').count() === 1, 'the filter form is a plain GET: it works without JavaScript');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
