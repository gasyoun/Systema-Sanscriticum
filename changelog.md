# Changelog

All notable changes to Systema-Sanscriticum are documented here.

This repository does not currently publish versioned release notes. Entries use
dated maintenance snapshots; keep upcoming work under [Unreleased] until it is
ready for a dated entry.

## [Unreleased]

### Added
- WebinarProvider abstraction (GC-B3, H601): vendor-agnostic `App\Services\Webinar\WebinarProvider`
  interface (`createMeeting`/`fetchParticipants`/`normalizeWebhook`); `ZoomService` now
  implements it with no behaviour change to existing methods (extract-interface refactor);
  `schedules.meeting_*` generated columns alias `zoom_*` (reversible migration, no vendor
  name leak in the schema); `BigBlueButtonService` skeleton driver (method stubs + BBB
  checksum-API scaffolding, throws until the Q4 full-deployment ticket lands) — insurance
  against a Zoom departure per ruling R1
  ([docs/DECISIONS_roadmap_forks_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md)).
- 3-day diagnostic marathon, Phase 6 (H440): 13-day evergreen warm-tail for
  unpaid registrants (Days 4-16 off the personal `day0_started_at` clock),
  cycling own-pace/installments/teacher-credibility/one testimonial themes,
  no urgency framing; auto-stops once `paid_at` is set. Closes out H440's
  6-phase build plan.
- Configurable CRM lead stages (GC-C1): `lead_stages` table replaces the
  hardcoded `Lead::STATUSES`/`FINAL_STATUSES` constants, plus a Filament
  drag-drop kanban board (`/admin/leads-board`) alongside the existing flat
  leads table. Payment auto-convert behavior unchanged.
  [PR #408](https://github.com/gasyoun/Systema-Sanscriticum/pull/408)
- H431 Phase 1 (selling-layout journey layer on the homepage): hero rebuilt around a
  three-path learning trajectory (Письмо/чтение → Грамматика → Тексты/чанты) resolved
  to real courses (`App\Support\TrajectoryPaths`), a «Почему мы» credentials/format
  block, and a proof block (years/books/crowdfunding numbers from `config/trust.php`
  + real testimonial slots via `Testimonial::featured()`, honest empty-state until
  the testimonial-collection @DO closes).

## [1.1.1] - 2026-07-09

### Added
- Selling-layout roadmap adopted for samskrte.ru: 13-layer teardown vs
  sanskritorium.ru and samskrtam.ru + 6-phase plan (hero trajectory,
  «почему мы» + proof blocks, recorded-catalog conversion, free funnel,
  art direction, samskrtam.ru retrofit, book checkout). Spec:
  [SELLING_LAYOUT_COMPARISON_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/SELLING_LAYOUT_COMPARISON_2026.md)
  (private hub); Phase 1 queued as H431.

## [1.1.0] - 2026-07-09

### Added
- Tagged snapshot of the June–July feature run (course levels + «С чего
  начать» quiz, newsletter subscribe, «Материалы» magazine hub, manuals-to-UI
  content audit H301, getcourse-parity Q3 roadmap H438).

## [1.0.0] - 2026-06-13

### Added
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-приложение: учебный кабинет, магазин курсов, конструктор лендингов, редактор лекций и панель администратора.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys
