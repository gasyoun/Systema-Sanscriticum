# `better-interface full` pass — Konsultaciya, Skin A (dark-native)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Handoff:** [H1976](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1976-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-a_30.07.26.md)
**Scope:** [`resources/views/marathon/skins/a/content.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/skins/a/content.blade.php) — skin A, all states reachable from `/online/konsultaciya` (`?skin=a` QA override + `MARATHON_LANDING_VISUAL_VARIANT=a` env default): empty form, free-track post-submit success, post-Telegram-click inline panel, FAQ accordion, dark timeline/benefit cards. Viewports 375×812 and 1280×900/800. Mode: `full`.
**Stack / conventions:** Laravel Blade + Tailwind (Vite build) + Alpine.js, matching the rest of `resources/views/marathon/`.
**Not in scope:** skins B/C/D, `MarathonController`/`MarathonVisual`/backend logic, H1067 copy content, the shop layout/header/footer (`layouts/shop.blade.php`, including its site-wide newsletter popup and cookie banner).

---

## Scope and Coverage

| Domain | Evidence inspected | Result |
|---|---|---|
| Accessibility | `fieldset`/`legend` on the track radio group, FAQ accordion `aria-expanded`/`aria-controls`, timeline node `aria-hidden`, focus state on inputs, live-rendered DOM via a standalone Playwright script (Chromium, system `channel: 'chrome'` — no Playwright MCP available this session) | 0 new findings (inherits skin B's already-fixed patterns; see below) |
| Layout | Single-column `max-w-2xl` flow, timeline + benefit-card grid, 375/1280 screenshots, overflow check (`scrollWidth` vs `clientWidth`) | Clear — false at both breakpoints |
| Writing | Microcopy against H1067 copy contract + packet Direction A | Clear (identical `$copy` variable contract as skin B, only re-themed) |
| Typography | Input/label font sizes, heading hierarchy, timeline/benefit-card text sizing | Clear |
| Colors | Token pairs computed against WCAG 2.1 contrast formula (not assumed) — see table below | 2 findings, both fixed pre-review during implementation |
| UI / motion | `transition-colors`, Alpine `x-show`/`x-cloak`, track radio `:class` binding, no autoplay/motion | Clear |

## Contrast self-check (packet A tokens, computed)

| Pair | Ratio | AA (4.5:1 normal / 3:1 large or UI) |
|---|---|---|
| Hero `#F1F5F9` on bg `#0A0D14` | 17.74:1 | Pass |
| Subtitle `slate-300` on `#0A0D14` | 13.09:1 | Pass |
| Meta `slate-400` on `#0A0D14` | 7.58:1 | Pass |
| Card body `slate-300` on surface `#111622` | 12.18:1 | Pass |
| Input text `slate-100` on input bg `#0A0D14` | 17.74:1 | Pass |
| Badge/accent text `#E85C24` on `#0A0D14` | 5.54:1 | Pass |
| Success text `green-200` on `green-900` | 7.52:1 | Pass |
| Validation text `red-400` on `#0A0D14`/surface | 7.03:1 / 6.54:1 | Pass |
| ~~Caption/placeholder `slate-500` on `#0A0D14`~~ | ~~4.08:1~~ | **Fail → fixed to `slate-400` (7.58:1)** |
| CTA white on accent `#E85C24` | 3.51:1 | Below 4.5:1 normal-text AA — **kept, brand-locked** (see below) |

**Finding (fixed pre-review, during implementation):** two instances of `text-slate-500`/`placeholder:text-slate-500` (the submit-button caption and the contact-field placeholder) computed to 4.08:1, below the 4.5:1 AA floor for normal text. Bumped both to `slate-400` (7.58:1). No regression — same visual weight, just lighter.

**Accepted gap, not fixed:** the CTA button's white text on the `#E85C24` accent background computes to 3.51:1, short of the 4.5:1 normal-text floor (it does clear the 3:1 large-text/UI-component floor). This is the pre-existing, site-wide brand accent — identical pairing used by skin B's CTA and the shop header — and the aesthetic lock (`high-end-visual-design`, "tokens from packet A, not invent") explicitly forbids inventing a new accent color for one skin. Fixing this would require a brand-wide color decision, out of scope for a single-skin handoff.

## Findings

No new accessibility findings — skin A inherits the already-reviewed, already-fixed structural patterns from skin B ([BETTER_INTERFACE_PASS_B_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/BETTER_INTERFACE_PASS_B_30.07.26.md)):

- Track radio group wrapped in `<fieldset><legend>Формат участия</legend>` (not a bare `<span>`), group error `id="track-error"` + `aria-describedby`.
- FAQ accordion buttons carry `:aria-expanded`, `aria-controls` matching the panel `id`, decorative `+`/`−` glyph `aria-hidden="true"`. Verified live: `aria-expanded` toggles `"false"` → `"true"` on click.

## Considered but Rejected

| Location | Candidate | Rejected because |
|---|---|---|
| `content.blade.php` timeline nodes (day dots) | Add `aria-label` to each decorative dot | Same reasoning as B's day-circle decision: the dot is adjacent, non-interactive decoration next to the day's own heading text — screen readers already get the meaningful content from `{{ $day['title'] }}`; a separate label would be redundant. |
| `content.blade.php` inputs — `focus:ring-[#E85C24]` | Add an explicit `focus:ring-2` width utility | **Re-verified empirically, evidence differs from B's claim:** live Playwright check shows `focus:ring-[#E85C24]` alone renders `box-shadow: none` on focus in this build (unlike B's doc, which asserted a rendered ring). However `focus:border-[#E85C24]` on the same elements **does** apply (`border-color: rgb(232, 92, 36)`), giving a clearly visible, high-contrast (5.54:1) focus indicator via border-color alone — sufficient for WCAG 2.4.7/2.4.11 without the ring. Not fixing here: this exact utility pairing is inherited from B's already-shipped, already-approved input markup: fixing only in A would diverge the two skins' input styling without a matching change to B, which is out of this handoff's scope. |
| Badge "Бесплатная консультация" | Merge into the `<h1>` for one accessible name | Static screen furniture, not a live-announced region (`aria-live="polite"` already covers the success state) — merging would lengthen the heading's accessible name with no new information. Same call as B. |

