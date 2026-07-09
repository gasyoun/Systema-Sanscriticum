# H298 — Systema first five minutes student UX audit

_Created: 07-07-2026 · Last updated: 09-07-2026_

> **Recreation note (07-07-2026, Sonnet 5 `claude-sonnet-5`):** originally minted in `Uprava/handoffs/` as part of a 7-item batch; MG closed the PR ([Uprava#13](https://github.com/gasyoun/Uprava/pull/13)) — *"these handoff files belong in Systema-Sanscriticum, not Uprava. Recreating them in the correct repo."* Recreated here verbatim (content unchanged) with only the Start-instruction path updated.

**Status:** ✅ Done — delivered as [PR #389](https://github.com/gasyoun/Systema-Sanscriticum/pull/389) (merged 09-07-2026); deliverable at [`docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md)
**Model:** intended executor **Sonnet 5** (`claude-sonnet-5`) — document-only UX audit, no code changes, standard tier. Minted by Sonnet 5 (`claude-sonnet-5`).
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`

## Mission

Create an audit document for activation after login: the student should understand the cabinet, open the first/next lesson, see support, and not need a manual.

Deliverable: `docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md`.

## Read First

- PR #353: Continue Learning block is merged.
- `resources/views/student/dashboard.blade.php`
- `resources/views/student/partials/continue-learning-card.blade.php`
- `resources/views/student/partials/onboarding-checklist.blade.php`
- `app/Http/Controllers/StudentController.php`
- `docs/onboarding-student.md`
- `docs/student-manual.md`
- `tests/Feature/CourseCardNextLessonTest.php`

## Audit Focus

- How the new Continue Learning card changes activation.
- Whether the old checklist now competes with the top card.
- Whether "Сменить пароль", bot blocks, cabinet notice, tabs, and support distract from learning.
- What progressive onboarding should replace the current checklist.
- What the empty/no-courses state should teach.

## Required Document Shape

1. Activation north star.
2. Current first-screen diagnosis after PR #353.
3. Proposed first-5-minutes path.
4. Progressive onboarding model.
5. Ticket list with acceptance criteria.
6. Metrics: first lesson opened, first homework draft, bot connect, support start.

## Guardrails

- Document-only PR. Do not alter the already-merged Continue Learning code.
- Start from latest `origin/main`.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/first-5-minutes-student-audit`
- PR title: `Add first five minutes student UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
