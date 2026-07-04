# Google Calendar Integration — Roadmap

_Created: 04-07-2026 · Last updated: 04-07-2026_

Design blueprint for a real, two-way Google Calendar integration in
Systema-Sanscriticum. Scoped by an MG decision interview on 04-07-2026; nothing
below is built yet — this document is the plan and the handoff target.

## 1. Status quo — there is no Google Calendar integration today

What the codebase actually has (verified 04-07-2026):

- **A Google-Calendar-*lookalike* UI, not a connection.** [`CalendarPage.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/CalendarPage.php)
  and [`ScheduleCalendarWidget.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Widgets/ScheduleCalendarWidget.php)
  are explicitly commented "Google-Calendar-like" — a Filament **FullCalendar**
  (month/week/day, drag & drop) rendered over the internal
  [`Schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Schedule.php)
  table. It never talks to Google.
- **"Google" in the codebase = social login only.** [`config/services.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php)
  holds `GOOGLE_CLIENT_ID`/`SECRET` for `/auth/google/callback` (Socialite,
  `email`/`profile` scopes). No `calendar` scope, no Google API client in
  [`composer.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/composer.json),
  no `.ics`/`webcal` export anywhere.
- **The one real external time integration is Zoom.** `Schedule` auto-creates
  meetings and stores `zoom_meeting_id` / `zoom_join_url`, with attendance sync
  via [`ZoomService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Zoom/ZoomService.php).

Consequences that motivated this roadmap:

| Question asked | Reality today |
|---|---|
| Course dates change → Google Calendar changes? | No external calendar exists. And editing a **Course/CourseBlock** `starts_at`/`ends_at` does **not** even move the `Schedule` session rows — they are independent tables. |
| Google Calendar changes → course data changes? | N/A — nothing reads from Google. |

"Deeper integration" therefore means **building from zero**. The design space was
fully open, so every axis below is an MG ruling, not a constraint inherited from
existing code.

## 2. Locked decisions (MG interview, 04-07-2026)

| Axis | Ruling |
|---|---|
| Sync direction | **Two-way** |
| Mechanism | **Both** — iCal/webcal feed (students) + Google Calendar API OAuth (teachers/admin) |
| What syncs | Per-session `Schedule` events **+** Course/CourseBlock date ranges **+** Zoom join link embedded in the event |
| Audiences | Students **+** Teachers **+** Admin/ops single master calendar |
| Conflict winner | **Last-write-wins** (by change-token / timestamp) |
| Students, concretely | **Read-only feed** — a subscription feed cannot accept edits, so students are one-way app→student; two-way rides the OAuth path only |
| Google-side teacher move cascades to | Move the `Schedule` row **+** reschedule the Zoom meeting **+** notify enrolled students |
| Course/block date changes | **Propagate** to their `Schedule` sessions, which then flow out to Google (new scheduling logic — see §6) |

## 3. Target architecture

Two independent channels, split by audience:

```
            ┌──────────────── App (source of record) ─────────────────┐
            │ Schedule ── Course/CourseBlock ── Zoom(meeting_id, url)  │
            └──────┬──────────────────────────────────────┬────────────┘
   push (read-only)│                       two-way (OAuth) │
          ┌────────▼────────┐                    ┌──────────▼───────────────┐
 STUDENTS │ iCal/webcal feed│   TEACHERS / ADMIN │ Google Calendar API +     │
     ◄────│ signed .ics URL │                    │ per-user OAuth;           │
          └─────────────────┘                    │ events.watch push ↔ worker│
                                                 └───────────────────────────┘
```

- **Students → read-only feed.** Per-user signed `.ics` URL
  (`/calendar/feed/{user}/{token}.ics`) + an "Add to Google Calendar" button.
  Google polls and auto-refreshes it. No OAuth for hundreds of students.
- **Teachers / admin → OAuth two-way.** Real Google events via the Calendar API,
  with `events.watch` push notifications so a teacher's drag inside Google reaches
  the app in seconds (with a periodic pull as reconciliation backstop).

## 4. Data model additions

| Table / column | Purpose |
|---|---|
| `google_accounts` (`user_id`, encrypted `access_token` + `refresh_token`, `scope`, `channel_id`, `resource_id`, `sync_token`) | Per-user OAuth state. Reuse the repo's existing Eloquent `encrypted` cast pattern (as `MarketingSetting` already does for bot tokens). |
| `schedules.google_event_id`, `schedules.google_etag`, `schedules.google_synced_at` | Link a session to its Google event + change-token for last-write-wins. |
| `schedules.remote_updated_at` | Google's `updated` timestamp, compared against `schedules.updated_at` to pick the winner. |
| `feed_tokens` (`user_id`, `token`, `revoked_at`) | Signed, **revocable** student feed URLs (the feed exposes a personal schedule — privacy). |

