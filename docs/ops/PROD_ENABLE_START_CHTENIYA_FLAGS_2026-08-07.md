_Created: 07-08-2026 · Last updated: 07-08-2026_

# Prod enable — «Старт чтения» reader + cohort flags (07-08-2026)

**Host:** `193.232.229.92` · app root `/var/www/html` · HEAD at enable: `8a194265` (v1.88.4).

## Flags flipped ON

| Env | Config key | Before | After |
|---|---|---|---|
| `KOSHA_READER=true` | `features.kosha_reader` | false | **true** |
| `START_CHTENIYA_COHORT_ENABLED=true` | `features.start_chteniya_cohort` | false | **true** |

- Backup: `.env.bak.h2107.20260807160940`
- `php artisan config:cache` rebuilt
- Verify: `php artisan config:show features.kosha_reader` → true; `features.start_chteniya_cohort` → true
- Smoke: `https://samskrte.ru/reading/kosha-demo` → **HTTP 200**

## What is live now

- Public Nala-1 demo reader (`/reading/kosha-demo`) — flag-gated only.
- Cabinet multi-pack routes (`/dvaram/reading`, `/dvaram/reading/{slug}`, lookup + add-to-SRS, teacher stalled-lemma page) are **code-live** but still require `StartChteniyaCohort::hasEntitlement()` (paid payment on the cohort Course).

## Residual — ops / human (not done this pass)

**Course slug `start-chteniya` is MISSING on prod.** With the cohort flag ON, `hasEntitlement()` still returns false for everyone until:

1. Filament: create **Course** with slug `start-chteniya` (title «Старт чтения» or as decided).
2. Filament: create **Tariff** with a real RUB price in the Akro €75–129 band (D6: do **not** invent price in code/env).
3. Filament: create **Group** + dates for the pilot cohort.
4. Optional: run `php artisan srs:import-start-chteniya-cohort` after first paid enrollments (gated by the same flag).

No money/access path was changed by this enable — only deploy kill-switches. Price remains a human decision.

## Rollback

```sh
cd /var/www/html
cp -a .env.bak.h2107.20260807160940 .env   # or set both env keys false
php artisan config:cache
php artisan config:show features.kosha_reader
php artisan config:show features.start_chteniya_cohort
```

_Dr. Mārcis Gasūns_
