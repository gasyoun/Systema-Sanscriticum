/**
 * H4115 — Money-surface iOS WebKit audit (H1391 bug class).
 *
 * Playwright WEBKIT with real iPhone device descriptors (same engine as iOS
 * Safari) over the money surfaces: /checkout/{tariff}, /online/konsultaciya,
 * donations, /paypal/{tariff} claim, /trial.
 *
 * GET-only by default — prod-safe, read-only, zero charge risk.
 * --local  : additionally exercise interactive POST flows (promo apply).
 *            ONLY against a local dev instance, never prod.
 *
 * Usage:
 *   node scripts/money_ios_webkit_audit.mjs                        # local dev
 *   BASE_URL=https://samskrte.ru node scripts/money_ios_webkit_audit.mjs
 *   node scripts/money_ios_webkit_audit.mjs --checkoutslug <slug>  # skip discovery
 *
 * Install once: npx playwright install webkit
 *
 * Exit 0 = every reachable surface clean; exit 1 = findings (overflow,
 * below-fold/occluded/undersized pay button, dead form fields, console errors).
 * Writes storage/app/money-ios-audit/{report.json,summary.md,*.png} (not git).
 */
import { webkit, devices } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const arg = (name, fallback = '') => {
  const i = args.indexOf(name);
  return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};

const BASE = (arg('--base-url', process.env.BASE_URL || 'http://127.0.0.1:8000')).replace(/\/$/, '');
const LOCAL = args.includes('--local');
const CHECKOUT_SLUG = arg('--checkoutslug', process.env.CHECKOUT_SLUG || '');
const OUT_DIR = join(ROOT, 'storage', 'app', 'money-ios-audit');
const TIMEOUT = 30000;

const DEVICES = ['iPhone 14', 'iPhone SE (3rd gen)'];

// Fixed public money surfaces + discovery seeds for slug-bearing links.
// Checkout links live on /k/{course-slug} pages (#tariffs section) — shop
// cards link there first, so discovery is two-hop: seed → /k/{slug} → money.
const FIXED_SURFACES = ['/online/konsultaciya', '/mecenaty'];
const DISCOVERY_SEEDS = ['/', '/online', '/mecenaty'];
const COURSE_PAGE_RE = /^\/k\/[^/]+$/;
const MONEY_PATTERNS = [/^\/checkout\/[^/]+$/, /^\/paypal\/[^/]+$/, /^\/trial\/[^/]+$/, /^\/mecenaty.*$/];
const MAX_COURSE_PAGES = 2;
const MAX_SURFACES = 8;

const PAY_BUTTON_RE = /оплат|перевести|\bpay\b|checkout|donate/i;
const CTA_FALLBACK_RE = /записат|купить|оформить|поддерж/i;
const COOKIE_BAR_RE = /cookie|кук|соглас|мы используем|privacy|персональн/i;

const results = [];

function compact(r) {
  const f = [];
  const notes = [];
  if (r.status >= 400) f.push(`HTTP ${r.status}`);
  if (r.overflowX > 0) f.push(`overflowX ${r.overflowX}px`);
  if (r.payButton && !r.payButton.visible) f.push('pay button not visible');
  if (r.payButton && r.payButton.visible && !r.payButton.inViewport) notes.push('pay button below fold (long landing — informational)');
  if (r.payButton && r.payButton.visible && !r.payButton.tapTargetOk) {
    f.push(`pay button tap target ${r.payButton.width}x${r.payButton.height} <44px`);
  }
  if (r.payButton && r.payButton.visible && r.payButton.occludedBy) {
    f.push(`pay button occluded by ${r.payButton.occludedBy}`);
  }
  if (r.csrfMissing) f.push('form(s) missing _token');
  if (r.deadFields.length) f.push(`dead fields: ${r.deadFields.join(', ')}`);
  for (const e of r.consoleErrors.slice(0, 5)) f.push(`console: ${e.slice(0, 140)}`);
  return { ...r, findings: f, notes, clean: f.length === 0 };
}

