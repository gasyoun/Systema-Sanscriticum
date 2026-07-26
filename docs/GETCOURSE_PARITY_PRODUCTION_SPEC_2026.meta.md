# Metadoc — GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md

_Created: 18-07-2026 · Last updated: 26-07-2026_

The companion record for
[GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md).

## Purpose

To be the **build input for wave 2 of the getcourse-parity programme**, replacing the H438 roadmap
in that role. R-1 ruled that wave 1 must open with a design pass because the programme was the least
specified of the four Tier-0 programmes — four roadmap rows and no R29-equivalent spec. The subject
document is that spec.

Its single load-bearing contribution is **§2, the precedence ladder**: the rule set that lets a
wave-2 step be executed by an agent without escalating. A roadmap never has one; a builder always
needs one. Everything else in the document is composition, depth for the wave-2 head, and honestly
named open questions.

## Audience

1. **A fresh agent starting GC-C1 or GC-C2** — the primary reader. §3/§4 are written so that agent
   never needs the H438 roadmap. That is the document's real acceptance criterion
   ([VERIFICATION §2.5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)).
2. **A human ruling the open forks** — §7 is addressed to a person, not an agent.
3. **A planner asking "what is the parity state today"** — §1 is the current answer, and it differs
   from the roadmap in three places.

## Provenance

- **Handoff:** [H1144](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1144-Opus_Systema-Sanscriticum_getcourse-parity-production-spec-r29-equivalent_17.07.26.md)
  (W1-D1). Intended and actual executor: **Opus 4.8 (`claude-opus-4-8`)**.
- **Method:** the 14 ticket states in §1 were each verified against the tree at `9b63861` by a
  dedicated read-only agent, and every verdict that was not a high-confidence `NOT_BUILT` was then
  re-checked by a second agent instructed to **refute** it. Six further agents produced the
  production-depth inputs behind §2–§6 (feature-flag registry and call-site idiom, `PaymentObserver`
  event chain, migration conventions, kanban package contract, `Lead` layer inventory,
  conversion/attribution services). 25 agents, ~2.5M subagent tokens.
- **Inputs consumed, never re-derived:** the H438 roadmap (analysis of record), R29 (template shape),
  and the four wave-1 plan docs (PLAN / ROADMAP / ARCHITECTURE / VERIFICATION).

### What the adversarial pass actually changed

Recorded because it is the argument for keeping that pass in future spec work — the naive states
would have shipped two wrong rows and missed the document's most valuable finding:

| Ticket | First pass | After audit | Why |
|---|---|---|---|
| GC-B3 | DONE | **PARTIAL** | The seam shipped, but its container binding has **zero consumers** — the webhook resolves the concrete `ZoomService` — and `services.bbb` does not exist, so the BBB driver is structurally unconfigurable. Interface present, abstraction inert. |
| GC-A3 | PARTIAL | **NOT_BUILT** | The "partial" evidence was entirely the ticket's own declared reuse base (`Announcement` scheduling, `MessageTemplate`), which predates it. Counting a ticket's inputs as its progress would make every reuse-heavy ticket PARTIAL by construction. |
| GC-C1 | PARTIAL (framed as H451 defying its ruling) | **PARTIAL** (framing corrected) | Chronology refutes the framing: H451 landed 10-07 11:06, the `Deal` ruling 11-07 00:01. H451 correctly executed the *then-current* ruling — and the superseded record was never marked superseded. This became **fork F2**, the document's most consequential finding. |

## Limitations — read before trusting it

- **§1 is a snapshot at `9b63861`.** Parity state moves. Re-verify before treating a row as current;
  the method is one read-only agent per ticket with an instruction never to assert absence without
  naming the search it ran.
- **§3–§4 are the only production-depth sections.** Wave-3/4 rows were pointers, not designs — GC-A1
  shipped anyway (25-07-2026, H1637, built directly from its own ticket text + reuse base, not from a
  production-depth section here). Do not build GC-D1 from this document; it still lacks that depth.
- **§7 was authored with all eight forks open** — the document deliberately resolved none. Three
  have since been ruled and folded back in: **F1** (19-07-2026 → GC-B1 rescope, shipped H1642),
  **F2** (21-07-2026 → separate `Deal`, shipped H1641), **F9** (26-07-2026 → keep both boards +
  an additive third shared-stage board, shipped H1658). F3 is settled by construction (separate
  stage tables). The remaining forks — F4–F8 — are still open, and anything downstream of them is
  provisional. Each ruled fork keeps its original framing inline, so the section reads as a
  decision log rather than a to-do list; check the fork's own leading paragraph, not this bullet,
  for its current state.
- **§2.4 corrects the boundary rule's own parenthetical** against the tree ("5 statuses" are DB rows
  now; plain course payments do not auto-convert their lead). The invariant is quoted verbatim and
  unaltered; only its stale description of `Lead` is annotated.
- **It documents two upstream doc defects it does not fix:** `CLAUDE.md`/`README.md` claim
  `PaymentObserver` calls `grantAccess()` (it does not), and `config/README.md` claims there are no
  feature flags (there are 20).

## Improvement backlog — ranked

1. **Resolve F2 and mark the losing decision record superseded.** Everything in wave 2 is downstream.
   Until then §3 is conditional. Highest leverage item in the document.
2. **Route F2 to the CONTRADICTIONS registry** regardless of how it is ruled — two live governing
   records disagreed for a week undetected, which is a process finding, not just a GC-C1 finding.
3. **Fix the two upstream doc defects** named above; a `PaymentObserver` bridge written from
   `CLAUDE.md` would attach to the wrong class.
4. **Re-verify §1 at the start of wave 2** and stamp a new date; state drifts fastest in Domain B.
5. **Fold the GC-C2 join-path ruling (F5) back into §4.1** once made, converting the fork table into
   a specification.
6. **Add the two `OrderPaymentConversion` defects** (§4.3) to the optimisation backlog — found in
   passing, deliberately not bundled.
7. **Consider promoting §2's ladder** to a repo-level invariant doc if a second programme needs it;
   today it is scoped to parity.

## Related

- Analysis of record: [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md)
- Template: [STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
- Wave-1 plan set: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md) ·
  [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md) ·
  [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) ·
  [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) ·
  [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)
- Contradicting decision record (fork F2): [DECISIONS_roadmap_forks_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md) §R2

## Revision history

| Date | Change | By |
|---|---|---|
| 26-07-2026 | **F9 ruled + implemented** (H1658): both existing boards kept, shared stage layer shipped as an additive third board (`UnifiedSalesBoard` over `UnifiedSalesStage`), view layer only — no migration, no physical merge of `lead_stages`/`deal_stages` (that stays F3, settled). §7 F9 rewritten as a decision record with the original framing kept below it; §1 GC-C1 row and §7's preamble updated; the "all eight forks are open" limitation above corrected to name F1/F2/F9 as ruled. | Opus 5 (`claude-opus-5[1m]`) |
| 25-07-2026 | GC-A1 row flipped `NOT_BUILT` → `DONE` (H1637: `segments` migration + `Segment` model + `SegmentResource` + `marketing_segments` flag, built independently of the Wave 2-3 forks per MG ruling). Wave-map bookkeeping note added; flag-registry table annotated. | Sonnet 5 (`claude-sonnet-5`) |
| 18-07-2026 | Created with the subject document (H1144, W1-D1). 14 tickets verified + adversarially audited; §2 precedence ladder authored; 8 forks named, none resolved. | Opus 4.8 (`claude-opus-4-8`) |

_Dr. Mārcis Gasūns_
