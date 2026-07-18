# PRANA_ROADMAP.meta.md — metadoc for `PRANA_ROADMAP.md`

_Created: 18-07-2026 · Last updated: 18-07-2026_

This is a **metadoc** — a document *about* a document. Its subject is
[PRANA_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md).
It does not duplicate the subject's content; it records everything *around* it. Kept per the
standing "one metadoc per important document" convention (`~/.claude/CLAUDE.md`).

## Subject
- **Document:** [PRANA_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md)
- **Purpose:** Original design + build plan (07-05-2026) for "Prana" — Systema's
  effort-based gamification currency (balance, lifetime total, ranks, earn/spend rules,
  P2P transfer, decay). Written before any of it existed in code.
- **Audience:** Whoever picks up further Prana work (ranks UI, P2P, decay tuning) and
  wants the original design rationale, not just the shipped schema.
- **Format / contract:** Free-form Markdown design doc — philosophy section, SQL DDL
  sketches, earn/spend tables, a 7-phase build plan (Фаза 0–6) with PHP snippets, a
  "what's intentionally excluded" section. No enforced structural contract.

## Provenance
- **Created:** 18-07-2026 (handoff H968, Sonnet 5 `claude-sonnet-5`).
- **Next hardening:** none planned — this metadoc documents a design record, not an
  active spec; update only if PRANA_ROADMAP.md itself is revised or the Prana system
  changes materially again.

## Improvement backlog (ranked)

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Add a top-of-file banner noting the doc predates the shipped implementation and pointing at `app/Services/Prana/PranaService.php` as the current source of truth | A reader following the doc's SQL/method signatures verbatim would build against a schema that no longer matches production (see Known limitations) | parked — cosmetic, low urgency; the doc's Deprecation status below already carries this warning |
| 2 | Reconcile the still-unbuilt phases (ranks table + UI, P2P transfer limits, decay warnings) against what actually shipped, and either mark them done or re-scope them as a fresh handoff | Roadmap currently reads as fully pending; some of it (transactions ledger, decay job) already exists under different names | parked — needs a human product call on whether ranks/P2P/decay are still wanted as designed, or superseded by whatever shipped |

## Known limitations / caveats

- **The doc is a pre-implementation plan; the shipped system diverged from it.**
  Verified 18-07-2026 by reading the actual code:
  - `app/Services/Prana/PranaService.php` exists, but its `award()` signature is
    `award(User $user, string $reason, ?Model $source, ?int $amount, array $meta)` —
    not the roadmap's `award(User $user, string $type, int $amount, array $context)`.
  - The transactions table (`database/migrations/2026_05_02_120000_create_prana_system.php`)
    uses `reason` (not `type`), has no `balance_after` snapshot column, and is
    idempotency-keyed on `(user_id, reason, source_type, source_id)` — a design not in
    the roadmap at all.
  - There is **no `prana_ranks` table, no `PranaRank` model, no `prana_rules` table, no
    `user_unlocked_assets` table** in the current schema — the roadmap's rank hierarchy
    (Śiṣya → Paṇḍita) and configurable-rules design were not built as specified.
  - What **did** ship beyond/differently from the roadmap: `app/Models/PranaPerk.php` +
    `PranaRedemption.php` (a perks/redemption model absent from the roadmap),
    `app/Console/Commands/PranaDecayCommand.php` (decay exists, under a different
    shape than the roadmap's `PranaDecayJob`).
  - Net effect: **do not treat the SQL DDL or `PranaService` method signatures in this
    doc as current** — they document original intent, not the live schema.
- The design philosophy section (effort-not-activity, dissipates-without-practice,
  unlocks-knowledge-not-discounts) is *not* contradicted by the above and likely still
  holds as the guiding principle — only the mechanical implementation plan is stale.
- "Что намеренно не включено" (leaderboards, daily-login bonuses, Prana-for-money,
  achievements) reflects a 07-05-2026 decision; not verified against current product
  direction as of this metadoc's creation.

## Intended use / known misuse

- **Intended use:** historical design record — read to understand *why* Prana behaves
  the way it does philosophically, or as a starting point if ranks/P2P/decay-as-designed
  are picked back up as a real feature request.
- **Known misuse:** copying SQL migrations or method signatures from this doc directly
  into new code without first diffing against `app/Services/Prana/PranaService.php` and
  `database/migrations/*prana*` — the shipped schema has moved on (see above).

## Maintenance & sunset plan

- No active maintainer; this is a point-in-time design doc, not a living spec.
- **Archival trigger:** if a future session substantially rebuilds or formalizes the
  Prana rank/P2P/decay design, either update this doc's status to `superseded by
  [the new spec]` or move it under an `archive/` folder alongside other retired
  roadmaps — do not silently leave two contradictory Prana designs both marked active.

## Deprecation status

`superseded by [the shipped Prana implementation in app/Services/Prana/, app/Models/PranaTransaction.php, PranaPerk.php, PranaRedemption.php, and database/migrations/*prana*]` —
the doc remains useful for its design philosophy but its concrete schema/task list no
longer matches production, per Known limitations above.

## Related documents

- [app/Services/Prana/PranaService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaService.php) — the actual, current implementation.
- [app/Services/Prana/PranaSettings.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaSettings.php)
- [database/migrations/2026_05_02_120000_create_prana_system.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_05_02_120000_create_prana_system.php) — live schema, diverges from this doc's DDL.
- [prana-gemini.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/prana-gemini.md) — sibling root doc, not yet metadoc-covered (out of scope for H968; flag separately if it needs one).
- [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md) — a later roadmap (11-07-2026) that correctly lists the shipped `PranaService`/`StreakService`/`PranaLeaderboard` as reusable "already exists" infrastructure, confirming the divergence documented here.

## Revision history

| Date | Event | Who (tier+version) |
|---|---|---|
| 18-07-2026 | Metadoc created; verified subject doc against live code (Prana system found substantially shipped but schematically divergent) | Sonnet 5 (`claude-sonnet-5`), handoff H968 |

_Dr. Mārcis Gasūns_
