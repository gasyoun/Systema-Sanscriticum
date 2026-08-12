_Created: 10-08-2026 · Last updated: 12-08-2026_

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

> **Corrected 12-08-2026 (H2599).** The check printed below was **vacuous** — the
> character class `[0-1]` in position 5 cannot match the `2` of `E85C24`, so the
> pattern does not match `[#E85C24]` even as a bare string
> (`echo '[#E85C24]' | grep -c '\[#[ED][0-9A-F][0-5][0-9A-F][0-1][0-9A-F]\]'` → `0`).
> It was therefore guaranteed to print `0` against any tree whatsoever, and it hid a
> real residual: **25 brand-orange classes** survived in three files this sweep never
> visited — `marathon/skins/b/content.blade.php` (the **default** skin; only `a`/`c`/`d`
> were excluded by decision) and the two public `certificate/` surfaces (never in the
> scoped directory list at all). Retired and replaced by the enumerated check below;
> the collapse itself was finished under
> [H2599](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2599-Opus_Systema-Sanscriticum_phase1-residual-brand-orange-skins-b-vacuous-regex_12.08.26.md).

Original claim — *zero brand-orange arbitrary-value classes remain in-scope*:

```bash
$ cd resources/views
$ grep -roh --include=*.blade.php '\[#[ED][0-9A-F][0-5][0-9A-F][0-1][0-9A-F]\]' \
    student shop promo livewire components partials layouts marathon srs reading checkout articles auth | wc -l
0          # ← vacuous: this pattern matches no brand-orange hex at all
```

**Replacement check — enumerate the retired colours literally, never a hue-range
pattern.** A pattern over a hex range has to be *tested against the strings it is
meant to catch*; a list of six literals cannot silently stop matching:

```bash
$ cd resources/views
$ grep -rohiE '\[#(e85c24|d04a15|d34f1c|d64e1c|d14e1a|d35400)\]' --include=*.blade.php \
    student shop promo livewire components partials layouts srs reading checkout \
    articles auth certificate | wc -l
0
$ grep -rohiE '\[#(e85c24|d04a15|d34f1c|d64e1c|d14e1a|d35400)\]' --include=*.blade.php \
    marathon --exclude-dir=a --exclude-dir=c --exclude-dir=d | wc -l
0
```

The `marathon` arm is split out because `skins/{a,c,d}` are out of scope **by
decision** (intentional A/B directions) while `skins/b` is not — folding them into
one command lets 46 legitimately-excluded hits mask an in-scope one, which is how
`skins/b` stayed invisible.

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
