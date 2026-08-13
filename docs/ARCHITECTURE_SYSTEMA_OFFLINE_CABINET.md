# Architecture — Systema offline cabinet

_Created: 12-08-2026 · Last updated: 13-08-2026_

This layer implements the rulings in the [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md).
See the [roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_OFFLINE_CABINET_2026H2.md),
[implementation sequence](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_OFFLINE_CABINET.md),
and [verification](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md).

## Component boundaries

```text
Existing entitlement rules ──> OfflineLessonAccess ──> signed manifest + 30-day lease
                                                    └─> ranged asset delivery
Vite offline core ──> IndexedDB: manifests, lease, state, queue, acknowledgements
                  └─> AssetStore: Cache Storage (PWA) / Capacitor filesystem (mobile)
                      └─> independently encrypted chunks + per-device protected key
Offline reader ──> ProgressQueue ──> idempotent sync API ──> canonical progress models
```

The normal web cabinet remains server-rendered. Only the minimal offline reader is a cached
application shell. Online and offline paths share access evaluation and progress mutation
services so there is one source of truth.

## Manifest v1

A manifest is immutable by version and contains user-visible course/lesson identity,
normalized lesson blocks, one exercise definition, asset identifiers, MIME types, sizes,
SHA-256 hashes, encryption chunk size, dependencies, and a version. It contains no signed URL
that must remain valid for 30 days. Asset reads are authenticated, same-origin, range-capable,
and re-authorized while online. External/CDN course assets are excluded until normalized behind
this boundary.

## Lease and device model

The server registers an opaque UUID device and issues an asymmetrically signed lease bound to
user, device, lesson, and manifest version. The lease lasts 30 days and warns during the last
7. The client pins the verification public key; the signing private key remains server-only.
After expiry, encrypted lesson content is locked but unsynced operations remain available for
later sync. The client retains maximum trusted server time to detect obvious clock rollback.

## Storage and encryption

- Structured records: IndexedDB, partitioned by user and device.
- PWA/Windows assets: encrypted chunks in Cache Storage.
- Capacitor assets: encrypted chunks through a filesystem adapter.
- Default cryptography for the spike: AES-256-GCM, independent 1 MiB chunks, unique random
  96-bit IV per chunk, authenticated metadata binding manifest/asset/chunk identity.
- PWA key: non-exportable WebCrypto `CryptoKey` persisted in IndexedDB.
- Mobile key: protected by Android Keystore/iOS Keychain through a tracked, regenerable
  Capacitor plugin/configuration.

No plaintext asset is persisted. Logout destroys the current user's key and content after
queued progress has synced or the user explicitly accepts local-work loss. User switching never
shares namespaces. Encryption protects files at rest; CSP/XSS and dependency hygiene remain
part of the boundary.

## Progress operation contract

Each operation carries a client-generated UUID, device UUID, lesson, kind, client timestamp,
payload, and schema version. Server acknowledgement and mutation are transactional.

- Completion is monotonic and its Prana/telemetry side effects execute once.
- Maximum viewed position never decreases; current position/bookmark uses the newest trusted
  timestamp.
- Exercise attempts are append-only.
- A duplicate UUID returns the original acknowledgement and canonical state.
- Conflicts and rejected operations are traceable without storing lesson content or answers in
  reliability telemetry.

## Service-worker policy

Use separate versioned namespaces for the reader shell, encrypted chunks, and transient
responses. Activation deletes obsolete shell namespaces only. The current `public/sw.js`
behavior that deletes every other cache must be replaced before content caching. Never cache
authenticated Blade HTML, menus, CSRF tokens, or PII. Downloaded lesson navigation resolves to
the cached reader shell; online-only routes resolve to the existing offline explanation.

## Load-bearing platform gate

The Capacitor wrapper uses remote `server.url`, while `server.errorPath` is a bundled local
origin that cannot see remote-origin IndexedDB. Wave 0 must prove that the remote-origin service
worker can satisfy a cold offline launch in Android/iOS WebViews and still reach native secure
storage/filesystem bridges. A failure stops that platform. It does not authorize a second local
store, a native rewrite, or plaintext.

### Ruling — offline content is delivered on the same-origin PWA surface only (13-08-2026, H2634)

**The Capacitor wrapper is STOPPED for offline content.** Online it is unchanged: it remains
the remote-origin WebView shell that H824 shipped, and this ruling touches none of its Wave 1
behaviour. What it may not do is store or read encrypted lesson content.

