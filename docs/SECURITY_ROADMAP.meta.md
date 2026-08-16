# SECURITY_ROADMAP.meta.md — metadoc about `SECURITY_ROADMAP`

_Created: 13-07-2026 · Last updated: 16-08-2026_

Companion metadoc for the [Security & Vulnerability-Avoidance Roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md) — the durable record of why that roadmap exists, who consumes it, and how it stays honest.

## Subject

- **Document:** [docs/SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md)
- **Purpose:** Sequence the security hardening of a public-repo, paid, PII-holding education platform into ordered waves, each anchored to an MG ruling and an executable handoff.
- **Audience:** MG (decision owner + webhook/GC action items), agents executing the wave handoffs (H071/H080/H081), and future sessions checking what is already closed before re-proposing work.
- **Format/contract:** A wave-ordered roadmap (Waves 1–4) with a posture snapshot, an MG decisions table, per-wave exit criteria, and a non-goals list; each item carries a ✅/🟡/🟠/[ ] status and links to the handoff or PR that closed it.

## Provenance

- **Subject created:** 03-07-2026 (from a `/roadmap-interview`, Fable 5 `claude-fable-5`, grounded in a posture audit).
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** Wave 1 webhook `.env` deploy **closed 16-08-2026** (H2896 — secrets SET on prod, unsigned POSTs 403/401). Optional leftover: GitHub Support GC of orphaned SHA `8851c92`. Wave 3/4 already closed (H2476 / H2478–H2480 / H2529). App residuals from H2896 are latent hardening, not Wave-1 exposure.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Make the Semgrep PHP SAST job a required, blocking gate after triage | Wave 3 exit criterion demands CI catch new PHP defects before merge, not merely advise | ✅ delivered by H885 (PR #509) — subject's "flip to required" line should be reconciled to done |
| 2 | Flip the 3 fail-open webhooks (Telegram/VK/Zoom) to fail-closed via prod `.env` | Wave 1 exit-criterion gap | ✅ closed 16-08-2026 (H2896): code fail-closed + prod secrets SET; live unsigned POST 403/401 |
| 3 | Execute Wave 4 platform upgrade (Laravel 10→12, PHP 8.2→8.3) | Framework is security-EOL since ~Feb 2025; the largest standing exposure | ✅ done (H2478 doc-close 09-08-2026): Laravel v12.64.0 live; PHP 8.3.32 on prod nginx/fpm/Horizon; CI matrix PHP 8.3 only |
| 4 | Close out the orphaned `8851c92` SHA via a GitHub Support GC request | Purged PII is still fetchable by exact 40-char SHA until GitHub GC | parked (optional acceleration; needs MG-account support ticket) |
| 5 | Reconcile stale in-doc statuses after H885 (Semgrep now required) | The doc still reads "advisory / flip once tuned"; drift misleads a future reader | parked (doc-refresh pass, low risk) |

## Known limitations / caveats

- **Scope is Systema-Sanscriticum only** — it does not cover sibling repos, shared infrastructure, or the Cologne dictionary stack; a security fact here does not generalize.
- **Not a substitute for the findings** — Wave 2 sequences the H071 money-core findings but deliberately does not restate them; the defect detail lives in the handoff.
- **Staleness risk is high** — statuses are hand-maintained and move fast (Semgrep advisory→required inside a week). Trust the linked PR/handoff over the inline status when they disagree; the doc's own "Last updated: 07-07-2026" predates H885.

## Intended use / known misuse

- **For:** deciding what security work is next, confirming an exposure is already closed before re-proposing it, and finding the handoff that owns a given wave.
- **Misuse:** treating a ✅ as independently verified (verify against the cited PR); re-opening a settled non-goal (per-user breach notification, PHP CodeQL, folding security into the general roadmap) — each is explicitly ruled out with rationale; or mining it for the money-core defect specifics (those are in H071, not here).

## Maintenance & sunset plan

- **Kept alive by:** each wave-executing session flipping the item it closes to ✅ with the PR link, in the same pass — mirrored to the Uprava handoffs registry and GTD.
- **Archived/ended looks like:** all four waves at exit criterion (supported Laravel/PHP, no open high/critical Dependabot alerts, every webhook fail-closed, SAST required) — at which point the doc converts to a closed posture record and future security work opens a fresh track rather than reviving these waves.

## Deprecation status

`active`

## Related documents

- [docs/ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
- [SECURITY.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY.md)
- [docs/webhook-security.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md)
- [.github/workflows/semgrep.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/semgrep.yml)
- [docs/money-core-adversarial-review.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-core-adversarial-review.md)
- [docs/php-8.3-upgrade.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md)
- [docs/support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 `claude-opus-4-8` |
| 09-08-2026 | Wave 4 backlog row #3 closed; provenance note updated (H2478) | Sonnet 5 `claude-sonnet-5` |
| 14-08-2026 | Wave 3 Dependabot auto-merge keep-green closed (H2476); Approve no longer blocks Enable | Grok 4.6 `grok-4.6` |
| 14-08-2026 | Wave 4 dependency posture closed (H2479); report + Dependabot/CI coverage for mobile and lecture-builder | Grok 4.6 `grok-4.6` |
| 14-08-2026 | Wave 4 deploy-surface review closed (H2480); Sail healthcheck no longer interpolates `${DB_PASSWORD}` | Grok 4.6 `grok-4.6` |

_Dr. Mārcis Gasūns_
