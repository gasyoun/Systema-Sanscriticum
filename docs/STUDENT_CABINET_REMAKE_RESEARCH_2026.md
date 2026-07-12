# Student cabinet remake — evidence ledger and fresh audit (H822)

_Created: 12-07-2026 · Last updated: 12-07-2026_

Research phase of the evidence-led student-cabinet remake, [H822](https://github.com/gasyoun/Uprava/blob/main/handoffs/H822-Fable_Systema-Sanscriticum_student-cabinet-custdev-ux-remake_12.07.26.md).
Produced by Fable 5 (`claude-fable-5`), 12-07-2026, from: the five committed 2026 UX audits, a fresh
code-level IA reconstruction of the cabinet at commit `03b0a42`, the Uprava custdev corpus, and the
Telegram support-analytics assets. Companion doc: [STUDENT_CABINET_EDTECH_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md).

**What this doc adds over the five existing audits:** (1) a single evidence ledger that merges
UX-audit findings with the *money* evidence from custdev (the audits never priced their findings);
(2) the real page/state map per student situation, including the states the code silently lacks;
(3) the first honest reconciliation of the Telegram support data question (real data exists — with a
scope caveat no prior doc states this bluntly); (4) a ranked job list. It deliberately does NOT
re-derive the audits' ticket lists — those stand ([§ Prior recommendations](#prior-recommendations-not-re-derived)).

## Evidence strength legend

- **measured** — counted from a durable source (CRM ledger, bank statement, payment table, LLM-classified corpus with stated coverage).
- **observed in code/UI** — verified against the repo at `03b0a42` with file:line.
- **support-derived** — from the Telegram-export analysis ([Uprava/telegram-zabota-export/ANALYSIS.md](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/ANALYSIS.md)); see scope caveat in §3.
- **M.G. ruling** — an explicit recorded decision; the remake must honor it, not re-ask.
- **hypothesis** — plausible, unmeasured. Never to be presented as a finding.

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
| SRS `/dvaram/srs` | `SrsController` | Flash-cards — nav hidden, `config('srs.enabled')` OFF in prod. |

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
| Subscriber/member | Membership is priced (M.G. ruling: 2 000 ₽/мес · 20 000 ₽/год) but **not launched**; no cabinet surface exists. | future |
| Mobile (cross-cutting) | Off-canvas 280 px drawer + hamburger at all widths; no bottom nav. Tab bar has no overflow wrapper (crowds at narrow width); chat panel fixed 70vh; course-row action button `hidden sm:flex` → icon-only on mobile. **Cabinet loads Tailwind via CDN at runtime** — it is not on the Vite build pipeline the rest of the app uses. | debt |
| Support (cross-cutting) | Livewire chat = last dashboard tab, free-text only, 10 s poll; human escalation via magic phrase («позови куратора»), zero diagnostic context passed. The public-site chat widget (Reverb work, H536/H612) is NOT wired into the cabinet. Web-side FAQ doesn't exist (faq.md is bot-only knowledge). | burial |

## 2. Evidence ledger

Columns: finding · source · strength · segment/state · severity · frequency (where known) ·
revenue/retention consequence · proposed response class. Findings are merged across the five audits
(A = [STUDENT_CABINET_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md),
B = [FIRST_5_MINUTES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md),
C = [LEARNING_EXPERIENCE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md),
D = [SELF_SERVICE_SUPPORT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SELF_SERVICE_SUPPORT_UX_AUDIT_2026.md),
E = [TECHNICAL_ANALYTICS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TECHNICAL_ANALYTICS_UX_AUDIT_2026.md)),
the fresh code map (§1), and custdev (CU = [CUSTDEV_2026](https://github.com/gasyoun/Uprava/blob/main/custdev/CUSTDEV_2026.md),
UP = [UPSELL_ANALYSIS_2026](https://github.com/gasyoun/Uprava/blob/main/custdev/UPSELL_ANALYSIS_2026.md),
OB = [OBJECTION_PLAYBOOK_2026](https://github.com/gasyoun/Uprava/blob/main/custdev/OBJECTION_PLAYBOOK_2026.md),
RE = [REACTIVATION_PLAYBOOK](https://github.com/gasyoun/Uprava/blob/main/custdev/REACTIVATION_PLAYBOOK.md),
ME = [MEMBERSHIP_PRICING_2026](https://github.com/gasyoun/Uprava/blob/main/custdev/MEMBERSHIP_PRICING_2026.md),
TG = [telegram-zabota-export/ANALYSIS.md](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/ANALYSIS.md)).

### 2a. Money facts that must shape the architecture (measured)

| # | Finding | Source | Strength | Consequence for the cabinet |
|---|---|---|---|---|
| M1 | 74.7 % of payers pay more than once; **repeat payments = 93.8 % of all revenue**; first payment ≈ 6 % of money | ME/UP (5 028 payments, CRM ledger × Tochka, two independent sources agree) | measured | The cabinet IS the revenue engine — retention/continuation surfaces outrank acquisition polish. A cabinet that merely «shows courses» under-serves the business's strongest asset. |
| M2 | 44.4 % buy >1 course with **no in-product cross-sell mechanism at all**; 72 people bought 5+ courses | UP | measured | Cross-sell already happens *despite* the product. A relevance-gated «next step» layer harvests existing behavior, doesn't manufacture it. |
| M3 | Grammar-entry students (Kochergina) yield 4–6 courses, ₽134–166k LTV — highest-value ladder; yoga/chants convert easier but yield 2–3 | UP | measured | Discovery layer should bridge practice-entry students toward the grammar ladder, not push volume offers. |
| M4 | Median first-contact→payment = 14 days, p75 = 140 days; «silent day 3 ≠ declined» | OB | measured | No urgency mechanics anywhere; long-cycle nudges (recordings, own pace) beat deadlines. |
| M5 | Pressure/urgency wording measured at −7…−9 pp conversion; installments +8, benefit-price +7…+9; on-point fast answers 54 % vs evaded 36 % | OB (1 021 win/loss dialogs, base 52 %) | measured (correlational, no A/B) | Hard guardrail: no deadlines, no «N человек уже купили», no fake scarcity in ANY cabinet surface. Price always shown with the way to take it (poblock/installment/benefit). |
| M6 | «Time» is objection #1 in the corpus (285 mentions) ahead of price (252); recordings + own pace + lifelong access is the standard reassurance | OB (⚠ two-classifier caveat: corpus-wide vs asked-price cohort — don't mix) | measured | Recordings deserve first-class placement and «в своем темпе» framing; time-poverty is the top psychological barrier of the core 35–55 buyer. |
| M7 | Churn model: pause ≥6× personal cadence → 62 % don't return | CU (`churn_score.py` over prod data) | measured (model) | «Вернуться с места остановки» reactivation surface in-cabinet for the lapsed; rhythm-break, not calendar-time, is the danger signal. |
| M8 | Diaspora: 129 PayPal payments / 37 payers / ~$18.6k / avg cheque $151 ≈ 2× RU; converts higher than RU | CU | measured | Cabinet payment surfaces need the «оплата из-за рубежа» path + 2–3 timezones on schedule surfaces (M.G. ruling already covers the landing side). |
| M9 | Core buyer: women 35–55 (91.5 % of known ages are 35+), Moscow/SPb, mobile-heavy (VK reach 90.3 % mobile) — but age known for only 71/~1000 CRM records (7.1 %) | CU | measured-but-thin | Mobile-first is evidence-backed; typography/contrast/density calibrated for 45+ eyes is a defensible default, not a guess. |
| M10 | Membership priced 2 000 ₽/мес · 20 000 ₽/год as a **cheap bottom rung** for 25–34 + reactivation — NOT a whale premium | ME | M.G. ruling | The cabinet's discovery layer gets a membership slot only in lapsed/completion contexts, per the ruling's targeting. |

### 2b. Cabinet friction (deduplicated, priced where evidence allows)

| # | Finding | Source | Strength | State | Severity | Revenue/retention consequence |
|---|---|---|---|---|---|---|
| F1 | No unified «next action» layer; co-equal blocks compete (partially mitigated by PR #353's continue card) | A §3, B | hypothesis (A's own framing) + observed | FL/RET | primary diagnosis | Continuation is the #1 retention behavior (M1); every scan-second spent re-orienting is friction on the money path. |
| F2 | Onboarding checklist renders above the continue card; both say «начните курс» | B §2–3 | observed (`dashboard.blade.php` render order) | FL | medium | First-session activation → first lesson → first repeat payment chain. |
| F3 | Access expiry is a silent vanish — no expired state, no renewal CTA | fresh §1 (group-filter query); D | observed | LAP | **critical** | Directly severs the 93.8 % repeat-revenue path at its most dangerous moment. The user can't even see WHAT disappeared. |
| F4 | «Почему закрыто?» access diagnostics spec-only; locked cards name the block but not price/contents | D §2 («single largest gap»), C2 | observed | DEBT/REC | high | Access confusion = support tickets + abandoned renewals; D-category access questions are 1.5 % of support volume (TG, staff-side proxy). |
| F5 | No next-lesson signal on the course page itself | C1 | observed | RET | high (every return visit, 20+ lesson courses) | Same continuity money path as F1. |
| F6 | No live-vs-recorded distinction in lesson lists; recordings never framed «в своем темпе» | C3, C §4 | observed | REC | medium | M6: time-objection reassurance is the product's strongest message and the UI never says it. |
| F7 | Mobile lesson page: transcript below homework (priority inverted on the majority device) | C L1 | observed | REC/RET | medium | M9: mobile-heavy 35–55 core. |
| F8 | Notes: no autosave, no unsaved-guard → silent loss | C L2 | observed | RET | medium | Trust damage — the exact currency the strict-register buyer (highest cheque, OB §6) punishes. |
| F9 | Manual-only «Завершить», no watch-derived progress, false expectation set | C L3 | observed | DONE | low-med | Completion gates the contextual «next course» offer moment (M2/M3). |
| F10 | Support buried: last tab, free-text only, magic-phrase escalation, zero context | D | observed | all | high | Support quality ties to conversion (M5: on-point fast answers 54 % vs 36 %); support-derived automatable share ~22 % (TG, staff-side). |
| F11 | Messages red dot fake; unread is localStorage; announcements never marked read server-side | fresh §1 | observed | all | medium | Notifications are the reactivation channel (M7) and currently untrustworthy. |
| F12 | Zero cabinet analytics: no events on dashboard/course/messages; only lesson heartbeat + coarse `last_activity_at`; 22 `data-analytics` markers inert; dashboard tabs unlinkable so untrackable | E §2, fresh §1 | observed | — | high (for the *program*, not the user) | Every conversion claim of the remake is unmeasurable until this closes. Instrumentation-needed vs already-present split in E stands. |
| F13 | Cabinet not on the Vite build: Tailwind CDN at runtime | fresh §1 | observed | — | medium (perf/fragility) | Any remake that ships real components must first land the build-pipeline migration or inherit the CDN fragility. |
| F14 | Bot-connect cards render unconditionally forever; construction banner reappears cross-device; password button in primary header | B, A §5.5 | observed | FL/RET | low-med | Noise tax on every session of every segment. |
| F15 | No web-side FAQ; faq.md knowledge is bot-only | D | observed | all | medium | Self-service deflection (TG: FAQ-automatable ~22 % staff-side) has no web home. |

### 2c. Starting hypotheses from the handoff — verdicts

| Hypothesis | Verdict on current evidence |
|---|---|
| Students need a single obvious next action | supported (A/B audits + M1 money logic), and PR #353 already half-built it; the remake's job is to finish the prioritization, not invent it |
| Recordings/materials/schedule/access/payment states hard to locate or understand | supported for access (F3/F4 — strongest), partially for recordings (F6/F7); schedule and payments are actually decent (calendar + debts tab) |
| Support is buried | supported (F10) |
| Feature-rich dashboard overwhelms | supported as framing (F1), but the fix is prioritization + progressive disclosure, not feature removal — debt self-service and continue card are genuinely good |
| Core buyer mobile, 35–55 | supported (M9) with the 7.1 %-coverage caveat |
| Contextual continuation offers outperform generic shop shelf | **plausible, unmeasured** (M2 shows cross-sell happens organically; no A/B exists; M5 gives the tone constraints) — this is a design bet to instrument, not a finding |

## 3. Telegram support evidence — what actually exists

Contrary to the handoff's fear of NO DATA: **real data exists and is already analyzed.**

- **Corpus:** `Uprava/telegram-zabota-export/result.json` — 36 MB genuine Telegram Desktop export, gitignored/local-only, PII-bearing. Never committed, never quoted.
- **Committed sanitized aggregates:** [ANALYSIS.md](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/ANALYSIS.md) + [agg_full.json](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/agg_full.json) + [series.json](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/series.json). Window Oct 2023 → Jul 2026 (34 months); ~50 900 messages, ~16 962 question-like (33 %), 99.7 % LLM-classified (Opus 4.8 `claude-opus-4-8`, 85 batches) into a data-derived A–M taxonomy.
- **Topic shares (of question-like volume):** coordination/delegation ~41 % · FAQ-bot-automatable ~22 % (Zoom 2.7 %, recordings 2.4 %, schedule 5.3 %, payment/price 7.4 %, access 1.5 %, materials 2.7 %) · noise ~18 % · LMS-report ~10 % (paid/debts 4.9 %, roster 5.5 %) · LMS-action 5.9 % · Sanskrit content ~2.3 %. Net automatable ~38.5 %.
- **⚠ Scope caveat (load-bearing):** this chat is the **internal curator/staff group («Забота»)**, not a student-facing support channel. Shares measure what *staff coordinate about*, which is a proxy for — not a measurement of — what students ask. Directionally: schedule, payment/price, recordings, Zoom access dominate the automatable band, which corroborates F4/F6/F10 but cannot rank student-side frequency.
- **Separation of cabinet friction vs course content:** in the A–M taxonomy, cabinet-relevant categories (A Zoom, B recordings, C schedule, D payment, E access, F materials, G debts, I access-granting) sum to ~32 % of question-like volume; Sanskrit-content questions are only ~2.3 % — the support load is overwhelmingly logistics, not pedagogy. That is the strongest available argument that cabinet self-service has real deflection headroom.
- **The app-side pipeline** (`SupportTopicClassifier` + `SupportTopicRule`) is a-priori keyword code with an **empty, operator-populated rule table** — no seeded rules, no local DB. `support:topic-ranking` / `content:detect-gaps` yield numbers only against the production DB (not on this machine). The A–M analysis and the app taxonomy are not yet connected (S2 of the support roadmap proposes seeding one from the other).

## 4. Ranked student jobs (frequency × severity × business consequence)

Learning continuity and trust rank above short-term conversion by design (handoff mandate + M5 evidence).

1. **Continue exactly where I stopped** — RET/REC, every session; M1/M7; half-built (continue card), missing on course page and mobile ergonomics.
2. **Understand what I have, until when, and what it costs to keep it** — DEBT/LAP; F3/F4 critical; directly guards the repeat-payment artery.
3. **Get to today's/next live event without friction** — active cohorts; calendar exists, Zoom join exists; needs surfacing in the next-action layer, in ≤30 s.
4. **Watch recordings comfortably at my own pace (mobile, 45+ eyes)** — REC; M6/M9; framing + mobile hierarchy fixes.
5. **Get unstuck (access/technical/how-to) without writing to a human** — all; F10/F15; TG says logistics dominate support volume.
6. **See my progress honestly and finish** — RET/DONE; F9; gates the legitimate offer moment.
7. **Take the sensible next step (block/course/recording/membership)** — the revenue job, deliberately ranked after the six trust jobs; M2/M3/M10; one contextual offer per state, suppressed during debt/frustration.
8. **Manage payments/documents myself** — payments tab is decent; add abroad-path (M8) and installment visibility (M5).

## 5. Prior recommendations (not re-derived)

The five audits carry 35 tickets total (A: T1–T7 execution order; B: T1–T6; C: 9 tickets; D: 6 tickets;
E: 7 tickets). They remain valid at the component level; this remake supersedes them **only at the
architecture level** (they assume the current one-mega-dashboard IA). Analytics ticket E-1..7 and the
event taxonomy in E are adopted as-is by the measurement contract below. Known @DECIDE items carried:
«content complete» semantics (B/T4), the three access-self-service decisions (D §4A), homework-policy
wording, `birth_year` consent checkbox.

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

Baseline/target plan: no lift claims without an experiment (M5's own numbers are correlational and
say so). First 4 weeks after any ship = baseline capture only; targets set after baselines exist.
KPIs: continue-CTR, time-to-next-action, lesson completion, support-start topic mix,
access self-resolution rate, recording discovery, offer impression→click→purchase, attach rate,
renewal rate, refund/complaint rate, revenue per active learner.

## 7. Honest limitations

- Lesson-level dropoff inside courses: **no data** (prod-DB gated, H477 blocked on `~/.secrets/samskrte_db.env`) — the oldest unclosed custdev item. Nothing in this doc pretends otherwise.
- Buyer demographics rest on a 7.1 % age slice; directional only.
- The Telegram shares are staff-side proxies (§3 caveat).
- All behavioral lifts are correlations; segment shares from LLM classification float between runs (direction stable, magnitude not).
- No student-side usability sessions/recordings exist; «overwhelm» findings are expert-audit judgments, not observed user behavior.

_Dr. Mārcis Gasūns_
