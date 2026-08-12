# Offline cabinet Wave 0 capability spike — 12-08-2026

## Verdict

**PARTIAL / NO-GO for Wave 1.** The shared PWA crypto and resumable-chunk contract is
implemented and green under automated tests. The V0 release gate remains closed because
this Windows executor has no JDK, Android SDK/ADB/device, installed-Edge automation target,
macOS/Xcode, or iPhone. Native crypto also has no accepted bridge yet. No platform stores
downloaded assets in plaintext and no weaker fallback was introduced.

## What shipped

- `resources/js/offline/spike.ts`: AES-256-GCM with independent 1 MiB chunks, random
  96-bit IVs, 128-bit tags, and AAD binding schema + manifest + asset + chunk + length.
- A non-exportable (`extractable=false`) WebCrypto device key persisted as a structured
  `CryptoKey` in IndexedDB.
- A resumable range contract that reuses completed encrypted chunks and downloads only
  missing ranges; malformed range lengths fail explicitly. PWA ciphertext is persisted by
  `CacheEncryptedChunkStore`; its response body contains only IV + ciphertext.
- A native `OfflineCrypto.probe()` contract that fails closed unless a native platform
  reports bridge reachability, non-exportability, restart persistence, and filesystem
  reachability. A generic secure key/value plugin cannot make this return PASS.
- Official Capacitor 8 `@capacitor/filesystem` 8.1.2 and
  `@capacitor/file-transfer` 2.0.5 dependencies in the tracked wrapper.

## Automated evidence

Environment: Windows, Node 24.15.0, npm 11.12.1, Vitest 3.2.7.

```text
npm run test:offline-spike
Test Files  1 passed (1)
Tests       6 passed (6)
```

The tests prove:

1. a non-exportable PWA key survives an IndexedDB reopen and cannot be exported;
2. AAD tampering with asset/index/size rejects decryption;
3. ciphertext copied to a different device key rejects decryption;
4. a partial three-chunk PDF resumes by reusing chunk 0 and fetching only chunks 1–2;
5. missing native crypto fails closed;
6. encoded storage expansion is below 20% (28 bytes per full 1 MiB chunk: 12-byte IV
   plus 16-byte GCM tag; approximately 0.0027%).

`npm run build` also passes with the spike registered as a Vite entry and exposing the
read-only `window.SystemaOfflineSpike` diagnostic API when that entry is loaded. These tests use
fake IndexedDB and are not represented as real Edge/iOS persistence evidence.

## Plugin/security finding

The maintained `@aparajita/capacitor-secure-storage` 8.x plugin was evaluated and
rejected for the content-encryption-key role. Its Android implementation protects stored
values with an Android Keystore key, but its key/value API returns the stored value to
JavaScript. That is appropriate for credentials but does not prove a non-exportable
content key performing AES operations behind the bridge. The official filesystem and
file-transfer plugins are retained only for asset I/O.

Primary references:

- <https://github.com/ionic-team/capacitor-plugins> (official Capacitor 8 plugins)
- <https://github.com/aparajita/capacitor-secure-storage> (evaluated secure store)

## Required continuation matrix

| Target | Required run | Current result | Gate |
|---|---|---|---|
| Recent Android/WebView | Install debug wrapper; load remote-origin spike online; download representative PDF/audio; terminate; disable network; cold-start; decrypt/read; resume interruption; copy ciphertext to second device | NOT RUN — JDK/SDK/ADB/device absent | STOPPED |
| Windows 11 installed Edge PWA | Install from remote origin; persist non-exportable key; cold-start offline; open/decrypt; restart; cross-profile copy negative test | NOT RUN — installed-PWA/browser automation target absent | STOPPED |
| Recent iPhone Safari/WKWebView | Repeat PWA and Capacitor remote-origin cold-start; verify bridge remains reachable offline; interruption/resume; cross-device negative test | NOT RUN — macOS/Xcode/iPhone absent | STOPPED |

For each run, record exact device model, OS, browser/WebView version, payload bytes,
encrypt/decrypt time, end-to-end download time with and without encryption, cold-open time,
and storage size. Encryption overhead must be ≤20% and cold open ≤2 seconds. A platform
may move to PASS only if the key is non-exportable, survives restart, ciphertext copied to
another device fails, and no plaintext asset appears in cache/filesystem inspection.

## Decision

Do not start Wave 1, deploy, or enable a flag. Continue Wave 0 on properly provisioned
Android, Windows, and iPhone targets. If no native bridge can perform AES with a
non-exportable platform key through tracked/regenerable configuration, stop Android/iPhone
rather than storing the key in JavaScript or plaintext.
