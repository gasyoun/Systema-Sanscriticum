# `better-interface full` pass — Konsultaciya, Skin C (warm paper)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Handoff:** [H1977](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1977-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-c_30.07.26.md)
**Scope:** [`resources/views/marathon/skins/c/content.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/skins/c/content.blade.php) — skin C, all states reachable from `/online/konsultaciya` (`?skin=c` QA override + `MARATHON_LANDING_VISUAL_VARIANT=c` env default): empty form, free-track post-submit success, post-Telegram-click inline panel, FAQ accordion, warm-paper lesson cards. Viewports 375×812 and 1280×800. Mode: `full`.
**Stack / conventions:** Laravel Blade + Tailwind (Vite build) + Alpine.js, matching the rest of `resources/views/marathon/`.
**Not in scope:** skins A/B/D, `MarathonController`/`MarathonVisual`/backend logic, H1067 copy content, the shop layout/header/footer (`layouts/shop.blade.php`).

---

## Scope and Coverage

| Domain | Evidence inspected | Result |
|---|---|---|
| Accessibility | `fieldset`/`legend` on the track radio group, FAQ accordion `aria-expanded`/`aria-controls`, day-card ◆ mark `aria-hidden`, focus state on inputs, live-rendered DOM via a standalone Playwright script (Chromium, system `channel: 'chrome'` — no Playwright MCP available this session) | 0 new structural findings (inherits skin B's already-fixed patterns); 3 contrast findings, all fixed pre-review |
| Layout | Single-column `max-w-2xl` flow, lesson-card + FAQ-rule grid, 375/1280 screenshots via computed `scrollWidth`/`clientWidth` overflow check | Clear — false at both breakpoints |
| Writing | Microcopy against H1067 copy contract + packet Direction C | Clear (identical `$copy` variable contract as skins A/B, only re-themed) |
| Typography | `font-serif` H1/card-title vs sans body split (Tailwind's built-in Georgia/Cambria/Times serif stack — no webfont import needed, matches the handoff's "Charter/Georgia if font already available; else system serif") | Clear |
| Colors | Token pairs computed against the WCAG 2.1 relative-luminance formula (not assumed) — see table below | 3 findings, all fixed pre-review during implementation |
| UI / motion | `transition-colors`, Alpine `x-show`/`x-cloak`, track radio `:class` binding, no autoplay/motion | Clear |

## Contrast self-check (packet C tokens, computed)

| Pair | Ratio | AA (4.5:1 normal / 3:1 large or UI) |
|---|---|---|
| H1/body text `#2C2416` on bg `#F7F1E8` | 13.64:1 | Pass |
| Muted `#6B5E4E` on bg `#F7F1E8` (subtitle/meta/lesson-card body) | 5.61:1 | Pass |
| Muted `#6B5E4E` on surface `#FFFCF7` (card body/form labels) | 6.15:1 | Pass |
| Input text `#2C2416` on white `#FFFFFF` | 15.31:1 | Pass |
| Error text `red-600` on white / surface | 4.83:1 / 4.72:1 | Pass |
| Success text `emerald-800` on `emerald-50` (shared success block, unmodified from A/B) | 7.30:1 | Pass |
| Focus indicator border `#C45C26` on white input bg (UI component, 3:1 floor) | 4.28:1 | Pass |
| ~~Badge text `#C45C26` on bg `#F7F1E8`~~ | ~~3.81:1~~ | **Fail → fixed to `#A94D1F` (4.96:1)** |
| ~~Contact placeholder `#9A8C77` on white~~ | ~~3.29:1~~ | **Fail → fixed to the standing muted token `#6B5E4E` (6.30:1 on white)** |
| ~~CTA/pay-button white on accent `#C45C26`~~ | ~~4.28:1~~ | **Below 4.5:1 normal-text floor → fixed: button fill darkened to `#A94D1F` (4.96:1), hover `#8B3E19`** |

**Findings (fixed pre-review, during implementation):** three color pairs computed below the 4.5:1 normal-text AA floor — the 11px uppercase badge label, the contact-field placeholder, and both CTA buttons' white-on-accent fill. Unlike skin A's CTA (which reused the pre-existing, sitewide-brand-locked `#E85C24` and was accepted as an out-of-scope gap), skin C's terracotta `#C45C26` is this skin's *own* new accent, introduced by this handoff — and the handoff's own goal line hard-requires "contrast ≥ 4.5:1 body". So all three were fixed rather than accepted: badge and CTA fills moved to the darker in-family `#A94D1F` (already present in the file as the original hover shade, so no new hue was invented — just promoted from hover-only to default-fill), and the placeholder moved to the file's own standing `muted` token instead of a one-off lighter tan. `#C45C26` itself stays the visual accent identity everywhere it is NOT rendering as small/body text against a light background — decorative day-card mark (`aria-hidden`), FAQ +/− glyph (`aria-hidden`), radio-selection border/tint, blockquote left border, and the input focus border (a UI-component indicator, governed by the 3:1 floor, which it clears at 4.28:1).

## Findings

No new structural accessibility findings — skin C inherits the already-reviewed, already-fixed patterns from skin B ([BETTER_INTERFACE_PASS_B_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_B_30.07.26.md)) / skin A ([BETTER_INTERFACE_PASS_A_31.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_A_31.07.26.md)):

- Track radio group wrapped in `<fieldset><legend>Формат участия</legend>` (not a bare `<span>`), group error `id="track-error"` + `aria-describedby`.
- FAQ accordion buttons carry `:aria-expanded`, `aria-controls` matching the panel `id`, decorative `+`/`−` glyph `aria-hidden="true"`. Verified live: `aria-expanded` toggles `"false"` → `"true"` on click.

**Local-only dev artifact, not a product defect:** `vendor/danog/madelineproto/src/polyfill.php` unconditionally `echo`s a Windows-perf warning at file scope on every autoload, which leaked into the raw HTTP response body under `artisan serve` on this Windows machine and briefly broke inline `<script>` parsing (`SyntaxError: Unexpected identifier 'runs'`) during the first Playwright pass. Same root cause A/B's passes already documented; commented out in this session's gitignored local `vendor/` copy only — never committed, does not affect production (`php-fpm`/nginx buffering doesn't exhibit this the same way, and the file is vendor-owned, not tracked).

## Considered but Rejected

| Location | Candidate | Rejected because |
|---|---|---|
| `content.blade.php` lesson cards (◆ mark) | Add `aria-label` to each decorative mark | Same reasoning as A/B's day-marker decision: the mark is adjacent, non-interactive decoration next to the lesson's own heading text — screen readers already get the meaningful content from `{{ $day['title'] }}`; a separate label would be redundant. |
| `content.blade.php` inputs — `focus:ring-[#C45C26]` | Add an explicit `focus:ring-2` width utility | **Re-verified empirically for this build** (Playwright computed style on a focused input): `focus:ring-[#C45C26]` renders `box-shadow: none`, while `focus:border-[#C45C26]` **does** apply (`border-color: rgb(196, 92, 38)`) — a clearly visible, high-contrast (4.28:1, clears the 3:1 UI-component floor) focus indicator via border-color alone, sufficient for WCAG 2.4.7/2.4.11 without the ring. Matches A's identical live-verified finding for the same `focus:border-*`/`focus:ring-*` pairing in this repo's Tailwind build. Not fixing here for the same reason A gave: this exact utility pairing is inherited from B's already-shipped input markup, and fixing only in C would diverge the skins' input styling without a matching cross-skin change, out of this handoff's scope. |
| Badge "Бесплатная консультация" | Merge into the `<h1>` for one accessible name | Static screen furniture, not a live-announced region (`aria-live="polite"` already covers the success state) — merging would lengthen the heading's accessible name with no new information. Same call as A/B. |
| Layout choice | O3 `layouts/marathon` light layout instead of O2 island | The handoff explicitly allows either ("pick one, document in PR"). O2 (skin B's proven full-bleed break under the dark shop header) was chosen over introducing a second layout file for a single skin — lower risk, identical break mechanism already verified working at both viewports by B's own pass. |

## Verification

- Overflow check (`scrollWidth > clientWidth`) at 375 and 1280 for `?skin=c`: **false** at both — no horizontal scroll.
- Full interaction pass (Chromium, `channel: 'chrome'`, standalone script — no Playwright MCP this session): quiz select + track radio (`:class` binding verified to actually flip to `border-[#C45C26] bg-[#FAF3EB]` after click, before/after diff) → name/contact fill → submit → success panel visible (`#marathon-success` present, styled `rgb(236, 253, 245)`) → Telegram CTA click → inline post-click state visible → FAQ toggle `aria-expanded` `"false"` → `"true"`. **17/17 checks passed** at both viewports plus the default-skin-unchanged check.
- **Default-skin check:** with no `?skin=` query param, the page renders skin B (`rgb(250, 250, 249)` body background, distinct from C's `rgb(247, 241, 232)`) — confirms "default still B" holds.
- Console/page errors: zero, after the MadelineProto local-vendor workaround above (which was itself the cause of the only errors seen during this session).
- **Not verified:** actual screen-reader software (NVDA/VoiceOver) and the env-persistence-through-POST path (`MARATHON_LANDING_VISUAL_VARIANT=c` surviving register()→redirect→GET) — A's pass verified this exact mechanism (shared `MarathonVisual::variantKey()`) for variant `a`; the logic is env-var-driven and skin-agnostic, so it is not re-derived per skin, consistent with A's own "not verified: NVDA/VoiceOver" caveat pattern.

## Verdict

**Approve** — no findings requiring a fix beyond the three contrast bumps already applied during implementation. Consistent with skins A/B's already-approved structural patterns; no regressions, no new accessibility gaps.

_Dr. Mārcis Gasūns_
