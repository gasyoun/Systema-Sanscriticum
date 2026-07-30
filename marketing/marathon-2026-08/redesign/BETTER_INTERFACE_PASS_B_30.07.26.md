# `better-interface full` pass — Konsultaciya, Skin B (light island)

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Handoff:** [H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md)
**Scope:** `resources/views/marathon/skins/b/content.blade.php` — skin B, all states reachable from `/online/konsultaciya` (default env + `?skin=b`): empty form, free-track post-submit success, paid-track "Шаг 2 из 2" payment card, post-Telegram-click inline panel, FAQ accordion. Viewports 375×812 and 1280×800. Mode: `full`.
**Stack / conventions:** Laravel Blade + Tailwind (CDN JIT) + Alpine.js (bundled via Livewire's `@livewireScripts`), matching the rest of `resources/views/marathon/`.
**Not in scope:** skins A/C/D (stub `@include`s pending H1976–H1978), `MarathonController`/backend logic, H1067 copy content, the shop layout/header/footer (`layouts/shop.blade.php`).

---

## Scope and Coverage

| Domain | Evidence inspected | Result |
|---|---|---|
| Accessibility | Radio group markup, FAQ accordion, form labels/errors, focus rings, hit areas, live-rendered DOM via Playwright (Chromium, keyboard-equivalent checks via computed styles) | 2 findings (fixed) |
| Layout | Single-column `max-w-2xl` flow, day-card grid, 375/1280 screenshots, overflow check (`scrollWidth` vs `clientWidth`) | Clear |
| Writing | Microcopy against H1067 copy contract + packet Direction B | Clear |
| Typography | Input/label font sizes (iOS-zoom risk), heading hierarchy | Clear |
| Colors | Token pairs against packet's own contrast self-check table (Direction B: muted stone-600/stone-50, stone-900/white, orange-on-white) | Clear (inherited from an already-reviewed design packet, not re-derived) |
| UI / motion | Transitions (`transition-colors`), Alpine `x-show`/`x-cloak` state changes, no autoplay/motion to gate behind `prefers-reduced-motion` | Clear |

## Findings

| # | Severity | Domain | Location | Before | After | Why |
|---|---|---|---|---|---|---|
| 1 | MEDIUM | Accessibility | `resources/views/marathon/skins/b/content.blade.php:170-196` | Radio group labeled by a plain `<span>Формат участия</span>`, no group semantics | Wrapped in `<fieldset class="border-0 p-0 m-0">` + `<legend>`, group error now `id="track-error"` + `aria-describedby` on the radio wrapper | A screen-reader user tabbing between the two track radios never hears "Формат участия" as their group context — native `fieldset`/`legend` is the platform's own answer, no ARIA needed |
| 2 | MEDIUM | Accessibility | `resources/views/marathon/skins/b/content.blade.php:230-238` (FAQ accordion) | Toggle `<button>` had no `aria-expanded`/`aria-controls`; the answer `<div>` had no `id` | Added `:aria-expanded="(open === {{ $i }}).toString()"`, `aria-controls="faq-panel-{{ $i }}"` on the button, matching `id` on the panel, `aria-hidden="true"` on the decorative `+`/`−` glyph | Same pre-existing pattern as production's original FAQ (inherited, not introduced this pass) — a screen reader never announced whether a question was open or closed |

Both fixed in this pass (verified: `fieldset` reset renders with `border-width: 0px` / `padding: 0px`, no visible regression at 1280 — see `h1975-fieldset-check.png` in this session's scratchpad, not committed).

## Considered but Rejected

| Location | Candidate | Rejected because |
|---|---|---|
| `content.blade.php:90/159/200/213` (all inputs/selects) | Add an explicit `focus:ring-2` width utility alongside `focus:ring-[#E85C24]` | Verified via Playwright: Chromium renders a visible ring on focus with the color-only utility (Tailwind's `ring-{color}` utility ships its own width fallback); this is also the exact pattern the pre-existing production page already used — no regression, no gain from adding a redundant width class |
| `content.blade.php:116` (day-number circle) | Add `aria-label` to the numbered circle div | It's adjacent, non-interactive decoration next to the day's own heading text (`{{ $day['title'] }}`) in the same card — screen readers already get the day title as the meaningful content; labeling the circle separately would be redundant, not additive |
| `content.blade.php:26` (badge "Бесплатная консультация") | Make the badge part of the `<h1>` for one full accessible name | It's static screen furniture, not the day's actual live announcement region (`aria-live="polite"` already covers the success state at line 43); merging it into the h1 would just make the heading's accessible name longer without new information |

## Verification

- Overflow check (`document.documentElement.scrollWidth > clientWidth`) at 375 and 1280: **false** (no horizontal scroll) — [`blade-styling`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.claude/skills/blade-styling/SKILL.md) Playwright loop, same session.
- Full interaction pass via Playwright (Chromium): quiz select + track radio + name/contact fill → submit → success panel visible → Telegram CTA click → inline post-click panel (`x-show="tgClicked"`) actually expands (computed `display !== 'none'`, not just clicked) → paid-track submit → "Шаг 2 из 2" card renders → FAQ toggle actually expands (computed `display`, not just clicked) → track-radio `:class` binding actually reacts (before/after class diff, `#E85C24` present after selection).
- Console/page errors: only the tool's own intentional network aborts (webfont CDN, `t.me` popup) — zero real console/page errors once a **pre-existing, out-of-scope** local-Windows-dev artifact was worked around (see note below); confirmed by reproducing the same artifact on the unrelated `/` route.
- **Not verified:** actual screen-reader software (NVDA/VoiceOver) — computed-style/attribute checks stand in for that here; flag for a follow-up manual pass if this variant becomes the sole shipped skin.

**Note (out of scope, not a finding against this handoff):** this Windows `php artisan serve` process leaks a `danog/madelineproto` startup warning (`vendor/danog/madelineproto/src/polyfill.php:10`, `echo` gated on `PHP_OS_FAMILY === 'Windows'`) as literal text prepended to every response body, including JS assets — which breaks Alpine.js parsing until worked around for this local QA session only (never committed; `vendor/` is gitignored). Reproduces on the unrelated `/` homepage too, and the warning's own condition means it cannot fire in production (Linux). Not fixed here — orthogonal to this handoff's scope (visual shell/CSS), and the fix (if wanted) belongs to whoever owns local dev-environment tooling, not the Konsultaciya landing.

## Verdict

**Approve** — both findings were `MEDIUM`, both closed in this pass, no `HIGH` remains.

_Dr. Mārcis Gasūns_
