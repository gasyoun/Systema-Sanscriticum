# Storyboard — /mecenaty impact scroll «Куда идут 500 ₽»

_Created: 10-09-2026 · Last updated: 10-09-2026_

**Surface:** [samskrte.ru/mecenaty](https://samskrte.ru/mecenaty) · view
[mecenaty.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/institute/mecenaty.blade.php) ·
controller [InstituteDonateController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/InstituteDonateController.php) ·
**Template:** [SCROLLYTELLING_STORYBOARD_TEMPLATE.md](https://github.com/gasyoun/Uprava/blob/main/docs/SCROLLYTELLING_STORYBOARD_TEMPLATE.md)
**Status:** awaiting MG read. Donations are LIVE (N2, [PR #2010](https://github.com/gasyoun/Systema-Sanscriticum/pull/2010)); the section itself ships behind a flag OFF.

## Goal

Turn the live donor page from a form into a **cause**: show what the Institute already builds and where a
monthly 500 ₽ goes. Donor-impact narrative is the estate's strongest scrollytelling fit; no CRO baseline
exists, so the flag ships dark and the flip is a human call.

## Constraints

1. **Money contour** — worktree off `origin/main`, feature flag **default OFF**, money tests mandatory, watcher-safe commit; prod enablement stays human.
2. Donor frame **ст. 582 ГК** and its wording untouched.
3. Gratitude list rules: **names only by explicit consent, never amounts** ([DonationGratitude](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DonationGratitude.php)).
4. Every number comes from a committed artifact ([INSTITUTE_MONETIZATION_PLAN_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INSTITUTE_MONETIZATION_PLAN_2026H2.md) / [config/institute.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/institute.php)) — no new claims.
5. Copy never «школа/академия»; «Институт» = витрина name only.

## Beats

| # | Beat / claim | Scroll trigger | Visual | Feed (committed) | Text | Fallback | Reduced motion | Analytics goal | QA check | Rights / PII | Owner |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | «Институт исследования санскрита — что это» | section enter | one-line mission + three направления icons | plan §«Меценаты» | 2 sentences from approved wording | static block | static | `scrolly_impact_1` | wording diff vs plan = approved only | none | build agent |
| 2 | «Что уже сделано» | sticky counter row, 3 steps | 3 fact tiles (словари / корпуса / курсы) | committed reports cited in the plan | numbers verbatim from cited artifact | table of the same numbers | static | `scrolly_impact_2` | each number traceable to link | none | build agent |
| 3 | «Куда идут 500 ₽ в месяц» | sticky list, 4 items | состав: научный разбор · ранний доступ к изданиям · кредиты доноров в изданиях · 1–2 встречи в год | plan §«Меценаты» | verbatim list | static list | static | `scrolly_impact_3` | diff vs plan = 0 | none | build agent |
| 4 | «Кто уже с нами» | section enter (no sticky) | gratitude list, names only | `donation_gratitudes` public rows | count + names; empty state «список открыт» | static list | static | `scrolly_impact_4` | empty state renders; no amounts | consent flag respected | build agent |
| 5 | «Стать меценатом» | none (static) | existing presets + free amount + ст.582 note, CTA visible | `institute.mecenaty_skus` | existing copy | n/a | n/a | existing donation goals | checkout rehearsal untouched | ст.582 wording intact | build agent |

## Mechanics

1. Partial `resources/views/institute/_scrolly.blade.php` inside the existing page; flag `institute.mecenaty_scrolly` default **false**; QA `?impact=1`.
2. Vanilla `IntersectionObserver` + CSS `sticky`; no new dependency; `@push('scripts')`.
3. `prefers-reduced-motion` / no-JS: stacked static blocks; all content readable.
4. Analytics: `window.reachGoal('scrolly_impact_N')` (same shop helper).

## QA

1. Feature test: flag off → section absent; `?impact=1` → present; ст.582 wording asserted on both.
2. `php artisan test --filter=Mecenaty` and donation blast-radius tests green (no payment-path diff).
3. Headless screenshots 360/768/1280; gratitude empty state covered.
4. `better-accessibility` + `/useit` on QA URL.

## Gates

1. **MG storyboard read** — this file.
2. **MG prod flag flip** — after QA screenshots; money row.
3. Publish-safety in spirit: no sums, no private data, consent respected.

## Out of scope

Payment contour changes, SKU/preset changes, mailing, legal wording changes.

_Dr. Mārcis Gasūns_
