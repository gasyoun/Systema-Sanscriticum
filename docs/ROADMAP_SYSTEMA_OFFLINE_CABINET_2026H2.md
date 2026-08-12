# Systema offline cabinet roadmap — 2026 H2–2027

_Created: 12-08-2026 · Last updated: 12-08-2026_

Binding decisions and autonomy rules live in the
[PLAN index](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md).
Technical boundaries are in the [architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md),
the Wave-1 sequence in [implementation](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_OFFLINE_CABINET.md),
and gates in [verification](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md).

## Goal

A student deliberately downloads learning, opens it after a cold start without a network,
studies text/PDF/audio and completes an exercise, then reconnects without losing or regressing
progress. The same manifest, lease, crypto, queue, and merge contracts serve iPhone, Android,
and Windows; platform adapters exist only where storage/key APIs genuinely differ.

## Waves

### Wave 0 — capability and security spike

**Status: IN PROGRESS — automated web harness green; physical V0 platform matrix pending.**
See the [12-08-2026 spike record](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md).

Prove remote-origin Capacitor cold-start offline, native bridge reachability, non-exportable
per-device keys, chunked AES-GCM performance, resumable encrypted storage, and Windows PWA key
persistence. Android is first; Windows follows; iPhone must pass the common baseline. A failing
platform stops without blocking the others and without plaintext fallback.

**Unblocks:** commitment to storage plugins, crypto format, and remote-origin topology.
**Exit:** measured spike record satisfies V0 criteria in the verification document.

### Wave 1 — representative lesson vertical slice

Add dark flags, additive device/lease/operation tables, a shared access evaluator, manifest and
ranged-asset APIs, 30-day signed leases, an idempotent progress merge API, TypeScript download
and sync modules, an offline reader, and explicit lesson-download UI. The representative lesson
contains normalized text, one PDF, one audio asset, and one exercise. Reliability telemetry
contains no content, answers, notes, or asset URLs.

**Unblocks:** internal dogfood on Android, Windows PWA, and recent iPhone.
**Exit:** V1–V8 pass; feature flag remains off and allowlist empty.

### Wave 2 — internal pilot

After human approval, deploy with the flag off, populate an allowlist of 10–20 consenting
students, validate real 72-hour offline use, measure download/sync failure rates and storage
estimates, and exercise the kill switch. Fix defects without widening scope.

**Unblocks:** course-level downloads and broader content formats.
**Exit:** zero silent loss, no entitlement bypass, budgets met, rollback rehearsed.

### Wave 3 — course packages and platform hardening

Compose verified lesson manifests into course downloads; add update/removal/storage management;
test device reboot, full disk, browser eviction, and removable-storage failure; deepen Android,
then Windows, then iPhone. Add background transfer only where the platform contract remains
regenerable from tracked Capacitor configuration.

**Unblocks:** wider opt-in beta.
**Exit:** failure-recovery matrix passes and no required native behavior lives only in ignored
`mobile/android` or `mobile/ios` files.

### Wave 4 — video feasibility and broad release

Run a separate video spike covering provider rights, download URLs, storage, encryption,
seek/range behavior, and iPhone eviction. Video ships only if it meets the same encrypted,
leased, resumable contract. Broaden availability gradually after adoption and reliability data.

**Exit:** human GO after measured pilot results; not implied by earlier green PRs.

## Non-goals

- No native UI rewrite, Flutter/React Native application, or separate Windows codebase.
- No automatic mirror of every entitled course.
- No video in Wave 1.
- No offline payments, chat, Zoom/live sessions, account/security editing, or entitlement changes.
- No custom claim that encryption is DRM; it is at-rest protection on a non-compromised device.
- No caching of authenticated Blade pages, CSRF tokens, navigation containing PII, or source
  course directories.

## Human gates

Deployment, flag activation, pilot membership, student invitations, native signing/store work,
and broad release require explicit approval. Implementation PRs may otherwise be merged when
green under R26.

---

_Dr. Mārcis Gasūns_