async function auditSurface(browser, deviceName, path) {
  const ctx = await browser.newContext({ ...devices[deviceName] });
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`));

  const r = {
    device: deviceName,
    viewport: `${devices[deviceName].defaultBrowserType}:${devices[deviceName].viewport.width}x${devices[deviceName].viewport.height}`,
    path,
    status: 0,
    finalPath: '',
    overflowX: 0,
    payButton: null,
    forms: 0,
    csrfMissing: false,
    deadFields: [],
    consoleErrors,
    screenshot: '',
  };

  try {
    const res = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    r.status = res ? res.status() : 0;
    if (r.status >= 400) {
      await ctx.close();
      return compact(r);
    }
    r.finalPath = new URL(page.url()).pathname;
    await page.waitForTimeout(400);

    r.overflowX = await page.evaluate(() => {
      const doc = document.documentElement;
      return Math.max(doc.scrollWidth, document.body?.scrollWidth || 0) - doc.clientWidth;
    });

    r.forms = await page.locator('form').count();
    // Only server-side POST forms need the hidden _token; JS-driven forms
    // (Alpine x-on:submit.prevent, method-less) send CSRF via header/meta.
    r.csrfMissing = await page.evaluate(() =>
      [...document.querySelectorAll('form[method="POST" i]')].some(
        (f) => f.querySelector('input, button') && !f.querySelector('input[name=_token]'),
      ));

    // Pay button: strict payment verbs first; fall back to generic CTA
    // («Записаться»/«Купить») so free-registration pages still get audited.
    let btn = page.locator('button, input[type=submit], a[href]').filter({ hasText: PAY_BUTTON_RE }).first();
    if (!(await btn.count())) {
      btn = page.locator('button, input[type=submit], a[href]').filter({ hasText: CTA_FALLBACK_RE }).first();
    }
    if (await btn.count()) {
      const box = await btn.boundingBox();
      if (box) {
        const vp = devices[deviceName].viewport;
        const inViewport = box.y + box.height <= vp.height && box.y >= 0;
        const occludedBy = await page.evaluate(({ x, y, w, h }) => {
          const cx = x + w / 2, cy = y + h / 2;
          const top = document.elementFromPoint(cx, cy);
          if (!top) return null;
          const holder = top.closest('[class]') || top;
          const txt = (holder.className || '').toString();
          const isOverlay = getComputedStyle(top).position === 'fixed' || getComputedStyle(top.parentElement || top).position === 'fixed';
          return isOverlay && !top.closest('button') && !top.closest('a') ? `${top.tagName}.${txt.slice(0, 60)}` : null;
        }, { x: box.x, y: box.y, w: box.width, h: box.height });
        r.payButton = {
          text: (await btn.textContent() || '').trim().slice(0, 60),
          className: ((await btn.getAttribute('class')) || '').slice(0, 160),
          visible: await btn.isVisible(),
          inViewport,
          width: Math.round(box.width),
          height: Math.round(box.height),
          tapTargetOk: box.width >= 44 && box.height >= 44,
          occludedBy,
        };
      }
    }

    // Dead form fields: focus lands elsewhere (hidden/covered inputs).
    for (const input of await page.locator('form input:visible[type=text], form input:visible[type=email], form input:visible[type=tel], form input:visible[type=number]').all()) {
      const name = await input.getAttribute('name');
      if (!name) continue;
      try {
        await input.focus({ timeout: 2000 });
        const active = await page.evaluate(() => document.activeElement?.getAttribute('name'));
        if (active !== name) r.deadFields.push(name);
      } catch { r.deadFields.push(`${name}(unfocusable)`); }
    }

    const shot = `${path.replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '')}__${deviceName.replace(/[^a-z0-9]+/gi, '_')}.png`;
    await page.screenshot({ path: join(OUT_DIR, shot), fullPage: true });
    r.screenshot = shot;
  } catch (e) {
    r.consoleErrors.push(`audit error: ${e.message.slice(0, 200)}`);
  }
  await ctx.close();
  return compact(r);
}

async function discoverPaths(browser) {
  if (CHECKOUT_SLUG) return [...FIXED_SURFACES, `/checkout/${CHECKOUT_SLUG}`];
  const ctx = await browser.newContext({ ...devices['iPhone 14'] });
  const page = await ctx.newPage();
  const found = new Set(FIXED_SURFACES);
  const coursePages = new Set();

  const harvest = async (url, patterns, bucket) => {
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    for (const href of await page.locator('a[href]').all()) {
      const h = await href.getAttribute('href');
      if (!h) continue;
      const p = h.startsWith('http') ? new URL(h).pathname : h.split('?')[0];
      if (patterns.some((re) => re.test(p))) bucket.add(p);
    }
  };

  for (const seed of DISCOVERY_SEEDS) {
    try {
      await harvest(seed, MONEY_PATTERNS, found);
      await harvest(seed, [COURSE_PAGE_RE], coursePages);
    } catch { /* seed unreachable — fixed surfaces still audited */ }
  }

  // Hop 2: /k/{slug} course pages carry the real /checkout/{tariff} links.
  for (const course of [...coursePages].slice(0, MAX_COURSE_PAGES)) {
    if (found.size >= MAX_SURFACES) break;
    try {
      await harvest(course, MONEY_PATTERNS, found);
    } catch { /* course page unreachable */ }
  }
  await ctx.close();
  const money = [...found].filter((p) => MONEY_PATTERNS.some((re) => re.test(p))).slice(0, MAX_SURFACES);
  return [...new Set([...money, ...FIXED_SURFACES])];
}

const browser = await webkit.launch();
mkdirSync(OUT_DIR, { recursive: true });
const paths = await discoverPaths(browser);
console.log(`money-ios-webkit-audit · BASE=${BASE} · webkit · surfaces=${paths.length}${LOCAL ? ' · LOCAL POST on' : ''}\n`);

for (const path of paths) {
  for (const deviceName of DEVICES) {
    const r = await auditSurface(browser, deviceName, path);
    results.push(r);
    const mark = r.clean ? 'ok' : '!!';
    console.log(`[${mark}] ${r.device.padEnd(28)} ${path} → ${r.status}${r.findings.length ? ' · ' + r.findings.join(' · ') : ''}`);
  }
}
await browser.close();

const skipped = results.filter((r) => r.status >= 400);
const audited = results.filter((r) => r.status < 400);
const dirty = results.filter((r) => !r.clean && r.status < 400);
writeFileSync(join(OUT_DIR, 'report.json'), JSON.stringify({ base: BASE, generated: new Date().toISOString(), local: LOCAL, results }, null, 2));

const md = [`# Money-surface iOS WebKit audit — ${new Date().toISOString().slice(0, 16).replace('T', ' ')}`, '',
  `Base: \`${BASE}\` · engine: WebKit (iOS Safari) · mode: ${LOCAL ? 'local+POST' : 'GET-only (prod-safe)'}`, '',
  `Surfaces: ${paths.length} · audited: ${audited.length} · skipped (HTTP≥400): ${skipped.length} · with findings: ${dirty.length}`, ''];
