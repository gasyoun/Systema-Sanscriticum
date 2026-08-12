import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import {
    ENCRYPTION_CHUNK_BYTES,
    IndexedDbDeviceKeyVault,
    MemoryEncryptedChunkStore,
    createDeviceKey,
    decryptChunk,
    encryptChunk,
    measureWebCrypto,
    probeNativeCrypto,
    randomBytes,
    resumeEncryptedAsset,
} from './spike';

describe('offline encrypted-storage capability spike', () => {
    beforeEach(() => {
        indexedDB = new IDBFactory();
        delete (globalThis as typeof globalThis & { Capacitor?: unknown }).Capacitor;
    });

    it('creates and persists a non-exportable PWA device key', async () => {
        const vault = new IndexedDbDeviceKeyVault('spike-key-test');
        const first = await vault.loadOrCreate();
        const afterRestart = await vault.loadOrCreate();

        expect(first.extractable).toBe(false);
        expect(afterRestart.extractable).toBe(false);
        await expect(crypto.subtle.exportKey('raw', afterRestart)).rejects.toThrow();

        const chunk = await encryptChunk(first, { manifestVersion: '1', assetId: 'pdf' }, 0, new Uint8Array([1, 2, 3]));
        expect(Array.from(await decryptChunk(afterRestart, chunk))).toEqual([1, 2, 3]);
    });

    it('binds every AES-GCM chunk to manifest, asset, index, and size', async () => {
        const key = await createDeviceKey();
        const chunk = await encryptChunk(key, { manifestVersion: '1', assetId: 'audio' }, 2, new Uint8Array([4, 5, 6]));

        await expect(decryptChunk(key, { ...chunk, assetId: 'other' })).rejects.toThrow();
        await expect(decryptChunk(key, { ...chunk, index: 3 })).rejects.toThrow();
        await expect(decryptChunk(key, { ...chunk, plainBytes: 4 })).rejects.toThrow();
    });

    it('cannot decrypt ciphertext copied to another device key', async () => {
        const sourceDevice = await createDeviceKey();
        const otherDevice = await createDeviceKey();
        const chunk = await encryptChunk(sourceDevice, { manifestVersion: '1', assetId: 'text' }, 0, new Uint8Array([7, 8, 9]));

        await expect(decryptChunk(otherDevice, chunk)).rejects.toThrow();
    });

    it('resumes a partial PDF/audio download without fetching completed ranges', async () => {
        const key = await createDeviceKey();
        const store = new MemoryEncryptedChunkStore();
        const identity = { manifestVersion: '1', assetId: 'representative.pdf' };
        const source = randomBytes(ENCRYPTION_CHUNK_BYTES * 2 + 37);
        const fetched: Array<[number, number]> = [];
        const fetchRange = async (start: number, end: number) => {
            fetched.push([start, end]);
            return source.slice(start, end).buffer;
        };

        await resumeEncryptedAsset(key, store, identity, ENCRYPTION_CHUNK_BYTES, fetchRange);
        fetched.length = 0;
        const resumed = await resumeEncryptedAsset(key, store, identity, source.byteLength, fetchRange);

        expect(resumed.reused).toEqual([0]);
        expect(resumed.downloaded).toEqual([1, 2]);
        expect(fetched).toEqual([
            [ENCRYPTION_CHUNK_BYTES, ENCRYPTION_CHUNK_BYTES * 2],
            [ENCRYPTION_CHUNK_BYTES * 2, source.byteLength],
        ]);
    });

    it('fails closed when the native non-exportable crypto bridge is absent', async () => {
        expect(await probeNativeCrypto()).toEqual({
            bridgeReachable: false,
            keyNonExportable: false,
            keyPersistsAfterRestart: false,
            filesystemReachable: false,
        });
    });

    it('keeps encrypted chunk storage expansion below the 20% budget', async () => {
        const measurement = await measureWebCrypto(2 * ENCRYPTION_CHUNK_BYTES);
        expect(measurement.encryptMs).toBeGreaterThan(0);
        expect(measurement.decryptMs).toBeGreaterThan(0);
        expect(measurement.storageOverheadPercent).toBeLessThan(20);
    });
});
