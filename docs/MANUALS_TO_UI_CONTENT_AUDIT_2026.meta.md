# MANUALS_TO_UI_CONTENT_AUDIT_2026.meta.md — metadoc about `MANUALS_TO_UI_CONTENT_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md) — it records the context around that audit (who it is for, where it came from, how to keep it current), not its content.

## Subject

- **Document:** [`MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md)
- **Purpose:** A document-only audit mapping knowledge that today lives only in the team manuals onto where a student should actually meet it inside the product (in-UI snippet, help page, or team-only), plus a prioritized list of content tickets. No UI or copy is changed by the audit itself.
- **Audience:** Product/UX owners, curators, and the developers who will implement the resulting snippets and help pages — not students directly.
- **Format / contract:** Plain Markdown analysis doc; three-tier content model, a manual inventory table, a manual-section → UI-surface mapping table, copy rules, and numbered tickets with an acceptance checklist. Advisory only — it defines target copy and treatment, it does not ship them.

## Provenance

- **Subject created:** 09-07-2026
- **Metadoc authored:** 13-07-2026, handoff H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`
- **Next hardening:** re-verify the "not yet built" claims (locked-lesson diagnostics, missing debt-tab microcopy) against the live Blade views before any ticket is picked up, since the codebase moves faster than this doc.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Add per-ticket completion tracking (link each ticket to the PR/commit that lands it) | The doc lists six content tickets but has no way to show which have shipped | parked (no owning H###) |
| 2 | Re-run the Blade-view scan that backs §2 and §3 and date-stamp it | The "confirmed no markup exists" findings are point-in-time and silently rot as views change | parked (needs code access) |
| 3 | Extract the drafted §5 copy into actual lang-string / partial keys | The audit calls for reusable shared partials but only holds the copy inline as prose | parked (implementation, not audit) |
| 4 | Cross-link this audit to its three sibling UX audits bidirectionally | The intro links out to siblings; the siblings may not link back | parked (sibling-repo edits) |
| 5 | Add a short glossary for the finding codes (`key_missing_for_paid_range`, etc.) | Codes are used without a single definition table a developer can trust | parked (low priority) |

## Known limitations / caveats

- The audit is a **snapshot**: its "already built" / "not yet built" verdicts depend on the state of `resources/views` at authoring time and are not automatically revalidated.
- It stops at target copy and treatment — it neither writes production strings nor guarantees the drafted Russian wording survives a native-copy pass.
- Structural/IA findings (e.g. the missing "Профиль" page) are explicitly deferred to a separate UX pass, so this doc is not a complete UX backlog.
- Finding-code coverage assumes the `access-self-service-spec.md` Phase-1 code set; new codes added to that service would not be reflected here.

## Intended use / known misuse

- **Intended:** as the content brief a developer or UX writer opens before implementing the locked-lesson snippet, debt-tab microcopy, homework tooltips, dictionary placeholder, prana-decay help page, or empty-state sweep.
- **Misuse:** treating it as shippable production copy (the Russian strings are drafts pending a copy pass), or as evidence of current UI state (its "not built" claims may already be stale).

## Maintenance & sunset plan

- **Owner:** Systema-Sanscriticum product/UX.
- **Cadence:** revisit whenever a listed ticket ships or the student-cabinet manuals change materially; re-scan the Blade views at the same time.
- **Sunset:** when all six tickets are implemented and the acceptance checklist is fully met, retire the audit into the shipped-work record (or fold it into a living content-guidelines doc) rather than leaving it as an open plan.

## Deprecation status

`active` — no ticket from the audit has shipped yet; it remains the current content plan.

## Related documents

- [`MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md) — the subject
- [`PUBLIC_STORE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PUBLIC_STORE_UX_AUDIT_2026.md) — sibling UX audit
- [`CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md) — sibling UX audit
- [`FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md) — sibling UX audit
- [`student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) · [`onboarding-student.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) · [`access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) · [`debtor-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md) — source manuals the audit draws on

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
