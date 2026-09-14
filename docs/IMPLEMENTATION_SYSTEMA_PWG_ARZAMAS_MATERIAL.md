# IMPLEMENTATION — PWG Arzamas-style material (wave-1)

_Created: 24-07-2026 · Last updated: 24-07-2026_

Parent index: [PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md)

Worktree off `origin/main`. Watcher-safe commits. Pathspec-limited. No money code.

---

## Step 0 — Claim isolation

1. `git fetch origin`
2. Worktree: `Systema-Sanscriticum-h###-pwg-arzamas` from `origin/main`
3. Confirm no colliding Article slug `peterburgskiy-slovar-pwg` in DB/seeds

---

## Step 1 — Scaffold material pack

**Creates:**

```
docs/materials/pwg-arzamas/
  README.md           # how to rebuild/import
  SOURCE.md           # full essay (or chapters/01.md …)
  FACTS.md            # claim table
  ASSETS.md           # rights table
  DECISIONS_LOG.md    # autonomy defaults used
  FOLLOWUPS.md        # council Minors + wave-2
  body.html           # import payload (generated)
  bibliography.md     # secondary works actually cited
public/images/materials/pwg/   # raster/SVG assets
```

**Depends on:** nothing  
**Acceptance:** directories exist; README points at PLAN

---

## Step 2 — Research pass (no full prose yet)

1. Read PWG prefaces RU/DE (at least volumes' program statements) from sibling clone
   `GitHub/PWG/prefaces/`
2. Read csl-guides `docs/dictionaries/pwg.mdx` for safe public facts
3. Re-open A36 committed paper for Latin-chapter numbers only from committed text
4. List 15–20 chapter titles (ROADMAP map) with **≥1 primary hook each**
5. Fill FACTS.md skeleton rows for dates, volumes, authorship, Cologne existence

**Depends on:** Step 1  
**Acceptance:** every chapter has ≥1 sourced hook; zero invented counts

---

## Step 3 — Image acquisition

1. Collect PD title-page / portrait candidates (Wikimedia Commons PD or library PD)
2. Cologne scan thumbs only with attribution line in ASSETS.md
3. Author 2–4 SVG diagrams: Petersburg family tree; timeline 1850s–1870s–Cologne
4. Optimize images (reasonable web size); alt text RU

**Depends on:** Step 2 chapter list  
**Acceptance:** ASSETS.md rows ≥12 with `used=yes` and license filled; or honest drop below with chapter still shipping

---

## Step 4 — Draft full SOURCE.md

1. Write all chapters in Arzamas register (curiosity hooks, concrete anecdotes, no AI filler)
2. Insert figures after relevant paragraphs (`<figure>…<figcaption>`)
3. End with soft CTA block (HTML matching existing `.article-cta` patterns if present in CSS;
   else simple paragraph + links to `/online/materialy`, Cologne PWG, csl-guides entry-anatomy)
4. Frontmatter block (YAML in HTML comment or separate `meta.json`): title, subtitle, excerpt, meta_*

**Depends on:** Steps 2–3  
**Acceptance:** ≥15 `##` headings; lay-readable; no pwg_ru sales pitch

---

## Step 5 — FACTS completion

1. Every factual sentence → row  
2. Cut or hedge anything unsourced  
3. Re-verify any A36/csl-atlas number against committed files  

**Depends on:** Step 4  
**Acceptance:** FACTS.md all `verified` or `hedged`; none blank

---

## Step 6 — RuWritingStyles councils

1. Run **sanskrit** council on SOURCE.md (or exported plain text)
2. Run **indology** council
3. Apply Major fixes to SOURCE.md
4. Log Minors in FOLLOWUPS.md

**Depends on:** Steps 4–5  
**Acceptance:** council reports archived under `docs/materials/pwg-arzamas/rws/`; no open Majors

---

## Step 7 — Render body.html

1. Convert SOURCE.md → HTML (`h2` for chapters; preserve figures)
2. Ensure first heading level is `h2` (h1 is page title in blade)
3. Compute `reading_time`
4. Spot-check TOC will list ≥15 items (show blade requires ≥2 h2)

**Depends on:** Step 6  
**Acceptance:** `body.html` present; manual count `h2` ≥15

---

## Step 8 — Artisan import command

**New files (suggested):**

- `app/Console/Commands/ImportPwgArzamasMaterial.php`
  - signature: `materials:import-pwg-arzamas {--publish : set is_published}`
  - reads `docs/materials/pwg-arzamas/body.html` + meta
  - upsert by slug (idempotent)
  - default `--publish` false in local; wave-1 final run uses `--publish` when ACCEPT green
- optional seeder thin wrapper for tests only

**Depends on:** Step 7  
**Acceptance:** `php artisan materials:import-pwg-arzamas` creates/updates row without error

---

## Step 9 — PHPUnit

**New test:** `tests/Feature/PwgArzamasMaterialTest.php`

- import idempotent (run twice → one row)
- `get(route('articles.show', 'peterburgskiy-slovar-pwg'))` → 200 when published
- body contains ≥15 `<h2`
- optional: Materials hub page contains title string when published

**Depends on:** Step 8  
**Acceptance:** test green in CI

---

## Step 10 — Ship

1. CHANGELOG.md `[Unreleased]` bullet  
2. PR (non-money; auto-merge OK if green)  
3. Production: deploy + `php artisan materials:import-pwg-arzamas --publish`  
4. Browser smoke: TOC, images, CTA, Materials card  
5. If prod CLI unavailable: STOP with runbook in README; leave `is_published` path documented — do not claim DONE  

**Depends on:** Step 9 + FACTS + councils  
**Acceptance:** live URL meets PLAN §2

---

## File touch list (expected)

| Path | Action |
|---|---|
| `docs/materials/pwg-arzamas/*` | add |
| `public/images/materials/pwg/*` | add |
| `app/Console/Commands/ImportPwgArzamasMaterial.php` | add |
| `tests/Feature/PwgArzamasMaterialTest.php` | add |
| `CHANGELOG.md` | edit Unreleased |
| Plan docs already on main | no change unless FOLLOWUPS |

---

_Dr. Mārcis Gasūns_
