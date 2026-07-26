# DECISIONS_LOG — PWG Arzamas material (wave-1 autonomy defaults)

_Created: 26-07-2026 · Last updated: 26-07-2026_

Per [PLAN §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md):
ambiguity → plan default + one-line rationale here, continue.

| # | Decision | Rationale |
|---|---|---|
| L1 | `SOURCE.md` is pure Markdown; `build_body.py` renders `body.html` (figures, aside, CTA) | org hook `check_md_full_urls.py` #2 blocks HTML in `.md` writes; generated `.html` is the sanctioned home for markup |
| L2 | Image path: `public/images/materials/pwg/` (static files, no Curator) | ARCHITECTURE §1 names this the preferred wave-1 path |
| L3 | Title finalized as «Петербургский словарь: 20 глав о главной книге санскрита» (D25 said «20 фактов», finalize-in-draft) | essay is chapter-built, not fact-list-built; «PWG» kept out of the lay title, present in meta_title |
| L4 | `category_id` left null at import | no obviously fitting prod category is knowable from the repo; Filament can assign later (D20 default + log), tracked in FOLLOWUPS W2-3 |
| L5 | Roadmap pillar 19 («Что словарь сделал с наукой») restored as ch. 19; pwg_ru mention compressed to one sentence + link inside ch. 18 | PLAN §6 fence: pwg_ru = «at most one closing sentence + link» |
| L6 | Cover: import command copies `public/images/materials/pwg/pwg-title-1855.jpg` → `storage/app/public/articles/covers/pwg-arzamas-title-1855.jpg` and sets `cover_path` | cover must be repo-controlled yet live on the `public` disk the Article accessor expects |
| L7 | Chapter map deviation from ROADMAP: added «Веда» (ch. 6) and «Первый русский мост» folded into ch. 18; pillars 16–17 (мифы/Кёльн) kept separate; «Как открыть сегодня» merged into chs. 18+20 | ROADMAP allows merging tail chapters while keeping ≥15 h2; final count = 20 h2 |
| L8 | MG live redirect 26-07-2026: Dahl portrait dropped (generated stat-infographics to replace it, W2-4); csl-atlas + Vigasin + papers data woven in (FACTS CA-1…CA-40); «Сокращения» merged into ch. 10, freed slot 11 → «Приставки-чемпионы и слова-поезда» | user instruction supersedes plan detail; samāsa/derivation stats are the image-generation base |
| L9 | Kossovich / славянофилы / фельетоны compressed to one ch. 16 paragraph; full story → SECOND Arzamas piece (FOLLOWUPS W2-5) | MG 26-07-2026: «их может быть и две» |
| L10 | Citation-total build discrepancy (801 790 A50 vs 772 567 vs 570 817) resolved as: essay says «свыше восьмисот тысяч» per A50, density/coverage per citation-apparatus.json; builds not mixed in one sentence | csl-atlas carries three committed counts with different scopes |

_Dr. Mārcis Gasūns_
