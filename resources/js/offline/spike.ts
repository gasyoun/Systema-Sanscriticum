export const OFFLINE_SPIKE_SCHEMA = 1;
export const ENCRYPTION_CHUNK_BYTES = 1024 * 1024;

const encoder = new TextEncoder();

export function randomBytes(size: number): Uint8Array {
    const bytes = new Uint8Array(size);
    for (let offset = 0; offset < size; offset += 65536) {
        crypto.getRandomValues(bytes.subarray(offset, Math.min(size, offset + 65536)));
    }
    return bytes;
}

export type AssetIdentity = {
    manifestVersion: string;
    assetId: string;
};

export type EncryptedChunk = AssetIdentity & {
    schema: 1;
    index: number;
    plainBytes: number;
    iv: Uint8Array;
    ciphertext: Uint8Array;
};

export interface EncryptedChunkStore {
    get(identity: AssetIdentity, index: number): Promise<EncryptedChunk | undefined>;
    put(chunk: EncryptedChunk): Promise<void>;
}

function chunkKey(identity: AssetIdentity, index: number): string {
    return `${identity.manifestVersion}/${identity.assetId}/${index}`;
}

function additionalData(identity: AssetIdentity, index: number, plainBytes: number): Uint8Array {
    return encoder.encode([
        OFFLINE_SPIKE_SCHEMA,
        identity.manifestVersion,
        identity.assetId,
        index,
        plainBytes,
    ].join('|'));
}

export async function createDeviceKey(): Promise<CryptoKey> {
    return crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

export async function encryptChunk(
    key: CryptoKey,
    identity: AssetIdentity,
    index: number,
    plaintext: BufferSource,
): Promise<EncryptedChunk> {
    const plainBytes = plaintext instanceof ArrayBuffer
        ? plaintext.byteLength
        : plaintext.byteLength;
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = await crypto.subtle.encrypt(
        {
            name: 'AES-GCM',
            iv,
            additionalData: additionalData(identity, index, plainBytes),
            tagLength: 128,
        },
        key,
        plaintext,
    );

    return {
        ...identity,
        schema: OFFLINE_SPIKE_SCHEMA,
        index,
        plainBytes,
        iv,
        ciphertext: new Uint8Array(ciphertext),
    };
}

export async function decryptChunk(key: CryptoKey, chunk: EncryptedChunk): Promise<Uint8Array> {
    const plaintext = await crypto.subtle.decrypt(
        {
            name: 'AES-GCM',
            iv: chunk.iv,
            additionalData: additionalData(chunk, chunk.index, chunk.plainBytes),
            tagLength: 128,
        },
        key,
        chunk.ciphertext,
    );

    return new Uint8Array(plaintext);
}

export class IndexedDbDeviceKeyVault {
    constructor(
        private readonly databaseName = 'systema-offline-spike-v1',
        private readonly keyName = 'device-aes-gcm-v1',
    ) {}

    private open(): Promise<IDBDatabase> {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.databaseName, 1);
            request.onupgradeneeded = () => {
                if (!request.result.objectStoreNames.contains('keys')) {
                    request.result.createObjectStore('keys');
                }
            };
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    async loadOrCreate(): Promise<CryptoKey> {
        const database = await this.open();
        try {
            const existing = await new Promise<CryptoKey | undefined>((resolve, reject) => {
                const request = database.transaction('keys').objectStore('keys').get(this.keyName);
                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result as CryptoKey | undefined);
            });
            if (existing) {
                return existing;
            }

            const key = await createDeviceKey();
            await new Promise<void>((resolve, reject) => {
                const transaction = database.transaction('keys', 'readwrite');
                transaction.objectStore('keys').put(key, this.keyName);
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => reject(transaction.error);
                transaction.onabort = () => reject(transaction.error);
            });
            return key;
        } finally {
            database.close();
        }
    }
}

export class MemoryEncryptedChunkStore implements EncryptedChunkStore {
    private readonly chunks = new Map<string, EncryptedChunk>();

    async get(identity: AssetIdentity, index: number): Promise<EncryptedChunk | undefined> {
        return this.chunks.get(chunkKey(identity, index));
    }

    async put(chunk: EncryptedChunk): Promise<void> {
        this.chunks.set(chunkKey(chunk, chunk.index), chunk);
    }
}

export class CacheEncryptedChunkStore implements EncryptedChunkStore {
    constructor(private readonly cacheName = 'systema-offline-spike-encrypted-v1') {}

    private request(identity: AssetIdentity, index: number): Request {
        const manifest = encodeURIComponent(identity.manifestVersion);
        const asset = encodeURIComponent(identity.assetId);
        return new Request(`/__systema_offline_spike__/${manifest}/${asset}/${index}`);
    }

    async get(identity: AssetIdentity, index: number): Promise<EncryptedChunk | undefined> {
        const cache = await caches.open(this.cacheName);
        const response = await cache.match(this.request(identity, index));
        if (!response) {
            return undefined;
        }

        const ivBytes = Number(response.headers.get('X-Offline-IV-Bytes'));
        const plainBytes = Number(response.headers.get('X-Offline-Plain-Bytes'));
        const body = new Uint8Array(await response.arrayBuffer());
        if (ivBytes !== 12 || !Number.isSafeInteger(plainBytes) || body.byteLength <= ivBytes) {
            throw new Error('Corrupt encrypted chunk metadata');
        }

        return {
            ...identity,
            schema: OFFLINE_SPIKE_SCHEMA,
            index,
            plainBytes,
            iv: body.slice(0, ivBytes),
            ciphertext: body.slice(ivBytes),
        };
    }

