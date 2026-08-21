# IMPLEMENTATION — Trial Deal + public book CTA

_Created: 21-08-2026 · Last updated: 21-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md). File-level DAG for two Grok 4.6 (`grok-4.6`) handoffs. Cluster 2 must not start until cluster 1 is merged to `main`.

Worktree: `git worktree add -b feat/trial-deal-<pid> ../Systema-Sanscriticum-hNNNN-<pid> origin/main`. Watcher-safe commits. `/money-pr-land` if step 5 is in the PR.

## Cluster 1 — trial Deal (handoff A)

### Step 1 — flags

Touch [config/features.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php): `crm_trial_booking` and `crm_trial_widget_public`, both `env(..., false)`. Document in [docs/ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md) and a `DEPLOY_QUEUE.md` row (enable = human, staff-only first).

### Step 2 — migration

New migration `*_add_trial_columns_to_deals_table.php` on `deals` as ARCHITECTURE §2. Default `kind='course'`. Do not backfill historical Deals as trial unless `source_payment` is already a trial SKU — optional in the same migration **only** if a deterministic `payments` predicate already exists; otherwise skip backfill (log as unattended default).

### Step 3 — model

[app/Models/Deal.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php): constants `KIND_*`, `TRIAL_SOURCE_*`, `TRIAL_OUTCOME_*`; `fillable` + casts; `schedule()` belongsTo; `scopeTrial()`. Factory states `trialFree` / `trialPaid`.

### Step 4 — `TrialBookingService`

New [app/Services/Crm/TrialBookingService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Crm/TrialBookingService.php). Methods: `bookFree`, `tagPaidDeal(Deal, Payment)`, `applyOutcome(Deal, string, ?User $actor)`. Flag short-circuit at the top. **No Payment writes. No access writes.**

Depends on steps 1–3.

### Step 5 — observer tag

[app/Observers/PaymentDealBridgeObserver.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php): after the existing open/close write, if `crm_trial_booking` and the payment is a trial SKU, call `tagPaidDeal`. Predicate: reuse whatever checkout already uses for trial (course `trial_price` / tariff key) — **do not invent a second “is trial” regex**. Money-contour PR marker.

Depends on step 4.

### Step 6 — reconcile command

New `app/Console/Commands/ReconcileTrialAttendance.php` (`crm:reconcile-trial-attendance`). Register in [app/Console/Kernel.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php) after the Zoom attendance command. Implements ARCHITECTURE §5. Opens `FollowUpTask` via existing constructor, not a new dispatcher.

Depends on step 4.

### Step 7 — staff UI

[DealKanbanBoard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/DealKanbanBoard.php) and Deal form/resource: badge «Пробник», outcome select (staff override → `applyOutcome`). Hide when `crm_trial_booking` is false. Do not merge lead_stages into deal_stages (F3 already ruled).

Depends on steps 3–4.

### Step 8 — cluster 1 tests + changelog

New `tests/Feature/Crm/TrialBookingTest.php`:

- flag OFF → `bookFree` no-ops, observer does not set `kind`
- free book creates Lead+Deal, `user_id` null when no User, no `course_group` row, no `LessonAccessGrant`
- second POST same email+schedule is idempotent
- paid trial tags the **same** Deal, count stays 1
- Zoom match → attended + FollowUpTask; no match → booked + confirm task; staff override to no_show
- Rank 4: service never calls grant helpers (spy or assert table counts)

Pint. Changelog `[Unreleased]` bullet. PR. Merge when green. **Do not** set prod env keys.

## Cluster 2 — widget CTA (handoff B, after A on main)

### Step 9 — book token

New `app/Support/TrialBookToken.php` (HMAC, `APP_KEY`). Round-trip test. Used by the resource and the POST controller.

### Step 10 — resource + POST

[PublicScheduleResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php): add `bookable` + `book_token` **only** when `crm_trial_widget_public` and the row is the course’s trial schedule. Never add `id` or Zoom fields.

New controller method or `PublicTrialBookController` `POST /api/public/schedule/book`. Throttle. 404 if widget flag off. Calls `bookFree`. JSON `{ok:true}` without join URL.

Depends on cluster 1 + step 9.

### Step 11 — iframe JS

[public/widgets/schedule.js](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/widgets/schedule.js) (or the Blade the widget actually ships): if `bookable`, show email field + «Записаться». Copy in Russian, register-appropriate. No Zoom text on success («Мы напишем о подключении»).

### Step 12 — cluster 2 tests

Extend [tests/Feature/PublicScheduleFeedTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PublicScheduleFeedTest.php):

- default flags: no `book_token`, no numeric ids, no zoom keys (existing assertions stay)
- widget flag ON: trial row has token; non-trial rows have `bookable=false`
- POST 404 when flag off; 422 without email; 200 creates Deal; throttle
- response body must not contain `zoom` or `http` join links

Changelog. PR. Merge. Widget prod flag stays false. Staff `CRM_TRIAL_BOOKING` flip is a **human ops** step after smoke (GTD), not this handoff.

## Fence reminder

Forbidden in both PRs: `grantAccess`, salary formula, telephony flags, `CABINET_HYBRID`, WordPress paste, csl-* .

_Dr. Mārcis Gasūns_
