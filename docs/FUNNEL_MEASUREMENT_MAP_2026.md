# Funnel measurement map — samskrte.ru shop (H2378)

_Created: 07-08-2026 · Last updated: 15-08-2026_

**Model:** Grok 4.5 (`grok-4.5`) · handoff [H2378](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2378-Grok_Systema-Sanscriticum_sales-measurement-goals-dashboard_07.08.26.md)

## Why card/checkout Metrika goals were 0 (H2062 baseline)

| Cause | Evidence |
|---|---|
| **Shop layout had no Metrika tag** | [`layouts/shop.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/shop.blade.php) had zero `mc.yandex.ru` includes; only promo/articles/payment success loaded counters. Comment in [`payment/success.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/payment/success.blade.php) already noted this gap. |
| **Card URL goal path mismatch** | `ya_analytics.py create-goals` used URL-contain `/online/kursy/`, but canonical course URL is `/k/{slug}` with `/online/kursy/{slug}` → **301**. Metrika records the final URL → goal never fires. |
| **Checkout URL goal starved** | Condition `/checkout` is correct for `/checkout/{tariff}`, but without a shop counter the pageviews never hit counter `106964341`. |
| **Not “absent traffic” alone** | H2062 90d: visits 17 998 on samskrte, lead goal 192, thank-you 90 — traffic exists on promo/lead paths; shop mid-funnel was uninstrumented. |

## Canonical event map

| Event | First-party (`activity_events`) | Metrika `reachGoal` | Dedup | Surface |
|---|---|---|---|---|
| `course_page_view` | Auth only | `course_page_view` | user+course_id / day | `/k/{slug}` |
| `begin_checkout` | Auth only | `begin_checkout` | user+tariff_id / day | `/checkout/{tariff}` |
| `payment_success` | Auth, qualifying paid | `payment_success` | once / payment_id | status → paid/success + `/payment/success` |
| `first_cabinet_action` | Auth | — | once / user ever | first `cabinet.home.view` (surface field) |
| `card_impression` / `next_step_click` / `sample_play` | `storefront_events` (guests OK; H2762, Kochergina only) | same names | impression 1/visitor/day | flags `CATALOG_NEXT_STEP` / `FLAGSHIP_CTA_AB` default OFF |

**Paid denominator (money truth):** `OrderPaymentConversionService` Rate A — real (non-conditional) `Payment` rows whose `tariff` is not in `config('conversion.excluded_tariffs')` (default `Расход,salary_payout,deposit,trial`); paid = status `paid|success`. Cohort by `payments.created_at`.

Do **not** redefine this denominator in Metrika or the report — report prints it next to first-party counts.

## Privacy

- Aggregate counts only in `php artisan sales:funnel-report`.
- No person-level export committed to git.
- Metrika loads only the public counter id (env-configurable); no email/phone in goal payloads.

## Operator commands

```bash
# First-party + canonical Payment window
php artisan sales:funnel-report --days=30
php artisan sales:funnel-report --days=90 --json

# Existing order→pay baseline (same Payment filter)
php artisan dozhim:baseline --days=30 --days=90
```

### Metrika owner residual (when OAuth token available)

From [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ):

```bash
# Token in .env.analytics — owner-authorized only
python ya_analytics.py create-goals   # adds JS action goals (idempotent)
python ya_analytics.py goals          # dump definitions; repair URL goals if still on /online/kursy/
python ya_analytics.py audit 30
```

**Paste-ready repair for legacy URL goal 568318506** (card): change condition from contain `/online/kursy/` to contain `/k/` **or** rely on new JS goal `course_page_view` and treat the URL goal as retired.

Synthetic browser probe (post-deploy): open `/k/<live-slug>` and `/checkout/<tariff>` with DevTools → Network → `mc.yandex.ru` + goal hits; do not claim live Metrika completion without that probe or API audit.

## Before / after event map

| Goal | Counter | Before (H2062 90d) | After (this PR) |
|---|---|---:|---|
| lead `515394318` | 106964341 | 192 | unchanged (JS on promo) |
| thank-you URL | 106964341 | 90 | still valid; shop also fires `payment_success` |
| card `/online/kursy/` | 106964341 | **0** | replaced/supplemented by JS `course_page_view` + shop tag + `/k/` |
| checkout URL | 106964341 | **0** | JS `begin_checkout` + shop tag on `/checkout/*` |
| CRM order paid | — | 0 | first-party `payment_success` + Rate A paid |

## Config

| Key | Default | Meaning |
|---|---|---|
| `YANDEX_METRIKA_SHOP_ID` | `106964341` | Shop layout counter |
| `YANDEX_METRIKA_SHOP_ENABLED` | `true` | Kill switch |

## Related

- [ORS-FAQ roadmap Phase 0 baseline](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md)
- [UTM_CONVENTION.md Metrika goals](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/UTM_CONVENTION.md)
- [`OrderPaymentConversionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php)
- [`AttributionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AttributionService.php) — UTM on signup; not rewritten here

_Dr. Mārcis Gasūns_