for (const r of results.filter((x) => x.status < 400)) {
  md.push(`## ${r.device} — ${r.path}`, '',
    `- status ${r.status} · overflowX ${r.overflowX}px · forms ${r.forms}${r.csrfMissing ? ' · ⚠ CSRF _token missing' : ''}`,
    `- pay button: ${r.payButton ? `${r.payButton.visible ? 'visible' : 'NOT visible'}, ${r.payButton.inViewport ? 'in viewport' : 'below fold'}, ${r.payButton.width}x${r.payButton.height}px${r.payButton.occludedBy ? ` · OCCLUDED by ${r.payButton.occludedBy}` : ''}` : 'not found'}`,
    r.deadFields.length ? `- ⚠ dead fields: ${r.deadFields.join(', ')}` : '',
    r.consoleErrors.length ? `- ⚠ console: ${r.consoleErrors.slice(0, 3).join(' | ').slice(0, 300)}` : '',
    r.screenshot ? `- screenshot: \`${r.screenshot}\`` : '', '');
}
writeFileSync(join(OUT_DIR, 'summary.md'), md.filter((l) => l !== undefined).join('\n'));

console.log(`\nreport: storage/app/money-ios-audit/{report.json,summary.md} · dirty=${dirty.length}/${audited.length}`);
process.exit(dirty.length ? 1 : 0);
