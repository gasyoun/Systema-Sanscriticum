# Technical analytics UX audit — 2026

_Created: 09-07-2026 · Last updated: 09-07-2026_

Analytics, event naming, UI component readiness, performance risks, and safe
implementation patterns for the cabinet/store UX roadmap. Grounded in the
current code — every claim below is cited against a real file, not assumed.
Sibling audits from the same UX-audit queue:
[`STUDENT_CABINET_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md),
[`CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md),
[`SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md),
[`MANUALS_TO_UI_CONTENT_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md).

## 1. Technical north star

Instrument the funnel that already exists in the product before adding new
UI. Every event this audit recommends maps to a state transition the backend
*already computes* (a lesson unlocked, a checkout started, a payment
succeeded) — the gap is almost entirely **firing a signal at the moment it
happens**, not building new tracking logic. Prefer extending the pattern
already proven on promo pages (Yandex Metrika `reachGoal` + VK pixel `_tmr`)
over introducing a new analytics stack; prefer backend-derived reports (the
`ChannelConversionReport`/`TeacherAnalytics` pattern) over a new client-side
event-log table wherever the fact is already durable server state. Never let
instrumentation risk payment or access correctness — every ticket in §6 is
additive and read-only with respect to money/access logic.

## 2. Current instrumentation/readiness

Three **separate, unconnected** instrumentation islands exist today — this
is the audit's central finding. Nothing joins them.

| Island | Where | What it actually does |
|---|---|---|
| **Marketing (Metrika/VK pixel)** | [`layouts/promo.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/promo.blade.php), [`layouts/articles.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/articles.blade.php) | Real, working `reachGoal`/`_tmr.push` calls for `begin_checkout`, `time_60s`, `scroll_bottom`, keyed to a per-page `yandex_metrika_id`/`vk_pixel_id`. **Only loaded on promo/article page layouts.** |
| **Learning (heartbeat)** | [`LessonView`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/LessonView.php) model + `POST /api/heartbeat` (`HeartbeatController`, [`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) line 235) | Real per-student-per-lesson state: `first_opened_at`, `last_opened_at`, `last_heartbeat_at`, `open_count`, `total_time_on_page`, `is_completed`. Powers [`TeacherAnalytics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherAnalytics.php) (funnel + student-progress reports in the Filament admin). This is genuine event capture, not a placeholder. |
| **Finance/channel (derived reports)** | [`ChannelConversionReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/ChannelConversionReport.php), `OrderPaymentConversionService` | Computed **retroactively** from durable state (`User.utm_source`, `Payment` rows) each time the report runs — there is no event-log table; "events" here are inferred from what's already saved, not fired in real time. |

**The gap that matters most: checkout and the entire student cabinet have
zero tracking wired.** Confirmed directly:
[`checkout/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/checkout/show.blade.php)
extends `layouts.shop`, not `layouts.promo` — and
[`layouts/shop.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/shop.blade.php)
and
[`layouts/student.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/student.blade.php)
have **no** Metrika/VK-pixel/`gtag` code at all (grepped directly, zero
hits). So the two places the requested taxonomy cares about most —
`begin_checkout`/`purchase` on the actual checkout page, and
`continue_learning_clicked`/`locked_reason_viewed` in the cabinet — currently
fire **nowhere**, marketing-side. (The learning side has a *different* real
signal — `LessonView` — that answers a related but not identical question:
"did they open the lesson," not "did they click continue.")

**`data-analytics` markers exist but are inert.** 22 occurrences across
[`resources/views/shop/partials/*.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/shop/partials)
and `shop/start.blade.php` (e.g. `data-analytics="product-ladder"`,
`data-analytics="sample-lesson"`, `data-analytics="onramp-quiz"`) — confirmed
no JavaScript anywhere reads this attribute. They're section labels a human
left as a map for future wiring, not live instrumentation. Treat them as a
**head start**, not evidence tracking already works.

**No screenshot/visual-regression testing infra.** No `playwright.config.*`
anywhere in the repo. `package.json` is minimal (Vite 8 + Tailwind 4, no
analytics or testing-beyond-PHPUnit packages); Alpine.js is loaded via CDN
in layouts, not an npm dependency.

## 3. Event taxonomy and naming rules

The mission's proposed taxonomy (`view_course`, `sample_lesson_play`,
`begin_checkout`, `purchase`, `first_lesson_opened`,
`continue_learning_clicked`, `locked_reason_viewed`,
`self_service_payment_started`, `support_started_from_context`,
`recording_played`) is sound and is what this audit recommends — with one
reconciliation: [`docs/vitrina.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/vitrina.md)
§"Что именно измерять" independently proposed an overlapping but
differently-named set (`view_course_page`, `sample_lesson_play`,
`begin_checkout`, `add_to_cart`, `purchase`, …) for the marketing funnel.
**Use the mission's names as canonical** (they're the more complete,
cabinet-aware set); where `vitrina.md` names the same concept differently,
treat `vitrina.md` as superseded by this taxonomy, not a second standard —
one taxonomy, not two competing ones.

