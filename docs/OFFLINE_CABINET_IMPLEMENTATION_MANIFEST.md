# Offline cabinet implementation manifest

_Created: 12-08-2026 · Last updated: 13-08-2026_

| Wave | Status | Flag | Implementation | Tests | Evidence |
|---|---|---|---|---|---|
| 0 — capability/security spike | IN PROGRESS; V0 physical matrix pending | No product flag introduced | `resources/js/offline/spike.ts`; Capacitor filesystem/file-transfer 8.x pins | `npm run test:offline-spike` — 8 passed | `docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md` |
| 0 — B1 service-worker cache ownership (H2634, [#1630](https://github.com/gasyoun/Systema-Sanscriticum/issues/1630)) | **FIXED** — `public/sw.js` activation reaped every sibling cache, including the encrypted content store, on every deploy (`skipWaiting` + `clients.claim`); replaced with a versioned allow-list over the `ors-cabinet-shell-` namespace it owns | No product flag introduced | `public/sw.js` | `npm run test:sw-cache-migration` — 5 passed; the same suite fails 4/5 against the pre-fix worker | Migration test evaluates the tracked `public/sw.js` itself: seeds a sibling content cache, activates a newer worker version, asserts the ciphertext survives |
| 0 — B2 Capacitor remote/local origin split (H2634) | **STOP — platform stopped for offline content**, online wrapper unchanged. `server.url` (remote origin) writes the key + ciphertext; `errorPath` is served from the local bundle scheme, and same-origin policy denies it both. Capacitor hosts one origin per WebView, so the only exit is a local-origin rewrite the reuse ruling forbids. Offline reading is delivered on the same-origin installed-PWA surfaces (Android Chrome, iPhone Safari, Windows Edge) — a delivery-vehicle stop, not a capability stop | No product flag introduced | Ruling in `docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md` § Load-bearing platform gate; enforced by `assertOfflineStorageAllowed()` in `resources/js/offline/spike.ts`; recorded in `mobile/capacitor.config.ts` + `mobile/README.md` | `npm run test:offline-spike` — the store constructor and `resumeEncryptedAsset` both refuse on a simulated native platform, with no range fetched | Reopening is a human decision costed as its own wave, not a config change |
| 0 — B3 native `OfflineCrypto` bridge (H2634) | **STOP — not owed.** The bridge protects a content key inside the Capacitor wrapper; with B2 stopping that wrapper for offline content it has no consumer, so no Swift/Kotlin plugin is authored. `probeNativeCrypto()` returning all-false on a native platform is now the correct steady state, not a gap | No product flag introduced | `resources/js/offline/spike.ts` (probe kept, fail-closed); rationale in `mobile/README.md` § Offline-cabinet Wave 0 gate | `npm run test:offline-spike` — fail-closed probe covered | No device evidence claimed. If the ruling is reopened, this probe is the first gate that must pass |
| 0 — Windows 11 installed Edge PWA (physical row, H2618) | **STOP / BLOCKED** — host is Windows 10 Pro build 19045, not Windows 11; site could not be genuinely installed as an Edge PWA on this host (`no-manifest` from Chromium's own installability engine despite a spec-valid manifest — 5 repair attempts did not resolve it) | No product flag introduced | Real-browser (Edge 151, non-installed tab) crypto/storage proofs against the built `spike.ts` bundle: non-exportable key + restart persistence, AAD-tamper rejection, cross-device rejection, resumable Cache Storage chunk reuse, ≤20% overhead, offline-fallback shell — all supplementary, non-substitutive for the blocked install gate | `docs/OFFLINE_CABINET_WAVE0_WINDOWS_EVIDENCE_2026-08-12.md` |
| 0 — iPhone Safari PWA + Capacitor WKWebView (physical rows, H2619) | Lane A (Safari PWA) **BLOCKED — host missing**, still owed and now unobstructed: B1 is fixed and B2/B3 do not apply to a same-origin PWA. Lane B (Capacitor WKWebView) **STOPPED** by the B2 ruling — no device session is owed for it | No product flag introduced | — | — | `docs/OFFLINE_CABINET_WAVE0_IPHONE_EVIDENCE_2026-08-12.md` (PR [#1627](https://github.com/gasyoun/Systema-Sanscriticum/pull/1627)) |
| 0 — Android Chrome PWA + Capacitor WebView (physical rows, H2617) | Lane A (installed Chrome PWA) **BLOCKED — host missing**, still owed and now unobstructed — same disposition as the iPhone Safari lane. Lane B (Capacitor Android WebView) **STOPPED** by the B2 ruling — no device session is owed for it. H2617 ran *before* H2634 landed and inventoried the Capacitor build toolchain (JDK 21 / SDK API 36 / ADB), which lane B no longer needs; the surviving lane-A blocker is narrower — **an Android device with Chrome, no Android toolchain at all** | No product flag introduced | None — no code written | Not re-run — Node/`fake-IndexedDB` results are not Android evidence | `docs/OFFLINE_CABINET_WAVE0_ANDROID_EVIDENCE_2026-08-12.md` |
| 1 — representative lesson | NOT STARTED; blocked by Wave 0 | Must default OFF | — | — | — |

Wave 0 is not “done” until every required target has real device/installed-PWA evidence.
Automated Node/fake-IndexedDB results are supporting evidence, not a substitute for V0.

## V0 platform rows

| Target | Lane | Verdict | Blockers | Evidence |
|---|---|---|---|---|
| Recent iPhone | Installed Safari PWA | BLOCKED — host missing; **unobstructed since 13-08-2026** | No macOS/Xcode/iPhone. **B1 cleared** (H2634); B2/B3 do not apply — an installed PWA is same-origin end to end | [OFFLINE_CABINET_WAVE0_IPHONE_EVIDENCE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_IPHONE_EVIDENCE_2026-08-12.md) |
| Recent iPhone | Capacitor remote-origin WKWebView | **STOPPED — platform ruled out for offline content** (H2634 B2), no longer host-blocked | **B2** ruled 13-08-2026: the remote/local origin split is unresolvable without the forbidden local-origin rewrite. **B3** stopped with it — the bridge has no consumer | [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md) § Load-bearing platform gate |
| Recent Android | Installed Chrome PWA | BLOCKED — host missing; **unobstructed since 13-08-2026** | No Android device attached (USB enumerates a webcam, card reader and hubs only). **B1 cleared** (H2634); B2/B3 do not apply — an installed PWA is same-origin end to end. Needs **only a device with Chrome** — no JDK/SDK/ADB | [OFFLINE_CABINET_WAVE0_ANDROID_EVIDENCE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_ANDROID_EVIDENCE_2026-08-12.md) |
| Recent Android | Capacitor remote-origin WebView | **STOPPED — platform ruled out for offline content** (H2634 B2), no longer host-blocked | Same B2/B3 ruling as WKWebView — Capacitor hosts one origin per WebView regardless of platform. The JDK 21 / SDK API 36 / ADB inventory H2617 recorded belongs to **this** stopped lane | [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md) § Load-bearing platform gate |

B1–B3 were tracked-configuration defects, not host defects: each foreclosed PASS on a
fully provisioned iPhone, which is why they were cleared before macOS/device time was
booked. All three are now disposed of — B1 fixed, B2 and B3 stopped.

**What a device session still owes after H2634:** installed-PWA cold-start evidence on
Android Chrome, iPhone Safari, and a real Windows 11 Edge host. The Capacitor WKWebView /
Android WebView rows are stopped, not pending, so no macOS or Xcode provisioning is needed
for Wave 0 any more.

---

_Dr. Mārcis Gasūns_
