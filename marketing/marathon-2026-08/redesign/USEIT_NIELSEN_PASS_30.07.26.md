# /useit Nielsen Full Pass — `/online/konsultaciya`

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Date:** 30-07-2026  
**Platform:** Web (mobile-first marketing landing)  
**Mode:** `standard`  
**URL:** [https://samskrte.ru/online/konsultaciya](https://samskrte.ru/online/konsultaciya)  
**Evidence:** production blade [`show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/marathon/show.blade.php) + shop layout dark body `bg-[#0A0D14]`; human report 30-07-2026; H1966 brief.  
**Evaluator:** Grok 4.5 (`grok-4.5`) via `/useit` → Nielsen H1–H10 authority skill.  
**Tasks in scope:** (1) understand offer in 10s, (2) pick track free/paid, (3) submit contact, (4) open Telegram + know what to do next, (5) read FAQ if stuck.

---

## Executive summary

- **Overall score: 3.4/10** (Poor for cold traffic)
- **Issue counts:** sev4: 3 · sev3: 6 · sev2: 5 · sev1: 3
- **Top 3 critical:**
  1. Hero + body copy unreadable (dark-on-dark) — blocks task 1 entirely.
  2. Form select/control text inherits light body color on white card — field looks empty.
  3. After «Продолжить в Telegram» no page-state change — system status invisible for the conversion-critical step.

## Scoreboard

| H | Heuristic | Score /5 | # issues | Worst severity |
|---|---|---|---|---|
| H1 | Visibility of system status | 2 | 3 | 4 |
| H2 | Match system ↔ real world | 4 | 1 | 2 |
| H3 | User control and freedom | 3 | 2 | 3 |
| H4 | Consistency and standards | 2 | 2 | 4 |
| H5 | Error prevention | 3 | 2 | 3 |
| H6 | Recognition rather than recall | 3 | 2 | 3 |
| H7 | Flexibility and efficiency | 3 | 1 | 2 |
| H8 | Aesthetic and minimalist design | 2 | 3 | 4 |
| H9 | Help recognize / diagnose / recover errors | 3 | 2 | 3 |
| H10 | Help and documentation | 4 | 1 | 2 |

---

## Detailed findings

### H1 — Visibility of system status
**Compliance:** ★★☆☆☆ (2/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H1-1 | 4 | Post-Telegram click | `<a target="_blank">` only; no inline «opened Telegram / press Start» | Immediate click state + copy link + checklist (redesign states) | L |
| H1-2 | 3 | Form submit | Success only after full reload + flash; easy to miss above fold if scrolled | Pin success block sticky/top; disable button + «Отправляем…» on submit | M |
| H1-3 | 2 | Paid track unpaid | Orange box exists after submit — good | Keep; make payment step number explicit («Шаг 2 из 2») | L |

**Positive:** Green «Вы записаны!» flash after POST exists.  
**Top fix:** Design post-Telegram-click state (all directions A–D).

### H2 — Match system ↔ real world
**Compliance:** ★★★★☆ (4/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H2-1 | 2 | Track labels | «С проверкой» is school jargon for cold ads traffic | Keep product name; add plain subline «куратор смотрит ваши ответы» | L |

**Positive:** Copy is warm Russian «вы», anti-urgency, ~15 min — matches real student language (H1067).  
**Top fix:** One plain-language line under paid track.

### H3 — User control and freedom
**Compliance:** ★★★☆☆ (3/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H3-1 | 3 | After submit | Cannot re-edit quiz/track without full re-register | Allow «изменить данные» collapse under success | M |
| H3-2 | 2 | Direction D multi-step | N/A today; if D ships, need back + edit | Progress bar + «Назад» on every step | M |

**Positive:** FAQ accordion can open/close freely (Alpine).  
**Top fix:** Edit path after success.

### H4 — Consistency and standards
**Compliance:** ★★☆☆☆ (2/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H4-1 | 4 | Theme | Light tokens (`#1A1A1A`, `gray-600`) on dark shop shell | Pick O1/O2/O3 and commit (no hybrid accident) | M |
| H4-2 | 3 | Form controls | Selects inherit `text-slate-200` from body | Explicit `text-stone-900 bg-white` on all inputs/selects | L |

**Positive:** Accent `#E85C24` matches shop header/CTA.  
**Top fix:** Single theme strategy (recommended O2 for B).

### H5 — Error prevention
**Compliance:** ★★★☆☆ (3/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H5-1 | 3 | Contact field | Free text «phone, email or Telegram» — no format hint/mask | Placeholder examples + soft client check | M |
| H5-2 | 2 | Quiz select | Empty default «Выберите…» required — good | Keep; pre-select nothing | — |

**Positive:** HTML `required` on quiz + contact.  
**Top fix:** Placeholder `+7… / name@mail.ru / @username`.

### H6 — Recognition rather than recall
**Compliance:** ★★★☆☆ (3/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H6-1 | 3 | Days section | Ordered list only; hard to scan on mobile | Numbered day cards with title+body always visible | M |
| H6-2 | 2 | Benefits | Titles as objections — good recognition | Keep structure; fix contrast so readable | L |

**Positive:** Intent quiz surfaces goals as choices, not free recall.  
**Top fix:** Day cards (all visual directions).

### H7 — Flexibility and efficiency
**Compliance:** ★★★☆☆ (3/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H7-1 | 2 | Messengers | Telegram only; VK/Max users dead-end | Design multi-CTA; flag Sonnet plumbing follow-on | M+eng |

**Positive:** Evergreen entry any day — no schedule friction.  
**Top fix:** Ghost VK/Max only if product enables (see H1966 context).

### H8 — Aesthetic and minimalist design
**Compliance:** ★★☆☆☆ (2/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H8-1 | 4 | Hero | Unreadable hierarchy → zero aesthetic signal | Readable type scale + one accent underline | M |
| H8-2 | 3 | Density | Form + benefits + days + FAQ all compete | Clear scroll order; CTA above fold on 375 | M |
| H8-3 | 1 | Cards | Generic white-border-shadow cards | Direction-specific elevation | L |

**Positive:** Single-column max-w-2xl is already focused.  
**Top fix:** Hierarchy via contrast + spacing, not more chrome.

### H9 — Help recognize / diagnose / recover errors
**Compliance:** ★★★☆☆ (3/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H9-1 | 3 | Validation | Relies on browser native; pay email has `@error` | Match inline RU errors for register form | M |
| H9-2 | 2 | Telegram fail | No «if bot did not open» recovery | Copy-link + FAQ link under CTA | L |

**Positive:** `session('error')` red box pattern exists.  
**Top fix:** Recovery copy under Telegram button.

### H10 — Help and documentation
**Compliance:** ★★★★☆ (4/5)

| ID | Sev | Location | Evidence | Recommendation | Effort |
|---|---|---|---|---|---|
| H10-1 | 2 | FAQ | Present but low contrast / after form | Keep; ensure readable; optional sticky «?» | L |

**Positive:** Six real FAQs cover time, Devanagari, Day 3, tracks, next step, host.  
**Top fix:** Contrast + place one FAQ teaser near CTA for «нужно ли деванагари».

---

## Prioritized action plan

### Must fix (sev 4–3)

1. **Theme collision** (H4-1, H8-1) — pick O1/O2/O3; no light tokens on dark body.
2. **Form control contrast** (H4-2) — explicit dark text on white inputs.
3. **Post-Telegram status** (H1-1, H9-2) — inline state + copy link.
4. **Day scannability** (H6-1) — cards not bare list.
5. **Success above fold** (H1-2) — flash pinned / scroll-to.
6. **Contact affordance** (H5-1) — placeholders + soft validation.
7. **Paid track plain language** (H2-1).
8. **Register inline errors** (H9-1).
9. **Edit after success** (H3-1).

### Should fix (sev 2)

- Multi-messenger design slots (H7-1) if product enables.
- Step labels for payment (H1-3).
- FAQ teaser near CTA (H10-1).

### Quick wins

- Input classes: `text-stone-900 bg-white border-stone-300`.
- Hero: `text-white` or light island wrap (one class change path for hotfix).
- Telegram click microcopy under button.
- Contact placeholder.

### Nice to have (sev 1)

- Card elevation polish per direction.
- Serif display only if C wins.

## Open questions / evidence gaps

- Live visual re-check of rendered select options in Safari/Chrome (inheritance often browser-specific).
- Real conversion drop-off after Telegram click (analytics not in this pass).
- Whether magnet bot name/avatar confuses Start (ops, not landing code).

## Suggested next skills

- WCAG AA contrast measure after implementer ships winner.
- `/ux-prototype` already satisfied by four static directions.
- `blade-styling` + Playwright on implementer handoff.

## Mapping to redesign directions

| Must-fix | A Dark | B Light island | C Paper | D Stepped |
|---|---|---|---|---|
| Theme | O1 | O2 | O2/O3 | O2 or O1 shell |
| Form contrast | dark elevated | white card | ivory card | per-step card |
| TG status | post-submit panel | same | same | Step 4 owns it |
| Day cards | timeline | 3 cards | lesson cards | collapsed summary |

_Dr. Mārcis Gasūns_
