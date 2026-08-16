# VERIFICATION — Wave 1 · Money-page SEO hygiene

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).

---

## Acceptance per W1 deliverable

| Deliverable | Command / flow | Pass |
|---|---|---|
| Unique `/k/` titles | Parse CSV: unique `meta_title`, unique `meta_description`; `mb_strlen` ≤60 / ≤160; every sitemap `/k/` slug present | Dry-run exit 0. After prod apply: two random Kochergina URLs have different `<title>`s |
| Artisan safety | `seo:fill-course-meta … --dry-run` on a fixture that includes a duplicate title | Exit 1; no DB write. Reset path NULLs only meta columns |
| Sitemap weights | Feature test + live `GET /sitemap.xml` | `donat` URL priority 0.3; one `format=recorded` course 0.6; a live course 0.8 |
| Homepage copy | Curl `/` | Description is no longer the generic «образовательная платформа…» string; H1 still about Sanskrit from zero; `#org` JSON-LD intact |
| `/online` copy | Curl `/online` | `<title>` ≠ homepage `<title>` |
| Course schema still live | Curl one `/k/{slug}` | `application/ld+json` contains `"@type":"Course"` and at least one Offer if the course has a priced tariff |
| P2 packet | File exists; `rg "must not run the write"` | Packet committed. `git grep mark-core-indexable` in the W1 diff shows **no** non-dry-run invocation |
| Deploy smoke | `deploy.sh` then curl `/` | HTTP 200. Login and checkout still load (do not complete a payment) |

Three live curls after deploy are mandatory (H2-17): `/`, one `/k/`, `/online`. Report PASS/FAIL in the evidence note. Do not ask a human to “please verify”.

---

## Test strategy

- New Feature tests under `tests/Feature/Seo/`.
- Do not run the full suite unless a touched file sits outside Seo + Sitemap + the two Blades. Pint on touched PHP.
- CSV uniqueness can be a pure PHPUnit test that reads the committed file (no DB).
- Artisan apply tests use sqlite/RefreshDatabase fixtures, never prod.
- No Dusk required for W1.
- No money-contour tests unless a test file you did not intend to open starts failing — then halt (H2-22).

---

## Risks and spikes

| Risk | Why it matters | What to do |
|---|---|---|
| Live sitemap `/k/` count ≠ local Course set | CSV “every sitemap URL” fails | Treat **live sitemap** as the acceptance set. Extra local-only slugs may be omitted; missing live slugs fail |
| Group titles overflow 60 chars | Uniqueness vs length | Drop filler («онлайн-курс», site name). Keep group number |
| Watcher reverts Blade mid-edit | Systema is afflicted | Scratchpad → one-shell land+commit ([watcher-safe-commit](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md)) |
| Sitemap cache hides priority change | Hourly `Cache::remember` | `cache:forget sitemap.xml` after deploy |
| Prod apply without dry-run evidence | Wrong titles go live | Dry-run output in the evidence note **before** apply |
| Accidental indexation write | Thin-content demotion | Halt. Packet is docs-only |
| Invented stats in copy | H2-18 | Only reuse numbers already rendered on `/` (21+, 5 000+, book/crowdfund figures) |
| GSC/Yandex keys missing | W5, not W1 | Ignore in W1 |

No spike is required before W1 architecture. A spike would only be justified if `meta_title` were missing on the Course model — it is not.

---

## Later waves (not W1, recorded so they inherit a bar)

| Wave | Acceptance sketch |
|---|---|
| W2 | Every rendered samskrtam href is in the lockfile and was HTTP 200 when locked |
| W3 | `GET /llms.txt` 200, lists ≥1 visible course, does not list `/cabinet` or `/dvaram` |
| W4 | Wave-log row with Webmaster reading; `is_indexable` count matches the packet expectation ± documented delta |
| W5 | API pull **or** parked KPI template with the credential gap named |

_Dr. Mārcis Gasūns_
