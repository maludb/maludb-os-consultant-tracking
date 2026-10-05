// Headless Chromium proof of slice 8 (docs/build-specs/worker-doors-shares.md, "Proof"): the exports page at 375 x 740 and 1280 x 800 — seven cards (one a row on the phone), the PAGE never scrolls sideways, every control at least 44 px, no console errors —
// the downloads save a file named for the export and the period whose content is the document (CSV and JSON), the period picker re-aims the buttons, JavaScript off still downloads, a Consultant and a Manager are turned away; Billing's home shows the Clerk's
// morning note (stacked on the phone) and a Manager's does not; the bell lists the note. Run through tests/phase3/slice8/run.sh (servers up, after the PHP proofs). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8301';
const SHOTS = process.env.SHOTS || '/tmp/ct-shots-p3s8';
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
const monday0 = psql('SELECT ct_week_start(current_date)');
const addDays = (d, n) => { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
const period = `from=2026-09-01&to=${addDays(monday0, 6)}`;
if (psql("SELECT count(*) FROM notifications WHERE kind = 'morning_note'") === '0') {
  psql("INSERT INTO notifications (member_id, kind, title, body) VALUES (28, 'morning_note', 'The morning note, Oct 5', E'Drafted 1 invoice: ENG-0001, 620.00 USD.\\nAllocated 2026-08: 4 charges.\\nOverdue: INV-00003.')");
}
const note = psql("SELECT body FROM notifications WHERE kind = 'morning_note' ORDER BY id DESC LIMIT 1");
const noteFirst = note.split('\n')[0];

for (const [vp, name] of [[PHONE, '375 x 740'], [DESK, '1280 x 800']]) {
  const { ctx, page, errors } = await session(28, vp);
  console.log(name + ' — Billing');
  await go(page, '/exports?' + period);
  const cards = await page.locator('#exports-cards .card[id^="export-card-"]').count();
  const rows = await page.evaluate(() => new Set([...document.querySelectorAll('#exports-cards > div')].map((e) => Math.round(e.getBoundingClientRect().top))).size);
  ok(cards === 7 && (vp.width < 600 ? rows === 7 : rows === 3), `the page: seven cards, ${vp.width < 600 ? 'one per row on the phone' : 'three to a row on the desktop'} (${rows} rows)`);
  ok((await page.locator('#exports-f-from').inputValue()) === '2026-09-01' && (await page.locator('#exports-f-to').inputValue()) === addDays(monday0, 6), 'the period picker shows the period asked');
  await widthOK(page, 'the exports page', vp.width); await touch(page, 'the exports page');
  await page.screenshot({ path: `${SHOTS}/${vp.width}-exports.png`, fullPage: true });
  const href = await page.getAttribute('#export-invoices-csv-btn', 'href');
  ok(href.includes('export=invoices') && href.includes('format=csv') && href.includes('from=2026-09-01'), 'a card\'s CSV button carries the export, the format and the period');
  const wipHref = await page.getAttribute('#export-wip-json-btn', 'href');
  ok(wipHref.includes('export=wip') && !wipHref.includes('from=') && wipHref.includes('to='), 'an "as of" export carries only the day');
  if (vp.width >= 1200) {
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#export-invoices-csv-btn')]);
    const body = fs.readFileSync(await dl.path(), 'utf8');
    ok(dl.suggestedFilename() === `ct-invoices-2026-09-01_${addDays(monday0, 6)}.csv`, `the file is named for the export and the period: ${dl.suggestedFilename()}`);
    ok(body.split('\n')[0].startsWith('number,client_client_id,client_name') && body.includes('INV-00001') && body.includes('SMOKE Acme') && body.includes('2479.00'), 'the CSV: the header, INV-00001, its client and total');
    const [dj] = await Promise.all([page.waitForEvent('download'), page.click('#export-expenses-json-btn')]);
    const doc = JSON.parse(fs.readFileSync(await dj.path(), 'utf8'));
    ok(doc.schema === 'os.ct-expenses/1' && Array.isArray(doc.rows) && doc.application === 'consultant_tracking', 'the JSON download is the document: os.ct-expenses/1');
    ok(page.url().includes('/exports'), 'the page stays where it was');
    await page.fill('#exports-f-from', '2026-08-01'); await page.fill('#exports-f-to', '2026-08-31');
    await page.click('#exports-show-btn'); await page.waitForLoadState('networkidle');
    ok((await page.getAttribute('#export-usage-csv-btn', 'href')).includes('from=2026-08-01') && (await page.getAttribute('#export-usage-csv-btn', 'href')).includes('to=2026-08-31'), 'the period picker re-aims every button (Use this period)');
    const resp = await page.goto(BASE + '/exports?from=2023-01-01&to=2026-10-02', { waitUntil: 'networkidle' });
    ok(resp.status() === 422 && (await page.content()).includes('at most 24 months'), 'a period over 24 months: the page says so (422)');
  }
  // home: the Clerk's note
  await go(page, '/');
  const region = await page.locator('#home-clerk-note').count();
  const body = region ? await page.locator('#home-clerk-note-body').innerText() : '';
  ok(region === 1 && body.includes(noteFirst), `Billing's home shows the Clerk's morning note ("${noteFirst.slice(0, 40)}…")`);
  const r = await page.evaluate(() => { const e = document.querySelector('#home-clerk-note'); const b = e.getBoundingClientRect(); return { l: Math.round(b.left), w: Math.round(b.width), vw: innerWidth }; });
  ok(r.l >= 0 && r.l + r.w <= r.vw, `the note card sits inside the ${vp.width} px viewport (${r.l}..${r.l + r.w})`);
  await widthOK(page, 'the home', vp.width);
  await page.screenshot({ path: `${SHOTS}/${vp.width}-home-note.png`, fullPage: true });
  await go(page, '/notifications');
  ok((await page.locator('#notifications-content').innerText()).includes('The morning note'), 'the bell lists the morning note');
  await widthOK(page, 'the bell', vp.width); await touch(page, 'the bell');
  ok(errors.length === 0, `no console errors at ${name}` + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — a Manager');
  const r = await page.goto(BASE + '/exports', { waitUntil: 'networkidle' });
  ok(r.status() === 403 && (await page.content()).includes('You may not download exports.'), 'a Manager: /exports is 403 in words');
  await go(page, '/');
  ok(await page.locator('#home-clerk-note').count() === 0, 'a Manager\'s home has no Clerk region');
  await widthOK(page, 'his home', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, PHONE);
  console.log('375 x 740 — a Consultant');
  const r = await page.goto(BASE + '/exports', { waitUntil: 'networkidle' });
  ok(r.status() === 403, 'a Consultant: /exports is 403');
  const r2 = await page.goto(BASE + '/exports/download.php?export=invoices&format=csv', { waitUntil: 'networkidle' });
  ok(r2.status() === 403 && (await page.content()).includes('You may not download exports.'), 'and so is a direct download, in words');
  await ctx.close();
}
{
  const { ctx, page } = await session(29, DESK);
  console.log('1280 x 800 — Finance');
  await go(page, '/exports?' + period);
  ok(await page.locator('#exports-cards .card[id^="export-card-"]').count() === 7, 'Finance (external) sees the seven downloads');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#export-usage-csv-btn')]);
  ok(dl.suggestedFilename().startsWith('ct-usage-2026-09-01_') && fs.readFileSync(await dl.path(), 'utf8').includes('cost_amount'), 'Finance downloads the usage detail with its cost column');
  const r = await page.goto(BASE + '/exports/download.php?export=reimbursements&format=csv&to=2026-10-05', { waitUntil: 'networkidle' });
  ok(r.status() === 403 || (await page.content()).includes('You may not read what is owed to people'), 'reimbursements (people data) are Billing\'s: Finance is refused in words');
  await ctx.close();
}
{
  const { ctx, page } = await session(28, DESK, { javaScriptEnabled: false });
  console.log('JavaScript off — Billing');
  await go(page, '/exports');
  await page.fill('#exports-f-from', '2026-09-01'); await page.fill('#exports-f-to', addDays(monday0, 6));
  await page.locator('#exports-show-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(page.url().includes('from=2026-09-01'), 'the period form is a plain GET: it works without JavaScript');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('#export-credit_notes-csv-btn').click({ force: true })]);
  ok(dl.suggestedFilename().startsWith('ct-credit-notes-2026-09-01_') && fs.readFileSync(await dl.path(), 'utf8').includes('CN-'), 'a download is a plain link: it works without JavaScript');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
