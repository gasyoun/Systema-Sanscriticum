# Offline cabinet implementation manifest

_Created: 12-08-2026 · Last updated: 13-08-2026_

| Wave | Status | Flag | Implementation | Tests | Evidence |
|---|---|---|---|---|---|
| 0 — capability/security spike | IN PROGRESS; V0 physical matrix pending | No product flag introduced | `resources/js/offline/spike.ts`; Capacitor filesystem/file-transfer 8.x pins | `npm run test:offline-spike` — 6 passed | `docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md` |
| 0 — Windows 11 installed Edge PWA (physical row, H2618) | **STOP / BLOCKED** — host is Windows 10 Pro build 19045, not Windows 11; site could not be genuinely installed as an Edge PWA on this host (`no-manifest` from Chromium's own installability engine despite a spec-valid manifest — 5 repair attempts did not resolve it) | No product flag introduced | Real-browser (Edge 151, non-installed tab) crypto/storage proofs against the built `spike.ts` bundle: non-exportable key + restart persistence, AAD-tamper rejection, cross-device rejection, resumable Cache Storage chunk reuse, ≤20% overhead, offline-fallback shell — all supplementary, non-substitutive for the blocked install gate | `docs/OFFLINE_CABINET_WAVE0_WINDOWS_EVIDENCE_2026-08-12.md` |
| 1 — representative lesson | NOT STARTED; blocked by Wave 0 | Must default OFF | — | — | — |

Wave 0 is not “done” until every required target has real device/installed-PWA evidence.
Automated Node/fake-IndexedDB results are supporting evidence, not a substitute for V0.

## V0 platform rows

| Target | Lane | Verdict | Blockers | Evidence |
|---|---|---|---|---|
| Recent iPhone | Installed Safari PWA | BLOCKED — host missing | No macOS/Xcode/iPhone; **B1** service worker deletes the encrypted chunk cache on activation | [OFFLINE_CABINET_WAVE0_IPHONE_EVIDENCE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_IPHONE_EVIDENCE_2026-08-12.md) |
| Recent iPhone | Capacitor remote-origin WKWebView | BLOCKED — host missing | No macOS/Xcode/iPhone; **B2** remote/local origin split leaves the offline fallback without the store; **B3** no tracked `OfflineCrypto` bridge | same file |

B1–B3 are tracked-configuration defects, not host defects: each forecloses PASS on a
fully provisioned iPhone and must be cleared before macOS/device time is booked.
