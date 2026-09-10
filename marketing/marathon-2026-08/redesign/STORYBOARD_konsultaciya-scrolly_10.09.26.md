# Storyboard — Konsultaciya landing: scrollytelling block «Как проходит консультация»

_Created: 10-09-2026 · Last updated: 10-09-2026_

**Surface:** [samskrte.ru/online/konsultaciya](https://samskrte.ru/online/konsultaciya) · controller
[MarathonController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/MarathonController.php) ·
skin b [content.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/skins/b/content.blade.php) ·
**Template:** [SCROLLYTELLING_STORYBOARD_TEMPLATE.md](https://github.com/gasyoun/Uprava/blob/main/docs/SCROLLYTELLING_STORYBOARD_TEMPLATE.md)
**Status:** build SHIPPED behind flag OFF (10-09-2026, H4521 — executor OxAlpha); prod flip after the copy A/B read (01-11-2026).

## Goal

Lift form conversions by answering the two measured objections — **price** and **schedule** — inside one
4-beat scroll. Evidence: ORS-FAQ dropoff taxonomy (`after_price` 49.7 % + `after_schedule` 22.6 % = 72.3 %
of classified dropoffs; 447/2632 dialogs — indicative, same audience,
[DROPOFF_TAXONOMY_2026.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/DROPOFF_TAXONOMY_2026.md)).

## Constraints

1. Cold FB/VK traffic; mobile 360 px first.
2. Copy A/B is live until **01-11-2026** ([config](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon_landing_copy.php)) —
   the block is structural, touches no copy variant, and stays **flag OFF** in prod until the read.
3. Money contour → watcher-safe commit, worktree off `origin/main`, PR, no new payment route.
4. Skin b only; skins a/c/d untouched (one aesthetic per direction, H1966).
5. All text comes from existing approved copy (`$days`, `MarathonLandingCopy`); no new marketing claims.

## Beats

| # | Beat / claim | Scroll trigger | Visual | Feed (committed) | Text | Fallback | Reduced motion | Analytics goal | QA check | Rights / PII | Owner |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | «Не знаете свой уровень — это и есть повод прийти» | section enter, sticky 1 viewport | screenshot of the level quiz, one highlighted question | repo UI screenshot (no data) | headline + 2 sentences from existing copy | static screenshot + text | static | `scrolly_step_1` | screenshot 360 px, console clean | own UI, no PII | build agent |
| 2 | «Три дня: что реально будет» | day cards stack sticky, one per step | existing day cards (3) revealed one by one | `$days` — config copy | card titles/bodies verbatim | all three cards stacked | static stack | `scrolly_step_2` | screenshot, copy unchanged (diff vs config) | none | build agent |
| 3 | «После консультации: маршрут, цена, расписание» | sticky tile row, 3 steps | three fact tiles (маршрут / цена / расписание) | existing approved copy only | verbatim numbers from config | stacked tiles | static | `scrolly_step_3` | numbers diff vs config = 0 | none | build agent |
| 4 | «Записаться на консультацию» | none (static) | form anchor, CTA always visible | — | existing CTA labels | n/a | n/a | existing lead goal | form submits on QA | consent wording untouched | build agent |

## Mechanics

1. Shared partial `resources/views/marathon/skins/_scrolly.blade.php`; skin b includes it after the «Три дня» section.
2. `@push('scripts')` — vanilla `IntersectionObserver` + CSS `sticky`; **no new dependency**.
3. Flag: `config('marathon_visual.scrollytelling')` default **false**; QA override `?scrolly=1&skin=b`.
4. Analytics: `window.reachGoal('scrolly_step_N')` via [shop-metrika.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/shop-metrika.blade.php) helper.
5. `prefers-reduced-motion` and no-JS: beats are ordinary stacked sections — identical text, no motion.
   Precisely: no-JS drops the reveal animation but CSS `sticky` (layout) still applies; only
   `prefers-reduced-motion` also turns stickiness off.

## QA

1. Feature test: flag off → block absent; `?scrolly=1` → block present.
2. Targeted `php artisan test --filter=Marathon`.
3. Vite build green; headless screenshots 360/768/1280.
4. `better-accessibility` pass (focus order, reduced motion, contrast) + `/useit` on the QA URL.
5. Metrika debug shows `scrolly_step_1..3`.

## Gates

1. **MG storyboard read** — this file; dated line in the build handoff before code.
2. **Prod flag flip after 01-11-2026** — after `php artisan marathon:copy-variant-report` is read; GTD row.
3. Money contour: worktree off `origin/main`, PR, merge when green; watcher-safe single-invocation commit.

## Out of scope

Copy changes, skins a/c/d, quiz/checkout behaviour, new claims, new payment routes.

## Implementation notes (executor, 10-09-2026)

Built under H4521. Block lives in [resources/views/marathon/skins/_scrolly.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/skins/_scrolly.blade.php),
included by skin b right after the «Три дня» section; flag `marathon_visual.scrollytelling` (default **false**), QA override `?scrolly=1`.

1. **Beat 1 visual is a static illustration, not a screenshot** — the storyboard asked for a screenshot of the
   level quiz. No screenshot asset exists in the repo and the quiz route is token-gated (`marathon.level-quiz/{token}`,
   404s for `zero`-cohort enrolments), so the beat renders the landing's own quiz options (`quizGoals` labels, one
   highlighted) as a decorative, `aria-hidden` illustration. Same information, no binary asset, no PII.
   **Flagged for the MG read** — swap to a real screenshot if the screenshot itself is the point.
2. **No new Tailwind utility.** `deploy.sh::need_npm_build()` skips the npm build on blade-only diffs
   (the H4463 trap), so every utility used here already exists in the deployed bundle; the two anchor
   offsets and all motion CSS live in the partial's inline `<style>`. No new JS dependency.
3. **Analytics**: `scrolly_step_1..3` fired once per beat on first intersection through `window.reachGoal`
   (aliased to the shop helper `window.shopReachGoal`); beat 4 keeps the existing lead goal.
4. **Verified** (local serve, Chrome via Playwright): 4 beats reveal, goals fire exactly once each, sticky
   CTA + sticky day stack + sticky tile row active, `prefers-reduced-motion` → static stack with no transitions,
   no horizontal overflow at 360/1280 (768 overflow is pre-existing in the shop header — reproduced with the
   flag OFF — and is tracked as a separate GTD row). Screenshot set committed under
   [marketing/marathon-2026-08/redesign/scrolly/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/marketing/marathon-2026-08/redesign/scrolly).

_Dr. Mārcis Gasūns_
