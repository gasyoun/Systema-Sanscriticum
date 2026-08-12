# Systema offline cabinet — execution plan, 2026 H2

_Created: 12-08-2026 · Last updated: 12-08-2026_

This plan turns the existing Systema student cabinet into a deliberately downloadable,
offline learning library on iPhone, Android, and Windows desktop. It extends—not replaces—the
current Blade/Livewire cabinet, PWA shell, Capacitor wrapper, Sanctum API, lesson access rules,
and progress models. Wave 1 proves one complete lesson with text, PDF, audio, an exercise,
encrypted device storage, a renewable 30-day lease, and lossless progress synchronization.

## Plan layers

- [Roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_OFFLINE_CABINET_2026H2.md)
- [Architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md)
- [Implementation](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_OFFLINE_CABINET.md)
- [Verification and risks](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md)

## Prior-art verdict

**PARTIAL — build only the offline-learning gap.** The existing
[mobile roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)
already locks a Capacitor hybrid wrapper, and H824 (Sonnet 5) — Mobile app Wave 1:
Capacitor scaffold shipped that wrapper under `mobile/`. H1488 (Sonnet 5) — Cabinet mobile
viewport audit and PWA shell shipped `public/manifest.webmanifest`, `public/sw.js`, and `public/offline.html`, documented
in the [mobile viewport/PWA audit](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MOBILE_VIEWPORT_CABINET_AUDIT_2026-07-24.md).
Today those layers provide installation and an error fallback only: they do not cache
authenticated lessons, package downloadable content, encrypt assets, lease access, or queue
progress. Existing `/api/v1`, `StudentController`, `lesson_user`, and `lesson_views` remain the
canonical access/progress system and must be extended rather than duplicated.

## Decisions taken

The following 28 rulings were given by M.G. in five interview rounds on 12-08-2026.

| # | Decision | Ruling |
|---|---|---|
| R1 | Offline meaning | Explicitly downloaded learning library |
| R2 | First content | Text, PDF, audio, exercises; video later |
| R3 | Offline state | Queue completion, exercises, bookmarks, and position; auto-sync |
| R4 | Platform order | Common baseline, then Android → Windows → iPhone depth |
| R5 | Online-only fence | Payments, live chat, Zoom/live classes, account/security, fresh entitlement checks |
| R6 | Common foundation | Offline-first web core shared by PWA and Capacitor |
| R7 | Selection UX | Explicit lesson/course download controls with size, state, and removal |
| R8 | Package contract | Versioned lesson manifests with hashes, sizes, assets, and dependencies |
| R9 | Local storage | IndexedDB for state; Cache Storage/native filesystem for large assets |
| R10 | Offline access | Renewable 30-day lease; warning at 7 days; preserve unsynced work after expiry |
| R11 | Conflicts | Completion monotonic; newest bookmark/position; attempts append-only |
| R12 | Wave 1 | One complete representative lesson vertical slice |
| R13 | Frontend | Isolated Vite TypeScript module with small Blade/Livewire hooks |
| R14 | Mobile assets | Shared storage interface; Capacitor filesystem adapter on phones |
| R15 | Downloads | Resumable pause/cancel, Wi-Fi preferred, cellular confirmation, checksums, space display |
| R16 | Windows | Installable Edge/Chrome PWA; native wrapper only after a measured gap |
| R17 | At-rest protection | Encrypt every downloaded asset with a per-device key |
| R18 | Devices | Recent iPhone, recent Android, constrained Android, Windows 11 Edge |
| R19 | Duration tests | 72 hours plus 23/30/31-day lease and clock-skew boundaries |
| R20 | Failure tests | Wave 1 interruption matrix; reboot/full/removable-storage hardening later |
| R21 | Integrity | Zero silent progress loss or regression; every queued operation traceable |
| R22 | Budgets | Open ≤2 s; encryption overhead ≤20%; metadata <10 MB/course; estimate ±10% |
| R23 | Exposure | Flagged internal pilot with 10–20 consenting students and instant disablement |
| R24 | Ambiguity | Apply marked reversible default, log it, and continue |
| R25 | Stop conditions | Stop for data loss, weakened encryption, entitlement bypass, keys/PII, destructive migration, or production credentials |
| R26 | Delivery authority | Branch, commit, push, PR, and merge green work; no deploy/flag/pilot without approval |
| R27 | Do-not-touch | Payments, entitlement semantics, live services, account security, secrets, source course files, store submission |
| R28 | Platform/encryption failure | Stop the affected platform only; never fall back to plaintext; additive flagged APIs/tables allowed |

