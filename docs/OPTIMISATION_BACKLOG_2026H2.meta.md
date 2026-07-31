# Metadoc — OPTIMISATION_BACKLOG_2026H2.md

_Created: 13-07-2026 · Last updated: 31-07-2026_

Companion record for
[`OPTIMISATION_BACKLOG_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPTIMISATION_BACKLOG_2026H2.md).

## Purpose

The single ranked index of what needs unblocking / speeding up / paying down in
Systema-Sanscriticum, replacing the prior state where that picture lived only in
`.ai_state.md` Dev Notes and was scattered across ~15 topic roadmaps. It is an **index**,
not a plan — the deep plans stay in the per-topic roadmaps it links to.

## Audience

MG (for the human-gated rows — secrets, findir money flags, channel publish) and any agent
picking up a Systema optimisation thread who needs the leverage-ranked entry point rather
than 15 roadmap files.

## Provenance

- Origin: handoff **H881** (Opus 4.8, `claude-opus-4-8`), 13-07-2026, in answer to "what
  needs optimisation at Systema?".
- Every row fact-checked against `origin/main` (worktree off `origin/main`), **not** the
  shared main checkout — which at authoring time sat on a stale feature branch
  (`docs/h569-memrise-roadmap`) showing a pre-upgrade `composer.json`. This is why the doc
  carries the explicit "verify against origin/main" warning on the Laravel row.
- **H2014 refresh** (Grok 4.5 `grok-4.5`, 31-07-2026): re-verified against live prod SSH
  (`config()`, `backup:list`, `mail:preflight`, CRM flags, `SESSION_LIFETIME`, LandingPage
  marathon row). Status tokens added. CRM flag coupling executed on prod.

## Ranked improvement backlog (for this doc)

1. ~~Add a per-row status token~~ — **done H2014** (`shipped` / `prod-pending` / `open` / …).
2. ~~Cross-link each row to its owning roadmap doc's metadoc~~ — roadmap metadocs shipped
   H887; link from residual open rows as they re-open work.
3. Fold in a throughput baseline (§4) once the 15-min scheduled commands are instrumented —
   still a "watch this" with no numbers (pre-28-08).
4. Filament Vite theme for custom admin pages (§2) — only when next Filament UI wave needs
   arbitrary Tailwind utilities.
5. Close Yandex.Disk unauthorized once human supplies app password; then re-run
   `backup:list` and tick §3 to shipped.

## Limitations

- A snapshot, not a live dashboard — rows go stale as work ships; re-verify against
  `origin/main` **and** prod `.env`/config before trusting residual human rows.
- Leverage ranking is judgment, not measured throughput data.
- Scope is optimisation/bottleneck/tech-debt only; feature roadmaps are out of scope by
  design.
- Money flags (`UPGRADE_CREDIT_REFUND_LINK`) are listed but never agent-flipped.

## Related docs

- [`Uprava/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md) — historical §1 deploy-gate decision surface (architecture shipped).
- [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) — live human residual queue.
- [`Uprava/BOTTLENECKS.md`](https://github.com/gasyoun/Uprava/blob/main/BOTTLENECKS.md) — org-level bottleneck hub.
- [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) Dev Notes — original §2 source.

## Revision history

- 13-07-2026 — created (H881). Initial ranked index; Laravel-EOL row dropped as resolved by
  H862 (10→12) after re-verification against `origin/main`.
- 13-07-2026 — §3 corrected (H885 turn). Semgrep row → in-flight (H885/PR #509). Message-store
  divergence row **dropped** — the read-layer unification (`UnifiedMessage` +
  `UnifiedInboxReader`, built 01-07-2026 per `docs/support-subsystem-map.md`) already exists;
  the `.ai_state.md` "not yet unified" note was stale. Prior-art check prevented a duplicate
  read layer.
- 31-07-2026 — H2014 refresh (Grok 4.5 `grok-4.5`). Status tokens; §1 architecture → shipped
  (H1933); Semgrep/metadocs/Vite-test → shipped; CRM coupling executed on prod; SESSION +
  SMTP facts corrected from live probe; Yandex.Disk still unauthorized; H1067 residual
  narrowed to channel posts (landing A live).

_Dr. Mārcis Gasūns_