    async put(chunk: EncryptedChunk): Promise<void> {
        const body = new Uint8Array(chunk.iv.byteLength + chunk.ciphertext.byteLength);
        body.set(chunk.iv, 0);
        body.set(chunk.ciphertext, chunk.iv.byteLength);
        const cache = await caches.open(this.cacheName);
        await cache.put(this.request(chunk, chunk.index), new Response(body, {
            headers: {
                'Content-Type': 'application/octet-stream',
                'X-Offline-IV-Bytes': String(chunk.iv.byteLength),
                'X-Offline-Plain-Bytes': String(chunk.plainBytes),
                'X-Offline-Schema': String(chunk.schema),
            },
        }));
    }
}

export type RangeFetcher = (start: number, endExclusive: number) => Promise<ArrayBuffer>;

export async function resumeEncryptedAsset(
    key: CryptoKey,
    store: EncryptedChunkStore,
    identity: AssetIdentity,
    totalBytes: number,
    fetchRange: RangeFetcher,
): Promise<{ downloaded: number[]; reused: number[]; ciphertextBytes: number }> {
    const downloaded: number[] = [];
    const reused: number[] = [];
    let ciphertextBytes = 0;
    const count = Math.ceil(totalBytes / ENCRYPTION_CHUNK_BYTES);

    for (let index = 0; index < count; index += 1) {
        const existing = await store.get(identity, index);
        if (existing) {
            reused.push(index);
            ciphertextBytes += existing.ciphertext.byteLength + existing.iv.byteLength;
            continue;
        }

        const start = index * ENCRYPTION_CHUNK_BYTES;
        const endExclusive = Math.min(totalBytes, start + ENCRYPTION_CHUNK_BYTES);
        const plaintext = await fetchRange(start, endExclusive);
        if (plaintext.byteLength !== endExclusive - start) {
            throw new Error(`Range ${start}-${endExclusive} returned ${plaintext.byteLength} bytes`);
        }
        const chunk = await encryptChunk(key, identity, index, plaintext);
        await store.put(chunk);
        downloaded.push(index);
        ciphertextBytes += chunk.ciphertext.byteLength + chunk.iv.byteLength;
    }

    return { downloaded, reused, ciphertextBytes };
}

type NativeOfflineCryptoProbe = {
    bridgeReachable: boolean;
    keyNonExportable: boolean;
    keyPersistsAfterRestart: boolean;
    filesystemReachable: boolean;
};

type CapacitorWindow = typeof globalThis & {
    Capacitor?: {
        isNativePlatform?: () => boolean;
        Plugins?: {
            OfflineCrypto?: { probe: () => Promise<NativeOfflineCryptoProbe> };
            Filesystem?: object;
        };
    };
};

/**
 * Native PASS is deliberately impossible without a bridge that performs crypto
 * inside Android Keystore/iOS Keychain and never returns raw key material to JS.
 * A generic secure key/value plugin does not satisfy this contract.
 */
export async function probeNativeCrypto(): Promise<NativeOfflineCryptoProbe> {
    const capacitor = (globalThis as CapacitorWindow).Capacitor;
    const plugin = capacitor?.Plugins?.OfflineCrypto;
    if (!capacitor?.isNativePlatform?.() || !plugin) {
        return {
            bridgeReachable: false,
            keyNonExportable: false,
            keyPersistsAfterRestart: false,
            filesystemReachable: Boolean(capacitor?.Plugins?.Filesystem),
        };
    }

    const result = await plugin.probe();
    return {
        ...result,
        filesystemReachable: Boolean(capacitor.Plugins?.Filesystem),
    };
}

export async function measureWebCrypto(bytes = 8 * ENCRYPTION_CHUNK_BYTES): Promise<{
    encryptMs: number;
    decryptMs: number;
    storageOverheadPercent: number;
}> {
    const key = await createDeviceKey();
    const identity = { manifestVersion: 'spike-v1', assetId: 'benchmark' };
    const source = randomBytes(bytes);
    const chunks: EncryptedChunk[] = [];

    const encryptStarted = performance.now();
    for (let offset = 0, index = 0; offset < bytes; offset += ENCRYPTION_CHUNK_BYTES, index += 1) {
        chunks.push(await encryptChunk(
            key,
            identity,
            index,
            source.slice(offset, offset + ENCRYPTION_CHUNK_BYTES),
        ));
    }
    const encryptMs = performance.now() - encryptStarted;

    const decryptStarted = performance.now();
    for (const chunk of chunks) {
        await decryptChunk(key, chunk);
    }
    const decryptMs = performance.now() - decryptStarted;
    const stored = chunks.reduce(
        (sum, chunk) => sum + chunk.iv.byteLength + chunk.ciphertext.byteLength,
        0,
    );

    return {
        encryptMs,
        decryptMs,
        storageOverheadPercent: ((stored - bytes) / bytes) * 100,
    };
}

const diagnosticApi = Object.freeze({
    ENCRYPTION_CHUNK_BYTES,
    CacheEncryptedChunkStore,
    IndexedDbDeviceKeyVault,
    createDeviceKey,
    decryptChunk,
    encryptChunk,
    measureWebCrypto,
    probeNativeCrypto,
    resumeEncryptedAsset,
});

if (typeof window !== 'undefined') {
    Object.defineProperty(window, 'SystemaOfflineSpike', {
        configurable: false,
        enumerable: false,
        value: diagnosticApi,
        writable: false,
    });
}

declare global {
    interface Window {
        SystemaOfflineSpike: typeof diagnosticApi;
    }
}
