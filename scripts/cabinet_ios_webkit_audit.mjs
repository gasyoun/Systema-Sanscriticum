/**
 * H4117 — Cabinet-routes iPhone WebKit audit (H1488 pattern, Playwright WebKit).
 *
 * Usage (repo root, app served locally):
 *   php -d error_reporting='E_ALL & ~E_DEPRECATED' artisan serve --port=8000
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cabinet_ios_webkit_audit.mjs
 *
 * Login: AUDIT_EMAIL / AUDIT_PASSWORD env (default local audit student).
 * GET-only navigation; no form POSTs beyond the single login.
 *
 * Checks per route × viewport (390×844 / 360×740 / 320×568, WebKit + iPhone UA):
 *   1. horizontal overflow (+ offender elements)
 *   2. tap targets < 44×44 px (buttons/links/[role=button])
 *   3. form controls with computed font-size < 16 px (iOS focus-zoom)
 *   4. Livewire feedback: [wire\:loading] / [wire\:offline] counts
 *   5. WebKit console errors
 *   6. viewport meta: interactive-widget / color-scheme / theme-color
 *
 * Output:
 *   docs/CABINET_IOS_WEBKIT_AUDIT_runtime.json
 *   docs/CABINET_IOS_WEBKIT_AUDIT_2026-09-05.md (summary)
 *   storage/app/cabinet-ios-audit/*.png (screenshots, never git)
 *
 * Exit 0 = every route × viewport completed (findings do NOT fail the run —
 * the report is the product). Exit 1 = runner/skip failures (auth, crash).
 */
import { webkit } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const EMAIL = process.env.AUDIT_EMAIL || 'ios-audit@local.test';
const PASSWORD = process.env.AUDIT_PASSWORD || 'ios-audit-2026';
const SHOT_DIR = 'storage/app/cabinet-ios-audit';
const OUT_JSON = 'docs/CABINET_IOS_WEBKIT_AUDIT_runtime.json';
const OUT_MD = 'docs/CABINET_IOS_WEBKIT_AUDIT_2026-09-05.md';

const VIEWPORTS = [
  { name: 'iPhone14_390x844', width: 390, height: 844 },
  { name: 'Compact_360x740', width: 360, height: 740 },
  { name: 'iPhoneSE_320x568', width: 320, height: 568 },
];

const IPHONE_UA =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

function parseCookie(raw) {
  if (!raw) return [];
  const [name, ...rest] = raw.split('=');
  const value = rest.join('=');
  const u = new URL(BASE);
  return [{ name, value, domain: u.hostname, path: '/' }];
}

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
  const email = page.locator('input[type="email"], input[name="email"]').first();
  const pass = page.locator('input[type="password"], input[name="password"]').first();
  await email.fill(EMAIL);
  await pass.fill(PASSWORD);
  // H4117 finding: at iPhone widths the login card collapses (w=0) and the
  // submit button is an unclickable 32px sliver off the viewport edge —
  // normal click fails ("<html> intercepts pointer events"). Submit via
  // Enter on the password field instead; the defect itself is reported.
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    pass.press('Enter'),
  ]);
  await page.waitForTimeout(500);
  return !page.url().includes('/login');
}

