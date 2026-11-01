/*
 * H7590 — поисковый движок шапки: Fuse.js по клиентскому индексу
 * public/search/index-{hash}.json (артикул SearchIndexBuilder::write()).
 * Грузится динамически из global-search.js — см. комментарий там.
 */
import Fuse from 'fuse.js';

/**
 * @param {string} indexUrl — URL актуального index-{hash}.json (из манифеста).
 * @returns {Promise<{search: (q: string, limit?: number) =>
 *   Array<{type: string, title: string, url: string, hint: ?string}>}>}
 */
export async function boot(indexUrl) {
    const response = await fetch(indexUrl, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        throw new Error('search index fetch failed: ' + response.status);
    }

    const payload = await response.json();

    const fuse = new Fuse(payload.items ?? [], {
        // Русские запросы короткие и падежные: ищем вхождение в любом месте,
        // порог мягче дефолтного (0.6), минимум 2 символа.
        threshold: 0.35,
        ignoreLocation: true,
        minMatchCharLength: 2,
        keys: [
            { name: 'title', weight: 0.75 },
            { name: 'hint', weight: 0.25 },
        ],
    });

    return {
        search(q, limit = 5) {
            return fuse
                .search(q, { limit })
                .map((hit) => hit.item);
        },
    };
}
