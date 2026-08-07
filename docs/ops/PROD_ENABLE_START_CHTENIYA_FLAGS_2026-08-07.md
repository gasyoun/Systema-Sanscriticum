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

**Course SKU is on prod (table below).** `hasEntitlement()` needs a real paid payment on course 443 for a given student. Optional follow-ups:

4. Optional: run `php artisan srs:import-start-chteniya-cohort` after first paid enrollments (gated by the same flag).

No money/access path was changed by this enable — only deploy kill-switches. Price remains a human decision.


## Course + Tariff + Group created (07-08-2026)

Human price rule: **8000 RUB per 4 classes**; **minimum 4x4 = 16 classes (32000 RUB)**.

| Entity | Id | Notes |
|---|---|---|
| Course | **443** | slug `start-chteniya`, title Start chteniya, 16 lessons, teacher_id=2 (Gasuns), format=live, beginner, visible+active |
| Group | **139** | slug `start-chteniya`, status=forming, min_size=4; linked via `course_group` |
| CourseBlock 1-4 | **1035-1038** | Block N (4 classes) |
| Tariff blocks | **5033-5036** | type=block, 8000 RUB each, keys `block_1`..`block_4` |
| Tariff full (min) | **5037** | type=full, **32000 RUB**, title Min 4x4 (16 classes), key `full` |

`StartChteniyaCohort::course()` resolves id **443**. Flags remain ON.

Residual: first paid student then optional `php artisan srs:import-start-chteniya-cohort`; ORS landing if still open.

## Rollback

```sh
cd /var/www/html
cp -a .env.bak.h2107.20260807160940 .env   # or set both env keys false
php artisan config:cache
php artisan config:show features.kosha_reader
php artisan config:show features.start_chteniya_cohort
```

_Dr. Mārcis Gasūns_
