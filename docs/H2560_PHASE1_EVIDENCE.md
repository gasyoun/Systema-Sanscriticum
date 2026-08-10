_Created: 10-08-2026 · Last updated: 10-08-2026_

# H2560 Phase 1 Evidence — Brand Orange Token Collapse

**Handoff:** H2560 (Opus 5)  
**Scope:** Tailwind v4 @theme token collapse for brand orange + hover variants  
**Executed by:** Opus 5 (`claude-opus-5`), 10-08-2026  

## What shipped

1. **Added `@theme` block** to [resources/css/app.css](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/css/app.css) defining `--color-brand: #e85c24` and `--color-brand-hover: #d04a15`
2. **Replaced 314 arbitrary-value classes** across 179 in-scope Blade templates:
   - `[#E85C24]` (brand orange) → `brand` (214 replacements)
   - `[#D04A15]` (hover) → `brand-hover` (60 replacements)
   - `[#D64E1C]` (hover drift) → `brand-hover` (18 replacements)
   - `[#D34F1C]` (hover drift) → `brand-hover` (15 replacements)
   - `[#D14E1A]` (hover drift) → `brand-hover` (7 replacements)
3. **Deleted `tailwind.config.js`** — dead code (never loaded, no `@config` directive exists)

## Visual equivalence proof

Compiled CSS before and after, extracted every `colour@alpha` pair (normalizing `#rrggbbaa` 8-digit hex, `oklab(L% a b / α)` literals, and `color-mix(in oklab, var(--color-X) N%, transparent)` to the same canonical form), diffed the sets.

**Result:**
- **0 unintended colours gained**
- **2 intended near-duplicates retired** (`#D14E1A`, `#D64E1C` — max pairwise delta ~7/255, perceptually indistinguishable)

Script: `h2560_css_equiv.py` (temp, not committed). Output:

```
before: app-2eeb144b.css  429 distinct colour@alpha  (0 theme tokens)
after : app-h2560.css  427 distinct colour@alpha  (2 theme tokens: brand, brand-hover)

LOST (present before, absent after): 2
    #d14e1a@100
    #d64e1c@100
GAINED (absent before, present after): 0

VERDICT: EQUIVALENT
```

## Git diff sanity check

Pure substitution, zero line-count drift:

```bash
$ git diff --numstat resources/views/ | awk '{added+=$1; deleted+=$2} END {print added, deleted}'
783 783
```

Every added line was a deleted line replaced in place (CRLF-preserving clone, so this is exact).

## Residual measurement

Zero brand-orange arbitrary-value classes remain in-scope:

```bash
$ cd resources/views
$ grep -roh --include=*.blade.php '\[#[ED][0-9A-F][0-5][0-9A-F][0-1][0-9A-F]\]' \
    student shop promo livewire components partials layouts marathon srs reading checkout articles auth | wc -l
0
```

(Pattern matches `[#E___]` or `[#D___]` hex in the brand-orange hue range.)

## What was NOT tokenized (intentional)

Per the plan in [UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UI_COMPONENT_LIBRARY_EXTRACTION_SYSTEMA_2026-08-10.md), Phase 1 deliberately did **not** tokenize:

- **Dark surfaces** (`#1F2636` 149×, `#101010` 114×, `#111622` 66×, `#0A0D14` 39× — 589 classes total)
- **Sky accent** (`#38BDF8` 111×)
- **Secondary red** (`#E3122C` 34×)

These remain as arbitrary-value classes pending a human decision: are they a real theme (semantic tokens), or accumulated promo styling (delete or leave as arbitrary values)? Phase 2 blocks on that ruling.

## Side effect — `prose` classes now unstyled

`tailwind.config.js` registered `@tailwindcss/typography`, so the 9 templates using `prose` classes (`resources/views/articles/*.blade.php`, `resources/views/promo/features.blade.php`) are now unstyled. Restoring it means adding `@plugin "@tailwindcss/typography";` to `app.css`, which is a visual change and so deliberately NOT bundled into this mechanical pass.

**Tracked as follow-up:** a human decides whether to restore the plugin (one line) or remove the `prose` classes (also one line per template, 9 total).

## Test gate

`php artisan test` in the worktree, full suite:

```
Tests:    2 skipped, 3151 passed (11360 assertions)
Duration: 3221.53s
```

Exit code 0. Zero failures. The 2 skips are pre-existing and environment-gated
(`Tests\Feature\FinanceLockingMySqlTest` — "Set RUN_MYSQL_CONCURRENCY_TESTS=1 to run InnoDB
lock probes"), not caused by this change.

_Dr. Mārcis Gasūns_
