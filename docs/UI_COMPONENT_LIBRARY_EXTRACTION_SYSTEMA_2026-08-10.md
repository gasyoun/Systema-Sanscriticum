# Systema UI component library — measured extraction plan (tokens first, then primitives)

_Created: 10-08-2026 · Last updated: 10-08-2026_

**Handoff:** H2541 (Opus 5) — Systema UI component library: token collapse + piecemeal `x-ui` primitive
extraction, with a parallel React mirror for Claude Design.
**Measured and authored by:** Opus 5 (`claude-opus-5`), 10-08-2026.
**Status:** **Phase 1 partially shipped** — the brand-orange collapse and the `tailwind.config.js`
deletion landed under H2560 (Opus 5) on 10-08-2026; evidence in
[docs/H2560_PHASE1_EVIDENCE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/H2560_PHASE1_EVIDENCE.md).
The gray-literal replacement (step 3 below) and all of Phase 2+ remain unstarted.

## Why this document exists

The request was "manage UI complexity — which high-level components would you pull into a component
library". This answers that from measurement rather than intuition, and records one finding that
inverts the obvious plan: **the problem here is drift, not copy-paste duplication**, so tokens must
land before any component is extracted.

## What was measured

All counts from the working tree at `2eeb144b` (10-08-2026), scoped to the student/public surface
(`student shop promo livewire components partials layouts marathon srs reading checkout articles auth`)
except where noted as repo-wide.

| Metric | Value |
|---|---|
| Blade templates, repo-wide | 330 (33,063 LOC) |
| Addressable surface (excl. `vendor/`, `filament/`, `emails/`) | ~150 templates |
| Distinct hex colours, repo-wide | **241** |
| Hex literal occurrences, repo-wide | 3,163 |
| Of which Tailwind arbitrary-value classes (`[#RRGGBB]`) | **2,107** |
| Tailwind version | **v4**, CSS-first (`@import "tailwindcss"` + `@source "../views"`) |
| Stack | Blade + Livewire + Filament 3 + Alpine |

### The palette, ranked

| Hex | Count | What it is |
|---|---|---|
| `#E85C24` | **1,154** | brand orange — 36.5% of every hex literal in the repo |
| `#1F2636` | 179 | dark surface |
| `#101010` | 121 | near-black |
| `#38BDF8` | 116 | sky accent |
| `#111622` | 81 | dark surface, second variant |
| `#9CA3AF` | 66 | **Tailwind `gray-400`, hand-copied as a literal** |
| `#D04A15` · `#D35400` · `#D34F1C` | 61 · 51 · 23 | **three hand-written hover states for the one orange** |
| `#6B7280` · `#F3F4F6` · `#E5E7EB` | 44 · 43 · 35 | **more Tailwind grays copied as literals** |
| `#E3122C` | — | a *second* brand red, in its own input cluster |

Two classes of waste are visible here. Tailwind's own grays are being pasted as hex when
`text-gray-400` would do, and the single brand orange has sprouted three unmanaged hover variants.

### Utility spread (distinct files containing the pattern)

| Pattern | Files | Occurrences |
|---|---|---|
| `rounded-xl` | 91 | 380 |
| `bg-white` | 91 | 338 |
| `rounded-full` | 75 | 270 |
| `inline-flex` | 72 | 218 |
| `x-data` (Alpine) | 51 | 88 |
| `grid-cols` | 51 | 171 |
| `input` element | 40 | 186 |
| `aria-*` | 36 | 119 |
| `fixed inset-0` (modal scrim) | 17 | — |
| `progress` | 13 | 21 |
| `role=` | — | 30 |
| `sr-only` | — | **5** |
| `table` element | — | **2** |

## The finding that changes the plan

Exact-duplicate class strings are **low**: the most-repeated button string appears 3 times, the most
repeated input string 11 times. Against 218 `inline-flex` and 186 `input` occurrences, that is not a
copy-paste problem — it is **241 colours' worth of independent drift**.

The consequence is concrete: **there is no canonical version to extract.** A mechanical
"find the repeated block, make it a component" pass has nothing to lock onto, because every call site
differs slightly — a different orange, a different radius, a different focus ring. Deciding the canon
*is* the work, and it is a design decision, not a refactor.

Hence the ordering below. Tokens are mechanical and decidable today; components are not, until tokens
give them a vocabulary to be built from.

Three near-duplicate input clusters, for illustration — same component, three different brand colours
and three different focus treatments:

```
w-full px-4 py-3  rounded-xl border border-gray-200 bg-gray-50 ... focus:ring-1 focus:ring-[#E85C24]   (11x)
w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 ... focus:ring-2 focus:ring-[#E3122C]/20 (5x)
w-full px-5 py-4  rounded-xl border border-transparent bg-[#252529] ... focus:border-[#3E3E45]           (4x)
```