## Autonomy contract

- On an unplanned non-security ambiguity, use the marked reversible default, record the choice,
  and continue. Park only the affected item if no reversible default exists.
- Halt the affected scope for potential data loss, plaintext fallback, weakened encryption,
  entitlement bypass, exposed secrets/PII, destructive migration, or missing production
  credentials. Diagnose and repair ordinary failing tests.
- Execution handoffs may branch, micro-commit, push, open PRs, and merge PRs that are green and
  within this plan. They may not deploy, enable `OFFLINE_CABINET_ENABLED`, populate the pilot
  allowlist, or invite students without explicit human approval.
- Do not change payment or entitlement meaning, live chat, Zoom/live-class behavior,
  account-security flows, production secrets, existing course source files, or store submission.
  Narrow shared access/progress interfaces and tests are permitted.
- If encryption, secure-key storage, or offline cold-start fails on one platform, stop that
  platform's release path and proceed with independent platforms. Never store downloadable
  assets in plaintext.

## Autonomy-readiness gate

**PASS for Wave 1.** Every deliverable has a named architecture contract, ordered file-level
steps, acceptance evidence, and risks. No blocking fork remains. The capability spike is an
intentional executable gate with a ruled failure policy, not an `@DECIDE`. Existing assets are
reused. Deployment and the student pilot remain correctly human-gated after implementation.

## Wave-0 execution

**Status: IN PROGRESS / release gate remains closed (12-08-2026).** The automated
PWA crypto/resume harness and tracked Capacitor filesystem dependencies shipped in
the planning branch. Six automated checks pass. Real Android, installed Windows PWA,
and iPhone/WKWebView cold-start measurements remain required by V0 and were not
available on the executor machine; therefore Wave 0 is deliberately not marked done
and Wave 1 must not start. Evidence and the exact continuation matrix are recorded in
[`OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md).

H2597 (Codex) — Offline cabinet Wave 0: encrypted storage and cold-start capability spike.

### Physical V0 successors

H2597 is now a partial parent: the automated harness is green in draft
[PR #1609](https://github.com/gasyoun/Systema-Sanscriticum/pull/1609), while three
independent Claude Code handoffs own physical verification. They may run concurrently
because each writes a separate evidence file and only its platform manifest row.

**H2617 (Opus 5) — Android/WebView device verification**
Folder: `C:\Users\user\Documents\GitHub\Systema-Sanscriticum` · model: Opus 5 (`claude-opus-5`)

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H2617-Opus_Systema-Sanscriticum_offline-cabinet-w0-android-webview-verification_12.08.26.md and execute it.
```

**H2618 (Sonnet 5) — Windows 11 installed Edge PWA verification**
Folder: `C:\Users\user\Documents\GitHub\Systema-Sanscriticum` · model: Sonnet 5 (`claude-sonnet-5`)

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H2618-Sonnet_Systema-Sanscriticum_offline-cabinet-w0-windows-edge-pwa-verification_12.08.26.md and execute it.
```

**H2619 (Opus 5) — iPhone Safari/WKWebView device verification**
Folder: `C:\Users\user\Documents\GitHub\Systema-Sanscriticum` on macOS/Xcode · model: Opus 5 (`claude-opus-5`)

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H2619-Opus_Systema-Sanscriticum_offline-cabinet-w0-iphone-wkwebview-verification_12.08.26.md and execute it.
```

H2597 (Codex) — Offline cabinet Wave 0: encrypted storage and cold-start capability spike
closes only after all three return evidenced PASS or platform-scoped STOP verdicts and
parent consolidation updates the aggregate report. A host-missing BLOCKED receipt is
bounded and useful, but it does not satisfy parent closure.
Until then PR #1609 stays draft, Wave 1 stays blocked, and no deploy/flag/pilot occurs.

```text
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md and execute it.
```

---

_Dr. Mārcis Gasūns_
