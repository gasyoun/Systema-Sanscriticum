# Verification and risks — Systema offline cabinet

_Created: 12-08-2026 · Last updated: 13-08-2026_

Plan: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md) ·
roadmap: [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_OFFLINE_CABINET_2026H2.md) ·
architecture: [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md) ·
steps: [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_OFFLINE_CABINET.md).

## Acceptance gates

| Gate | Required evidence |
|---|---|
| V0 capability | Cold-start offline and read encrypted content on recent Android Chrome installed PWA, Windows 11 installed Edge PWA, and recent iPhone Safari installed PWA; non-exportable key survives restart; ciphertext copied to another device fails; no plaintext fallback. **Capacitor WebView rows (Android WebView / iOS WKWebView) are STOPPED, not pending** — H2634 ruled the wrapper's remote/local origin split unresolvable without the forbidden local-origin rewrite; see [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md) § Load-bearing platform gate |
| V1 vertical slice | Download online, enable airplane mode, cold-start, read text/PDF, play/seek audio, complete one exercise, bookmark, mark complete |
| V2 download integrity | Pause, app termination, network loss, Wi-Fi→cellular, duplicate tap, corrupt chunk, expired session, and manifest update all recover explicitly; checksum failure never marks complete |
| V3 lease | Deterministic fixtures for day 23, day 30, day 31, forward/backward clock skew; warning and lock correct; unsynced operations survive expiry |
| V4 merge | Completion and max position never regress; newest current position/bookmark wins; attempts append; duplicate UUID is a no-op including Prana/telemetry |
| V5 privacy/security | Disk/cache inspection finds no plaintext lesson asset or answer payload; signing private key absent from client; logout/user switching isolates or destroys keys/content; online-only surfaces remain unavailable offline |
| V6 budgets | Offline lesson opens ≤2 seconds on each target; encryption/download overhead ≤20%; metadata <10 MB/course; displayed storage estimate within ±10% |
| V7 dark launch | Flag off gives no UI, no endpoint behavior, and no writes; allowlist empty; kill switch tested; migrations reversible; existing online tests remain green |
| V8 pilot readiness | Reliability-only telemetry, documented rollback, 10–20-user allowlist, support runbook; deployment and invitation remain human-gated |

Use automated PHP tests for authorization, leases, idempotency, merge rules, and flags; JS tests
for crypto/storage/queue state machines; service-worker/browser tests for cached routing and
cache migration; and real-device evidence for V0, V1, V2, and V6. The acceptance report must
name exact device/OS/browser/WebView versions and commands used.

## Later hardening gate

Before widening beyond the internal pilot, add device reboot, full-disk/quota exhaustion,
browser eviction, and removable-storage disappearance. This is the user's staged R20 ruling:
it is not required to begin Wave 1, but it is mandatory before broad release.

## Risks and ruled responses

| Risk | Response |
|---|---|
| Capacitor remote/local origin split prevents cold offline launch | V0 spike; stop affected platform; do not create a second store implicitly — **fired 13-08-2026 (H2634): platform stopped for offline content; the "no second store" half is now enforced in code by `assertOfflineStorageAllowed()`** |
| Secure-key plugin is unmaintained or requires ignored native edits | Reject it; select a tracked/regenerable alternative or stop platform |
| WebCrypto lacks streaming AES-GCM | Independent bounded chunks; measure memory and overhead |
| Current service worker deletes unrelated caches (the encrypted content store) | Replace activation policy before storing content — **fired 13-08-2026 (H2634, [#1630](https://github.com/gasyoun/Systema-Sanscriticum/issues/1630)): `public/sw.js` now owns only the `ors-cabinet-shell-` namespace, pinned by `npm run test:sw-cache-migration`** |
| Browser/iOS eviction removes assets | Verify every open, expose repair/removal, request persistence where available |
| Clock rollback extends a lease | Maximum trusted server time + bounded skew; document that client clocks are not DRM |
| Replayed completion duplicates rewards | Unique operation UUID and transactional acknowledgement around shared mutation service |
| `lesson_user` contains duplicates | Audit first; no destructive cleanup without proven migration and tests |
| Third-party CDN resources break offline reader | Offline reader uses only locally built Vite assets |
| XSS exposes decrypted in-memory content | Tight CSP/dependency review; minimal reader surface; encryption is not advertised as DRM |
| External asset lacks range/CORS control | Exclude it from Wave 1; normalize behind same-origin delivery later |
| Storage estimate is advisory | Display estimate as estimate, validate free space, handle quota denial explicitly |
| Entitlement behavior drifts | One shared access evaluator; parity tests; no semantic change under this plan |

## Stop versus continue

Stop the affected platform or scope for potential data loss, plaintext persistence, weakened key
protection, entitlement bypass, secrets/PII exposure, or destructive migration. Continue through
ordinary failing tests by diagnosing them. A platform stop does not block independent platforms.

---

_Dr. Mārcis Gasūns_
