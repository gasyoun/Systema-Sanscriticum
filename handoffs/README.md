# Systema-Sanscriticum handoffs — UX audit queue

_Created: 07-07-2026 · Last updated: 05-09-2026_

Repo-local handoffs, distinct from the org-wide [`Uprava/handoffs/`](https://github.com/gasyoun/Uprava/tree/main/handoffs)
hub. MG ruled (closing [Uprava PR #13](https://github.com/gasyoun/Uprava/pull/13)) that this
7-item UX-audit batch belongs in Systema-Sanscriticum itself, not in Uprava — each item
produces a `docs/*_UX_AUDIT_2026.md` deliverable that lives and is consumed entirely within
this repo, so the task-spec should live here too.

Each file is started the same way Uprava handoffs are, by pasting its one-line starter into
a fresh session.

| ID | File | Status | Deliverable |
|---|---|---|---|
| H296 | [H296-Sonnet_Systema-Sanscriticum_public-store-ux-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H296-Sonnet_Systema-Sanscriticum_public-store-ux-audit_07.07.26.md) | ✅ Done — [PR #355](https://github.com/gasyoun/Systema-Sanscriticum/pull/355) merged | [`docs/PUBLIC_STORE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PUBLIC_STORE_UX_AUDIT_2026.md) |
| H297 | [H297-Sonnet_Systema-Sanscriticum_checkout-purchase-ux-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H297-Sonnet_Systema-Sanscriticum_checkout-purchase-ux-audit_07.07.26.md) | ✅ Done — [PR #379](https://github.com/gasyoun/Systema-Sanscriticum/pull/379) merged | [`docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) |
| H298 | [H298-Sonnet_Systema-Sanscriticum_first-5-minutes-student-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H298-Sonnet_Systema-Sanscriticum_first-5-minutes-student-audit_07.07.26.md) | ✅ Done — [PR #389](https://github.com/gasyoun/Systema-Sanscriticum/pull/389) merged | [`docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md) |
| H299 | [H299-Sonnet_Systema-Sanscriticum_learning-experience-ux-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H299-Sonnet_Systema-Sanscriticum_learning-experience-ux-audit_07.07.26.md) | 🟡 Queued | `docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md` |
| H300 | [H300-Sonnet_Systema-Sanscriticum_self-service-support-ux-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H300-Sonnet_Systema-Sanscriticum_self-service-support-ux-audit_07.07.26.md) | 🟡 Queued | `docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md` |
| H301 | [H301-Sonnet_Systema-Sanscriticum_manuals-to-ui-content-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H301-Sonnet_Systema-Sanscriticum_manuals-to-ui-content-audit_07.07.26.md) | 🟢 Delivered — [PR #401](https://github.com/gasyoun/Systema-Sanscriticum/pull/401) draft | [`docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md) |
| H302 | [H302-Sonnet_Systema-Sanscriticum_technical-analytics-ux-audit_07.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H302-Sonnet_Systema-Sanscriticum_technical-analytics-ux-audit_07.07.26.md) | 🟡 Queued | `docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md` |

All 7 are document-only PRs (no Blade/PHP/route changes) — see each file's Guardrails
section. This repo has a confirmed watcher process that reverts uncommitted working-tree
changes; land any edits here via `/watcher-safe-commit`.

_Dr. Mārcis Gasūns_
