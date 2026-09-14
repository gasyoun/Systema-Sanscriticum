# ROADMAP — Moyklass live-group ops for Systema (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Cover: [PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md).

## Why this exists

[Мой Класс для языковых школ](https://moyklass.com/crm-dlja-jazykovyh-shkol) sells trial booking, visit-pack абонементы, CEFR occupancy, parent apps, филиалы, telephony, WhatsApp. We are one online Sanskrit school with an LMS. This roadmap is **only** the hybrid live-group slice that samskrte actually lacks: trial as a CRM object, then a public book button on the schedule we already publish.

## Waves

### Wave 1 (this `/ask`) — two handoffs

1. **Cluster 1 — trial Deal.** Columns on `deals`, `TrialBookingService`, tag paid-trial Deals, Zoom reconcile → `trial_outcome`, draft `FollowUpTask`, staff UI behind `crm_trial_booking`. Unblocks cluster 2.
2. **Cluster 2 — book CTA on `/widgets/schedule`.** Signed `book_token`, POST endpoint, iframe button. Behind `crm_trial_widget_public`. Does not paste into WordPress.

### Wave 2 (not this sitting)

- Teacher-marked roster on live Zoom groups (journal), still without changing payroll formula.
- Optional student-visible “your пробник” card in `/dvaram` (no store app).
- Human paste of the iframe onto `samskrtam.ru/raspisanie/` (same gate as H1427).

### Wave 3+ (parked, not scheduled)

- Visit-pack абонемент (new money object).
- 13% tax certificate / contract PDF (legal copy).
- Native iOS/Android (separate product year; see [ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)).
- Attendance-based teacher payroll (conflicts with `TeacherSalaryService`).

## Non-goals (explicit)

Native mobile apps this span · IP telephony / call journal (H2486 HOLD) · WhatsApp / Wazzup · multi-branch P&L · parent dual-login · public CRM API for other schools · warehouse · CEFR occupancy dashboard · rebuilding GetCourse Deal/Lead boards · rebuilding the public schedule feed · auto-send campaigns · flipping `CABINET_HYBRID`.

## What already closed the rest of the Moyklass pitch

| Moyklass claim | Our owner |
|---|---|
| Leads, funnel, tasks | `Lead`, `Deal`, `FollowUpTask`, Customer 360 |
| Payments, debts | `Payment`, promises, debtors |
| Schedule + groups | `Schedule`, `Group`, intake/waitlist |
| Student cabinet | `/dvaram` + Telegram/VK |
| Staff roles | `RoleGate` |
| Mailings / segments | `Campaign`, `Segment`, `MessageTemplate` (flags) |
| Teacher pay | `TeacherSalaryService` (revenue share, not per-head) |
| SMS | `SmsRuChannel` |

_Dr. Mārcis Gasūns_
