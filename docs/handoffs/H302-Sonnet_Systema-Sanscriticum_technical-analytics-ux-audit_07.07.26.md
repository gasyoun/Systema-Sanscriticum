# H302 — Systema technical analytics UX audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

**Status:** 🟡 Queued
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\handoffs\H302-Sonnet_Systema-Sanscriticum_technical-analytics-ux-audit_07.07.26.md and execute it.`

## Mission

Create an audit document for analytics, event naming, UI component readiness, performance risks, and safe implementation patterns for the cabinet/store UX roadmap.

Deliverable: `docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md`.

## Read First

- `docs/STUDENT_CABINET_UX_AUDIT_2026.md` if present; otherwise PR #352.
- PR #353 summary and changed files.
- `docs/vitrina.md` sections on analytics/events.
- `resources/views/shop/partials/*.blade.php` for existing `data-analytics` markers.
- `resources/views/student/partials/continue-learning-card.blade.php`
- `resources/views/checkout/show.blade.php`
- `app/Http/Controllers/StudentController.php`
- `app/Http/Controllers/ShopController.php`
- `package.json`, `vite.config.js`, `tailwind.config.js`
- existing analytics/report services under `app/Services/Reports` and tests mentioning analytics/events.

## Audit Focus

- Minimal event taxonomy: `view_course`, `sample_lesson_play`, `begin_checkout`, `purchase`, `first_lesson_opened`, `continue_learning_clicked`, `locked_reason_viewed`, `self_service_payment_started`, `support_started_from_context`, `recording_played`.
- Where to place non-invasive `data-analytics` attributes now.
- What should be backend events vs frontend events.
- Avoid breaking payment/access logic.
- Vite/Tailwind/CDN consistency and design-component duplication risks.
- Testing strategy for future UX PRs: feature tests + Playwright screenshots where needed.

## Required Document Shape

1. Technical north star.
2. Current instrumentation/readiness.
3. Event taxonomy and naming rules.
4. Data collection boundaries/privacy.
5. Component/performance risks.
6. Recommended implementation tickets.
7. Acceptance metrics and validation checklist.

## Guardrails

- Document-only PR. Do not wire analytics in this PR.
- Start from latest `origin/main`.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/technical-analytics-ux-audit`
- PR title: `Add technical analytics UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