## Phase 1 — tokens (do this first, alone)

Mechanical, low-risk, and it unblocks everything else. No component work in this phase.

1. ✅ **Done (H2560).** Define an `@theme` block in
   [resources/css/app.css](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/css/app.css).
   Shipped with `--color-brand` + `--color-brand-hover` only. `--color-ink`, `--color-surface` and
   `--radius-card` were **deliberately not** defined: the surfaces they would name are exactly the
   dark-palette question a human still owes a ruling on (see Open questions), and `--radius-card`
   is a Phase-2 concern that belongs with the Card primitive, not ahead of it.
2. ✅ **Done (H2560).** Collapse the oranges to `--color-brand` + `--color-brand-hover`.
   314 arbitrary-value classes retired across 179 templates. Measured against the *in-scope*
   surface the collapse was allowed to touch, that is 1,007 → 802 (the 2,107 figure in the table
   above is repo-wide and includes `filament/`, `vendor/`, `emails/`, and the skins, all excluded).
   Four hover oranges unified, not three — the census found `#D14E1A` (7×) in addition to
   `#D04A15` / `#D64E1C` / `#D34F1C`; max pairwise delta ~7/255, so `#D04A15` won on plurality.
3. ⬜ **Not started.** Replace hand-copied Tailwind grays (`#9CA3AF`, `#6B7280`, `#F3F4F6`,
   `#E5E7EB`, `#111827`) with their native utilities — pure find-and-replace, no visual change.
   Independent of the dark-palette ruling, so this is the next executable step in Phase 1.
