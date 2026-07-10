# Self-service support UX audit — 2026

_Created: 09-07-2026 · Last updated: 09-07-2026_

Turns "I cannot login / where is my course / why is this locked / where is the
recording / how do I pay?" into contextual help and self-service paths before
a human curator is needed. Grounded in the current code — every claim below is
cited against a real file, not assumed from an older spec.

## 1. Support north star

A student with a problem should resolve it in three ways, cheapest first:

1. **See the answer already on the page** — the thing that's wrong is visible
   in context (a locked lesson explains why; a debt explains what's owed and
   how to pay it), no navigation required.
2. **Fix it themselves with one click** — a safe, idempotent action already
   exists as a mechanism (reconnect a bot, reset a password, materialize an
   already-paid block, pay a debt) and needs only a button wired to it.
3. **Escalate to a human with context already attached** — "позвать
   куратора" hands the curator a thread that already carries the diagnostic
   findings, not a blank slate they have to re-derive by asking questions.

The bot is a **notification/support enhancement**, never required onboarding
— [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md)
§1 frames it exactly this way, and nothing in the current dashboard forces a
student through Telegram/VK to solve a problem — bot connection is optional
(`$tgConnected`/`$vkConnected` in
[`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php)
lines 184, 236).

## 2. Current support surfaces (what actually exists today)

| Surface | Where | State |
|---|---|---|
| **Debt self-service** | "Мои долги" tab, [`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php) lines 312–319 (tab), 727–822 (CTAs) | ✅ **Fully built.** `DebtPaymentResolver` + `DebtPaymentController` + `PromiseAutoFulfiller` all exist and are wired — "Оплатить" (next installment), "Оплатить всё" (bundle), "Оплатить курс"/per-block CTAs. Phase 1 ([PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293)) and Phase 2 (multi-block sibling-row access, bundle checkout) both landed per [`docs/debtor-self-service-phase2-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-phase2-spec.md). Tested: [`tests/Feature/Student/DebtSelfServiceTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Student/DebtSelfServiceTest.php), [`StudentDebtsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Student/StudentDebtsTest.php). |
| **Access self-service** | Nowhere yet | ❌ **Spec-only, not built.** [`docs/access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) describes `AccessDiagnosticsService` + a "Почему закрыто?" block, neither exists in `app/` or the dashboard view (confirmed: no `AccessDiagnosticsService` file, no "Почему закрыто" string anywhere in the codebase). This is the single largest gap this audit found — see §4/§5. |
| **Web chat (AI + human)** | "Поддержка" tab, `dashboard.blade.php` line 328–332 (tab button), line 589–596 (`@livewire('student-chat')`) | 🟡 **Built, but free-text-only, buried.** [`student-chat.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/livewire/student-chat.blade.php) is a plain chat box — no structured problem picker, no quick-reply buttons. Escalation to a human is a **magic phrase** ("напишите «позови куратора»", line 12 of the view) rather than a button — a student has to know the phrase exists. It's the *last* tab in the dashboard's tab bar (after Courses/Dictionaries/Payments/Debts/Prana), not a top-level nav item (`layouts/student.blade.php` nav has Dashboard/Calendar/Open-lessons/SRS/Messages — no "Помощь"/"Поддержка" entry). |
| **Human handoff** | Trigger words in bot + `student-chat`'s "позови куратора" phrase | ✅ **Works, but zero context is passed.** [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md) §6 — trigger words (`куратор`/`человек`/`помощь`/`админ`/`менеджер`/`оператор`) set a 2h cache flag, alert curators via the main bot. The curator gets a link into the admin dialog view but **no diagnostic summary** (no "this student has a locked lesson + an unfulfilled promise" context attached) — they still have to ask what's wrong. |
| **Curator-side unified inbox** | [`Helpdesk.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) | ✅ **Built and tested**, including claim/assign + inbox/mine/resolved status tabs ([`tests/Feature/Support/HelpdeskTabsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/HelpdeskTabsTest.php)) — this is curator-facing, not student-facing, but it's the thing a student's escalation lands in, so it's the other half of the loop. Note: [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) line 99 ("Status filter tabs missing in UI") is **stale** — this landed since that doc was last updated (05-07-2026); flagging so the next reader of that map doesn't repeat the check. |
| **Content-gap detection (curator side)** | [`content:detect-gaps`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DetectContentGaps.php) command, `H418` | ✅ **Built 09-07-2026**, not student-facing — ranks which support categories have no matching `MessageTemplate`/FAQ content. Directly useful for §3/§5 below: run it against real production data to validate (or correct) the taxonomy this audit proposes from `support:topic-ranking`'s existing category list. |
| **Bot FAQ knowledge base** | `resources/knowledge/faq.md`, read by `BotKnowledgeBase` | ✅ Built, Telegram/VK-only — a student on the *web* dashboard has no equivalent static FAQ surface; the only web answer path is the free-text AI chat. |

## 3. Top student problem taxonomy

Sourced from the categories `SupportTopicRule`/`SupportTopicAssignment` already
classify (`support:topic-ranking`, `content:detect-gaps`) plus the problem
classes named explicitly in `docs/access-self-service-spec.md` and
`docs/debtor-self-service-spec.md`. This audit does not have live production
`support:topic-ranking`/`content:detect-gaps` output to rank by actual volume
— **running those two commands against real data is the first concrete
follow-up ticket** (§5, ticket 6) rather than guessing at a ranking here.

| # | Problem | Today's path | Self-service exists? |
|---|---|---|---|
| 1 | "Оплатил, а доступа нет" / "не могу войти" | `docs/access-self-service-spec.md` `key_missing_for_paid_range` finding | ❌ No — spec only |
| 2 | "Не могу войти" (password) | `PasswordResetController` exists, but nothing surfaces it from a locked-lesson context | 🟡 Mechanism exists, not surfaced |
| 3 | "Не подключен бот" | `telegram.connect` deep-link exists (dashboard shows connect status) | 🟡 Surfaced on dashboard, not from a *locked-lesson* context |
| 4 | "Где записи" (recordings) | No dedicated flow; explicitly out of Phase 1 scope per `access-self-service-spec.md` §"Граница" | ❌ Not scoped anywhere yet |
| 5 | "Не продлил" / "оплата просрочена" | "Мои долги" tab, `DebtPaymentResolver` | ✅ Fully built |
| 6 | "Рассрочка — следующий платеж / погасить всё" | Same tab, promise-aware checkout | ✅ Fully built |
| 7 | "Какой бот подключать / где что" | No structured onboarding surface; `cabinet-bot.md` FAQ is Telegram/VK-only | ❌ Web-side gap |
| 8 | Everything else (общий вопрос) | Free-text chat → AI → optional human escalation via magic phrase | 🟡 Works but ungrounded (no diagnostic context attached to escalation) |

## 4. Recommended self-service flows

**Flow A — "Почему закрыто?" (the single biggest gap).** Build
`AccessDiagnosticsService` exactly as scoped in
[`docs/access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md)
§"Архитектура Phase 1" — it is fully designed, reuses only existing
mechanisms (`BlockAccessMaterializer`, `telegram.connect`,
`password.request`), and explicitly stays read-only + idempotent. This audit
does not re-derive that design; it confirms the design is sound (no
mechanism it proposes is missing from the codebase) and that **nothing has
been built against it yet** — it is ready to implement as-is. The spec's
open `@DECIDE` items (block-on-lesson-card vs. dedicated tab; which three
actions are safe to automate; how to handle "paid but no covering payment
landed") still need MG's answer before implementation — carrying them
forward here rather than re-litigating.

**Flow B — Escalation-with-context.** When a student triggers "позови
куратора" (or a trigger word in Telegram/VK per `cabinet-bot.md` §6), attach
a short diagnostic snapshot to the curator alert/thread: which lessons are
locked and why (once Flow A exists, this is nearly free — same
`AccessDiagnosticsService` output), any open debt/promise state (already
computed by `StudentDebtsService::forUser()`), and bot-connection status
(already computed for the dashboard). This turns "student says something's
broken, curator asks five questions" into "curator opens the thread and
already sees the finding." No new diagnostic logic — just attaching what
Flow A and the existing debt service already compute to the handoff.

**Flow C — Discoverable help entry point.** Promote "Поддержка" from the
*last* dashboard tab to a top-level nav item (or at minimum, move it earlier
in the tab order — right after "Мои курсы"), and give the chat surface an
opening structured picker ("Доступ и вход" / "Оплата" / "Где мой урок/запись"
/ "Другое") instead of only a blank text box. Selecting a category can
pre-route into Flow A's findings (for access) or the debt tab (for payment)
before ever reaching free-text AI chat — cheapest-answer-first, per §1's
north star. This does not replace the AI chat; it puts a faster path in
front of it for the categories that already have a deterministic answer.

**Flow D — Recordings ("где записи").** Explicitly out of scope for Phase 1
per the access spec, and this audit doesn't have visibility into where
recordings are gated/stored (`CourseMaterialsArchiver`/`Lesson` per the
spec's note) to design this responsibly. Recommend: run
`content:detect-gaps`/`support:topic-ranking` against real data first (§5
ticket 6) — if "где записи" volume justifies it, that becomes its own
follow-up spec, not a guess added here.

## 5. UI/contextual-help tickets

Ordered cheapest-and-highest-leverage first, not by roadmap wave:

1. **Implement `AccessDiagnosticsService` + "Почему закрыто?" block** per the
   existing `docs/access-self-service-spec.md` design. Blocked only on MG's
   three `@DECIDE` answers already listed in that spec — no new design work
   needed. This is the highest-leverage single ticket in this audit: it's
   fully specced, reuses only existing mechanisms, and closes the #2-by-cost
   support topic per the spec's own citation of `support:topic-ranking`.
2. **Attach diagnostic context to human escalation** (Flow B) — small, once
   ticket 1 lands; wire `AccessDiagnosticsService` output +
   `StudentDebtsService::forUser()` into the curator alert text in
   `docs/cabinet-bot.md` §6.2's alert path.
3. **Give "Поддержка" a structured entry picker** (Flow C) before the
   free-text box — a handful of buttons in
   [`student-chat.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/livewire/student-chat.blade.php)
   that route to the debt tab / access findings / stay in chat.
4. **Promote "Поддержка" in the tab order** (or to top nav) — currently last
   after Courses/Dictionaries/Payments/Debts/Prana in `dashboard.blade.php`
   lines 300–332; a student with an urgent problem has to scan past four
   other tabs to find it.
5. **Web-side static FAQ parity.** `resources/knowledge/faq.md` only feeds
   the Telegram/VK bot's `BotKnowledgeBase`; the dashboard has no equivalent
   surfaced static-answer panel for a student who never leaves the web
   cabinet. Could be as small as rendering the same `faq.md` content into a
   dashboard panel/modal.
6. **Run `support:topic-ranking` and `content:detect-gaps` against real
   production data** and revisit §3's taxonomy ranking with actual numbers
   instead of the qualitative ordering used here. Both commands already
   exist and are safe read-only reporting.

## 6. Metrics

Reuse the metric shapes already built for the adjacent support-automation and
content-ops work rather than inventing new ones:

- **Support deflection** — the existing `auto_rate`/`deflection_score`
  computed by
  [`support:topic-ranking`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportTopicRanking.php)
  and
  [`content:detect-gaps`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DetectContentGaps.php)
  (human replies vs. AI-sent, per category). Once Flow A/C land, track
  whether the "access" and "payment" categories' `auto_rate` rises (fewer
  human replies needed for the same query volume) — the mechanism to measure
  this already exists, no new instrumentation needed.
- **Context-rich support starts** — the fraction of curator-escalated
  threads that arrive with a diagnostic snapshot attached (Flow B) vs. blank.
  New counter: increment on `SupportAiReplyEvent`-style event when Flow B's
  context-attach fires (reuse the existing `event_type`/`meta` shape on
  [`SupportAiReplyEvent`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php)
  rather than a new table).
- **Debt self-service starts** — already measurable today: count of
  `DebtPaymentController` route hits vs. total students with an open debt
  (`StudentDebtsService::forUser()` count). No new tracking needed to start
  measuring this one; it's the one flow in this audit that's fully built.

_Dr. Mārcis Gasūns_
