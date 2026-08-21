/**
 * H3212 — Playwright capture for cabinet guides.
 *
 *   node scripts/capture-guide-screenshots.mjs --guide student --base-url http://127.0.0.1:8000
 *   node scripts/capture-guide-screenshots.mjs --guide curator --base-url http://127.0.0.1:8000
 *   node scripts/capture-guide-screenshots.mjs --guide accountant --base-url http://127.0.0.1:8000
 *
 * Login student: STUDENT_GUIDE_EMAIL / STUDENT_GUIDE_PASSWORD (fixture, never prod).
 * Login curator: CURATOR_GUIDE_EMAIL / CURATOR_GUIDE_PASSWORD (fixture, never prod).
 * Login accountant: ACCOUNTANT_GUIDE_EMAIL / ACCOUNTANT_GUIDE_PASSWORD (fixture, never prod).
 * Accountant PNG → storage/app/guide-shots/accountant/ (not git).
 * No Chromium: exit 2; commit text+manifest, do not invent PNGs.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);

function arg(name, fallback = '') {
  const i = args.indexOf(name);
  return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
}

const guide = arg('--guide', 'student');
const baseUrl = arg('--base-url', process.env.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const widths = [1440, 390];

const guides = {
  student: {
    manifest: 'docs/generated/student_guide_shots.json',
    email: process.env.STUDENT_GUIDE_EMAIL || '',
    password: process.env.STUDENT_GUIDE_PASSWORD || '',
    authName: 'student',
    loginPath: '/login',
    defaultOut: 'docs/screenshots/student-guide',
    log: 'docs/generated/student_guide_shots_last_run.json',
  },
  curator: {
    manifest: 'docs/generated/curator_guide_shots.json',
    email: process.env.CURATOR_GUIDE_EMAIL || '',
    password: process.env.CURATOR_GUIDE_PASSWORD || '',
    authName: 'manager',
    loginPath: '/admin/login',
    defaultOut: 'docs/screenshots/curator-guide',
    log: 'docs/generated/curator_guide_shots_last_run.json',
  },
  accountant: {
    manifest: 'docs/generated/accountant_guide_shots.json',
    email: process.env.ACCOUNTANT_GUIDE_EMAIL || '',
    password: process.env.ACCOUNTANT_GUIDE_PASSWORD || '',
    authName: 'accountant',
    loginPath: '/admin/login',
    defaultOut: 'storage/app/guide-shots/accountant',
    log: 'docs/generated/accountant_guide_shots_last_run.json',
  },
};

if (!guides[guide]) {
  console.error('Unknown --guide (student|curator|accountant).');
  process.exit(1);
}

const cfg = guides[guide];
const email = cfg.email;
const password = cfg.password;
const manifestPath = join(ROOT, cfg.manifest);
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const outDir = join(ROOT, manifest.outDir || cfg.defaultOut);
mkdirSync(outDir, { recursive: true });

async function login(page) {
  if (!email || !password) {
    return false;
  }
  await page.goto(baseUrl + cfg.loginPath, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const emailSel = page.locator('#email, input[type="email"]').first();
  const passSel = page.locator('#password, input[type="password"]').first();
  await emailSel.fill(email);
  await passSel.fill(password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }).catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  return !page.url().includes('/login');
}

async function main() {
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
  } catch (err) {
    console.error('No Chromium. Install: npx playwright install chromium');
    console.error(String(err));
    process.exit(2);
  }

  const context = await browser.newContext();
  const page = await context.newPage();
  let loggedIn = false;
  const written = [];

  try {
    for (const shot of manifest.shots) {
      const needsAuth = shot.auth && shot.auth !== 'guest';
      if (needsAuth) {
        if (!email || !password) {
          console.warn(`skip ${shot.slug}: no fixture email/password`);
          continue;
        }
        if (!loggedIn) {
          loggedIn = await login(page);
          if (!loggedIn) {
            console.warn(`skip ${shot.slug}: login did not succeed`);
            continue;
          }
        }
      }

      for (const width of widths) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
        const res = await page.goto(baseUrl + shot.path, {
          waitUntil: 'domcontentloaded',
          timeout: 30000,
        });
        const status = res ? res.status() : 0;
        if (status >= 400) {
          console.warn(`skip ${shot.slug}-${width}: HTTP ${status} ${shot.path}`);
          continue;
        }
        if (shot.click) {
          const loc = page.locator(shot.click).first();
          if (await loc.count()) {
            await loc.click().catch(() => {});
            await page.waitForTimeout(400);
          }
        }
        if (shot.wait) {
          await page.waitForSelector(shot.wait, { timeout: 8000 }).catch(() => {});
        }
        await page.waitForTimeout(300);
        const file = join(outDir, `${shot.slug}-${width}.png`);
        await page.screenshot({ path: file, fullPage: true });
        written.push(file);
        console.log('wrote', file);
      }
    }
  } finally {
    await browser.close();
  }

  const logPath = join(ROOT, cfg.log);
  writeFileSync(logPath, JSON.stringify({ at: new Date().toISOString(), written, count: written.length }, null, 2));
  console.log(`done: ${written.length} files`);
  if (written.length === 0) {
    process.exit(3);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
