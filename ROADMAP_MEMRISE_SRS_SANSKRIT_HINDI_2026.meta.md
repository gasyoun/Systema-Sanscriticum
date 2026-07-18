# ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.meta.md — metadoc for `ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md`

_Created: 18-07-2026 · Last updated: 18-07-2026_

This is a **metadoc** — a document *about* a document. Its subject is
[ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md).
It does not duplicate the subject's content; it records everything *around* it. Kept per the
standing "one metadoc per important document" convention (`~/.claude/CLAUDE.md`).

## Subject
- **Document:** [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md)
- **Purpose:** Phased build plan (11-07-2026) to bring the full Memrise learning loop
  (spaced-repetition review, all test modes, gamification, user mnemonics) into Systema,
  seeded from an exported Memrise course, and extend it to Hindi. Opens with a
  prior-art finding that most of the underlying SRS/gamification engine already exists
  in-repo (built under H211) — so the plan is import-and-extend, not build-from-scratch.
- **Audience:** Whoever executes handoff H569 (currently the active tracker), or a
  future session extending the SRS/Memrise-clone/Hindi work.
- **Format / contract:** Numbered sections (0–6): prior-art gap map, locked decisions
  table, feature→status gap table, phased plan (P0–P6), risks table, open
  `@DECIDE` questions, handoff pointer with a literal starter-line code block. Follows
  the org's standing roadmap conventions (dated header, clickable full blob URLs,
  `@DECIDE` questions section, executable one-line handoff starter).

## Provenance
- **Created:** 18-07-2026 (handoff H968, Sonnet 5 `claude-sonnet-5`).
- **Next hardening:** update this metadoc's Deprecation status / backlog when H569
  P0 (the Memrise course export) finally lands or the roadmap is materially revised —
  no separately scheduled handoff for the metadoc itself.

## Improvement backlog (ranked)

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Resolve the P0 export blocker (needs a human browser session with CourseDump2022, or Memrise credentials for a scripted pull) | P0 is explicitly time-critical — Memrise is sunsetting community courses with no published shutdown date; every day of delay risks losing the source course entirely | queued (H569) — tracked as `@DO` in Uprava GTD, blocked on a human, not agent-doable |
| 2 | Once P0 lands, resolve Open Question Q1 (`Lesson.flash_cards` vs `SrsCard` reconciliation) before P1 import wiring | Determines whether flash-card content gets a second, divergent home | parked — genuine `@DECIDE`, doc's own recommendation is "migrate into SRS" but unconfirmed |
| 3 | Re-verify the "already exists" gap-map table (§0) against the repo once P1 import work resumes | The table was accurate as of the 11-07-2026 prior-art sweep; `PranaService`/`Fsrs.php`/etc. may have moved since (confirmed present as of 18-07-2026 metadoc creation, but not re-diffed line-by-line) | parked — cheap spot-check, do at P1 resume time rather than now |

## Known limitations / caveats

- The §0 "already exists" table is a point-in-time prior-art finding (11-07-2026).
  Spot-checked 18-07-2026 while authoring this metadoc: `app/Services/Srs/Fsrs.php`,
  `FsrsCard.php`, `Rating.php`, `ReviewService.php`, `State.php` and
  `app/Services/Prana/PranaService.php` all still exist as claimed — the reuse premise
  holds. Not re-verified line-by-line against the doc's specific method/route claims.
- The Prana gamification layer this doc cites as reusable infrastructure has itself
  drifted from ITS OWN original design doc — see
  [PRANA_ROADMAP.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.meta.md)
  Known limitations. This doesn't invalidate the "Prana exists, reuse it" claim here
  (it does exist and is live), only means the exact award/rank mechanics may differ
  from what PRANA_ROADMAP.md originally specified.
- P0 (course export) is a hard external dependency on Memrise staying reachable —
  the doc itself flags this as the top risk with no fallback beyond "export now."

## Intended use / known misuse

- **Intended use:** the execution plan for H569; read P0–P6 in order, check the Open
  Questions before starting P1, and use the gap-map table (§0/§2) to avoid rebuilding
  SRS/gamification machinery that already exists.
- **Known misuse:** starting at P1 (import) without first securing the P0 export —
  the doc explicitly orders phases by risk/dependency, not calendar convenience, and
  P0 is time-critical precisely because it depends on an external service's uptime.

## Maintenance & sunset plan

- Owned by whoever is actively executing H569; no dedicated maintainer beyond that.
- **Sunset trigger:** once H569 fully completes (P0 through P6, or P6 audio is
  formally deferred to its own tracked follow-up), flip this doc's Deprecation status
  to `superseded by [the shipped Memrise/SRS feature + its own docs]` and note the
  outcome here.

## Deprecation status

`active` — H569 is still 🔵 In work per the Uprava handoffs registry as of 18-07-2026
(P1 import scaffold shipped, PR #462 merged; P0 course export still blocked on a
human/credentials). This roadmap remains the live plan of record.

## Related documents

- [H569-Sonnet_DO_Systema_memrise-clone-srs-sa-hi_11.07.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/H569-Sonnet_DO_Systema_memrise-clone-srs-sa-hi_11.07.26.md) — the tracking handoff this roadmap points at.
- [database/seeders/data/memrise_6679375/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) — the contract for what the P0 export must produce.
- [app/Services/Srs/Fsrs.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Srs/Fsrs.php) — the FSRS engine this roadmap reuses.
- [app/Services/Prana/PranaService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaService.php) — the gamification layer this roadmap wires review events into.
- [PRANA_ROADMAP.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.meta.md) — sibling metadoc; documents how the Prana system referenced here diverged from its own original design doc.

## Revision history

| Date | Event | Who (tier+version) |
|---|---|---|
| 18-07-2026 | Metadoc created; H569 status confirmed 🔵 In work via Uprava handoffs registry; §0 reuse claims spot-checked against live code | Sonnet 5 (`claude-sonnet-5`), handoff H968 |

_Dr. Mārcis Gasūns_