## Verification

- Overflow check (`scrollWidth > clientWidth`) at 375 and 1280, both the default (env unset → B) and `?skin=a`: **false** at every combination — no horizontal scroll.
- Full interaction pass (Chromium, `channel: 'chrome'`): quiz select + track radio (`:class` binding verified to actually flip to `border-[#E85C24] bg-[#E85C24]/10` after click, before/after diff) + name/contact fill → submit → success panel visible (`#marathon-success` present) → Telegram CTA click → inline post-click state (`x-show="tgClicked"`) computed `display: block` (not just clicked — actually expanded) → FAQ toggle `aria-expanded` `"false"` → `"true"`.
- **Env-persistence check (production-relevant path):** with `MARATHON_LANDING_VISUAL_VARIANT=a` set (no `?skin=` query param), skin A renders on the initial GET **and survives the register()  POST → redirect → GET cycle** — post-submit `body` background computed to `rgb(10, 13, 20)` (`#0A0D14`), success panel using the dark `bg-green-900` classes. Confirms the acceptance criterion "env `=a` renders A" holds through the full user flow, not just the first paint.
- **`?skin=` QA-override non-persistence (expected, not a bug):** `?skin=a` on the initial GET renders A correctly, but the POST→redirect from `register()` does not preserve the query string, so the post-submit page falls back to the env default. This matches `MarathonVisual`'s own docblock ("`?skin=` is a QA-only override, never persisted") — pre-existing H1975 shell behavior, out of this handoff's scope to change.
- Console/page errors: zero, across every navigation and interaction in the pass.
- MadelineProto Windows dev-artifact (documented in H1975's pass) worked around identically for this local QA session only — commented out in the gitignored local `vendor/` copy, never committed.
- **Not verified:** actual screen-reader software (NVDA/VoiceOver) — computed-style/attribute checks stand in, same caveat as B's pass.

## Verdict

**Approve** — no findings requiring a fix beyond the two contrast bumps already applied during implementation. Consistent with skin B's already-approved patterns; no regressions, no new accessibility gaps.

_Dr. Mārcis Gasūns_
