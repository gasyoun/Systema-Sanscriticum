# VERIFICATION — Wave 2 · Contextual links to samskrtam.ru

_Created: 16-08-2026 · Last updated: 16-08-2026_

**Umbrella ID:** `SAMSKRTE-SEO-H2` · **Pack:** `/ask` samskrte.ru SEO 16-08-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_SEO_H2_*`

Index: [PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_SEO_H2_2026.md).  
Implementation: [IMPLEMENTATION_SYSTEMA_SAMSKRTE_SEO_H2_W2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SAMSKRTE_SEO_H2_W2.md).

---

## Acceptance

| Deliverable | Command / flow | Pass |
|---|---|---|
| Allowlist host | Parse `database/data/seo/samskrtam_related.json` | Every `url` starts with `https://samskrtam.ru/`; `_home` has ≥3 entries |
| Lockfile | `seo:lock-samskrtam-related` then committed `.lock.json` | Every allowlist URL has `status=200`; artisan exit 1 on any miss |
| Runtime honesty | Feature test | A URL in the allowlist but **not** in the lockfile is not rendered |
| Homepage strip | Curl `/` after deploy | «samskrtam.ru» hrefs from `_home`; `#org` JSON-LD intact |
| Course block | Curl one mapped `/k/{slug}` | ≥1 `https://samskrtam.ru/` href that is in the lockfile |
| Unmapped / donate | Curl donate or an unmapped slug | Related-reading block absent |
| Live re-check | HTTP GET each rendered href | 200. Dead link = fail (H2-19) |
| No WP / no essays | Diff | No ORS-FAQ / WordPress files; no new `/s/` routes or Article rows |

## Test strategy

- New Feature tests under `tests/Feature/Seo/` (sibling of W1).
- Lockfile freshness is the committed `fetched_at`; do not HTTP-fetch inside the request-path tests (use the committed lock + a fixture that omits one URL).
- The artisan lock command **does** HTTP-fetch when run by the executor to produce the lock; tests of the artisan may `Http::fake`.
- No Dusk. No money-contour tests.

## Risks

| Risk | What to do |
|---|---|
| samskrtam.ru down / Anubis / rate-limit | Check [Uprava/SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md) first. Empty allowlist after a real probe = escalate, do not invent |
| Mapping 87 `/k/` slugs | Only map when the article is actually about that topic. Unmapped = no block |
| Watcher reverts Blade | Scratchpad → one-shell land+commit |
| Cross-host canonical | Do **not** add `rel=canonical` to samskrtam (ARCH: later sitting) |

W3–W5 stay out of this bar.

_Dr. Mārcis Gasūns_
