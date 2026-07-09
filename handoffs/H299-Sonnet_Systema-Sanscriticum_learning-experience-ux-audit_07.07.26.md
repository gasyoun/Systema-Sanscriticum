# H299 — Systema learning experience UX audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

> **Recreation note (07-07-2026, Sonnet 5 `claude-sonnet-5`):** originally minted in `Uprava/handoffs/` as part of a 7-item batch; MG closed the PR ([Uprava#13](https://github.com/gasyoun/Uprava/pull/13)) — *"these handoff files belong in Systema-Sanscriticum, not Uprava. Recreating them in the correct repo."* Recreated here verbatim (content unchanged) with only the Start-instruction path updated.

**Status:** 🟡 Ready for review — [#391](https://github.com/gasyoun/Systema-Sanscriticum/pull/391)
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\handoffs\H299-Sonnet_Systema-Sanscriticum_learning-experience-ux-audit_07.07.26.md and execute it.`

## Mission

Create an audit document for the course home and lesson page: learning should feel calm, guided, and recording-friendly.

Deliverable: `docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md`.

## Read First

- `resources/views/student/course.blade.php`
- `resources/views/student/lesson.blade.php`
- `resources/views/student/partials/homework.blade.php`
- `resources/views/student/partials/homework-alerts.blade.php`
- `app/Http/Controllers/StudentController.php` methods `showCourse`, `showLesson`, `completeLesson`, `saveNote`
- `app/Models/Lesson.php`
- `docs/student-manual.md`
- `tests/Feature/Student/LessonAccessTest.php`
- `tests/Feature/LessonMaterialsAccessTest.php`
- `tests/Feature/CourseCardNextLessonTest.php`

## Audit Focus

- Course page as `course home`, not a mini landing page.
- Next lesson, progress, materials, and module states.
- Lesson page hierarchy: video, lesson status, transcript, files, notes, homework.
- Mobile behavior.
- Recorded lessons: "watch at your pace" clarity.
- Locked lessons and block purchase explanation as inputs to Self-Service Support audit.

## Required Document Shape

1. Learning north star.
2. Course home findings.
3. Lesson page findings.
4. Recorded-course learning mode.
5. Prioritized tickets.
6. Acceptance metrics: lesson start, lesson complete, recording play, homework submit.

## Guardrails

- Document-only PR. No Blade/code changes.
- Start from latest `origin/main`.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/learning-experience-ux-audit`
- PR title: `Add learning experience UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