| Event | Fires when | Layer | Backend fact it maps to (if any) |
|---|---|---|---|
| `view_course` | Course/lesson catalogue page loads | Frontend | — |
| `sample_lesson_play` | Free sample lesson video starts | Frontend | already a `data-analytics="sample-lesson"` section marker to wire |
| `begin_checkout` | Checkout page loads with a tariff selected | Frontend | — currently **not fired anywhere** on the real checkout page (see §2) |
| `purchase` | Payment succeeds | **Backend**, not frontend | `Payment::processSuccessfulPayment()` — fire here, not client-side, so it can't be lost to an ad-blocker or a closed tab |
| `first_lesson_opened` | A student's very first `LessonView.first_opened_at` is set | **Backend**, derived | already computable from existing `LessonView` data — no new capture needed, just a report/goal-fire hook on that write |
| `continue_learning_clicked` | The "Продолжить"/"Начать обучение" CTA on [`continue-learning-card.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/continue-learning-card.blade.php) is clicked | Frontend | zero markers found on this partial today |
| `locked_reason_viewed` | A student expands/sees a "why is this locked" explanation | Frontend | depends on the (currently unbuilt) `AccessDiagnosticsService` panel — see [`SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md) §4 Flow A; **do not build this event ahead of the feature it instruments** |
| `self_service_payment_started` | A debt-tab CTA ("Оплатить"/"Оплатить всё") is clicked | Frontend | `DebtPaymentController` routes already exist to attach a backend confirmation event to |
| `support_started_from_context` | "Поддержка" tab opened or "позови куратора" triggers | Frontend | ties into [`SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md) Flow B (context-attached escalation) |
| `recording_played` | A lesson recording starts playing | Frontend | out of scope per the access-self-service spec's own boundary note (recordings phase is deferred) — name reserved, not built |

**Naming rules:**

- `snake_case`, verb-past-tense-or-noun (`purchase`, `begin_checkout`) —
  matches the mission list and the existing Metrika `reachGoal` names
  already live in production (`begin_checkout`, `time_60s`, `scroll_bottom`).
  Don't introduce a second casing convention.
- **A financial or access-state event is a backend event, never
  frontend-only.** `purchase` must fire from `Payment::processSuccessfulPayment()`
  (server-side, can't be lost/blocked), mirroring how `first_lesson_opened`
  should be derived from the already-durable `LessonView` write rather than
  a duplicate client-side fire. Frontend events are for **intent** signals
  (clicked, viewed, expanded) that have no durable backend record of their
  own.
- Reuse the existing `reachGoal`/`_tmr.push` dual-fire pattern from
  `layouts/promo.blade.php` for any new frontend event — don't introduce a
  third tracking vendor without a documented reason.

## 4. Data collection boundaries/privacy

- **No PII in event payloads.** Existing `reachGoal` calls pass only a goal
  name, no user data — keep that pattern. `user_id` joins happen server-side
  (as `LessonView`/`Payment` already do), never in a client-side analytics
  payload.
- **Respect the existing consent/config gate.** Metrika/VK pixel are
  conditionally rendered (`@if($page?->yandex_metrika_id)`) — any new
  tracking on cabinet/checkout pages must be gated the same way, not
  hardcoded to always fire, so a page without configured IDs doesn't silently
  break or leak.
- **Support-chat privacy precedent applies here too.** [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
  already gates sending imported Telegram DMs to an external AI service
  behind `support_ai_include_telegram` (off by default) — `support_started_from_context`
  instrumentation must never itself forward chat *content* to an analytics
  vendor, only the fact that support was opened.
- **`LessonView`'s heartbeat data stays backend-only.** It already answers
  "how long did they watch" for `TeacherAnalytics` — there is no reason to
  also mirror it into Metrika/VK pixel as a duplicate event stream; that
  would be the exact kind of redundant-instrumentation risk this north star
  (§1) warns against.

## 5. Component/performance risks

- **Design-component duplication.** The shop partials
  (`resources/views/shop/partials/*.blade.php`) and the student dashboard
  (`resources/views/student/dashboard.blade.php`, 952 lines) are built as
  large, mostly self-contained Blade files with inline Tailwind classes — no
  shared component library found for buttons/cards/tabs (each CTA button in
  `dashboard.blade.php` restates its own Tailwind class string). Adding
  `data-analytics`/event-firing attributes piecemeal across dozens of
  hand-styled buttons risks drift (some wired, some forgotten) — recommend a
  small shared Blade component (or at minimum a documented attribute
  convention) for "trackable CTA button" before wiring events at scale, not
  after.
- **Vite/Tailwind consistency.** Single `vite.config.js` entry point
  (`resources/js/app.js`, `resources/css/app.css` + a separate
  `article.css`) — adding an analytics helper script is a small, low-risk
  addition to `app.js`; no bundler restructuring needed.
- **Alpine-via-CDN is a real constraint.** Since Alpine isn't an npm
  dependency, any new event-firing JS helper should be a plain vanilla
  function callable from `x-on:click`/`wire:click` handlers already used
  throughout the dashboard (e.g. `activeTab = 'chat'`), not an
  Alpine-plugin-specific pattern that assumes a build step Alpine doesn't
  have here.
- **`/api/heartbeat` is already a proven pattern for a polling/beacon
  endpoint** — if `continue_learning_clicked`/`locked_reason_viewed` end up
  needing a durable backend record (not just a client-side goal fire), this
  is the existing precedent to extend rather than inventing a new endpoint
  shape.
- **No performance red flags found** — the shop/dashboard pages are
  server-rendered Blade with Alpine for interactivity, not a heavy SPA;
  adding a handful of `reachGoal` calls carries negligible weight compared to
  the existing Yandex Metrika script already loaded on promo pages.

## 6. Recommended implementation tickets

Ordered cheapest-and-highest-leverage first:

1. **Wire the marketing tracking stack onto checkout.** `checkout/show.blade.php`
   extends `layouts.shop`, which has zero tracking — the single highest-
   leverage gap (§2). Extend `layouts/shop.blade.php` with the same
   conditionally-gated Metrika/VK-pixel snippet `layouts/promo.blade.php`
   already has, then fire `begin_checkout` on page load and `purchase` from
   the backend success path.
2. **Activate the existing `data-analytics` markers.** 22 inert section tags
   already exist in the shop partials — write the small JS helper (§5) that
   reads them and fires a `view_*`/scroll-depth goal, reusing names already
   present in the markup (`sample-lesson` → `sample_lesson_play`, etc.)
   rather than renaming them.
3. **`continue_learning_clicked` on the dashboard CTA.** Smallest new
   surface — one `x-on:click` handler on the existing button in
   `continue-learning-card.blade.php`, no backend change needed.
4. **`self_service_payment_started` on the debt tab.** Ties directly into
   the already-fully-built debt self-service flow (`DebtPaymentController`)
   — confirms whether the self-service payment path is actually being used
   before investing further in it.
5. **`first_lesson_opened` as a derived backend goal-fire**, hooked onto the
   existing `LessonView` write path rather than duplicated client-side —
   lowest-risk because it reuses data already being captured correctly.
6. **Defer `locked_reason_viewed` and `recording_played`** until their
   underlying features ship (`AccessDiagnosticsService` per the
   self-service-support audit; recordings per that audit's Flow D) — do not
   instrument a UI surface that doesn't exist yet.
7. **A shared "trackable CTA" Blade component** (§5) — pays for itself the
   moment ticket 3+ starts touching more than one button; worth doing before
   the instrumentation spreads further, not after.

## 7. Acceptance metrics and validation checklist

- `begin_checkout` and `purchase` both fire and reach Metrika/VK pixel for a
  real checkout flow — verified by a real test purchase (or a captured
  network request in dev), not just code review.
- No payment or access-control behavior changed by any ticket in §6 — every
  ticket above is additive (a new script tag, a new click handler, a new
  goal-fire hook), verified by running the existing payment/access feature
  test suites (`tests/Feature/Student/DebtSelfServiceTest.php`,
  `tests/Feature/Student/LessonAccessTest.php`) unchanged and green after
  each ticket.
- The two islands identified in §2 (marketing goal-fires, backend-derived
  reports) stay reconciled to one taxonomy (§3) — a new event name is never
  added without checking whether `vitrina.md` or an existing `reachGoal` call
  already names the same concept differently.
- Testing strategy for future UX PRs, per the mission's ask: **feature
  tests** for any new backend-fired event (assert the goal-fire call/queued
  job happens, the same way `DebtSelfServiceTest` asserts state changes);
  **Playwright screenshots are not yet set up** (§2) — recommend introducing
  a minimal Playwright config only when a ticket in §6 first needs visual
  regression coverage, not speculatively ahead of need.

_Dr. Mārcis Gasūns_
