# IMPLEMENTATION — Wave 1 · Money-page SEO hygiene

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Architecture: [ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md).  
Acceptance: [VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W1.md).

Systema is guarded **and** watcher-afflicted. Worktree off `origin/main`. Author in a scratchpad; land + commit in one shell. Never edit Payment/Tariff/checkout. Never run `dictionary:mark-core-indexable` without `--dry-run`.

Ordered steps. Each depends on the previous unless marked *parallel*.

---

### Step 0 — Worktree + fence

1. `git fetch origin` in [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum). Session-unique worktree: `git worktree add -b <branch> ../Systema-Sanscriticum-h<id>-<pid> origin/main`.
2. `python Uprava/tools/precheck_handoff.py <H###>`. Exit 4 = already shipped, stop. Exit 3 = isolate and continue.
3. Fence check: do not open `app/Models/Payment.php`, tariff/checkout controllers, `config/features.php` club/28-08 flags, Metrika snippets, or `public/robots.txt` for edit.

### Step 1 — Sitemap priorities

**Files:** [app/Http/Controllers/SitemapController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SitemapController.php), existing sitemap Feature test (or new `tests/Feature/Seo/SitemapPriorityTest.php`).

1. In the `Course::where('is_visible', true)` chunk, also select `format`.
2. Priority helper (same class or a tiny dedicated helper — no new table):
   - slug contains `donat` (case-insensitive) → `0.3`
   - else `format === 'recorded'` → `0.6`
   - else `0.8`
3. Test with two visible courses (donate slug + recorded + live) asserting the three priorities. Cache: call the controller (or forget `sitemap.xml` first).

### Step 2 — Artisan + CSV schema

**Files (new):** `app/Console/Commands/FillCourseMeta.php`, `database/data/seo/course_meta_h2.csv`, `tests/Feature/Seo/FillCourseMetaTest.php`.

1. Signature: `seo:fill-course-meta {csv} {--dry-run} {--reset-slugs=}`.
2. Parse CSV (`slug,meta_title,meta_description`). Reject BOM. Reject rows whose slug is not a visible course (warn + skip, or fail — default **fail** so a stale slug cannot silently no-op).
3. Validate uniqueness of title and of description; `mb_strlen` title ≤60, description ≤160.
4. `--dry-run` prints a table (write / skip-unchanged / conflict) and exits 1 on any validation error.
5. Apply updates only `meta_title` / `meta_description`. Do not touch `title`, prices, visibility, or tariffs.
6. `--reset-slugs=a,b` sets those two columns NULL (rollback aid).

### Step 3 — Fill the CSV for every sitemap `/k/`

1. Dump the live set: from a local DB if present, else parse [https://samskrte.ru/sitemap.xml](https://samskrte.ru/sitemap.xml) `/k/` locs. That list is the acceptance set.
2. Write one RU title + description per slug. Kochergina clones: include group number + live/запись + year if present in the current title. Donate: non-course wording.
3. Facts only already on the live site (21+ years, 5 000+ students, published prices). No new testimonials. No invented hours.
4. Commit the CSV in the same PR as the artisan. Dry-run must exit 0 against a DB seeded with those slugs (or a unit that validates the file internally).

### Step 4 — Homepage + `/online` copy (*parallel with steps 1–3 once artisan exists*)

**Files:** [resources/views/main.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/main.blade.php), [resources/views/shop/index.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/index.blade.php) (and the shop layout title/description if `/online` meta lives there).

1. Homepage `<title>` / `meta name="description"` / H1 must agree on «курсы санскрита с нуля» (or a close unique variant). Current H1 is already that; the description is generic «образовательная платформа…» — replace it. Do not add sections. Do not restyle.
2. `/online` title/description must name the catalog (курсы санскрита онлайн), not repeat the homepage string exactly.
3. Do not change Metrika, cookie banner, or chat widget markup.

### Step 5 — P2 packet (write only)

**File (new):** `docs/SEO_P2_WAVE1_ACTIVATION_PACKET_2026.md`.

Must contain:

- Exact command with `--limit=435` and the allowlist path.
- Measured expectation: ~805 rows (16-08-2026 prod dry-run).
- `--reset` reversal.
- Sitemap forget + Yandex.Webmaster submit (human).
- Row template for [SEO_P2_INDEXATION_WAVE_LOG_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_P2_INDEXATION_WAVE_LOG_2026.md).
- Bold line: **an agent must not run the write**.

Point at the packet from the existing wave log. Do not flip config.

### Step 6 — Tests + changelog + PR

1. `php artisan test --filter=Seo` (or the files above). Pint on touched PHP.
2. [CHANGELOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md) `[Unreleased]` Added/Changed bullets. Then `/cut-release` same pass after merge if that is the house rhythm for this repo.
3. PR from the worktree branch. Merge when green (`gasyoun/*` always-merge).
4. Remove the worktree after the push is confirmed.

### Step 7 — Deploy

On the known Systema host, the only sanctioned path is [deploy.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) (`docs/deploy.md`). After merge: run it. One smoke: homepage HTTP 200. If login/checkout breaks: `--rollback`, halt.

### Step 8 — Prod apply + live curl

1. On prod, after deploy: `php artisan seo:fill-course-meta database/data/seo/course_meta_h2.csv --dry-run` then without `--dry-run`.
2. `php artisan cache:forget sitemap.xml` if the deploy cache rebuild did not already.
3. Curl (report PASS/FAIL, do not ask a human to verify):
   - `https://samskrte.ru/` — new description present; Organization JSON-LD still has `#org`.
   - one Kochergina `/k/` — unique `<title>`, Course JSON-LD still present.
   - `https://samskrte.ru/online` — catalog title distinct from homepage.
4. Spot-check sitemap donate priority is `0.3`.

W2–W5 are **out of this handoff**. Stop after step 8 evidence is in the PR/body or a short `docs/SEO_H2_W1_EVIDENCE_2026.md`.

_Dr. Mārcis Gasūns_
