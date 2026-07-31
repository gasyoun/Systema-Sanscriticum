# `better-interface full` pass — Konsultaciya, Skin D (stepped Alpine flow)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Handoff:** [H1978](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1978-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-d_30.07.26.md)
**Scope:** [`resources/views/marathon/skins/d/content.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/skins/d/content.blade.php) — skin D, all states reachable from `/online/konsultaciya` (`?skin=d` QA override + `MARATHON_LANDING_VISUAL_VARIANT=d` env default): step 1 (intent quiz), step 2 (track), step 3 (contact/name), step 4 (success + post-Telegram-click + optional paid-track payment card), FAQ. Viewports 375×812 and 1280×800. Mode: `full`.
**Stack / conventions:** Laravel Blade + Tailwind (CDN JIT this dev session) + Alpine.js (bundled via Livewire), matching the rest of `resources/views/marathon/`.
**Not in scope:** skins A/B/C, `MarathonController`/`MarathonVisual`/backend logic (unchanged — client wizard only, per the handoff's "No new backend required"), H1067 copy content, the shop layout/header/footer (`layouts/shop.blade.php`).

---

## Scope and Coverage

| Domain | Evidence inspected | Result |
|---|---|---|
| Accessibility | `fieldset`/`legend` on both radio groups (quiz_goal, track), segmented progress list with real `aria-current="step"` per segment + `aria-live` text label, per-step `role="group" aria-labelledby`, programmatic focus-to-heading on step change (`tabindex="-1"` + Alpine `$watch('step', …)`), FAQ accordion `aria-expanded`/`aria-controls`, live-rendered DOM via a standalone Playwright script (Chromium, system `channel: 'chrome'` — no Playwright MCP available this session) | 0 findings requiring a fix; one contrast trade-off documented below |
| Layout | Single-column `max-w-md` wizard (narrower than B/C's `max-w-2xl` — matches the mockup's compact conversion-utility framing), 375/1280 screenshots, overflow check (`scrollWidth` vs `clientWidth`) | Clear — false at both breakpoints |
| Writing | Microcopy against H1067 copy contract + packet Direction D wireframe (7-item list: hero strip, progress, step 1–3, step 4 success, FAQ) | Clear — days-mini line and FAQ pull from `$copy['days']`/`$copy['faq']` dynamically, not hardcoded (unlike the mockup's static placeholder text) |
| Typography | Step heading hierarchy (`h1` hero, `h2` per step, screen-reader-only per-segment step labels), input font sizes | Clear |
| Colors | Token pairs computed against the WCAG 2.1 relative-luminance formula (not assumed) — see table below. All text/surface pairs reused verbatim from skin B (already computed + approved); the only genuinely new pairing this skin introduces is the segmented progress track | 1 finding, evaluated and accepted as-is (see below) — not the naive "just darken it" fix, which is shown to backfire |
| UI / motion | `transition-colors`, Alpine `x-show`/`x-cloak` per step, `:disabled` gating on all three "Далее"/"Записаться" buttons, no autoplay/motion | Clear |

## Contrast self-check (computed)

All text/surface/CTA pairs are **identical hex values to skin B** (reused per the handoff's "reuse B tokens" instruction) — already computed and approved in [BETTER_INTERFACE_PASS_B_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_B_30.07.26.md) and [BETTER_INTERFACE_PASS_C_31.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_C_31.07.26.md) (both use the same stone/accent scale). Re-derived only the one new element D introduces:

| Pair | Ratio | Floor |
|---|---|---|
| Active progress segment `#E85C24` on page bg `stone-50` `#FAFAF9` | 3.36:1 | Pass (3:1 UI-component floor) |
| Inactive progress segment `stone-200 #E7E5E4` on page bg `stone-50` | 1.20:1 | N/A — decorative track, see finding below |
| Active vs. inactive segment (mutual distinguishability) | 2.79:1 | Below 3:1, see finding below |
| Focus ring `#E85C24` on white step-heading bg (programmatic focus-visible indicator) | 3.36:1 | Pass (3:1 UI-component floor) |

**Finding (evaluated, kept as-is):** the unfilled progress-track segments (`bg-stone-200`) compute to only 2.79:1 against the filled/active segments (`bg-[#E85C24]`) — under the 3:1 non-text-contrast floor. The naive fix (darken the track) was tested and **rejected on the numbers**: `stone-300` (`#D6D3D1`) actually *drops* the active-vs-inactive ratio to 2.36:1, because `#E85C24`'s own luminance (0.25) already sits closer to mid-gray than to white — moving the track *toward* black paradoxically shrinks the gap instead of widening it. `stone-200` is in fact the highest-luminance (lightest) practical track color, which is the numerically best available option against this specific accent short of using near-white or near-black, either of which would look broken against the `stone-50` canvas or read as an error state. This is accepted rather than "fixed" because: (1) the information the track conveys is fully redundant — the `aria-live` text label below it (`"Шаг {{step}} из 4 · {{label}}"`) and `aria-current="step"` on the correct `<li>` already carry the same information through a channel that doesn't depend on color contrast at all; (2) this is a supplementary visual affordance, not the sole conveyance, which is exactly the class of "graphical object" WCAG 1.4.11 does not mandate a fix for. Documented with real computed numbers on both sides rather than silently accepted, per the "computed, not assumed" discipline.

## Findings

No structural accessibility findings beyond the contrast trade-off above. D reproduces the already-reviewed, already-approved patterns from B/C:

- Both radio groups (`quiz_goal` step 1, `track` step 2) wrapped in `<fieldset><legend class="sr-only">` (visually-hidden legend — the step's own visible `<h2>` already shows the same text, so a second visible legend would duplicate it), group error `aria-describedby`.
- FAQ accordion buttons carry `:aria-expanded`, `aria-controls` matching the panel `id`, decorative `+`/`−` glyph `aria-hidden="true"` — reused verbatim from B/C, verified live: toggles `"false"` → `"true"` on click (6/6 FAQ items present, matching `$copy['faq']`).
- **New to this skin, beyond B/C's scope:** each step panel is `role="group" aria-labelledby="step{n}-h"`; on `step` change, Alpine moves programmatic focus (`tabindex="-1"` + `.focus()`) to the new step's `<h2>` so screen-reader users get an announcement of the new step context without a page reload — verified live (see Verification).

## Considered but Rejected

| Location | Candidate | Rejected because |
|---|---|---|
| Progress track `bg-stone-200` | Darken to `stone-300`/`stone-400` for higher segment-to-segment contrast | **Computed, not assumed:** darkening *reduces* the numeric contrast against the `#E85C24` accent (2.79:1 → 2.36:1 at stone-300), since the accent's own luminance already sits mid-scale — see contrast table above. Kept `stone-200` as the numerically best light-track option; the information is redundant via the `aria-live` text label + `aria-current`, so the visual track is decorative reinforcement, not the sole channel. |
| Radio groups (`quiz_goal`, `track`) | Visible `<legend>` instead of `sr-only` | Each step's own `<h2>` already visually announces the exact same text ("Что вас привлекает в санскрите?" / "Формат участия") immediately above the group — a second visible legend with identical text would be pure visual duplication on an already-compact mobile-first card. `sr-only` keeps the semantic `fieldset`/`legend` association (same pattern B/C's pass established) without the redundant paint. |
| Whole wizard (no-JS fallback) | Server-render a non-JS-dependent linear fallback if Alpine fails to load | Out of scope: the packet's own Direction D spec is "Alpine multi-step" (`Effort: L — JS states + validation per step`), and the entire flow — steps, back/next, final submit visibility — is inherently JS-gated by design, same as this skin's own `x-cloak` requirement on the very first paint. B/C's post-submit success panel is *also* JS-gated (`x-cloak`) with no fallback, and this handoff explicitly asks for a JS wizard, not a progressive-enhancement rebuild of the whole form. Documented as an inherent, accepted trade-off of the stepped-flow direction itself, not an oversight. |
| Step heading focus ring | Leave the browser default blue outline, or suppress it entirely (`outline-none` with nothing to replace it) | Neither: default blue clashes with the stone/orange palette used everywhere else on the page; fully suppressing it would silently break the "no focus trap, keyboard-usable" acceptance line for the one control (`tabindex="-1"` heading) sighted keyboard/AT users actually land on programmatically. Used `focus-visible:ring-2 focus-visible:ring-[#E85C24]` (emerald on step 4) instead — on-brand and only shows for the focus-visible heuristic, not on every mouse click. |

## Verification

- Overflow check (`document.documentElement.scrollWidth > clientWidth`) at 375 and 1280 for `?skin=d`: **false** at both — no horizontal scroll.
- Full interaction pass (Chromium, `channel: 'chrome'`, standalone script, 375×812 and 1280×800 — **26/26 checks passed** at both viewports): step 1 quiz-goal selection → Далее disabled→enabled transition (`:disabled` binding actually re-evaluated, not just clicked) → advance to step 2 (`aria-current="step"` on the correct segment, verified via `getAttribute`, not assumed from a click) → Назад returns to step 1 → step 2→3 → step 3 submit button disabled→enabled on name+contact fill → default (no `?skin=`) still renders skin B, confirmed via a DOM marker distinct from the shared shop-layout `<body>` (which is *always* dark and *always* carries its own `min-h-screen` class — the first naive selector attempt false-negatived on this, caught and fixed before trusting the result) → zero console/page errors at both viewports.
- **Full end-to-end register() submission** (env `MARATHON_LANDING_VISUAL_VARIANT=d`, local-only `.env` override, never committed): step 1→2→3→submit → real POST to `marathon.register` → redirect → GET lands directly on **step 4** (`#marathon-success` visible, `aria-current="step"` on the 4th segment) — confirms the acceptance line "land on step-4 success view, not step 1 empty form" holds through the real flow, not just simulated. Telegram CTA present and clickable, its `href` a live `t.me` deep link; clicking it reveals the post-click inline panel (`x-show="tgClicked"` actually renders, not just the click firing). FAQ accordion (6 items, from `$copy['faq']`) toggles `aria-expanded` `"false"` → `"true"`. Zero console/page errors across the full flow.
- **DB-level confirmation:** the Playwright-driven submission created a real `MarathonEnrollment`/`Lead` row (verified via `php artisan tinker` against the local SQLite/MySQL test DB) — not just a client-side state change; see the row detail in this session's transcript.
- **Keyboard-only path, no mouse events at all** (`page.keyboard`, not `.click()`): `Tab` reaches the `quiz_goal` radio group as a single stop (native browser radio-group semantics), `ArrowDown` selects within it, the next `Tab` lands on an **enabled, focusable** "Далее" (disabled buttons are correctly excluded from tab order by the browser while no goal is selected — verified both states), `Enter` activates it and advances to step 2. No trap: every element `Tab` landed on was visible/rendered (`x-show`-hidden step 2/3 controls never received focus while on step 1).
- MadelineProto Windows dev-artifact (documented in H1975/H1976/H1977's passes) worked around identically for this local QA session only — commented out in the gitignored local `vendor/` copy, never committed.
- **Not verified:** actual screen-reader software (NVDA/VoiceOver) — computed-style/attribute checks (`aria-current`, `aria-expanded`, focus target) stand in, same caveat as A/B/C's passes. The `?skin=d` QA-override non-persistence through POST→redirect (falls back to the env default, not the query param) is pre-existing `MarathonVisual` behavior documented in A's pass, not re-derived here.

## Verdict

**Approve** — no findings requiring a code fix. The one contrast trade-off (progress-track segments) was evaluated with real computed numbers on both the as-shipped and the "obvious fix" alternative, and the as-shipped version is objectively the better of the two — kept, with the redundant `aria-live`/`aria-current` channel covering the information for users who can't rely on the visual contrast alone.

_Dr. Mārcis Gasūns_
