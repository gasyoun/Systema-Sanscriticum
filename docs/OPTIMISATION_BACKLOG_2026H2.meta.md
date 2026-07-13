# Metadoc — OPTIMISATION_BACKLOG_2026H2.md

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion record for
[`OPTIMISATION_BACKLOG_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPTIMISATION_BACKLOG_2026H2.md).

## Purpose

The single ranked index of what needs unblocking / speeding up / paying down in
Systema-Sanscriticum, replacing the prior state where that picture lived only in
`.ai_state.md` Dev Notes and was scattered across ~15 topic roadmaps. It is an **index**,
not a plan — the deep plans stay in the per-topic roadmaps it links to.

## Audience

MG (for the human-gated rows — deploy gate, secrets) and any agent picking up a Systema
optimisation thread who needs the leverage-ranked entry point rather than 15 roadmap files.

## Provenance

- Origin: handoff **H881** (Opus 4.8, `claude-opus-4-8`), 13-07-2026, in answer to "what
  needs optimisation at Systema?".
- Every row fact-checked against `origin/main` (worktree off `origin/main`), **not** the
  shared main checkout — which at authoring time sat on a stale feature branch
  (`docs/h569-memrise-roadmap`) showing a pre-upgrade `composer.json`. This is why the doc
  carries the explicit "verify against origin/main" warning on the Laravel row.

## Ranked improvement backlog (for this doc)

1. Add a per-row status token (open / prod-pending / shipped) once the deploy gate clears,
   so the doc can be pruned mechanically as migrations run.
2. Cross-link each row to its owning roadmap doc's metadoc once the queued `/metadoc` sweep
   (GTD:714) gives those 13 roadmaps metadocs.
3. Fold in a throughput baseline (§4) once the 15-min scheduled commands are instrumented —
   currently a "watch this" with no numbers.

## Limitations

- A snapshot, not a live dashboard — rows go stale as work ships; re-verify against
  `origin/main` before trusting any row, especially version/config claims.
- Leverage ranking is judgment, not measured throughput data.
- Scope is optimisation/bottleneck/tech-debt only; feature roadmaps are out of scope by
  design.

## Related docs

- [`Uprava/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md) — the §1 deploy-gate decision surface.
- [`Uprava/BOTTLENECKS.md`](https://github.com/gasyoun/Uprava/blob/main/BOTTLENECKS.md) — the org-level bottleneck hub this repo-local index feeds.
- `.ai_state.md` Dev Notes — the source the §2 dev-loop rows were consolidated from.

## Revision history

- 13-07-2026 — created (H881). Initial ranked index; Laravel-EOL row dropped as resolved by
  H862 (10→12) after re-verification against `origin/main`.
- 13-07-2026 — §3 corrected (H885 turn). Semgrep row → in-flight (H885/PR #509). Message-store
  divergence row **dropped** — the read-layer unification (`UnifiedMessage` +
  `UnifiedInboxReader`, built 01-07-2026 per `docs/support-subsystem-map.md`) already exists;
  the `.ai_state.md` "not yet unified" note was stale. Prior-art check prevented a duplicate
  read layer.

_Dr. Mārcis Gasūns_
