# Konsultaciya landing — four redesign directions (H1966)

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Handoff:** [H1966](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1966-Fable_Systema-Sanscriticum_konsultaciya-ui-redesign_30.07.26.md)  
**Live:** [https://samskrte.ru/online/konsultaciya](https://samskrte.ru/online/konsultaciya)  
**Methods (design):** [Taste-skill orchestration](https://github.com/gasyoun/Uprava/blob/main/docs/TASTE_SKILL_ORCHESTRATION_FABLE_REDESIGN_PIPELINE_2026.md) · [/useit Nielsen pass](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md)  
**Methods (multi-dir implement — mandatory):** [jakubkrehel/skills](https://github.com/jakubkrehel/skills) **`better-interface`** + six domain skills (`better-accessibility` · `better-layout` · `better-writing` · `better-typography` · `better-colors` · `better-ui`) + repo [`blade-styling`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.claude/skills/blade-styling/SKILL.md) — registered on H1966 skill-pack table 30-07-2026  
**Author session:** Grok 4.5 (`grok-4.5`) executing Fable-tier design packet  
**Status:** Design packet landed ([PR #921](https://github.com/gasyoun/Systema-Sanscriticum/pull/921)). **Multi-direction:** A–D concurrent (B = default only). **Implement DAG:** [H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md) → H1976 A · H1977 C · H1978 D. **Do not re-run design** when applying `better-*`.

---

## Phase 1 audit (redesign-existing-projects)

### Keep

| Asset | Why |
|---|---|
| H1067 copy keys (`hero_title`, benefits, days, faq, CTA) | Ruled product copy; visual layer only |
| Form fields `quiz_goal`, `track`, `contact`, `name` + CSRF | Backend contract |
| Flash keys `marathon_result`, `marathon_telegram_link`, `marathon_track`, `marathon_paid`, `marathon_contact` | Controller contract |
| Accent `#E85C24` + shop header chrome | Brand continuity |
| Single column funnel + anti-urgency | Conversion discipline |
| Alpine FAQ accordion pattern | Already in stack |
| Free vs paid «с проверкой» semantics | Product |

### Kill / fix

| Pattern | Problem | Fix in A–D |
|---|---|---|
| Light text tokens on dark shop body | Catastrophic contrast (useit H4/H8) | O1 or O2/O3 per direction |
| Unstyled select/input inheritance | Light-on-white | Explicit control colors |
| Bare day `<ol>` | Low scan (H6) | Day cards / timeline / steps |
| Telegram-only success with silent click | No system status (H1) | Post-click panel in every direction |
| Generic white card + gray-100 borders | Flat AI-default | Direction-specific surfaces |

### Design Read + dials

**Design Read:** redesign-overhaul of cold-traffic education landing for FB/VK adults who fear Sanskrit is inaccessible; preserve brand orange + funnel fields; fix theme collision first.

| Direction | Aesthetic lock | VARIANCE | MOTION | DENSITY | Role |
|---|---|---|---|---|---|
| A Dark-native | high-end night shop | 5 | 3 | 5 | concurrent variant |
| **B Light island** | **minimalist-ui** | **4** | **2** | **3** | **default variant** (not sole winner) |
| C Warm paper | editorial serif/ivory | 5 | 2 | 4 | concurrent variant |
| D Stepped flow | conversion GetCourse-like | 3 | 4 | 4 | concurrent variant |

---

## Multi-direction policy (not a single pick)

**Ruled 30-07-2026:** a human will **not** choose one of A–D. **Several directions ship at once** as concurrent visual variants (same idea as H1067 copy A/B, but for chrome/layout).

| Axis | Mechanism (implement target) |
|---|---|
| Visual direction | e.g. `MARATHON_LANDING_VISUAL_VARIANT=a\|b\|c\|d` (or query `?skin=`) + Blade partials / CSS packs under `resources/views/marathon/` |
| Copy (H1067) | existing `MARATHON_LANDING_COPY_VARIANT` — independent of visual skin |
| Default | **B** if unset — cold-traffic safe; A/C/D remain first-class |

Why B is a good **default** (not a kill switch for A/C/D):

1. O2 light island fixes contrast without rewriting whole shop shell.
2. Matches cold FB/VK education-page expectations.
3. Lowest risk wrap on current blade.
4. Covers useit Must-fix set at S–M effort.
5. Keeps `#E85C24` + shop header/footer.

**Contrast floor applies to every variant** before it is considered shippable (no grey-on-`#0A0D14`).

---

## Shared post-submit + post-Telegram states (all directions)

### After «Записаться» (session flash)

1. Success banner top of content (not below fold): title «Вы записаны», body about personal Day 1–2 in Telegram.
2. Primary CTA: Telegram solid.
3. Secondary: «Скопировать ссылку» (JS clipboard).
4. Checklist: Start у бота → ждать День 1 → канал @samskrte optional.
5. If paid unpaid: payment card as «Шаг 2».
6. Optional: «Изменить контакт» re-opens form.

### After click «Продолжить в Telegram» (no backend)

Immediate inline state (no poll required for v1):

> Открыли Telegram. Нажмите **Start** у бота — иначе дни не придут.  
> Если окно не открылось — скопируйте ссылку или вернитесь и нажмите снова.

Optional v2 (Sonnet): poll `GET …/status/{token}` → «Бот подключён».

---

## Direction A — Dark-native shop continuity

**Thesis:** Premium night catalog page continuous with samskrte shop — for visitors already in the dark chrome brand world.  
**Layout option:** **O1**.  
**Aesthetic:** high-end-visual-design (restrained, not neon).  
**Effort:** **M**.

### Tokens

| Role | Value |
|---|---|
| bg | `#0A0D14` |
| surface | `#111622` |
| text | `#F1F5F9` (slate-100) |
| muted | `#94A3B8` (slate-400) |
| accent | `#E85C24` |
| success | `#14532D` / text `#BBF7D0` |
| border | `#1F2636` |
| radius | 16px cards, 12px controls |
| type | Nunito Sans; H1 2rem/2.5rem weight 900; body 1rem/1.6 |

### Wireframe (scroll)

1. Hero: H1 white, subtitle slate-400, orange 3px underline.
2. Social proof line (host name + «3×15 мин»).
3. Benefits: dark cards, left accent bar.
4. Days: vertical timeline (orange nodes).
5. Form: elevated dark card; labels slate-200; inputs `bg-[#0A0D14] border-[#1F2636] text-slate-100`.
6. Post-submit success green-dark panel.
7. FAQ: dark accordion.

### Components

- Day cards: number circle + title + body.
- Track radios: selected = orange border + surface lift.
- Quiz select: dark-native options (explicit colors).
- Messenger: orange primary; Telegram outline cyan `#0088cc`.
- FAQ: same Alpine pattern, dark surfaces.

### Contrast self-check

| Pair | Risk | Fix |
|---|---|---|
| muted on bg | slate-400 on #0A0D14 ≈ OK for large | keep body ≥ slate-300 for long text |
| placeholder | too faint | slate-500 min |
| success green | light green-50 on dark | use dark green panel |

### Mockup

[`redesign/direction-a-dark.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-a-dark.html)

---

## Direction B — Light minimalist island (**default variant**)

**Thesis:** Calm modern edtech paper under shop header — opens Sanskrit for cold FB/VK traffic without museum dust.  
**Layout option:** **O2** (`min-h-screen bg-stone-50 text-stone-900` content wrap).  
**Aesthetic:** **minimalist-ui**.  
**Effort:** **S–M**.

### Tokens

| Role | Value |
|---|---|
| bg | `#FAFAF9` (stone-50) |
| surface | `#FFFFFF` |
| text | `#1C1917` (stone-900) |
| muted | `#57534E` (stone-600) |
| accent | `#E85C24` |
| success | `#ECFDF5` / text `#065F46` |
| border | `#E7E5E4` (stone-200) |
| radius | 20px form, 14px cards |
| type | Nunito Sans; H1 1.875–2.25rem; body 1.0625rem; max-w-xl |

### Wireframe

1. Full-width light island starts under dark header.
2. Hero centered, short H1, one-line proof under subtitle.
3. Three day cards in column (or soft 1-col stack).
4. Benefits as quiet list or 2 compact cards (optional collapse on mobile).
5. Form white card, large track radio cards, obvious select.
6. CTA orange full width; helper text under.
7. FAQ light accordion.
8. Success replaces form top with green panel + TG CTA.

### Components

- Track radios: full-width padded cards; selected ring orange.
- Quiz: native select with `text-stone-900 bg-white`.
- TG: solid `#0088cc` or brand orange + TG icon wordmark.
- VK/Max: optional quiet text links (disabled until product).

### Contrast self-check

| Pair | Risk | Fix |
|---|---|---|
| muted stone-600 on stone-50 | AA pass | use for body; labels stone-800 |
| orange on white | large text OK | buttons white on orange |
| shop dark header → light island | intentional break | full-bleed island, no gray-600 on dark |

### Mockup

[`redesign/direction-b-light.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-b-light.html)

---

## Direction C — Warm Indology paper

**Thesis:** Scholarly trust without dust — 35+ audience who want seriousness, not SaaS chrome.  
**Layout option:** **O2** or **O3** light marathon layout.  
**Aesthetic:** editorial warm (serif display + sans body).  
**Effort:** **M**.

### Tokens

| Role | Value |
|---|---|
| bg | `#F7F1E8` |
| surface | `#FFFCF7` |
| text | `#2C2416` |
| muted | `#6B5E4E` |
| accent | `#C45C26` (terracotta; still near brand) |
| success | `#E8F5E9` / `#1B5E20` |
| border | `#E0D4C4` |
| radius | 8–12px (less pill) |
| type | Charter/Georgia H1; Nunito Sans body; thin HR rules |

### Wireframe

1. Ivory full bleed; centered column max-w-2xl.
2. H1 serif; thin rule; subtitle.
3. Optional quote block for testimonial (only if real).
4. Days as “lesson cards” with small decorative mark (Unicode ॐ or simple diamond — decorative only; body stays Cyrillic).
5. Form paper card, terracotta CTA.
6. FAQ under thin rules.

### Contrast self-check

| Pair | Risk | Fix |
|---|---|---|
| muted brown on ivory | usually OK | measure body ≥ 4.5:1 |
| ornament overload | looks dated | max one ornament per day card |

### Mockup

[`redesign/direction-c-paper.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-c-paper.html)

---

## Direction D — Conversion-first stepped flow

**Thesis:** One decision per screen — GetCourse clarity for mobile FB traffic.  
**Layout option:** **O2** shell + Alpine multi-step.  
**Aesthetic:** conversion utility (minimal chrome).  
**Effort:** **L** (JS states + validation per step).

### Tokens

Reuse **B** tokens (light island) so step UI stays readable; progress accent `#E85C24`.

### Wireframe

1. Compact hero strip (title + 15 min badge) fixed above steps.
2. Progress 1–4.
3. Step 1: intent quiz only → Далее.
4. Step 2: track radios → Далее.
5. Step 3: contact + name → Записаться (POST).
6. Step 4 success: big check, TG primary, copy link, checklist, optional pay.
7. FAQ collapsible after step 4 or bottom link.

### Components

- Progress bar with accessible `aria-current`.
- Back button on steps 2–3.
- Disable Далее until field valid.
- Post-TG click state lives entirely in step 4.

### Contrast self-check

Same as B; ensure step titles always stone-900.

### Mockup

[`redesign/direction-d-stepped.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-d-stepped.html)

---

## Implementer note (multi-direction — no winner pick)

| Item | Detail |
|---|---|
| Files | `resources/views/marathon/show.blade.php` + per-direction partials/CSS (or one shell + `direction-{a,b,c,d}` includes); optional `layouts/marathon.blade.php` if O3 |
| Layout | B/C → **O2** wrap; A → **O1** tokens; D → O2 + Alpine steps — **all retained as variants** |
| Switch | Config (and/or query) for visual variant; default **b**; copy variant axis stays separate |
| Do not break | H1067 copy keys; field names; routes `marathon.register` / `marathon.pay`; flash `marathon_*`; evergreen anti-urgency |
| Skills | **Mandatory stack:** (1) [`blade-styling`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.claude/skills/blade-styling/SKILL.md) + Playwright contrast **per direction**; (2) taste Phase 4 from each mockup (or shared shell + direction packs); (3) **[jakubkrehel/skills](https://github.com/jakubkrehel/skills) `better-interface full`** per variant (loads a11y/layout/writing/type/color/ui). Primary table: [H1966 skill packs](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1966-Fable_Systema-Sanscriticum_konsultaciya-ui-redesign_30.07.26.md). |
| Out of scope | VK/Max deliver-due; BotFather; Filament; Day content; forcing a single skin; re-running the design packet |

### Contrast floor (every variant)

Every shippable direction: body text ≥ 4.5:1; inputs `text-stone-900 bg-white` (or dark-native explicit tokens on A). No “wait for pick” hotfix path — multi-dir is the path.

### Skill packs for implement (mirror of H1966)

| Pack | Role | Status |
|---|---|---|
| `/useit` | Nielsen H1–H10 on production | applied (design) — [USEIT_NIELSEN_PASS_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md) |
| Taste orchestration | Phase 0–3 mockups A–D | applied (design) |
| **`better-*` (jakubkrehel/skills)** | Holistic interface QA on each concurrent variant | **required for implement** — install: `~/.grok/skills/better-*` / `~/.agents/skills/` |

**Invoke:** `better-interface full online/konsultaciya multi-dir variants` (Grok) · `/better-interface full …` (Claude CLI skills) · `$better-interface full …` (Codex). Use `quick` only for a single-variant spot check. Close HIGH/MEDIUM findings (or document accept) before marking a skin shippable. Optional artifact: `redesign/BETTER_INTERFACE_PASS_DD.MM.YY.md`.

---

## Appendix — BotFather copy pack (`@samskrte_bot`)

| Field | Suggested |
|---|---|
| Name | `ОРС · 3 дня с санскритом` |
| About (≤120) | `Бесплатная 3-дневная консультация ОРС: ~15 мин/день, без деванагари. Дни 1–2 в боте, День 3 — Zoom.` |
| Description | `После Start вы получите личный День 1. Ссылка одноразовая. Если бот молчит — напишите в поддержку на сайте samskrte.ru. Дни идут в вашем темпе.` |
| Avatar | Logo on `#E85C24` solid, high contrast at 40×40 |

Human pastes into BotFather; not a landing PR.

---

## Deliverables index

| File | What |
|---|---|
| This doc | Directions A–D + audit + multi-dir policy (B default) |
| [redesign/USEIT_NIELSEN_PASS_30.07.26.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/USEIT_NIELSEN_PASS_30.07.26.md) | Full `/useit` H1–H10 |
| [redesign/direction-a-dark.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-a-dark.html) | Static mockup A |
| [redesign/direction-b-light.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-b-light.html) | Static mockup B |
| [redesign/direction-c-paper.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-c-paper.html) | Static mockup C |
| [redesign/direction-d-stepped.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/redesign/direction-d-stepped.html) | Static mockup D |

Open HTML files in a browser (desktop 1280 + devtools 375). Unopened mockup is a draft, not a deliverable — verify locally.

---

## Next step (no human direction pick)

**Implement DAG minted 30-07-2026** (Sonnet 5). This packet + four HTML mockups = design source of truth. Skill pack gate closed (`better-interface` required).

| Order | Handoff | Scope |
|---|---|---|
| **1 — launch first** | [H1975](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md) | Visual switch + **default B** light island |
| 2 | [H1976](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1976-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-a_30.07.26.md) | Skin A dark-native (after H1975) |
| 3 | [H1977](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1977-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-c_30.07.26.md) | Skin C warm paper (after H1975) |
| 4 | [H1978](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1978-Sonnet_Systema-Sanscriticum_konsultaciya-visual-dir-d_30.07.26.md) | Skin D stepped Alpine (after H1975) |

**Launch today:**

```
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H1975-Sonnet_Systema-Sanscriticum_konsultaciya-visual-shell-b_30.07.26.md and execute it.
```

_Dr. Mārcis Gasūns_
