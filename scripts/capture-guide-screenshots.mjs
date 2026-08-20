/**
 * H3212 — Playwright capture for cabinet guides.
 *
 *   node scripts/capture-guide-screenshots.mjs --guide student --base-url http://127.0.0.1:8000
 *
 * Login: STUDENT_GUIDE_EMAIL / STUDENT_GUIDE_PASSWORD (fixture, never prod).
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
const email = process.env.STUDENT_GUIDE_EMAIL || '';
const password = process.env.STUDENT_GUIDE_PASSWORD || '';
const widths = [1440, 390];

if (guide !== 'student') {
  console.error('Unknown --guide (wave 1 is student).');
  process.exit(1);
}

const manifestPath = join(ROOT, 'docs/generated/student_guide_shots.json');
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const outDir = join(ROOT, manifest.outDir || 'docs/screenshots/student-guide');
mkdirSync(outDir, { recursive: true });

async function login(page) {
  if (!email || !password) {
    return false;
  }
  await page.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.fill('#email', email);
  await page.fill('#password', password);
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
      if (shot.auth === 'student') {
        if (!email || !password) {
          console.warn(`skip ${shot.slug}: no STUDENT_GUIDE_EMAIL/PASSWORD (fixture only)`);
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

  const logPath = join(ROOT, 'docs/generated/student_guide_shots_last_run.json');
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
