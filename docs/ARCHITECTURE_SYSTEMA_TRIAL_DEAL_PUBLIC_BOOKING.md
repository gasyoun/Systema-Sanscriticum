# ARCHITECTURE — Trial Deal + public book CTA

_Created: 21-08-2026 · Last updated: 21-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md).

## 1. Build vs reuse

| Piece | Verdict |
|---|---|
| Pipeline object | **Reuse `Deal`.** Add columns. No `TrialBooking` table. |
| Calendar | **Reuse `Schedule`.** `Course.trial_schedule_id` and ordinary group `Schedule` rows. No `TrialEvent`. |
| Paid trial money | **Reuse checkout + `PaymentDealBridgeObserver`.** Tag `kind=trial`. Do not open a second Deal. |
| Free intro date | **Reuse `NextIntroSession` / `trial_schedule_id`.** |
| Follow-up | **Reuse `FollowUpTask`** on the Deal. No new task type required (`TYPE_MESSAGE` + note). |
| Attendance signal | **Reuse `webinar_attendances` / `zoom:sync-attendance`.** |
| Capacity | **Reuse `Group::min_size` / `activeUsers()`.** Else unlimited + warning. |
| Public calendar UI | **Reuse `/widgets/schedule` + `PublicScheduleResource`.** Add book CTA. Do not rebuild the feed. |
| Guest capture | **Reuse Lead-by-email** (newsletter / support lead-capture). Do not always create a User. |
| Access / Zoom for a paid course | **Do not reuse `LessonAccessGrant` for free seats.** |

## 2. Deal columns (cluster 1)

Additive migration on `deals`:

```
kind            string   default 'course'   -- course | trial
schedule_id     unsignedBigInteger nullable  FK schedules nullOnDelete
trial_source    string   nullable           -- free | paid  (null if kind=course)
trial_outcome   string   nullable           -- booked | attended | no_show | converted
```

Indexes: `(kind, trial_outcome)`, `(schedule_id)`. Existing rows stay `kind=course`.

`Deal` remains Rank 4: it never calls `grantAccess()`, never writes `payments`, never writes `course_group`.

`converted` is set when a later **course** Deal for the same user/lead+course is won, or staff marks it. Do not invent conversion from Zoom.

## 3. Who may book (free)

`TrialBookingService::bookFree(email, schedule, attrs)`:

1. Return silently if `crm_trial_booking` is false.
2. Find-or-create `Lead` by email. Set `Deal.lead_id`. Set `Deal.user_id` only if a User with that email already exists.
3. Refuse a second **open** trial Deal for the same lead+schedule (idempotent).
4. Capacity: if the Schedule’s Group has `min_size` and `activeUsers()+openTrialDeals >=` a published cap when one exists; if no cap, allow and surface the count on the staff card.
5. Create Deal `kind=trial`, `trial_source=free`, `trial_outcome=booked`, first open `deal_stages` row, `amount` 0, `course_id` from the schedule’s owning course.
6. Do **not** attach `course_group`. Do **not** create `LessonAccessGrant`.

Zoom delivery for free seats lives **outside** Deal: a small mailer/cabinet card that reads `Schedule.link` for that one row. Staff can also paste from `/admin`. Guests without a User get the join URL in email only when `crm_trial_booking` is on — still not via the public JSON.

## 4. Paid trial path

When `PaymentDealBridgeObserver` would open or close a Deal and the Payment is a trial SKU (`Course.trial_price` path / trial tariff already used by checkout):

- Same Deal identity as today (user/lead+course+installment group).
- Set `kind=trial`, `trial_source=paid`, `schedule_id` from `course.trial_schedule_id` if present.
- On paid/success, leave `trial_outcome=booked` until Zoom/staff; do not skip attendance.
- Access grant stays on `PaymentObserver` (existing paid-trial behaviour). This plan does not widen it to free seats.

Flag: observer trial-tagging also requires `crm_trial_booking`. If only `crm_pipeline_board` is on, behaviour stays as today (untagged course Deals).

## 5. Attendance reconcile

Artisan `crm:reconcile-trial-attendance` (scheduled, same window as `zoom:sync-attendance`):

- Open trial Deals whose `Schedule.end` is past grace (15 min).
- If a `webinar_attendances` row matches `users.email` or Lead email → `trial_outcome=attended`, then `FollowUpTask` TYPE_MESSAGE due +1 day, note «дожим после пробника».
- If no Zoom row and email cannot be matched → **leave `booked`**, open FollowUpTask «подтвердить посещение». Do not write `no_show`.
- If Zoom ran and the matched person was absent → `no_show` + FollowUpTask.
- Staff override on the Deal form always wins; append a `DealTransition` note.

Unmatched Zoom emails are a named risk, not a fuzzy name match.

## 6. Public widget (cluster 2)

`PublicScheduleResource` **must not** emit `Schedule.link`, `zoom_*`, or raw numeric ids ([H1427](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1427-Sonnet_Systema-Sanscriticum_public-schedule-widget-w1b_21.07.26.md) test is the security boundary).

When `crm_trial_widget_public` is true **and** the row is trial-bookable (course has `trial_schedule_id` equal to this schedule, or the schedule is flagged as the intro session):

- `bookable: true`
- `book_token`: URL-safe HMAC of `schedule_id` with `APP_KEY` (payload in the token, not a public id). Token is not a stable permalink.

`POST /api/public/schedule/book` `{book_token, email, name?}`:

- `throttle:5,1`
- 404 if `crm_trial_widget_public` is false
- Resolves token → `TrialBookingService::bookFree`
- Returns `{ok: true}` without Zoom URL

Iframe JS on `/widgets/schedule` shows a «Записаться» button only when `bookable`. Live WordPress paste remains a **human in-chat** action, same as decision 15 of the teacher-load plan.

## 7. Flags

```
crm_trial_booking        env CRM_TRIAL_BOOKING         default false
crm_trial_widget_public  env CRM_TRIAL_WIDGET_PUBLIC   default false
```

Widget POST requires **both**. Staff booking in `/admin` requires only the first.

## 8. Surfaces

- Staff: Deal kanban / Deal form badge «Пробник» + outcome; Customer 360 already composes Deals — no new 360 table.
- Public: existing iframe only.
- Student cabinet: out of wave 1 (decision 6).

_Dr. Mārcis Gasūns_
