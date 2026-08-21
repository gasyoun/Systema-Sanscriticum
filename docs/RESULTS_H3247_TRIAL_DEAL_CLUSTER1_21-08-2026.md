# RESULTS — H3247 cluster 1 (trial Deal)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Parent: [PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md) · [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TRIAL_DEAL_PUBLIC_BOOKING.md) C1.

Executor: Grok 4.6 (`grok-4.6`). Non-Fable delivery.

## Outcome

| Item | Value |
|---|---|
| Status | Merged |
| PR | [gasyoun/Systema-Sanscriticum#1949](https://github.com/gasyoun/Systema-Sanscriticum/pull/1949) |
| Duplicate closed | [PR #1948](https://github.com/gasyoun/Systema-Sanscriticum/pull/1948) |
| Release | [v1.90.8](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.8) ([PR #1950](https://github.com/gasyoun/Systema-Sanscriticum/pull/1950)) |
| Registry | [H3247](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3247-Grok_Systema-Sanscriticum_trial-deal-kind-booking_21.08.26.md) ✅ |

## C1 criteria

| Criterion | Result |
|---|---|
| Flag default OFF | `config('features.crm_trial_booking')` and `crm_trial_widget_public` read `env(..., false)` in [config/features.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) |
| Free book does not grant a course | After `bookFree`, `course_group` / `group_user` / `LessonAccessGrant` counts unchanged |
| Idempotent book | Two `bookFree` same email+schedule → one Deal |
| Paid trial does not double Deal | Observer tags existing Deal; `Payment::isTrial()` is `tariff=trial`; count stays 1 |
| Zoom match | Matching `webinar_attendances` email → `attended` + FollowUpTask «дожим после пробника» |
| Zoom miss | No attendance row → `booked` + «подтвердить посещение»; never auto `no_show` |
| Staff override | `applyOutcome(no_show)` sticks |
| Observer silent when trial flag off | Trial SKU still excluded from H2102 course-sale shape; `kind` not set |
| Pint + focused tests | `php artisan test --filter=TrialBooking` — 13 tests, 45 assertions, exit 0; `php vendor/bin/pint --dirty` clean after one unary-operator fix |

## Unattended defaults (logged)

- No historical Deal backfill to `kind=trial`.
- Reconcile never writes `no_show` (staff override only).
- No new capacity engine (`Group::min_size` is a recruitment floor).

Widget POST / `PublicScheduleResource` not changed — that is [H3248 (Grok 4.6) — Wave 1 cluster 2: book CTA on /widgets/schedule after cluster 1](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3248-Grok_Systema-Sanscriticum_public-schedule-trial-book-cta_21.08.26.md). Prod flag flip of `CRM_TRIAL_BOOKING` remains a human ops step after staff smoke ([DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) №80).

_Dr. Mārcis Gasūns_
