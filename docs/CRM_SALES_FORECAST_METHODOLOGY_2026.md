# CRM sales forecast — methodology and config

_Created: 14-08-2026 · Last updated: 14-08-2026_

H2485 (Grok 4.6 `grok-4.6`) Wave 3. Read-only: the service never writes `payments`, tariffs or access.

## What it forecasts

Weighted expected revenue from **open** [`Deal`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php) rows:

`sum(deal.amount × p_stage)`

`p_stage` is the mid/low/high band for `deal_stages.key` in [config/crm_forecast.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/crm_forecast.php). The Blade page only **prints** that config. Hardcoded probabilities in the UI are a fail.

Won/lost stages are excluded from the open pipeline (`Deal::open()` / `closed_at` plus final-stage skip).

## Actual revenue denominator

Same qualifying Payment filter as [OrderPaymentConversionService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php):

- `is_conditional = false`
- `tariff` not in `conversion.excluded_tariffs`
- `status` in `paid` / `success`
- cohort by `payments.created_at`

Public entry: `qualifyingPaidRevenue($from, $to, $onlyCreatedBy)`. Forecast actuals **must** call this. A second Payment query with a different filter is a denominator fork (handoff fail).

The live page compares **current** weighted forecast (next `horizon_days`) with **previous**-horizon actuals. Those two numbers are **not** a paired error. Paired error is only the backtest table.

## Manager scope

Reuses [`RoleGate::managerSalesReport()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/RoleGate.php) (admin / accountant / manager; teacher/student out).

| Slice | Join | Why |
|---|---|---|
| Open pipeline | `deals.assigned_to` | Open deals often have no Payment yet |
| Actuals | `payments.created_by_user_id` | F5 RULED manager-sales denominator |

A manager sees only their own join on each slice. Admin/accountant see all, including «Без менеджера». The two joins can disagree; the page says so.

## Aging and next action

- Age = days from last `DealTransition` onto the current stage, else `deals.created_at`.
- Buckets = `crm_forecast.aging_windows_days` (default 7 / 14 / 30 + overflow).
- Coverage = open deals that have at least one `FollowUpTask` with `done_at` null.

## Backtest and missing history

Windows = `crm_forecast.backtest_windows_days` (default 30 / 90).

For window `[as_of − N, as_of)`:

1. If window start `< history_available_from` (default **2026-07-25**, Deal table birth / H1641) → status `unavailable`, `forecast_mid = null`. **Not** zero. Zero would look like “we forecasted nothing and that is a number”.
2. Otherwise reconstruct stage at window start from append-only `deal_transitions` (last `to_stage` at or before T, else `from_stage` of the first move after T, else current open stage if the deal never moved).
3. A deal whose stage cannot be recovered is counted in `unavailable_deal_count` and **dropped** from the weighted sum. Window becomes `partial` or `unavailable`. No Payment-derived fake snapshot.

Open-on-pending Deals only exist after H2102. Earlier comparable windows can be `available` with an empty or won-only pipeline — that is an honest empty journal, not a fabricated one. Forecast error in those windows is not a model score.

Command (works while the UI flag is OFF):

```
php artisan crm:forecast-report --json
```

## Flag

`crm_sales_forecast` / `CRM_SALES_FORECAST` default **OFF**. `/admin/sales-forecast` is hidden until a human flips it. The artisan report stays readable.

Surface: [`SalesForecast`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/SalesForecast.php) · service: [`SalesForecastService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Crm/SalesForecastService.php).

_Dr. Mārcis Gasūns_
