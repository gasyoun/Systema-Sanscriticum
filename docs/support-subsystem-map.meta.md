# support-subsystem-map.meta.md — metadoc about `support-subsystem-map`

_Created: 13-07-2026 · Last updated: 25-07-2026_

Companion record for [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — the ground-truth agent reference for the Systema-Sanscriticum support subsystem. This metadoc holds what is *around* the document (purpose, provenance, backlog, caveats); it does not restate the subsystem facts the subject enumerates.

## Subject

- **Document link:** [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
- **Purpose:** Code-verified inventory of what the support subsystem actually is on `main` — the two message stores, the already-built infra, the identity mappings, the resolved naming landmine, and the genuinely-open punch-list — so an agent does not rebuild what exists or trip a naming collision.
- **Audience:** Any agent (Claude Code or Codex) or engineer about to build, extend, or reason about the support/helpdesk area of the Laravel app.
- **Format/contract:** A dated agent-reference note — TL;DR, comparison tables of models with full blob-URL links, a dated decision log, and a "still open" gap table keyed to jivo.md phases. It is descriptive ground truth, not a task tracker; open work is mirrored to handoffs/GTD, not driven from here.

## Provenance

- **Subject created:** 01-07-2026 (git first-add of `docs/support-subsystem-map.md`).
- **Metadoc authored:** 13-07-2026, handoff H890 (metadoc sweep II), Opus 4.8 (`claude-opus-4-8`).
- **Next hardening:** on the next substantive edit of the subject, verify the model/service links still resolve against `main` (files get renamed — the `SupportConversation`/`SupportDailyRollup` split is precisely this hazard) and re-check the "Actually open" table against merged support PRs.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Add a "last verified against `main` at a pinned commit hash" line near the header | The whole value proposition is "verified against code"; without a pinned commit a reader can't tell how stale the verification is | parked (no H### assigned) |
| 2 | Split the frozen dated decision log from the live "Actually open" table into clearly separate zones (or a linked sub-doc) | The doc mixes an immutable 01-07 log with a mutable 05-07 punch-list; readers skim past the live part | parked (low priority) |
| 3 | Add a one-line link-integrity check (CI or pre-commit) over the model/service blob URLs | Renames silently rot the ~25 code links this doc leans on | parked (tooling) |
| 4 | Cross-link each "Actually open" row to its owning handoff/issue where one exists | Turns the gap table into an actionable index rather than prose | parked (needs handoff IDs) |
| 5 | Fold in the reply-OUT canary result once WS1.3 runs | The single highest-risk untested path (row 6) will flip from "untested" to a verdict | parked (blocked on canary) |

## Known limitations / caveats

- The subject is a **point-in-time snapshot** — its accuracy decays every time a support model/service is renamed or moved. The "05-07-2026" verification date is the honest expiry marker.
- It leans heavily on ~25 hard-coded blob URLs into `app/Models`, `app/Services/Support`, and migrations; a file rename breaks them silently.
- It is descriptive, not authoritative for planning: the live queue of open work lives in handoffs/GTD, and this doc can lag those.
- One historical confabulation (an "XSS" finding that never existed) is documented inline as corrected — a reminder that earlier revisions carried at least one unverified claim.

## Intended use / known misuse

- **Intended:** the single **ground-truth** reference for the support area — read *before* building anything support-side to avoid rebuilding existing infra or reusing a taken model name.
- **Known misuse / the pairing that matters:** the subject is the code-verified counterweight to its companion [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md), whose **"current state" claims are unreliable** (sourced from Jivo help pages, not the repo). Treating jivo.md's "Как у нас сейчас" / open-questions inventory as fact is the exact failure this document exists to prevent. Use jivo.md only for product decomposition and roadmap *shape*; use this doc for what the code actually is.
- Do **not** treat the dated decision log as a live task list — those four decisions were closed 01-07-2026; genuinely-open work is in the "Actually open" table only.

## Maintenance & sunset plan

- **Cadence:** re-verify against `main` whenever the support subsystem is touched, and refresh the "Actually open" table as gaps close.
- **Owner:** whoever next builds in the support area inherits the verify-and-bump duty (bump "Last updated" on the subject, not this metadoc's provenance).
- **Sunset:** retire or archive when the two message stores are genuinely unified behind one operational model and jivo.md is decommissioned — at which point "two separate stores" ceases to be the core fact and the doc's framing no longer holds.

## Deprecation status

`active`

## Related documents

- [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — the subject.
- [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md) — companion product-strategy doc; **unreliable on current state**, this subject is its ground-truth counterweight.
- [support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md) — the canonical identity-reconciliation story referenced from the subject's Step 3.
- [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) — owns the reply-OUT canary (WS1.3).

## Revision history

| Date | Change | Model |
|---|---|---|
| 13-07-2026 | metadoc created (H890) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