4. ✅ **Done (H2560).** Delete `tailwind.config.js` (no longer linkable — the file is gone as of
   this phase; its last committed state is at
   [tailwind.config.js@2eeb144b](https://github.com/gasyoun/Systema-Sanscriticum/blob/2eeb144b/tailwind.config.js)).
   **It is dead code:** Tailwind v4 only reads a JS config when the CSS names it via `@config`, and no
   `@config` directive exists anywhere in this repo. Its empty `theme.extend` was never the reason
   `bg-brand` didn't work — the file simply is not loaded.
   **Caveat found during execution, not visible when this plan was written:** the dead file also
   registered `@tailwindcss/typography`, so the `prose` classes in 9 templates are now unstyled.
   Restoring the plugin is one line (`@plugin "@tailwindcss/typography";` in `app.css`) but it is a
   *visual* change, so it was kept out of the mechanical pass and is queued as a human decision.

Leave `#E3122C` and the dark-skin palettes alone for now; whether they are a second brand or drift is
a question for a human, and Phase 1 must not smuggle in that decision.

## Phase 2 — the seven primitives, ranked

Ranked by blast radius × decidability. Each row is one PR.

| # | Component | Evidence | Why this rank |
|---|---|---|---|
| 1 | **Field** (input/label/error/hint) | 40 files, 186 inputs; **33 near-identical strings in 5 clusters** | Highest exact-duplication in the repo, so the canon is the easiest to argue. Best first extraction. |
| 2 | **Button** | 72 files, 218 `inline-flex` | Biggest interactive surface. The 9× `w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c]` string is the de-facto primary. Variants: `primary`/`secondary`/`ghost`/`danger` × `sm`/`md`/`lg` × `full-width`. |
| 3 | **Card / Surface** | **91 files**, `rounded-xl` 380 + `bg-white` 338 | Widest spread of anything measured. Ranked below Button only because "card" is the vaguest canon — needs slots (header/body/footer), not just a class bundle. |
| 4 | **Badge / Pill** | 75 files, `rounded-full` 270 | Cheap, high-visibility. Care needed: `rounded-full` also matches avatars and decorative ping dots — do not over-capture. |
| 5 | **Modal / Dialog** | 17 files with `fixed inset-0`; 4 hand-rolled modals | **The accessibility win.** Only 5 `sr-only` and 30 `role=` across ~150 templates says these modals lack focus traps, escape handling, and labelled dialogs. One correct implementation fixes all four at once: [trial-modal](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/trial-modal.blade.php), [deposit-modal](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/deposit-modal.blade.php), [shop-login-modal](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/shop-login-modal.blade.php), [newsletter-subscribe-popup](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/newsletter-subscribe-popup.blade.php). |
| 6 | **ProgressBar** | 13 files, 21 `progress` | Small and self-contained; concentrated in [student/dashboard.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php) (1,057 LOC, the largest template in the repo). |
| 7 | **Avatar** | already [partials/user-avatar.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/user-avatar.blade.php) | A promotion, not an extraction. Cheapest row on the board. |

### Phase 2b — domain compositions (only after the primitives)

`CourseCard` (already exists as
[components/shop/course-card.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/components/shop/course-card.blade.php)
— rebase it on the primitives), `LessonRow`, `HomeworkCard`, `StatTile`, `EmptyState`, `Alert/Banner`.

### Explicitly out of scope

| Excluded | Count | Reason |
|---|---|---|
| `resources/views/filament/` | 51 files | Filament 3 owns its own component system. Do not fight it. |
| `resources/views/vendor/` | 58 files | Vendor overrides. |
| `resources/views/emails/` | 32 files | Inline-style medium, different rules entirely. |
| Tables | 2 in repo | Not a pattern. Do not build a `Table` primitive. |
| `marathon/skins/{a,c,d}` | 3 skins | **Deliberate** visual A/B directions (commits `b96408ed`, `50c2d234`, `de6fd230`). This divergence is intentional — normalising it would destroy the experiment. |
| PDF / certificate views | 4 files | Print medium. |

## Phase 3 — the React mirror (gated, and honestly optional)

The approved React library needs one architectural constraint stated plainly: **React cannot be the
runtime here.** Livewire morphs the DOM and Alpine owns local state; React's reconciler would fight
both. Mounting React into a Livewire-morphed subtree is a known-bad pattern.

So the division of labour is:

- **Blade `x-ui.*` stays the shipped runtime.** Every user-visible pixel keeps coming from Blade.
- **React is the design artifact** — the canonical spec, and the thing `/design-sync` uploads so
  Claude Design generates on-brand Systema screens made of real Systema parts.
- **One token source.** A single `tokens.json` generates both the Tailwind v4 `@theme` block (Blade)
  and the React token module. Tokens are never authored twice.
- **Keep the React surface tiny** — the 7 primitives, not 330 templates.

**The honest trade-off:** this means two implementations of every primitive, which is the same drift
risk that produced the current 241-colour situation. It pays for itself *only* if Claude Design
generation actually gets used. If the goal is internal maintainability alone, Phase 1 + Phase 2
(Blade-only) delivers effectively the whole win at roughly 40% of the cost, and Phase 3 should be
dropped without regret. Phase 3 is therefore structured as strictly additive: abandoning it must
never invalidate Phases 1–2.

Prerequisite for `/design-sync`: the skill's shape detection currently finds **no** `.storybook/`,
**zero** `*.stories.*`, and **zero** `.tsx`/`.jsx`/`.vue` files, so it cannot run against this repo
today. Phase 3 is what creates its input.

## Piecemeal execution rules

The point of "piecemeal" is that no step is large enough to fail expensively.

1. **Tokens land before any component.** Non-negotiable — see the finding above.
2. **Rule of two.** Extract only when a second real call site needs it. No speculative primitives.
3. **One primitive per PR**, each carrying: the `x-ui` component, the migrated call sites, and a
   visual check via the existing Dusk harness (`a0a4647b`, H2532).
4. **Never touch** the excluded surfaces in the table above.
5. **A PR that only adds a component and migrates nothing is incomplete** — migration is what proves
   the canon was right.

## Reproducing these numbers

```bash
cd resources/views
S="student shop promo livewire components partials layouts marathon srs reading checkout articles auth"
grep -roh --include=*.blade.php '#[0-9A-Fa-f]\{6\}' . | tr 'a-f' 'A-F' | sort | uniq -c | sort -rn | head -20
grep -roh --include=*.blade.php '\[#[0-9A-Fa-f]\{6\}\]' . | wc -l
for p in inline-flex rounded-xl bg-white 'fixed inset-0'; do
  echo "$(grep -rl --include=*.blade.php -- "$p" $S | wc -l) files  $p"
done
```

## Open questions for a human

- **Restore `@tailwindcss/typography`, or drop `prose`?** Raised by H2560, and the only one of these
  with a live visual consequence *right now*: deleting the dead `tailwind.config.js` also removed the
  plugin registration, so `prose` in 9 templates renders unstyled. Either add
  `@plugin "@tailwindcss/typography";` to `app.css` (restores the previous look) or strip the `prose`
  classes (accepts the plainer look). Both are one-liners; neither belongs in a mechanical pass.
- Is `#E3122C` a second brand colour or drift? Phase 1 deliberately does not decide this.
- Are the dark-skin palettes (`#1F2636`, `#101010`, `#111622`, `#0A0D14`) a real dark theme, or
  accumulated one-off promo styling? **This one blocks Phase 2** — it is 589 of the 802 remaining
  in-scope arbitrary-value classes, so the answer decides whether they become
  `--color-surface-*` tokens or simply get deleted.
- Is Claude Design generation actually wanted? That single answer decides whether Phase 3 happens.

_Dr. Mārcis Gasūns_