async function auditPage(page, path) {
  const res = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const status = res ? res.status() : 0;
  if (status >= 400) return { path, status, skipped: true };
  await page.waitForTimeout(400);

  const metrics = await page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const clientW = doc.clientWidth;
    const scrollW = Math.max(doc.scrollWidth, body?.scrollWidth || 0);

    const offenders = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > clientW + 1) {
        offenders.push({
          tag: el.tagName.toLowerCase(),
          cls: typeof el.className === 'string' ? el.className.slice(0, 70) : '',
          right: Math.round(r.right),
        });
        if (offenders.length >= 6) break;
      }
    }

    const smallTargets = [];
    for (const el of document.querySelectorAll('a, button, [role="button"], input[type="submit"]')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.width < 44 || r.height < 44) {
        const label = (el.textContent || el.getAttribute('aria-label') || el.getAttribute('title') || '')
          .trim()
          .slice(0, 40);
        const style = getComputedStyle(el);
        if (style.position === 'fixed' && r.width === 0) continue;
        smallTargets.push({
          tag: el.tagName.toLowerCase(),
          label,
          w: Math.round(r.width),
          h: Math.round(r.height),
        });
      }
      if (smallTargets.length >= 10) break;
    }

    const smallInputs = [];
    for (const el of document.querySelectorAll('input, select, textarea')) {
      const t = (el.getAttribute('type') || '').toLowerCase();
      if (['hidden', 'checkbox', 'radio', 'submit', 'button'].includes(t)) continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0) continue;
      const fs = parseFloat(getComputedStyle(el).fontSize);
      if (fs < 16) {
        smallInputs.push({ tag: el.tagName.toLowerCase(), type: t, fontSize: fs });
        if (smallInputs.length >= 8) break;
      }
    }

    const wireLoading = document.querySelectorAll('[wire\\:loading]').length;
    const wireOffline = document.querySelectorAll('[wire\\:offline]').length;

    const meta = document.querySelector('meta[name="viewport"]');
    const metaContent = meta ? meta.getAttribute('content') || '' : '';
    const themeColor = !!document.querySelector('meta[name="theme-color"]');

    return {
      scrollW,
      clientW,
      overflowX: scrollW - clientW,
      offenders,
      smallTargets,
      smallTargetsTotal: document.querySelectorAll('a, button, [role="button"]').length,
      smallInputs,
      wireLoading,
      wireOffline,
      metaContent,
      hasInteractiveWidget: metaContent.includes('interactive-widget'),
      hasColorScheme: !!document.querySelector('meta[name="color-scheme"]'),
      themeColor,
    };
  });

  return { path, status, skipped: false, ...metrics };
}