The gate above is decided rather than left pending because the split is structural, not a
misconfiguration.
[`mobile/capacitor.config.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/capacitor.config.ts)
puts the WebView on `https://<cabinet-host>`, where the WebCrypto device key and the Cache
Storage ciphertext are written; `server.errorPath` is served from the bundled `webDir` on the
local scheme (`capacitor://localhost` on iOS). Same-origin policy denies the fallback page both
the key and the chunks, so a cold offline launch reaches a retry screen no matter how well the
crypto works. Capacitor's `server.url` model hosts **one** origin per WebView, so there is no
configuration in which the online remote page and an offline local-origin reader share a store.

Three exits existed. Only the third is available under the standing rulings:

| Exit | Verdict |
|---|---|
| **Local-origin app shell** — drop `server.url`, bundle the reader, fetch everything cross-origin with token auth | **Rejected.** This is the native rewrite the gate above forbids and the reuse verdict below excludes ("Reuse; add small hooks and one offline reader" · "Extend H1488/H824; no replacement wrapper"). It also moves authentication off the reused web session onto a cross-origin token flow — the session/auth consequence named below — for a delivery vehicle whose offline capability is already available elsewhere. |
| **Two origins in one WebView** — remote online, local offline, one store | **Not available.** Not a ruling but a platform fact: `server.url` fixes the WebView to a single origin, and the local bundle is reachable only as the load-error fallback. Any implementation degenerates into the exit above. |
| **Remote-origin only, no offline content in the wrapper** | **Ruled.** This is the response the verification contract pre-committed to: *"Capacitor remote/local origin split prevents cold offline launch → V0 spike; stop affected platform; do not create a second store implicitly."* |

**A platform stop here is not a capability stop.** Offline content is a same-origin problem, and
the installed-PWA surfaces are same-origin end to end — the service worker, the device key, and
the encrypted chunks all live under `https://<cabinet-host>`. Android Chrome and iPhone Safari
installed PWAs therefore stay fully in scope for V0, as does Windows installed Edge. What is
stopped is the store-wrapper *delivery vehicle*, not offline reading on Android or iOS.

**Consequence for session and auth: none.** Precisely because the local-origin exit is rejected,
the offline reader keeps the reused web session on the cabinet origin, and no cross-origin token
flow, CORS asset policy, or second authentication path is introduced by Wave 1.

**Consequence for the native secure-key bridge:** the tracked `OfflineCrypto` bridge exists to
protect a content key inside Android Keystore / iOS Keychain *for the wrapper*. With the wrapper
stopped for offline content, that bridge has no consumer, and it is stopped with it rather than
implemented speculatively — see [`mobile/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/README.md)
§ *Offline-cabinet Wave 0 gate*. `probeNativeCrypto()` continues to fail closed, which is now the
correct steady state rather than a missing implementation.

**Enforcement is mechanical, not prose.** `assertOfflineStorageAllowed()` in
[`resources/js/offline/spike.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/js/offline/spike.ts)
refuses to construct the Cache Storage chunk store or resume a download when
`Capacitor.isNativePlatform()` is true, so the "second store implicitly" outcome the gate forbids
cannot be reached by an ordinary code path.

**Reopening this ruling** needs a human decision, not a further spike, since the cost is a
different product shape rather than an unknown. It becomes worth revisiting if a store release
must ship offline reading *and* the installed PWA is judged insufficient on iOS (Safari's
storage eviction being the plausible trigger). The reopen path is the rejected local-origin exit
in full — bundled reader shell, cross-origin authenticated ranged asset delivery, and the
session/auth rework — costed as its own wave, never as a configuration change.

## Build-versus-reuse verdict

| Concern | Verdict |
|---|---|
| Cabinet and lesson UI | Reuse; add small hooks and one offline reader |
| Authentication | Reuse web session and Sanctum token routes |
| Entitlement | Extract and reuse current group/payment/grant evaluator; semantics unchanged |
| Progress | Reuse `lesson_user`, `lesson_views`, Prana, telemetry through shared services |
| PWA/Capacitor | Extend H1488 (Sonnet 5) — Cabinet mobile viewport audit and PWA shell, and H824 (Sonnet 5) — Mobile app Wave 1: Capacitor scaffold; no replacement wrapper |
| Offline manifest, lease, encrypted store, queue | New—the confirmed gap |
| Windows app | Reuse installable PWA; Tauri only after measured failure |

---

_Dr. Mārcis Gasūns_
