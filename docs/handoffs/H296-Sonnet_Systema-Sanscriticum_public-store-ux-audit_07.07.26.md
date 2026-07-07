# H296 — Systema public store UX audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

**Status:** 🟡 Queued
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\handoffs\H296-Sonnet_Systema-Sanscriticum_public-store-ux-audit_07.07.26.md and execute it.`

## Mission

Create a Russian-first, English-ready audit document for the public catalog and course selling pages. The desired product feel is Arzamas / Synchronization-style editorial catalog, not a generic ecommerce grid, while keeping purchase paths clear.

Deliverable: `docs/PUBLIC_STORE_UX_AUDIT_2026.md`.

## Read First

- `docs/STUDENT_CABINET_UX_AUDIT_2026.md` if present; otherwise PR #352.
- `docs/course-landing-spec.md`
- `docs/vitrina.md`
- `resources/views/shop/index.blade.php`
- `resources/views/livewire/shop/course-catalog.blade.php`
- `resources/views/components/shop/course-card.blade.php`
- `resources/views/shop/show.blade.php`
- `app/Livewire/Shop/CourseCatalog.php`
- `app/Http/Controllers/ShopController.php`

## Audit Focus

- Can a cold visitor understand live vs recorded, beginner vs advanced, Sanskrit vs Hindi/text/philosophy?
- Does the catalog feel editorial and trustworthy rather than noisy?
- Are course cards comparable without becoming pure ecommerce?
- Is the course page clear about outcomes, teacher, sample lesson, price, format, access after purchase?
- Are recorded courses visible as a library, not leftovers from live cohorts?

## Required Document Shape

1. North star for public store.
2. Current journey: `/online` -> course page -> tariff -> checkout.
3. Findings by screen.
4. Recommended IA: editorial shelves, live courses, recorded library, bundles later.
5. Prioritized tickets.
6. Acceptance metrics: course-page CTR, sample lesson play, tariff click, begin checkout.

## Guardrails

- Document-only PR. No Blade/code changes.
- Start from latest `origin/main`; PR #353 is already merged.
- This repo has a watcher that reverts uncommitted changes. Use watcher-safe commits: stage and commit only the audit document.
- Do not include unrelated local files such as `.claude/settings.local.json`, `dbg_resp.html`, or `docs/.playwright-mcp/`.

## Branch And PR

- Branch: `codex/public-store-ux-audit`
- PR title: `Add public store UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/PUBLIC_STORE_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
