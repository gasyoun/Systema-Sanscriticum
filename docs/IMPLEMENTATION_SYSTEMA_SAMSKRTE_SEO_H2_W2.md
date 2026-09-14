# IMPLEMENTATION — Wave 2 · Contextual links to samskrtam.ru

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Wave-2 handoff:** [H2918 (Grok 4.6) — samskrte.ru SEO H2 wave-2 contextual links to samskrtam.ru](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2918-Grok_Systema-Sanscriticum_samskrte-seo-h2-w2_16.08.26.md) · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Architecture §4: [ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md).  
Acceptance: [VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SAMSKRTE_SEO_H2_W2.md).  
W1 (already shipped): [SEO_H2_W1_EVIDENCE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_H2_W1_EVIDENCE_2026.md).

Systema is guarded **and** watcher-afflicted. Worktree off `origin/main`. Author in a scratchpad; land + commit in one shell. Do **not** edit samskrtam.ru WordPress. Do **not** add `/s/` essays on samskrte. Do **not** invent slugs.

Ordered steps. Each depends on the previous unless marked *parallel*.

---

### Step 0 — Worktree + fence + W1 present

1. `git fetch origin` in [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum). Session-unique worktree: `git worktree add -b <branch> ../Systema-Sanscriticum-h<id>-<pid> origin/main`.
2. `python Uprava/tools/precheck_handoff.py <H###>`. Exit 4 = already shipped, stop. Exit 3 = isolate and continue.
3. Confirm W1 merge is on `origin/main` (`seo:fill-course-meta` exists). Links do **not** depend on the prod CSV apply.
4. Fence: do not open Payment/Tariff/checkout, `config/features.php` club/28-08 flags, Metrika snippets, `public/robots.txt`, or any ORS-FAQ / WordPress path.

### Step 1 — Discover live samskrtam.ru URLs (do not invent)

1. Fetch [https://samskrtam.ru/](https://samskrtam.ru/) and any public sitemap / catalog the agent can reach without WP credentials.
2. Keep only `https://samskrtam.ru/…` URLs that return **HTTP 200** (follow one redirect hop if it stays on `samskrtam.ru`).
3. If the allowlist would be empty after this probe: **stop and escalate**. Do not invent paths. Roadmap meta: empty allowlist is an escalate, not a silent skip.

### Step 2 — Allowlist JSON

**File (new):** `database/data/seo/samskrtam_related.json`

Schema:

```
{
  "_home": [ {"url": "https://samskrtam.ru/…", "anchor": "…"} ],
  "<course-slug>": [ {"url": "…", "anchor": "…"} ]
}
```

Rules:

- `_home` is required and must have ≥3 entries (homepage strip).
- Course keys are optional. Map only when the article is actually about that topic (Kochergina, Hindi, Gītā, Yoga-sūtras, …). Unmapped `/k/` pages render **no** related block.
- `anchor` is short RU, facts only, no invented testimonials.
- Every `url` must start with `https://samskrtam.ru/`.
- Donate slug (`donat-na-razvitie-ors`) must **not** get a course-key (not a course).

### Step 3 — Lockfile artisan

**Files (new):** `app/Console/Commands/LockSamskrtamRelated.php`, `database/data/seo/samskrtam_related.lock.json`, `tests/Feature/Seo/SamskrtamRelatedTest.php`.

1. Signature: `seo:lock-samskrtam-related {json} {--lock=}`.
2. GET each unique URL from the allowlist. Record `{url, status, fetched_at}` in the lock file (UTF-8, no BOM).
3. Exit 1 if any URL is not HTTP 200 or host is not `samskrtam.ru`.
4. Runtime **never** HTTP-fetches. The request path only reads the committed lock + allowlist.

Commit the lock file in the same PR as the allowlist.

### Step 4 — Blade partial

**Files:** new `resources/views/partials/samskrtam-related.blade.php`; include from [shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php) (after the main description / before final CTA is fine) and [main.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/main.blade.php) (`_home`).

1. Heading «Читать на samskrtam.ru» (or a close unique variant).
2. Render only URLs present in **both** the allowlist and the lockfile.
3. `target` may stay same-tab. `rel="noopener"` if `target=_blank`.
4. Do not restyle the rest of the page. Do not touch Metrika, cookie banner, or chat widget.

A tiny helper (`App\Support\SamskrtamRelated` or similar) that loads JSON once is allowed. No new table.

### Step 5 — Tests + changelog + PR

1. PHPUnit under `tests/Feature/Seo/`:
   - every allowlist URL is in the lockfile and was 200 when locked
   - a locked-200 URL renders on `/` (`_home`) and on one mapped `/k/{slug}`
   - an unmapped course (or donate) does **not** render the block
   - a URL missing from the lockfile does **not** render even if listed in the allowlist
2. Pint on touched PHP.
3. [CHANGELOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md) `[Unreleased]` Added bullet. `/cut-release` after merge.
4. PR, merge (`gasyoun/*` always-merge). Remove the worktree after the push is confirmed.

### Step 6 — Deploy + live curl

On the known Systema host: [deploy.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh). Then curl (PASS/FAIL, do not ask a human):

- `https://samskrte.ru/` — `_home` anchors present; `#org` JSON-LD still present
- one mapped `/k/{slug}` — at least one `https://samskrtam.ru/` href
- one unmapped `/k/` or donate — no related-reading block
- each rendered href HTTP 200 (re-check, not only the lockfile)

W3–W5 are **out of this handoff**. Stop after the evidence note (`docs/SEO_H2_W2_EVIDENCE_2026.md` or the PR body).

_Dr. Mārcis Gasūns_
