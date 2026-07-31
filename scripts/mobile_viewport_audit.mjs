/**
 * H1488 — optional Playwright mobile viewport audit for student cabinet routes.
 *
 * Usage (from repo root, with a running app + session cookie):
 *   set BASE_URL=http://127.0.0.1:8000
 *   set SESSION_COOKIE=laravel_session=...
 *   node scripts/mobile_viewport_audit.mjs
 *
 * Install once: npm i -D playwright && npx playwright install chromium
 *
 * Exit 0 = no horizontal overflow at 390×844 / 360×740 / 320×568.
 * Writes docs/MOBILE_VIEWPORT_CABINET_AUDIT_runtime.json next to this script's CWD.
 */
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const COOKIE = process.env.SESSION_COOKIE || '';
const VIEWPORTS = [
  { name: 'iPhone14', width: 390, height: 844 },
  { name: 'AndroidCompact', width: 360, height: 740 },
  { name: 'iPhoneSE', width: 320, height: 568 },
];

// Routes under the student auth middleware. Course/lesson need real IDs — skip if 404.
const ROUTES = [
  '/dvaram',
  '/calendar',
  '/messages',
  '/library',
  '/progress',
  '/access',
  '/open-lessons',
  '/dvaram/koloda',
  '/dvaram/koloda/stats',
  '/dvaram/koloda/decks',
];

function parseCookie(raw) {
  if (!raw) return [];
  const [name, ...rest] = raw.split('=');
  const value = rest.join('=');
  if (!name || !value) return [];
  const u = new URL(BASE);
  return [{ name, value, domain: u.hostname, path: '/' }];
}

async function measure(page, path) {
  const res = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const status = res ? res.status() : 0;
  if (status >= 400) {
    return { path, status, skipped: true, overflowX: null };
  }
  await page.waitForTimeout(250);
  const metrics = await page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const scrollW = Math.max(doc.scrollWidth, body?.scrollWidth || 0);
    const clientW = doc.clientWidth;
    const overflowX = scrollW - clientW;
    const offenders = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > clientW + 1) {
        const tag = el.tagName.toLowerCase();
        const cls = (el.className && typeof el.className === 'string')
          ? el.className.slice(0, 80)
          : '';
        offenders.push({ tag, cls, right: Math.round(r.right), width: Math.round(r.width) });
        if (offenders.length >= 8) break;
      }
    }
    return { scrollW, clientW, overflowX, offenders };
  });
  return { path, status, skipped: false, ...metrics };
}

const browser = await chromium.launch();
const results = [];
let failed = 0;

for (const vp of VIEWPORTS) {
  const context = await browser.newContext({
    viewport: { width: vp.width, height: vp.height },
    userAgent:
      'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
  });
  if (COOKIE) {
    await context.addCookies(parseCookie(COOKIE));
  }
  const page = await context.newPage();
  for (const route of ROUTES) {
    const row = { viewport: vp.name, ...await measure(page, route) };
    if (!row.skipped && row.overflowX > 2) {
      failed += 1;
    }
    results.push(row);
    console.log(
      `${vp.name.padEnd(16)} ${route.padEnd(22)} status=${row.status} overflowX=${row.overflowX ?? 'skip'}`
    );
  }
  await context.close();
}

await browser.close();

const out = {
  generated_at: new Date().toISOString(),
  base: BASE,
  failed_overflow_count: failed,
  results,
};
writeFileSync('docs/MOBILE_VIEWPORT_CABINET_AUDIT_runtime.json', JSON.stringify(out, null, 2));
console.log(`\nfailed_overflow_count=${failed}`);
process.exit(failed > 0 ? 1 : 0);