## 5. Sync mechanics — the hard part

**Last-write-wins**, per event, each reconcile: compare app `updated_at` vs
Google `updated`; newer side overwrites the loser; refresh `google_etag` /
`sync_token`. Guards to build in from the start:

- **Clock normalization.** Google's `updated` is UTC; the app is hardcoded
  `Europe/Moscow` in [`config/app.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/app.php).
  Normalize both to UTC before comparing, or LWW picks the wrong winner around DST
  and offset boundaries.
- **Echo suppression.** Writing to Google bumps its `updated` and fires our own
  `events.watch` webhook. Store the etag we just wrote and ignore the echo, or the
  two sides ping-pong forever.

## 6. Cascade & course-date propagation

**When a teacher moves an event inside Google** (OAuth path), cascade all three:

1. Move the `Schedule` row (start/end) — baseline; without it the move is cosmetic.
2. Reschedule the linked Zoom meeting via the Zoom API (`zoom_meeting_id`) —
   idempotent, keyed off the meeting id so retries don't duplicate.
3. Notify the group via the channels the repo already has
   (`User::sendTelegramMessage()` / `sendVkMessage()`) — this is how read-only-feed
   students learn of a time change.

**Course/block date → session propagation.** Changing a `CourseBlock`
`starts_at`/`ends_at` shifts its sessions, which then flow to both channels. This is
the one genuinely new piece of domain logic (not plumbing) and carries an **open
decision** — the shift rule:

- **(a) Translate** — move every session by the same delta as the block boundary
  (preserves inter-session spacing; the common case for "the course starts a week
  later").
- **(b) Redistribute** — spread sessions evenly across the new window (changes
  cadence; needed when the window length itself changed).

Resolve this at the start of Phase 4, not now.

## 7. Phased build order (each phase shippable alone)

| Phase | Deliverable | Depends on | Acceptance |
|---|---|---|---|
| **1. iCal feed** | Signed per-user `.ics` feed + "Add to Google Calendar" buttons; Zoom link + course-range events embedded | nothing (no Google dependency) | A student subscribes; sessions + course ranges appear in their calendar; revoking the token kills the feed |
| **2. OAuth connect + app→Google push** | Teachers/admin link Google; sessions + ranges written into their calendars (one-way) | Google `calendar`-scope app verification (§8) | A teacher links; app edits appear in Google within one sync cycle |
| **3. Google→app pull (two-way)** | `events.watch` channels + sync worker + last-write-wins + the 3-step cascade (§6) | Phase 2 | A teacher's drag in Google moves the Schedule row, reschedules Zoom, and notifies the group |
| **4. Course-date propagation** | `CourseBlock` date edits shift sessions and flow out | Phase 1 (feed) or 2/3; shift-rule decision | Editing a block's dates moves its sessions per the chosen rule and updates both channels |

Phase 1 delivers student value immediately with zero Google dependency and de-risks
the whole effort — build it first.

## 8. Risks & external blockers

- **Google verification / sensitive scope (Phase 2 gate).** The `calendar`
  read-write scope is **sensitive** — Google requires app verification / a security
  assessment before external users may grant it. This is an external-party lead time;
  **start it early** as it gates Phase 2. The existing Google-login integration uses
  only `email`/`profile` and does not cover this.
- **This repo has a watcher.** [`.claude/hooks/watcher_autosave.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.claude/hooks/watcher_autosave.py)
  reverts uncommitted working-tree edits — all implementation work must land via the
  `/watcher-safe-commit` discipline (author + commit in one shot, verify survival vs
  `HEAD`).
- **Timezone.** `Europe/Moscow` is hardcoded; all Google↔app time comparisons must be
  UTC-normalized (see §5).
- **API quota.** OAuth-per-teacher plus `events.watch` channels consume Calendar API
  quota and channels expire (max ~1 week) — the sync worker must renew watch channels
  and back off on quota errors.
- **Feed privacy.** The student `.ics` feed exposes a personal schedule; URLs must be
  unguessable (signed token) and revocable, never sequential ids.

## 9. Open questions (do not guess — bring to MG)

1. **Shift rule** for course-date propagation — translate (a) or redistribute (b)?
   (§6, Phase 4.)
2. **Admin master calendar** — one shared Google calendar the app owns via a service
   account, or an OAuth-linked ops account? (Affects §4 token model.)

---

_Dr. Mārcis Gasūns_
