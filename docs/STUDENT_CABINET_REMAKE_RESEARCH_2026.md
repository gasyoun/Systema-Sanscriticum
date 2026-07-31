# Student cabinet remake — evidence ledger and fresh audit (H822)

_Created: 12-07-2026 · Last updated: 12-07-2026_

Research phase of the evidence-led student-cabinet remake, [H822](https://github.com/gasyoun/Uprava/blob/main/handoffs/H822-Fable_Systema-Sanscriticum_student-cabinet-custdev-ux-remake_12.07.26.md).
Produced by Fable 5 (`claude-fable-5`), 12-07-2026, from: the five committed 2026 UX audits, a fresh
code-level IA reconstruction of the cabinet at commit `03b0a42`, the private Uprava custdev corpus,
and the private Telegram support-analytics assets. Companion doc:
[STUDENT_CABINET_EDTECH_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md).

**Publication note.** This repo is public; the measured business figures (cheque sizes, revenue
splits, LTV per ladder, conversion lifts, support-topic shares) live in the **private** full
version, [Uprava/custdev/STUDENT_CABINET_REMAKE_RESEARCH_FULL_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/STUDENT_CABINET_REMAKE_RESEARCH_FULL_2026.md)
(private link; dead for external readers by design). Here each money fact is stated
qualitatively with an ID (M1…M10) so the design docs can cite it without republishing numbers.

**What this doc adds over the five existing audits:** (1) a single evidence ledger merging
UX-audit findings with the private money evidence (the audits never priced their findings);
(2) the real page/state map per student situation, including states the code silently lacks;
(3) an honest reconciliation of the Telegram support-data question; (4) a ranked job list.
It does NOT re-derive the audits' ticket lists (§5).

## Evidence strength legend

- **measured** — counted from a durable source (payment ledgers, classified corpora; exact figures in the private full version).
- **observed in code/UI** — verified against the repo at `03b0a42` with file:line.
- **support-derived** — from the private Telegram-export analysis; see scope caveat in §3.
- **M.G. ruling** — an explicit recorded decision; honored, not re-asked.
- **hypothesis** — plausible, unmeasured. Never presented as a finding.

## 1. The real page/state map (fresh, code-level, 12-07-2026)

The cabinet is **one mega-dashboard plus five satellite pages**. All routes sit in a single
group behind `auth` + `track.activity` + `student.maintenance`
([routes/web.php:231](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php)).

| Surface | Route | What it is |
|---|---|---|
| Dashboard `/dvaram` | `student.dashboard` | 70 KB blade, 6 client-side Alpine tabs (courses · dictionaries · chat · payments · debts* · prana*) — *conditional. Tabs have no URLs (except `#prana`/`#debts` hashes): not linkable, not trackable, back-button-invisible. |
| Course `/course/{slug}` | `student.course` | Lesson list with lock/complete state; locked rows deep-link out to `shop.course.show#tariffs`. |
| Lesson `/course/{slug}/lesson/{id}` | `student.lesson` | Player (YouTube/Rutube), transcript, notes, homework, prev/next. Paywall enforced in controller (redirect + flash), not in view. |
| Calendar `/calendar` | `student.calendar` | Schedule events Today/Tomorrow/date + iCal feed. |
| Open lessons `/open-lessons` | `student.open-lessons` | All free published lessons, regardless of purchase. |
| Messages `/messages` | `student.messages` | Announcements. Unread state is **localStorage only**; the sidebar red dot is **hardcoded fake** (`student.blade.php:126`, comment «пока фейковая»). |
| SRS `/dvaram/koloda` | `SrsController` | Flash-cards — nav hidden, `config('srs.enabled')` OFF in prod. |

Per-situation walkthrough (the eight situations the handoff names):

| Situation | What the code actually does today | Gap class |
|---|---|---|
| First login | Lands on `/dvaram` courses tab. Continue-learning card (PR #353) computes ONE next action (debt → homework-revision → trial → next lesson → done → empty). But the 4-step onboarding checklist renders ABOVE it, competing for the same job; construction banner + bot cards + password button add noise. | prioritization |
| Returning learner | Same landing. Continue card works; course page itself has NO next-lesson signal (only the dashboard card), so every return via course page re-scans the list. | continuity |
| Active cohort | Calendar exists with iCal; lesson page shows «Состоится…» + Zoom join for future sessions. No live-vs-recorded distinction in course lesson lists. | legibility |
| Recording-only buyer | Recordings are just lessons; trial/per-lesson grants surface as «Пробные занятия». Mobile inverts priority: transcript falls below homework. Nothing says «смотрите в своем темпе». | framing |
| Lapsed learner | **No state at all.** Expired access = course silently vanishes from grid, menu and course page (group query filter). No «доступ истек / продлить» surface anywhere. The only renewal path is debt banners (if a debt is modeled) or finding the public shop. | missing state |
| Debt / access problem | The strongest area: per-course banners (promise-active green / overdue red / debt orange / «Оплачено до» positive), a debts tab with pay/reschedule/pay-all self-service (PR #293). But «Почему закрыто?» diagnostics is **spec-only, not built** (`AccessDiagnosticsService` doesn't exist). | partial |
| Completion | Manual «Завершить» only; no watch-derived progress, page never says so. Certificates: PDF/JPG download. All-completed continue-card state exists. | expectation |
| Subscriber/member | Membership is priced (private ruling; not launched); no cabinet surface exists. | future |
| Mobile (cross-cutting) | Off-canvas 280 px drawer + hamburger at all widths; no bottom nav. Tab bar has no overflow wrapper (crowds at narrow width); chat panel fixed 70vh; course-row action button `hidden sm:flex` → icon-only on mobile. **Cabinet loads Tailwind via CDN at runtime** — it is not on the Vite build pipeline the rest of the app uses. | debt |
| Support (cross-cutting) | Livewire chat = last dashboard tab, free-text only, 10 s poll; human escalation via magic phrase («позови куратора»), zero diagnostic context passed. The public-site chat widget (Reverb work, H536/H612) is NOT wired into the cabinet. Web-side FAQ doesn't exist (faq.md is bot-only knowledge). | burial |

## 2. Evidence ledger

### 2a. Money facts that must shape the architecture (measured; figures in the private full version)

| # | Finding (qualitative form) | Strength | Consequence for the cabinet |
|---|---|---|---|
| M1 | The overwhelming majority of revenue comes from **repeat payments** by existing students; most payers pay more than once; the first payment is a small fraction of lifetime money | measured (two independent financial sources agree) | The cabinet IS the revenue engine — retention/continuation surfaces outrank acquisition polish. |
| M2 | A large share of students buys more than one course **with no in-product cross-sell mechanism at all** | measured | Cross-sell already happens *despite* the product; a relevance-gated «next step» layer harvests existing behavior. |
| M3 | Grammar-entry students yield the deepest course ladders and the highest LTV; practice-entry (yoga/chants) converts easier but shallower | measured | Discovery should bridge practice-entry students toward the grammar ladder, not push volume offers. |
| M4 | The buying decision is slow (median weeks, long tail months); «silent ≠ declined» | measured | No urgency mechanics; long-cycle nudges beat deadlines. |
| M5 | Pressure/urgency wording measurably HURTS conversion; installments and benefit-price visibility measurably help; fast on-point answers outconvert evasive ones | measured (correlational, no A/B) | Hard guardrail: no deadlines, no scarcity, no bulk social proof; price always shown with the way to take it. |
| M6 | «Нет времени» is the most frequent objection in the dialog corpus — ahead of price; recordings + own pace + lifelong access is the standard reassurance | measured (with a two-classifier caveat, see full version) | Recordings deserve first-class placement and «в своем темпе» framing. |
| M7 | Churn is driven by a break in **personal rhythm** (a pause several times longer than one's own cadence), not calendar time | measured (model over prod data) | «Вернуться с места остановки» surface; rhythm-break is the danger signal. |
| M8 | The Russian-speaking diaspora pays via PayPal at roughly double the domestic cheque and converts well | measured | Payment surfaces need the «оплата из-за рубежа» path + timezones on schedule surfaces. |
| M9 | The core buyer is a woman 35–55, capital cities, mobile-heavy — with an explicit small-sample caveat on demographics coverage | measured-but-thin | Mobile-first + typography/contrast for 45+ eyes is a defensible default. |
| M10 | Membership is priced (M.G. ruling) as a **cheap bottom rung** for the younger cohort + reactivation — NOT a whale premium | M.G. ruling | The membership slot targets per the ruling; price stays out of public docs before launch. |

### 2b. Cabinet friction (deduplicated; A–E = the five audits, fresh = §1 code map)

| # | Finding | Source | Strength | State | Severity |
|---|---|---|---|---|---|
| F1 | No unified «next action» layer; co-equal blocks compete (partially mitigated by PR #353) | A §3, B | hypothesis + observed | FL/RET | primary diagnosis |
| F2 | Onboarding checklist renders above the continue card; both say «начните курс» | B §2–3 | observed | FL | medium |
| F3 | Access expiry is a silent vanish — no expired state, no renewal CTA | fresh §1; D | observed | LAP | **critical** — severs the repeat-revenue path (M1) at its most dangerous moment |
| F4 | «Почему закрыто?» access diagnostics spec-only; locked cards name the block but not price/contents | D §2, C2 | observed | DEBT/REC | high |
| F5 | No next-lesson signal on the course page itself | C1 | observed | RET | high (every return visit) |
| F6 | No live-vs-recorded distinction in lesson lists; recordings never framed «в своем темпе» | C3, C §4 | observed | REC | medium (M6) |
| F7 | Mobile lesson page: transcript below homework (priority inverted on the majority device) | C L1 | observed | REC/RET | medium (M9) |
| F8 | Notes: no autosave, no unsaved-guard → silent loss | C L2 | observed | RET | medium (trust) |
| F9 | Manual-only «Завершить», no watch-derived progress, false expectation set | C L3 | observed | DONE | low-med (gates the offer moment) |
| F10 | Support buried: last tab, free-text only, magic-phrase escalation, zero context | D | observed | all | high (M5) |
| F11 | Messages red dot fake; unread is localStorage; announcements never marked read server-side | fresh §1 | observed | all | medium |
| F12 | Zero cabinet analytics: no events on dashboard/course/messages; only lesson heartbeat + coarse `last_activity_at`; 22 `data-analytics` markers inert; tabs unlinkable so untrackable | E §2, fresh §1 | observed | — | high for the program |
| F13 | Cabinet not on the Vite build: Tailwind CDN at runtime | fresh §1 | observed | — | medium |
| F14 | Bot-connect cards render unconditionally forever; construction banner reappears cross-device; password button in primary header | B, A §5.5 | observed | FL/RET | low-med |
| F15 | No web-side FAQ; faq.md knowledge is bot-only | D | observed | all | medium |

Audits: A = [STUDENT_CABINET_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md),
B = [FIRST_5_MINUTES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md),
C = [LEARNING_EXPERIENCE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md),
D = [SELF_SERVICE_SUPPORT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md),
E = [TECHNICAL_ANALYTICS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md).

### 2c. Starting hypotheses from the handoff — verdicts

| Hypothesis | Verdict on current evidence |
|---|---|
| Students need a single obvious next action | supported (A/B audits + M1 money logic); PR #353 already half-built it — finish the prioritization, don't invent it |
| Recordings/materials/schedule/access/payment states hard to locate or understand | supported for access (F3/F4 — strongest), partially for recordings (F6/F7); schedule and payments are actually decent |
| Support is buried | supported (F10) |
| Feature-rich dashboard overwhelms | supported as framing (F1); the fix is prioritization + progressive disclosure, not feature removal — debt self-service and continue card are genuinely good |
| Core buyer mobile, 35–55 | supported (M9) with the coverage caveat |
| Contextual continuation offers outperform generic shop shelf | **plausible, unmeasured** (M2 shows organic cross-sell; no A/B exists; M5 gives tone constraints) — a design bet to instrument, not a finding |

## 3. Telegram support evidence — what actually exists

Contrary to the handoff's fear of NO DATA: **real data exists and is already analyzed** —
privately. A genuine multi-year Telegram export (local-only, gitignored, PII-bearing, never
committed or quoted) has a committed **sanitized aggregate analysis in the private Uprava repo**
(data-derived taxonomy, near-complete LLM classification by Opus 4.8 `claude-opus-4-8`).

Qualitative conclusions safe to state here:

- Support volume is dominated by **logistics** (schedule, payment/price, recordings, Zoom/access, materials); genuine Sanskrit-content questions are a small minority. That is the strongest available argument that cabinet self-service has real deflection headroom.
- A substantial minority of question-like traffic is judged automatable by FAQ/bot surfaces.
- **⚠ Scope caveat (load-bearing):** the analyzed chat is the **internal curator/staff group**, not a student-facing channel. Shares measure what staff coordinate about — a proxy for, not a measurement of, student demand. It corroborates F4/F6/F10 but cannot rank student-side frequency.
- The app-side pipeline (`SupportTopicClassifier` + `SupportTopicRule`) is a-priori keyword code with an **empty, operator-populated rule table** — no seeded rules, no local DB. `support:topic-ranking` / `content:detect-gaps` yield numbers only against the production DB (not available on the dev machine). The private analysis and the app taxonomy are not yet connected.

## 4. Ranked student jobs (frequency × severity × business consequence)

Learning continuity and trust rank above short-term conversion by design (handoff mandate + M5).

1. **Continue exactly where I stopped** — RET/REC, every session; M1/M7; half-built (continue card), missing on course page and mobile ergonomics.
2. **Understand what I have, until when, and what it costs to keep it** — DEBT/LAP; F3/F4 critical; directly guards the repeat-payment artery.
3. **Get to today's/next live event without friction** — active cohorts; calendar + Zoom join exist; needs surfacing in the next-action layer, ≤30 s.
4. **Watch recordings comfortably at my own pace (mobile, 45+ eyes)** — REC; M6/M9; framing + mobile hierarchy fixes.
5. **Get unstuck (access/technical/how-to) without writing to a human** — all; F10/F15; logistics dominate support volume (§3).
6. **See my progress honestly and finish** — RET/DONE; F9; gates the legitimate offer moment.
7. **Take the sensible next step (block/course/recording/membership)** — the revenue job, deliberately ranked after the six trust jobs; M2/M3/M10; one contextual offer per state, suppressed during debt/frustration.
8. **Manage payments/documents myself** — payments tab is decent; add the abroad path (M8) and installment visibility (M5).

## 5. Prior recommendations (not re-derived)

The five audits carry 35 tickets total (A: T1–T7; B: T1–T6; C: 9; D: 6; E: 7). They remain valid
at the component level; this remake supersedes them **only at the architecture level** (they assume
the current one-mega-dashboard IA). Analytics tickets and E's event taxonomy are adopted as-is by
§6. Known @DECIDE items carried: «content complete» semantics (B/T4), the three access-self-service
decisions (D §4A), homework-policy wording, `birth_year` consent checkbox.

## 6. Measurement contract (instrumentation: needed vs present)

Present today: `LessonView` heartbeat (lesson page only), `last_activity_at` (60 s throttle),
`ActivityEvent` append log (sparse call sites), durable rows (payments, promises, homework,
certificates) that support **derived** funnels without new capture.

Needed (from E's canonical taxonomy, unchanged names): `continue_learning_clicked`,
`self_service_payment_started`, backend-fired `purchase` and `first_lesson_opened`;
deferred until their features exist: `locked_reason_viewed`, `recording_played`.
Remake adds (proposed, same naming style): `offer_impression` / `offer_click` / `offer_purchase`
(with `context` + `suppression_reason` dimensions), `access_expiry_viewed`, `renewal_started`,
`support_entry_opened` (with `topic` once a picker exists), `next_action_shown` (with `kind`).

Baseline/target plan: no lift claims without an experiment (M5's own numbers are correlational).
First 4 weeks after any ship = baseline capture only; targets set after baselines exist.
KPIs: continue-CTR, time-to-next-action, lesson completion, support-start topic mix,
access self-resolution rate, recording discovery, offer impression→click→purchase, attach rate,
renewal rate, refund/complaint rate, revenue per active learner.

## 7. Honest limitations

- Lesson-level dropoff inside courses: **no data** (prod-DB gated) — the oldest unclosed custdev item. Nothing here pretends otherwise.
- Buyer demographics rest on a small known-age slice; directional only.
- The Telegram shares are staff-side proxies (§3 caveat).
- All behavioral lifts are correlations; LLM-classified segment shares float between runs (direction stable, magnitude not).
- No student-side usability sessions/recordings exist; «overwhelm» findings are expert-audit judgments, not observed user behavior.

_Dr. Mārcis Gasūns_
