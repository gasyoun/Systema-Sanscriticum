/**
 * H6327 — генератор трафаретов прописи (Excalidraw library).
 *
 * Печатная форма букв: пунктирный контур низкой непрозрачности + подпись
 * транслитерацией. Ученик обводит трафарет свободной кистью.
 *
 * Запуск: node scripts/generate-devanagari-stencils.mjs
 * Выход:  public/libraries/devanagari-stencils.excalidrawlib
 */
import { mkdirSync, writeFileSync } from 'node:fs';

const LETTERS = [
    ['अ', 'a'], ['आ', 'ā'], ['इ', 'i'], ['ई', 'ī'], ['उ', 'u'], ['ऊ', 'ū'],
    ['ए', 'e'], ['ऐ', 'ai'], ['ओ', 'o'], ['औ', 'au'],
    ['क', 'ka'], ['ख', 'kha'], ['ग', 'ga'], ['घ', 'gha'], ['ङ', 'ṅa'],
];

const COLS = 5;
const CELL = 140;
const SIZE = 100;

let seed = 20261010;
const rnd = () => {
    // детерминированный prng — идемпотентная генерация (проверка byte-diff)
    seed = (seed * 1103515245 + 12345) & 0x7fffffff;
    return seed;
};

function textElement(text, x, y, { fontSize = 96, opacity = 30, dashed = true } = {}) {
    return {
        type: 'text',
        x, y,
        width: fontSize * text.length * 0.8,
        height: fontSize * 1.25,
        angle: 0,
        strokeColor: '#1e1e1e',
        backgroundColor: 'transparent',
        fillStyle: 'hachure',
        strokeWidth: 2,
        strokeStyle: dashed ? 'dashed' : 'solid',
        roughness: 1,
        opacity,
        groupIds: [],
        frameId: null,
        roundness: null,
        seed: rnd(),
        version: 1,
        versionNonce: rnd(),
        isDeleted: false,
        boundElements: null,
        updated: 1,
        link: null,
        locked: false,
        text,
        fontSize,
        fontFamily: 3, // Cascadia/код — контурная поддержка деванагари в браузере
        textAlign: 'center',
        verticalAlign: 'top',
        containerId: null,
        originalText: text,
        lineHeight: 1.25,
    };
}

const libraryItems = LETTERS.map(([letter, translit], index) => {
    const col = index % COLS;
    const row = Math.floor(index / COLS);
    const x = col * CELL + 20;
    const y = row * CELL + 20;

    return {
        id: `devanagari-${letter}-${index + 1}`,
        status: 'published',
        created: 1791600000, // 2026-10-10
        name: `${letter} — ${translit}`,
        elements: [
            textElement(letter, x + (SIZE - 76) / 2, y + 4, { fontSize: 96, opacity: 25 }),
            textElement(translit, x + (SIZE - 30) / 2, y + SIZE + 8, { fontSize: 20, opacity: 70, dashed: false }),
        ],
    };
});

const payload = {
    type: 'excalidraw',
    version: 2,
    source: 'https://samskrte.ru',
    libraryItems,
};

mkdirSync('public/libraries', { recursive: true });
writeFileSync('public/libraries/devanagari-stencils.excalidrawlib', JSON.stringify(payload, null, 2) + '\n');
console.log(`Wrote public/libraries/devanagari-stencils.excalidrawlib (${libraryItems.length} items)`);
