# H301 — Systema manuals to UI content audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

**Status:** 🟡 Queued
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\handoffs\H301-Sonnet_Systema-Sanscriticum_manuals-to-ui-content-audit_07.07.26.md and execute it.`

## Mission

Create an audit document mapping student-facing manuals into contextual UI snippets, empty states, help cards, and short help pages. The manuals should remain source-of-truth for the team, but normal students should not need to read them.

Deliverable: `docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md`.

## Read First

- `docs/student-manual.md`
- `docs/onboarding-student.md`
- `docs/admin-manual.md` if present
- `docs/finance-manual.md` only for boundaries, not student UI
- `docs/access-self-service-spec.md`
- `docs/debtor-self-service-spec.md`
- `resources/views/student/dashboard.blade.php`
- `resources/views/student/course.blade.php`
- `resources/views/student/lesson.blade.php`
- `resources/views/checkout/show.blade.php`

## Audit Focus

- Which manual sections belong inside UI.
- Which belong in help pages.
- Which are team-only and should never surface to students.
- Copy style: Russian-first, concise, English-ready key concepts.
- Reusable text snippets for login, access, payments, homework, dictionary, support, recordings.
- Avoid long visible explanatory blocks in working screens.

## Required Document Shape

1. Content north star.
2. Manual inventory.
3. Mapping table: manual section -> UI surface -> snippet/help page/team-only.
4. Copy rules.
5. Prioritized content tickets.
6. Acceptance: every frequent student question has a contextual UI answer.

## Guardrails

- Document-only PR.
- Do not edit existing manuals unless noting contradictions in the new audit.
- Start from latest `origin/main`.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/manuals-to-ui-audit`
- PR title: `Add manuals to UI content audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
