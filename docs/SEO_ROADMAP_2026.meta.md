# SEO_ROADMAP_2026.meta.md — metadoc about `SEO_ROADMAP_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion metadoc for [`SEO_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md), the Yandex-first SEO roadmap for the samskrte.ru course storefront.

## Subject

- **Document:** [`SEO_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md)
- **Purpose:** Sequence the SEO work for samskrte.ru with Yandex as the primary engine and Google as secondary, ranking structured-data and content wins by leverage against course-sales goals.
- **Audience:** MG and any session/engineer picking up SEO or dictionary-exposure work on the Systema-Sanscriticum (Laravel) storefront.
- **Format/contract:** Prioritised roadmap — state-of-play tables (P0/P1/P2 tiers), per-gap effort sizing, and shipped/pending status markers tied to concrete handoffs and blade partials.

## Provenance

- **Subject created:** 05-07-2026.
- **Metadoc authored:** 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- **Next hardening:** none planned.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Close the P0 follow-up `@DO`s inside the doc (YouTube `sameAs` URL, Yandex.Webmaster + Yandex Business registration) once done | Roadmap shows them as ⏳ open; they are human/prod actions that should flip to ✅ with a date | parked (blocked on human prod actions in Yandex.Webmaster / Business) |
| 2 | Record P2 dictionary-page deploy activation outcome (index_enabled flip, mark-core-indexable run, sitemap submission) | §5.2 lists it as deploy-gated ⏳; the roadmap becomes stale the moment prod flips without a status update | parked (blocked on prod deploy + monitored indexation waves) |
| 3 | Add a measurement/verification section — how each shipped schema is confirmed live (Rich Results, Yandex validator, ИКС/traffic deltas) | Roadmap claims wins but has no back-check loop; effectiveness is unverified | parked (needs a post-launch analytics baseline) |
| 4 | Fold P1-b topical-cluster progress into a tracked checklist or sibling doc | Marked "ongoing content discipline" with no artifact to measure against | parked (ongoing, no owning handoff) |

## Known limitations / caveats

- **Scope:** SEO/structured-data + dictionary-exposure strategy for samskrte.ru only — not general marketing, ads, or funnel/CRO work.
- **Point-in-time status.** Every ✅/⏳ marker reflects state as of the 08-07-2026 last-update; shipped items (H193 P0 batch, H210 Wave-1 mechanisms) are code-complete but several are deploy-gated, so "built" ≠ "live in prod". Treat status markers as needing re-verification against the actual deployment before relying on them.
- **Yandex-first premise** is an editorial judgment (behavioral factors + YATI over markup); if the traffic mix or primary engine shifts, the P0→P2 ordering must be re-derived.

## Intended use / known misuse

- **For:** deciding what SEO work to do next and in what order, and seeing which structured-data assets already exist so they are not rebuilt (§1 "do NOT rebuild").
- **Misuse:** reading a ✅ marker as "already indexing / live in production" — many wins are shipped-but-gated behind `DICTIONARY_SEO_INDEX_ENABLED` and human registrations. Also not an implementation spec: the blade/controller links are pointers, not the source of truth for current code.

## Maintenance & sunset plan

- **Kept alive by:** whoever ships the next SEO increment flips the relevant row and bumps "Last updated"; the P0/P2 human/prod `@DO`s are mirrored in [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).
- **Sunset:** once P2 dictionary pages are live and indexation is stable, the roadmap collapses to a maintenance/monitoring note; archive it (or supersede with a results-log doc) when all three tiers are shipped and verified.

## Deprecation status

active

## Related documents

- [`WIKIDATA_SAMEAS_SPOTCHECK.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/WIKIDATA_SAMEAS_SPOTCHECK.md)
- [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)

## Revision history

| Date | Event | Who |
|---|---|---|
| 13-07-2026 | metadoc created (H887) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
