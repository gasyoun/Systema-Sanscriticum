# Metadoc — ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md

_Created: 22-07-2026 · Last updated: 05-09-2026_

**Purpose:** development plan for turning the exported Bühler
grammatical-tables Memrise course (6517849, 5 stem-class paradigm levels)
into an SRS deck feature, and for connecting it to the already-planned but
unbuilt "auto-declension drills" line in
[SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md) §3.

**Audience:** whoever picks up [H1442](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1442-Sonnet_Systema_memrise-grammar-tables-buhler-roadmap_22.07.26.md)
or a future declension-drill feature session.

**Provenance:** H1442, Sonnet 5 (`claude-sonnet-5`), 22-07-2026 — written
same-pass as the course export, off a live cross-repo prior-art check
(SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX §3 already named this gap unbuilt).

**Ranked improvement backlog:**
1. Resolve Open Question 1 (Option A vs B review-mode sequencing) with MG —
   currently just a recommendation, not a ruling.
2. P1 schema design needs to actually be implemented and tested before this
   doc's phases can be marked done.
3. If Option B (full-paradigm grid) is chosen, this doc's P1/P2 split will
   need real UI-design detail added, not just the two-line sketch here.

**Limitations:** written from a single small (78-row, 5-class) export — the
paradigm-cell parsing approach (P2, `col_b` label parsing) is untested
against the full range of case/number labels Bühler's grammar uses; only
five stem classes are represented, so the schema may need revision once a
sixth/seventh class (e.g. consonant stems) is added.

**Revision history:**
- 22-07-2026 — created (H1442, Sonnet 5 `claude-sonnet-5`).

_Dr. Mārcis Gasūns_
