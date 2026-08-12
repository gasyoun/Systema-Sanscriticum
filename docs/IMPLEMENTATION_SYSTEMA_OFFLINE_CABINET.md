# Implementation — Systema offline cabinet Wave 1

_Created: 12-08-2026 · Last updated: 12-08-2026_

Execute under the [PLAN autonomy contract](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md).
Architecture: [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md) ·
verification: [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md) ·
programme order: [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_OFFLINE_CABINET_2026H2.md).

## Ordered build sequence

1. **Run the release-gating spike.** Add an isolated `resources/js/offline/spike.ts` and tracked
   Capacitor dependencies/configuration in `mobile/package.json` and `mobile/capacitor.config.ts`.
   Prove cold offline start from remote `server.url`, native bridge calls, non-exportable key
   persistence, 1 MiB AES-GCM chunks, ≤20% overhead, and partial PDF/audio resume on each target.
   Record measurements in a dated `docs/` spike report. Do not proceed on a failed platform.

2. **Add dark configuration and reversible schema.** Add `OFFLINE_CABINET_ENABLED=false`, an
   empty pilot allowlist, and `config/offline.php`. Create additive migrations/models for
   offline devices, download leases, progress operation acknowledgements, and exercise attempts.
   Use UUID operation uniqueness. Audit `lesson_user` duplicates before adding any uniqueness
   constraint; never delete or coalesce rows without an explicit safe migration.

3. **Extract the shared authorization boundary.** Implement
   `app/Services/Offline/OfflineLessonAccess.php` and narrowly route both existing lesson access
   and new offline endpoints through it. Preserve group, free lesson, personal grant, paid
   tariff, and publication behavior byte-for-byte; protect with focused parity tests.

4. **Implement devices, manifests, leases, and ranged assets.** Add flag-gated Sanctum routes in
   `routes/api.php`; API controllers/resources and services under `app/Http/Controllers/Api`,
   `app/Http/Resources`, and `app/Services/Offline`. Manifest v1 covers one representative lesson
   and its normalized text, PDF, audio, and exercise. Sign the 30-day device-bound lease and
   expose same-origin range requests with hash/size metadata.

5. **Implement idempotent progress sync.** Add `OfflineProgressSyncRequest`, controller, and
   `OfflineProgressMerger`. Batch UUID operations transactionally; return per-operation ack and
   canonical state. Route completion and heartbeat writes through shared services so retries do
   not duplicate progress, Prana, or telemetry. Encode the R11 merge rules as unit/feature tests.

6. **Build the shared TypeScript core.** Add `resources/js/offline/` modules for contracts,
   IndexedDB, crypto, lease validation, `AssetStore`, Cache/Capacitor adapters, resumable
   `DownloadManager`, and `ProgressQueue`; register a Vite entry. Verify the server hash before
   committing encrypted chunks. Persist no plaintext content.

7. **Replace the narrow service worker safely.** Update `public/sw.js` with distinct shell and
   encrypted-content namespaces and targeted cleanup. Add a locally built minimal offline-reader
   Vite entry and `resources/views/student/offline-reader.blade.php`. Never cache authenticated
   pages or CDN runtime assets. Extend `PwaShellAssetsTest` and JS tests.

8. **Add the vertical-slice UI.** Add `student/partials/offline-download.blade.php` to the lesson
   page behind the dark flag. Show content types, total size, available-space estimate,
   Wi-Fi/cellular decision, progress, pause/resume/cancel, update, and remove. The reader handles
   text, PDF, audio position, one exercise, completion, bookmark, queue state, lease warning, and
   expiry lock while preserving unsynced operations.

9. **Complete platform adapters.** Wire filesystem, network, and secure-key plugins only through
   tracked `mobile/` configuration and code; no required manual edits in ignored generated native
   projects. Update `mobile/README.md`. Validate Android first, installed Windows PWA second,
   iPhone baseline third.

10. **Close the release gate.** Add PHP, JS, service-worker, browser, and device evidence specified
    in VERIFICATION. Reliability telemetry may record outcomes, resume count, corruption, queue
    depth, sync result, conflicts, and lease state—but not content, answers, notes, or asset URLs.
    Leave the flag off and allowlist empty. Open/merge a green PR under R26; do not deploy.

## Required implementation defaults

- Prefer browser/native primitives and small adapters; do not add a frontend framework.
- Use schema/version discriminators on manifests, leases, encrypted chunks, and operations.
- Use server time for lease issuance and record maximum trusted time client-side.
- A storage quota denial or corrupt chunk produces a recoverable explicit state, never a partial
  “downloaded” marker.
- Any plugin selected must support tracked configuration and current Capacitor 8. Pin its version
  and document its security/maintenance evidence in the spike report.

---

_Dr. Mārcis Gasūns_
