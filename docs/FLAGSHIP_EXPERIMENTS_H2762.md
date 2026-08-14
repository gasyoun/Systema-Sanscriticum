# H2762 — Isolated next-step and CTA A/B on Kochergina

_Created: 15-08-2026 · Last updated: 15-08-2026_

Two isolated tests on **one** named flagship. Not a catalogue-wide rollout. Not a revenue claim. Do not ship a «winner» from thin traffic.

## Named flagship

| Key | Match | Prod URL |
|---|---|---|
| `kochergina` | slug contains `kocerginoi` | [https://samskrte.ru/k/grammatika-po-kocerginoi-gr61](https://samskrte.ru/k/grammatika-po-kocerginoi-gr61) |

«Старт чтения» and Büehler stay unchanged. Price, checkout provider, why-us, and membership are not touched.

## Flags (default OFF)

| Flag | Env | What it shows |
|---|---|---|
| `features.catalog_next_step` | `CATALOG_NEXT_STEP` | R12 next-step strip on the Kochergina **catalog card** only |
| `features.flagship_cta_ab` | `FLAGSHIP_CTA_AB` | R15 CTA **label** A/B on the Kochergina course hero only |
| start of window | `FLAGSHIP_EXPERIMENT_STARTED_AT` | ISO date. Empty = window is «flag is on». After 30 days both tests revert (hide strip, restore previous CTA). |

Config: [config/flagship_experiments.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/flagship_experiments.php).

## R12 next-step (E025 E034)

- **Hypothesis:** a visible «После этого → Бюлер / тексты / рецитация» on the Kochergina card raises next-step CTR.
- **Metric:** `next_step_click` / `card_impression`.
- **Window:** 30 days.
- **Stop:** n too small to distinguish, or the strip breaks the card → hide.
- **Rollback:** `CATALOG_NEXT_STEP=false` (or revert the PR). The block is gone.

Links resolve by live visible courses (Büehler slug / «Старт чтения» / title `Рецитаци`). Missing target → `/online`, never a dead URL.

## R15 CTA A/B (E031 E060)

- **Hypothesis:** «Смотреть пробный урок» vs «Записаться» changes `sample_play` and `begin_checkout`.
- **One variable:** the hero secondary label. `href` stays `#sample`.
- **Arms:** `a` = previous string «Смотреть пробный урок»; `b` = «Записаться». Sticky cookie `shop_cta_ab`.
- **Window:** 30 days.
- **Stop:** traffic too thin to call a winner → **DEFER**. Do not ship a winner.
- **Rollback:** `FLAGSHIP_CTA_AB=false` restores the previous CTA string.

## Event names

First-party table `storefront_events` (guests allowed). After three analytics-route lookups:

1. [config/analytics.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/analytics.php) + `shopReachGoal` — Metrika, no first-party guest store.
2. [ActivityEvent](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/ActivityEvent.php) / `FunnelTelemetry` — `user_id NOT NULL`, guests dropped.
3. `GameEvent` — drills (H1678), not shop.

So clicks go to `storefront_events`. No IP, no user-agent. `visitor_id` is an opaque cookie.

| Event | Experiment | When |
|---|---|---|
| `card_impression` | `next_step` | Kochergina card rendered (1 / visitor / day) |
| `next_step_click` | `next_step` | GET `/online/next-step/{buhler\|texts\|recitation}` |
| `sample_play` | `cta_ab` | Kochergina `/k/{slug}/preview` while R15 is on |
| `begin_checkout` | `cta_ab` | `/checkout/{tariff}` for a Kochergina tariff while R15 is on |

Metrika names match when `shopReachGoal` fires (`next_step_click`, `sample_play`). Existing `begin_checkout` on the checkout page is unchanged.

## 7-day count query

```bash
php artisan shop:flagship-experiments --days=7
php artisan shop:flagship-experiments --days=7 --json
```

Equivalent SQL:

```sql
SELECT experiment, event_name, variant, COUNT(*) AS n
FROM storefront_events
WHERE created_at >= NOW() - INTERVAL 7 DAY
GROUP BY experiment, event_name, variant
ORDER BY experiment, event_name;
```

R12 CTR ≈ `next_step_click` / `card_impression`. R15 compare `sample_play` and `begin_checkout` by `variant`.

## Prove

- Tests: `FlagshipExperimentsTest`, `FlagshipExperimentsTest` (unit)
- Flags default OFF
- Isolated: Büehler / Hindi cards and pages keep the previous CTA and have no next-step strip

_Dr. Mārcis Gasūns_