async function discoverRoutes(page) {
  const found = { course: null, lesson: null };
  await page.goto(BASE + '/dvaram', { waitUntil: 'domcontentloaded', timeout: 30000 });
  const hrefs = await page.evaluate(() =>
    Array.from(document.querySelectorAll('a[href]')).map((a) => a.getAttribute('href'))
  );
  for (const h of hrefs || []) {
    if (!found.course && /\/courses\/[^/]+\/?/.test(h) && h.startsWith('/')) found.course = h;
    if (!found.lesson && /\/lessons\/[^/]+\/?/.test(h) && h.startsWith('/')) found.lesson = h;
  }
  if (found.course && !found.lesson) {
    await page.goto(BASE + found.course, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const sub = await page.evaluate(() =>
      Array.from(document.querySelectorAll('a[href]')).map((a) => a.getAttribute('href'))
    );
    for (const h of sub || []) {
      if (/\/lessons\/[^/]+\/?/.test(h) && h.startsWith('/')) {
        found.lesson = h;
        break;
      }
    }
  }
  return found;
}

const browser = await webkit.launch();
mkdirSync(SHOT_DIR, { recursive: true });
mkdirSync(dirname(OUT_JSON), { recursive: true });

// One login pass → shared storage state.
const ctx0 = await browser.newContext({
  viewport: VIEWPORTS[0],
  userAgent: IPHONE_UA,
  isMobile: true,
  hasTouch: true,
});
if (process.env.SESSION_COOKIE) {
  await ctx0.addCookies(parseCookie(process.env.SESSION_COOKIE));
}
const p0 = await ctx0.newPage();
const loggedIn = await login(p0);
if (!loggedIn) {
  console.error(`AUTH FAILED for ${EMAIL} at ${BASE}/login — check AUDIT_EMAIL/AUDIT_PASSWORD`);
  await browser.close();
  process.exit(1);
}
const discovered = await discoverRoutes(p0);
const storageState = await ctx0.storageState({ path: join(SHOT_DIR, 'state.json') });
await ctx0.close();
console.log(`logged in; discovered course=${discovered.course} lesson=${discovered.lesson}`);

const ROUTES = [
  { path: '/dvaram', name: 'dashboard' },
  { path: process.env.AUDIT_COURSE || '/c/grammatika-sanskrita-demo', name: 'course' },
  { path: process.env.AUDIT_LESSON || '/c/grammatika-sanskrita-demo/u/1', name: 'lesson' },
  { path: '/dvaram/koloda', name: 'srs-koloda' },
  { path: '/dvaram/koloda/stats', name: 'srs-stats' },
  { path: '/calendar', name: 'calendar' },
  { path: '/guide', name: 'guide' },
  { path: '/trial', name: 'trial-public' },
].filter((r) => r.path);

const storageStatePath = join(SHOT_DIR, 'state.json');
// Re-login per context (cookie jar per context); state reuse skipped for simplicity.

const results = [];
let runnerFailures = 0;
const consoleErrors = [];

for (const vp of VIEWPORTS) {
  const context = await browser.newContext({
    viewport: { width: vp.width, height: vp.height },
    userAgent: IPHONE_UA,
    isMobile: true,
    hasTouch: true,
    deviceScaleFactor: 2,
    storageState,
  });
  const page = await context.newPage();
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push({ viewport: vp.name, path: page.url(), text: msg.text().slice(0, 200) });
  });
  page.on('pageerror', (err) => {
    consoleErrors.push({ viewport: vp.name, path: page.url(), text: `pageerror: ${String(err).slice(0, 200)}` });
  });

  // Session comes from storageState (single login above) — re-login only as
  // a fallback, to avoid Laravel login throttling on repeat POSTs.
  await page.goto(BASE + '/dvaram', { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (page.url().includes('/login')) {
    const ok = await login(page);
    if (!ok) {
      runnerFailures += 1;
      console.error(`[${vp.name}] AUTH FAILED`);
      await context.close();
      continue;
    }
  }

  for (const route of ROUTES) {
    let row;
    try {
      row = await auditPage(page, route.path);
    } catch (e) {
      row = { path: route.path, status: 0, skipped: true, error: String(e).slice(0, 200) };
    }
    const shot = join(SHOT_DIR, `${vp.name}_${route.name}.png`);
    try {
      await page.screenshot({ path: shot, fullPage: false });
      row.screenshot = shot;
    } catch {
      row.screenshot = null;
    }
    row.viewport = vp.name;
    row.name = route.name;
    results.push(row);
    const flag = row.skipped
      ? `SKIP${row.status === 404 ? '(n/a: route absent on this dataset)' : ''}`
      : `ox=${row.overflowX} small44=${row.smallTargets.length} <16px=${row.smallInputs.length} wl=${row.wireLoading} wo=${row.wireOffline}`;
    console.log(`${vp.name.padEnd(18)} ${route.name.padEnd(14)} status=${row.status} ${flag}`);
    if (row.skipped && row.status !== 404) runnerFailures += 1;
  }
  await context.close();
}

await browser.close();

const summary = {
  generated_at: new Date().toISOString(),
  base: BASE,
  engine: 'playwright-webkit (iPhone UA, isMobile, touch)',
  viewports: VIEWPORTS.map((v) => v.name),
  routes: ROUTES.map((r) => ({ name: r.name, path: r.path })),
  discovered,
  runner_failures: runnerFailures,
  console_errors: consoleErrors,
  results,
};
writeFileSync(OUT_JSON, JSON.stringify(summary, null, 2));

// --- MD summary ---
const lines = [];
lines.push(`# Cabinet-routes iPhone WebKit audit — ${new Date().toISOString().slice(0, 10)}`);
lines.push('');
lines.push(`Base: ${BASE} · engine: WebKit (iPhone UA, touch) · H4117.`);
lines.push('');
lines.push(`Discovered: course=${discovered.course ?? '—'} · lesson=${discovered.lesson ?? '—'}`);
lines.push('');
lines.push(`Runner failures (skips): ${runnerFailures} · console errors: ${consoleErrors.length}`);
lines.push('');
lines.push('| viewport | route | status | overflowX | tap<44 | input<16px | wire:loading | wire:offline |');
lines.push('|---|---|---|---|---|---|---|---|');
for (const r of results) {
  lines.push(
    `| ${r.viewport} | ${r.name} | ${r.status}${r.skipped ? ' (skip)' : ''} | ${r.skipped ? '—' : r.overflowX} | ${r.skipped ? '—' : r.smallTargets.length} | ${r.skipped ? '—' : r.smallInputs.length} | ${r.skipped ? '—' : r.wireLoading} | ${r.skipped ? '—' : r.wireOffline} |`
  );
}
lines.push('');
if (consoleErrors.length) {
  lines.push('## Console errors (WebKit)');
  lines.push('');
  for (const c of consoleErrors.slice(0, 15)) lines.push(`- [${c.viewport}] ${c.path}: ${c.text}`);
  lines.push('');
}
lines.push('Screenshots: storage/app/cabinet-ios-audit/ (not git). Raw JSON: docs/CABINET_IOS_WEBKIT_AUDIT_runtime.json.');
writeFileSync(OUT_MD, lines.join('\n') + '\n');

console.log(`\nrunner_failures=${runnerFailures} console_errors=${consoleErrors.length}`);
console.log(`wrote ${OUT_JSON} and ${OUT_MD}`);
process.exit(runnerFailures > 0 ? 1 : 0);
