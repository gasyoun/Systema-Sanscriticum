# VERIFICATION — Trial Deal + public book CTA

_Created: 21-08-2026 · Last updated: 21-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md).

## C1 — trial Deal (cluster 1)

| Criterion | Command / flow |
|---|---|
| Flag default OFF | `php artisan tinker` / feature test: `config('features.crm_trial_booking') === false`; `bookFree` inserts 0 deals |
| Free book does not grant a course | After `bookFree`, `course_group` count unchanged; no `LessonAccessGrant` for that user/email |
| Idempotent book | Two `bookFree` same email+schedule → one Deal |
| Paid trial does not double Deal | Checkout trial payment with board flag on: still one Deal, now `kind=trial` |
| Zoom match | Seed `webinar_attendances` with matching email → `attended` + one open FollowUpTask |
| Zoom miss | No attendance row → `booked` + confirm-attendance FollowUpTask (not `no_show`) |
| Staff override | `applyOutcome(no_show)` sticks |
| Observer silent when trial flag off | Pipeline board may still open a course Deal; `kind` stays `course` |
| Pint + focused tests | `php vendor/bin/pint --dirty`; `php artisan test --filter=TrialBooking` |

Staff smoke (after merge, **human** env flip of `CRM_TRIAL_BOOKING` only): guest `/admin/deals` 302; logged curator opens a trial Deal from a known intro `Schedule`; outcome select saves. Do not enable the widget key in this smoke.

## C2 — widget (cluster 2)

| Criterion | Command / flow |
|---|---|
| Allowlist intact | `PublicScheduleFeedTest` still forbids `link`, `zoom_*`, numeric `id` |
| Token gated | Widget flag off → no `book_token` in JSON |
| POST 404 when off | `POST /api/public/schedule/book` 404 |
| POST creates CRM rows | Flag on + valid token + email → Lead + trial Deal; body has no Zoom URL |
| Throttle | Fifth extra request in a minute is 429 |
| Iframe | Local `/widgets/schedule` shows the button only on bookable rows (manual or a feature test of the JSON the JS consumes) |

WordPress paste is **not** an acceptance criterion for either handoff.

## Risks

| Risk | Why it matters | Mitigation |
|---|---|---|
| Double Deal on paid trial | H2102 already opens a Deal; a naive `bookFree` on checkout would duplicate | Observer **tags**; `bookFree` is free-path only |
| Zoom email ≠ Lead email | False no_show kills conversion | Leave `booked` + confirm task |
| Public JSON leaks Zoom | Guests skip the funnel; security boundary of H1427 | Token HMAC; POST never returns join URL; feed test is blocking |
| One flag would light the widget | Round 3 vs Round 4 fight | Two keys (logged Round 5 default) |
| Capacity unknown | Overflow Zoom rooms | Unlimited + staff count if no `min_size` |
| Watcher on Systema | Uncommitted edits vanish | Watcher-safe commit; worktree |
| Rank 4 leak | Service “helpfully” grants the intro lesson | Tests on `course_group` / grant tables; fence in the contract |

## Spikes (none blocking)

- Exact checkout predicate for “this Payment is a trial SKU” — read existing trial checkout tests before writing a new one. If two predicates exist, pick the one the paid-trial test already uses and log it.
- Whether `Schedule.link` accessor still parses Zoom from `description` — do not put `description` on the public resource (already forbidden).

_Dr. Mārcis Gasūns_
