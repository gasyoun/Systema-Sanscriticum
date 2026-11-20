/**
 * H6327 — persistence-тест доски прописи (Excalidraw).
 *
 * Рисуем штрих → ждём автосохранение («Сохранено») → закрываем контекст
 * (полностью новый браузер) → входим тем же студентом заново → штрих на месте.
 *
 * Запуск (нужен запущенный сервер с собранными ассетами):
 *   BASE_URL=http://127.0.0.1:8000 \
 *   STUDENT_EMAIL=... STUDENT_PASSWORD=... \
 *   node tests/Browser/devanagari-board-persistence.spec.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const EMAIL = process.env.STUDENT_EMAIL;
const PASSWORD = process.env.STUDENT_PASSWORD;

if (!EMAIL || !PASSWORD) {
    console.error('Нужны STUDENT_EMAIL и STUDENT_PASSWORD (бот-провиженный тестовый аккаунт, канон /кабинет email).');
    process.exit(2);
}

function fail(message) {
    console.error('FAIL:', message);
    process.exit(1);
}

async function login(browser) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('input[type="email"], input[name="email"]', EMAIL);
    await page.fill('input[type="password"], input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');
    return { context, page };
}

const browser = await chromium.launch();

try {
    // Сессия 1: открываем доску, рисуем.
    const first = await login(browser);
    await first.page.goto(`${BASE}/dvaram/propisi`);
    await first.page.waitForSelector('#devanagari-board-root .excalidraw', { timeout: 30000 });

    const canvas = first.page.locator('.excalidraw .canvas, .excalidraw canvas').first();
    // Кисть (freedraw) — клавиша 7 в Excalidraw; по умолчанию стоит selection,
    // которым «рисовать» нельзя.
    await first.page.keyboard.press('7');
    await canvas.hover({ position: { x: 120, y: 120 } });
    await first.page.mouse.down();
    for (let i = 0; i < 12; i += 1) {
        await first.page.mouse.move(120 + i * 8, 120 + Math.round(30 * Math.sin(i / 2)));
    }
    await first.page.mouse.up();

    // Автосохранение (debounce 800мс): ждём статус.
    await first.page.waitForSelector('#board-save-status:has-text("Сохранено")', { timeout: 10000 });
    console.log('OK: штрих нарисован, статус «Сохранено» увиден');
    await first.context.close();

    // Сессия 2: полностью новый браузерный контекст.
    const second = await login(browser);
    await second.page.goto(`${BASE}/dvaram/propisi`);
    await second.page.waitForSelector('#devanagari-board-root .excalidraw', { timeout: 30000 });
    await second.page.waitForTimeout(1500);

    const restored = await second.page.evaluate(() => {
        const api = window.__excalidrawApi;
        if (!api) return null;
        const elements = api.getSceneElements() ?? [];
        return elements.filter((el) => !el.isDeleted).length;
    });

    if (!restored || restored < 1) {
        fail(`в свежей сессии на доске 0 элементов (ожидали >= 1), получено: ${restored}`);
    }
    console.log(`OK: в свежей сессии на доске элементов: ${restored}`);
    console.log('PASS: board survives reload AND a new browser session');
    await second.context.close();
    process.exit(0);
} catch (error) {
    fail(error?.message ?? String(error));
} finally {
    await browser.close();
}
