# H300 — Systema self-service support UX audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

**Status:** 🟡 Queued
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\handoffs\H300-Sonnet_Systema-Sanscriticum_self-service-support-ux-audit_07.07.26.md and execute it.`

## Mission

Create an audit document that turns "I cannot login / where is my course / why is this locked / where is recording / how do I pay?" into contextual help and self-service paths before a human curator is needed.

Deliverable: `docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`.

## Read First

- `docs/access-self-service-spec.md`
- `docs/debtor-self-service-spec.md`
- `docs/debtor-self-service-phase2-spec.md`
- `docs/support-subsystem-map.md`
- `docs/support-identity.md`
- `docs/cabinet-bot.md` if present
- `resources/views/student/dashboard.blade.php`
- `resources/views/student/messages.blade.php`
- `resources/views/livewire/student-chat.blade.php`
- `app/Services/StudentDebtsService.php`
- `app/Services/DebtPaymentResolver.php`
- support tests under `tests/Feature/Support/`
- debt tests under `tests/Feature/Student/`

## Audit Focus

- "Помощь" entry point organized by problem, not channel.
- `Почему закрыто?` pattern for lessons/blocks.
- Access recovery vs not purchased vs debt vs recording not ready.
- Bot positioning: notification/support enhancement, not required onboarding.
- Human escalation: "позвать куратора".
- What support context should be passed to admins/curators.

## Required Document Shape

1. Support north star.
2. Current support surfaces.
3. Top student problem taxonomy.
4. Recommended self-service flows.
5. UI/contextual-help tickets.
6. Metrics: support deflection, context-rich support starts, debt self-service starts.

## Guardrails

- Document-only PR. Do not change support routing or bot behavior.
- Start from latest `origin/main`.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/self-service-support-audit`
- PR title: `Add self-service support UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
