# CRM sales forecast — parity and backtest evidence

_Created: 14-08-2026 · Last updated: 14-08-2026_

H2485 (Grok 4.6 `grok-4.6`). Fixture numbers from `tests/Feature/Crm/SalesForecastServiceTest.php` (RefreshDatabase, `Carbon::setTestNow(2026-08-14 12:00:00)`). Not production history. Live prod snapshot from the same day is in the last section.

## Open-pipeline parity

| Source | Amount |
|---|---|
| `Deal::query()->open()->sum('amount')` | 1 234.50 |
| `SalesForecastService::snapshot()['pipeline']['open_amount']` | 1 234.50 |

Won deal of 99 999 is excluded. Reproduced by `test_open_pipeline_amount_reconciles_with_open_deals`.

## Actual-revenue parity

Same window `[2026-07-15, 2026-08-14)`:

| Filter | Count | Amount |
|---|---|---|
| Qualifying paid `full` on 2026-08-02 | 1 | 4 800 |
| Excluded `deposit` | 0 (dropped) | — |
| Conditional 500 | 0 (dropped) | — |
| Qualifying paid on 2026-06-01 (outside window) | 0 (dropped) | — |
| `OrderPaymentConversionService::qualifyingPaidRevenue` | 1 | 4 800 |
| Forecast `actuals_previous_horizon` | 1 | 4 800 |

Reproduced by `test_actuals_reuse_conversion_qualifying_denominator`.

## Weighted forecast (config-owned)

Config under test: `new.mid=0.10`, `negotiation.mid=0.50`.

| Deal | Stage | Amount | mid |
|---|---|---|---|
| A | new | 10 000 | 1 000 |
| B | negotiation | 20 000 | 10 000 |
| **Total** |  | **30 000** | **11 000** |

Low 8 500 / high 14 000. Reproduced by `test_weighted_forecast_uses_config_probabilities`. Changing `new.mid` to 0.40 moves the 10 000-deal forecast from 1 000 to 4 000 without a Blade edit.

## Backtest disclosure

| Window | `history_available_from` | Status | forecast_mid | actual | error |
|---|---|---|---|---|---|
| 90d ending 2026-08-14 | 2026-08-01 | **unavailable** | `null` (not 0) | `null` | `null` |
| 30d ending 2026-08-14 | 2026-01-01 | available | 1 000 (open 10 000 × 0.10) | 4 000 | +3 000 |

The unavailable row is the required missing-history disclosure: Deal journal starts 2026-07-25 in default config; a window that starts earlier is not reconstructed from payments.

## Manager isolation

| Viewer | Pipeline | Actuals |
|---|---|---|
| Anna (`assigned_to` / `created_by`) | 10 000 (her deal) | 3 000 |
| Admin | 30 000 (Anna+Boris) | 12 000 |

Boris’s 20 000 deal and 9 000 payment are absent from Anna’s snapshot.

## Live prod · 14-08-2026 12:54 MSK

Host `/var/www/html` HEAD `f416e312` (`chore(release): 1.89.34`). Cron `deploy.sh` at 09:35 pulled `502ad695..f416e312`. Command: `php artisan crm:forecast-report --json` (read-only). Flag `CRM_SALES_FORECAST` absent in `.env` → UI still OFF.

| Slice | Value |
|---|---|
| Open deals | 3 · all stage `new` · all `assigned_to` null («Без менеджера») |
| Open pipeline | 18 000 ₽ (ids 16 / 24 / 66 = 2 000 + 8 000 + 8 000) |
| Weighted forecast next 30d | 1 440 … **2 700** … 4 500 ₽ |
| Next-action coverage | 1 / 3 = 33.3 % |
| Aging | 0–7: 8 000 ₽ (1) · 8–14: 10 000 ₽ (2) |
| Qualifying paid last 30d | **117** payments · **574 825** ₽ |
| Backtest 30d and 90d | **unavailable** (`history_available_from=2026-07-25`; windows start 15-07 and 16-05). `forecast_mid` is null, not 0. |

The 574 825 ₽ actuals are not a paired error against the 2 700 ₽ forecast: most paid course revenue still arrives without an open Deal (won-on-paid bridge). The page says that; do not read the gap as model failure.

## Reproduce

```
php artisan test --filter=SalesForecast
php artisan crm:forecast-report --json
```

_Dr. Mārcis Gasūns_
