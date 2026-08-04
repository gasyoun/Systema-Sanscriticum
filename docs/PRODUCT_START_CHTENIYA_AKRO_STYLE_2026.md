_Created: 01-08-2026 · Last updated: 02-08-2026_

# PRODUCT — «Старт чтения» (Akro-style 5-week pilot) · Systema register

**Status:** partly in code, **inert on prod.** H2105 landed the money contour and
H2110 the in-cabinet reader; both sit behind flags that default OFF, so nothing is
student-visible until ops enables them. This file is the **Systema-local** product
register so agents and ops do not treat the offer as undocumented ambient context.

| Field | Value |
|---|---|
| Brand | samskrte sub-offer **«Старт чтения»** (no new brand) |
| Competitive frame | [akro-greek.com/courses](https://akro-greek.com/courses?locale=ru) 5-week «from grammar to first author» |
| Duration | 5 weeks · live group + between-session cabinet work |
| Price posture | Match Akro **€75–129** band; exact RUB/Tochka amount = human ops at go-live |
| App shell | **Systema only** — embed kosha pack JSON; no second LMS |
| Interim materials | Hitopadeśa-0 + subhāṣita-beginner (owned kosha packs) |
| Long-term spine | Custom natural-method continuous Sanskrit + RU gloss (SanskritGrammar track) |
| Audio v1 | **None** — live teacher voice only (honest non-parity with Akro) |
| Entitlement key (planned) | `start_chteniya_cohort` (name final at H2105) |
| UTM campaign | `utm_campaign=start-chteniya` |

## One-paragraph goal

Ship a paid 5-week funnel (landing → checkout → live group → `/dvaram` homework +
reading packs + cohort-flagged SRS) that reuses existing drills and kosha reading
packs, without claiming a professional audio library and without a second product brand.

## Hub plan set (canonical — Uprava)

Do not re-derive architecture here. Execute from:

| Layer | Doc |
|---|---|
| Competitive roadmap (H2098) | [ROADMAP_AKRO_STYLE_SANSKRIT_PRODUCT_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/ROADMAP_AKRO_STYLE_SANSKRIT_PRODUCT_2026.md) |
| Plan index | [PLAN_AKRO_START_CHTENIYA_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_AKRO_START_CHTENIYA_2026.md) |
| Architecture | [ARCHITECTURE_AKRO_START_CHTENIYA_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_AKRO_START_CHTENIYA_2026.md) |
| Implementation order | [IMPLEMENTATION_AKRO_START_CHTENIYA_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/IMPLEMENTATION_AKRO_START_CHTENIYA_2026.md) |
| Verification | [VERIFICATION_AKRO_START_CHTENIYA_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/VERIFICATION_AKRO_START_CHTENIYA_2026.md) |
| Staging manifest | [ASK_BATCH_STAGING_AKRO_PRODUCT_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/ASK_BATCH_STAGING_AKRO_PRODUCT_2026-08.md) |

## Systema ownership map

| Surface | Planned owner | Handoff | Code status (01-08-2026) |
|---|---|---|---|
| Cohort funnel: SKU + enrollment + Tochka grant | Systema | [H2105](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2105-Sonnet_Systema-Sanscriticum_start-chteniya-cohort-funnel_01.08.26.md) | ❌ not started — money-contour, human merge |
| Wire freeze packs + deep links + cohort SRS | Systema | [H2106](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2106-Sonnet_Systema-Sanscriticum_start-chteniya-pack-wire-srs_01.08.26.md) | ❌ blocked on H2105 + kosha freeze |
| Progress + teacher stalled-lemma view | Systema | [H2107](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2107-Sonnet_Systema-Sanscriticum_start-chteniya-progress-teacher_01.08.26.md) | ❌ Wave 2 |
| Multi-pack `reading_pack` import | Systema | [H2110](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2110-Opus_Systema-Sanscriticum_start-chteniya-reading-pack-import_01.08.26.md) | ✅ shipped — `hitopadesa-0` vendored from the H2109 freeze (sha256-pinned) and readable at `/dvaram/reading/{slug}` under `StartChteniyaCohort::hasEntitlement()`; both flags default OFF. `subhashita-beginner` deliberately NOT imported (second schema — see below) |
| Tap-token morph + RU gloss + add-to-SRS | Systema | [H2111](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2111-Opus_Systema-Sanscriticum_start-chteniya-tap-token-ui_01.08.26.md) | ❌ Wave 2 |

Sibling (non-Systema) wave-1 pieces:

| Surface | Repo | Handoff | Status |
|---|---|---|---|
| W1–W5 curriculum map | SanskritGrammar | [H2112](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2112-Fable_SanskritGrammar_start-chteniya-w1w5-curriculum_01.08.26.md) | ✅ merged — dual-run residual H2121 |
| Pack freeze export | kosha | [H2109](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2109-Sonnet_kosha_start-chteniya-pack-freeze_01.08.26.md) | ✅ override PR — dual-run residual H2129 |
| ORS landing + WC body | ORS-FAQ | [H2108](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2108-Fable_ORS-FAQ_start-chteniya-landing_01.08.26.md) | 🟡 open |
| Natural-method story scaffold | SanskritGrammar | [H2113](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2113-Fable_SanskritGrammar_start-chteniya-natural-method-story_01.08.26.md) | 🟡 open (does not block pilot wire) |
| Week-4 metre residual | SanskritKaraoke | [H2114](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2114-Sonnet_SanskritKaraoke_start-chteniya-week4-metre_01.08.26.md) | 🟡 open |

## Prior-art inside this repo (reuse — do not rebuild)

| Asset | Path / pattern | Use for «Старт чтения» |
|---|---|---|
| Marathon paid track | [config/marathon.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php), Tochka paid path | Funnel shape; **prefer Tariff+Course** if multi-week `grantAccess` keys needed (H2105) |
| Nala-1 reading pack demo | `ReadingPackController`, [resources/data/kosha_reading_pack_nala_1.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/kosha_reading_pack_nala_1.json) | Multi-slug extend; vendor freeze under `resources/data/cohort_start_chteniya/` |
| SRS B1 import | `srs:import-kosha-b1-demo`, flag `kosha_srs` | Cohort-scoped deck; **never** global `SRS_ENABLED=true` |
| Student cabinet | `/dvaram` | Between-session homework + reader + dictionary tab |

## Planned data contracts (Systema side)

### Reading pack JSON

Same schema as vendored Nala-1 / kosha `reading/data/{slug}.json`:

- Top-level: `slug`, `title`, `ref`, `text_name`, `source`, `built`, `stats`
- `sentences[]` → `tokens[]` with `form`, `lemma`, `upos`, `morph`, `gloss`, optional `gloss_ru`

**Do not invent a second schema.** Pin freeze copies with `MANIFEST.json` (sha256 + built date).

**H2110 held that line, and it cost a pack.** The H2109 freeze also pins
`subhashita-beginner`, whose shape is `sayings[]` → `lines[].chunks[]` with
`t`/`lemma_slp1`/`gloss_ru` triples — **not** `sentences[]`/`tokens[]`. The freeze
manifest's own `adapter_note` says an importer must adapt or normalize it rather than
introduce a second schema silently. H2110's acceptance was `hitopadesa-0` in the cabinet
and its stated failure mode was "second pack schema", so that pack is **not vendored at
all** rather than half-imported: the adapter is a separate, visible piece of work, not a
side effect of a reader route. Vendoring is done by
[`scripts/vendor_cohort_start_chteniya_packs.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/vendor_cohort_start_chteniya_packs.py),
which verifies every file against the freeze's own sha256 + byte count **before** writing
and re-verifies with `--check` (H2129 had to correct two stale MANIFEST hashes; that check
is what catches the class).

### Cohort entitlement

- Gates multi-pack reader routes, cohort SRS deck, lesson deep links from the W1–W5 map
- Does **not** flip global [config/srs.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php) default
- Fence: never contaminate R20 marathon analytics arms

## 5-week classroom arc (summary)

Full path table lives in SanskritGrammar after H2112; summary for Systema lesson skeleton:

| Week | Live focus | Digital (owned assets) |
|---|---|---|
| 1 | Pronunciation, cabinet | Script / marathon-class + top-50 freq lemmas |
| 2 | Forms in context | Morphology band-1 + sandhi curriculum top rules |
| 3 | Continuous prose | Hitopadeśa-0 RU gloss (interim) + sandhi join/split |
| 4 | Oral + metre | Live teacher voice + Karaoke **metre quiz only** (no audio pipeline) |
| 5 | First literature band | subhāṣita-beginner *or* story chapter if ready |

## Fences (absolute)

- No prod deploy from packaging handoffs without ops path
- Money-contour PRs (H2105): **no auto-merge** — human merge (`/money-pr-land`)
- No global SRS flip
- No TTS / IndicF5 / Karaoke align-render-audio pipeline in wave-1
- No CommentaryStrategies apparatus in the beginner funnel
- No second sandhi engine or dictionary stack
- No invented live RUB prices or cohort dates in public copy

## Relation to SAMSKRTE-TIER0 (marathon 28-08)

[PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md)
owns the **marathon 28-08** live gate. «Старт чтения» is a **separate** 5-week paid
sub-offer: same school, same LMS, different SKU and cohort fence. Do not merge analytics
arms or reuse marathon string tariff alone without an explicit H2105 grant design.

## Agent launch

Systema code work starts with H2105 (Sonnet), not this register file:

```
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H2105-Sonnet_Systema-Sanscriticum_start-chteniya-cohort-funnel_01.08.26.md and execute it.
```

_Provenance:_ Grok 4.5 (`grok-4.5`), H2139, 01-08-2026 — docs register only; no money code.

_Dr. Mārcis Gasūns_
