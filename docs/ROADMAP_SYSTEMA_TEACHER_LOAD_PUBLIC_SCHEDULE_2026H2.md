# ROADMAP — Teacher-load report + public schedule widget (Systema-Sanscriticum, 2026H2)

_Created: 21-07-2026 · Last updated: 21-07-2026_

Cover doc: [PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md).

## Wave 1a — Direction foundation + admin teacher-load analytics

Unblocks everything else; ships independently and is useful on its own.

- `CategorySeeder`: draft taxonomy (~15–20 rows) mined from the current `raspisanie/` course
  names, idempotent (`firstOrCreate` by slug).
- `Group::directions()` helper (derived `Group→courses→categories`, no migration).
- `Teacher::groupsLed()` / a `Course::groupsForTeacher()`-style query answering "which groups
  does this teacher lead, by direction" — reused by both wave 1a and wave 1b.
- New Filament page **"Нагрузка преподавателей"** (teacher-load analytics): table of teacher ×
  group-count, a `ChartWidget` breakdown by direction, filterable.
- Deliverable is "done" per [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) §1.

## Wave 1b — Public feed + embeddable widget

Depends on 1a's direction-resolution query.

- Public, unauthenticated, throttled+cached JSON endpoint: schedule entries with the
  decision-9 field allowlist (course/direction, teacher, weekday+time, course-landing link,
  group seat count/fill status) — no Zoom links, no PII, no raw IDs.
- Public Blade+JS widget route (`/widgets/schedule`): filterable by direction and by teacher,
  mobile-responsive, neutral Tailwind styling.
- Embed artifact: a copy-pasteable `<iframe>` snippet + short instructions, handed to MG as
  the human step (see autonomy contract, decision 15).
- Deliverable is "done" per [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE.md) §2–3.

## Wave 2 (not this plan — explicit non-goals)

Listed so a future session doesn't rediscover these by trial and error, and doesn't assume
this plan silently covers them:

- Actually pasting the iframe into the live `samskrtam.ru/raspisanie/` page — a human/@DO
  action gated on WP credentials + an explicit in-session go-ahead (decision 15).
- Any WordPress-side plugin/theme code — deliberately avoided (decision 8).
- Retiring or migrating the 47-product WooCommerce course catalogue on samskrtam.ru (open D4
  decision from the H1068 editorial audit) — unrelated question, not touched here.
- samskrte.ru↔samskrtam.ru brand/design/navigation unification (H563/H1068 sync initiative).
- Payment, checkout, access-control, or pricing changes of any kind.
- The mobile app (Capacitor `mobile/`).
- Publishing the same widget to any site other than samskrtam.ru (e.g. samskrte.ru) — the
  mechanism is reusable by construction, but wiring a second consumer is a separate decision.
- Denormalizing teacher↔group or adding a canonical `direction_id` to `Group` — deliberately
  deferred (decisions 5–6) until the on-the-fly query proves too slow in practice.

_Dr. Mārcis Gasūns_
