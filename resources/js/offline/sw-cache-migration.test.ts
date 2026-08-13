/**
 * H2634 / issue #1630 — the cabinet service worker must not destroy the offline
 * cabinet's encrypted chunk cache when it activates.
 *
 * `public/sw.js` is a classic service-worker script, not a module: it reads the globals
 * `self` and `caches` and registers listeners as a side effect. The test therefore
 * evaluates the real tracked file (never a copy of its logic) inside a function scope
 * with both globals injected, then drives the lifecycle events by hand against an
 * in-memory CacheStorage. Nothing here is a browser stub of convenience — the assertions
 * are about the exact bytes that ship to production.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

const SW_PATH = fileURLToPath(new URL('../../../public/sw.js', import.meta.url));
const SW_SOURCE = readFileSync(SW_PATH, 'utf8');

/** The offline cabinet's store — a sibling cache this worker does not own. */
const CONTENT_CACHE = 'systema-offline-spike-encrypted-v1';

type FakeCache = Map<string, string>;

class FakeCacheStorage {
    readonly caches = new Map<string, FakeCache>();

    async open(name: string): Promise<{
        addAll(urls: string[]): Promise<void>;
        put(key: string, value: string): Promise<void>;
        match(key: string): Promise<string | undefined>;
    }> {
        if (!this.caches.has(name)) {
            this.caches.set(name, new Map());
        }
        const entries = this.caches.get(name) as FakeCache;

        return {
            addAll: async (urls: string[]) => {
                for (const url of urls) {
                    entries.set(url, `body:${url}`);
                }
            },
            put: async (key: string, value: string) => {
                entries.set(key, value);
            },
            match: async (key: string) => entries.get(key),
        };
    }

    async keys(): Promise<string[]> {
        return Array.from(this.caches.keys());
    }

    async delete(name: string): Promise<boolean> {
        return this.caches.delete(name);
    }
}

type Listener = (event: { waitUntil(promise: Promise<unknown>): void }) => void;

type Harness = {
    storage: FakeCacheStorage;
    dispatch(type: 'install' | 'activate'): Promise<void>;
    skipWaitingCalls: number;
    claimCalls: number;
};

/**
 * Evaluate the tracked `public/sw.js` with injected globals.
 *
 * `version` rewrites the worker's own OWNED_VERSION constant so a test can activate a
 * genuinely NEWER worker over caches left by an older one — the migration scenario an
 * ordinary deploy produces, since `install` calls `skipWaiting()` and `activate` calls
 * `clients.claim()`.
 */
function loadServiceWorker(storage: FakeCacheStorage, version = 'v1'): Harness {
    let source = SW_SOURCE;
    if (version !== 'v1') {
        const replaced = source.replace("const OWNED_VERSION = 'v1';", `const OWNED_VERSION = '${version}';`);
        expect(replaced, 'OWNED_VERSION constant not found in public/sw.js').not.toBe(source);
        source = replaced;
    }

    const listeners = new Map<string, Listener>();
    const harness: Harness = {
        storage,
        skipWaitingCalls: 0,
        claimCalls: 0,
        dispatch: async (type) => {
            const listener = listeners.get(type);
            expect(listener, `no ${type} listener registered by public/sw.js`).toBeTypeOf('function');
            const pending: Array<Promise<unknown>> = [];
            (listener as Listener)({ waitUntil: (promise) => void pending.push(promise) });
            await Promise.all(pending);
        },
    };

    const self = {
        addEventListener: (type: string, listener: Listener) => listeners.set(type, listener),
        skipWaiting: () => {
            harness.skipWaitingCalls += 1;
        },
        clients: {
            claim: async () => {
                harness.claimCalls += 1;
            },
        },
    };

    // eslint-disable-next-line no-new-func
    new Function('self', 'caches', source)(self, storage);

    return harness;
}

describe('cabinet service-worker cache ownership (H2634 / #1630)', () => {
    let storage: FakeCacheStorage;

    beforeEach(() => {
        storage = new FakeCacheStorage();
    });

    it('activating a NEW worker version preserves the offline encrypted content cache', async () => {
        // A device that downloaded lessons under the previous worker.
        const content = await storage.open(CONTENT_CACHE);
        await content.put('/__systema_offline_spike__/1/lesson.pdf/0', 'ciphertext-chunk-0');
        await (await storage.open('ors-cabinet-shell-v1')).addAll(['/offline.html']);

        // An ordinary deploy ships a byte-changed worker; skipWaiting + claim make it live now.
        const worker = loadServiceWorker(storage, 'v2');
        await worker.dispatch('install');
        await worker.dispatch('activate');

        expect(await storage.keys()).toContain(CONTENT_CACHE);
        expect(await (await storage.open(CONTENT_CACHE)).match('/__systema_offline_spike__/1/lesson.pdf/0'))
            .toBe('ciphertext-chunk-0');
        expect(worker.claimCalls).toBe(1);
    });

    it('still reaps its OWN obsolete shell versions', async () => {
        await (await storage.open('ors-cabinet-shell-v1')).addAll(['/offline.html']);
        await (await storage.open('ors-cabinet-shell-v0')).addAll(['/offline.html']);

        const worker = loadServiceWorker(storage, 'v2');
        await worker.dispatch('install');
        await worker.dispatch('activate');

        const keys = await storage.keys();
        expect(keys).toContain('ors-cabinet-shell-v2');
        expect(keys).not.toContain('ors-cabinet-shell-v1');
        expect(keys).not.toContain('ors-cabinet-shell-v0');
    });

    it('leaves every cache outside its own namespace alone', async () => {
        for (const foreign of [CONTENT_CACHE, 'workbox-precache-v2', 'some-third-party-cache']) {
            await (await storage.open(foreign)).put('/probe', 'kept');
        }

        const worker = loadServiceWorker(storage, 'v2');
        await worker.dispatch('install');
        await worker.dispatch('activate');

        const keys = await storage.keys();
        for (const foreign of [CONTENT_CACHE, 'workbox-precache-v2', 'some-third-party-cache']) {
            expect(keys, `${foreign} must survive activation`).toContain(foreign);
        }
    });

    it('re-activating the SAME version keeps its own shell cache', async () => {
        const worker = loadServiceWorker(storage, 'v1');
        await worker.dispatch('install');
        await worker.dispatch('activate');

        expect(await storage.keys()).toContain('ors-cabinet-shell-v1');
        expect(await (await storage.open('ors-cabinet-shell-v1')).match('/offline.html')).toBeDefined();
        expect(worker.skipWaitingCalls).toBe(1);
    });

    it('guards the regression directly: the tracked worker uses no deny-list activation', () => {
        // The exact shape of the H2634 defect. A future edit that reintroduces
        // "delete everything that is not my cache" fails here even if the tests above
        // were adjusted to match it.
        expect(SW_SOURCE).not.toMatch(/keys\s*\.filter\(\s*\(k\)\s*=>\s*k\s*!==\s*CACHE\s*\)/);
        expect(SW_SOURCE).toContain('OWNED_PREFIX');
    });
});
