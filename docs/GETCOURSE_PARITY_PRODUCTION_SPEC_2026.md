# GetCourse-parity — production spec (the R29-equivalent for the parity programme)

_Created: 18-07-2026 · Last updated: 29-07-2026_

The production ruling of the getcourse-parity programme, required by **R-1**
([PLAN §1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md)):
wave 1 must open with a design pass that produces the spec the programme never had. Authored by
Opus 4.8 (`claude-opus-4-8`), handoff
[H1144](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1144-Opus_Systema-Sanscriticum_getcourse-parity-production-spec-r29-equivalent_17.07.26.md).

**Analysis of record, consumed and not re-derived:**
[ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md)
(H438 — 4 domains, 14 tickets, 7 settled MG rulings). **Template:**
[STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
(R29), whose five production-making properties are enumerated in
[ARCHITECTURE §2.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md).

**What this document buys.** It makes wave 2 executable; it does not make any parity feature
built. GC-C1 is out of wave 1 by R-1. Do not read a merged spec as parity progress in the
product sense.

**Scope of depth (ruled in [ARCHITECTURE §2.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)).**
§1 covers all 14 tickets at table depth. §3–§4 reach **production depth for the wave-2 head only**
— GC-C1 and GC-C2. Quizzes and marketing sit two waves out and their inputs will have moved;
specifying them now would be speculative. R29 scoped itself identically (R29.6: "this one ladder
only").

**Verification provenance.** Every state in §1 was verified against the tree at `9b63861`
(fetched 18-07-2026), one read-only agent per ticket, and every non-`NOT_BUILT` verdict was then
re-checked by a second adversarial agent instructed to refute it. Two verdicts changed under that
audit and are marked ⚠ in §1. The states below are measurements, not restatements of the roadmap
— in three places they contradict it.

---

## 1. Composition — every GC-* ticket, its verified state, its wave

**State vocabulary** (the distinction that keeps this table honest):

| State | Means |
|---|---|
| **DONE** | The ticket's own claim is satisfied in the tree. |
| **PARTIAL** | Some of the ticket's own deliverables exist; the claim is not satisfied. |
| **NOT_BUILT** | None of the ticket's own deliverables exist. |
| **REMOVED** | It existed and was deliberately deleted; its return is a human decision. |

A ticket's **declared reuse base is not progress.** Most parity tickets name existing code they
would build on; counting that as partial delivery would make every reuse-heavy ticket PARTIAL by
construction. Only the ticket's *own* deliverables move it off `NOT_BUILT`. This rule is what
demoted GC-A3 (§1 ⚠) under audit.

| Ticket | Domain | Verified state (18-07-2026) | Wave | Evidence anchor |
|---|---|---|---|---|
| **GC-B2** attendance dashboard | B | **DONE** | — shipped | Flag [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) line 193, default `false`; [`AttendanceDashboard.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/AttendanceDashboard.php); `ClassAttendanceService::dashboard()`; 3 tests; commit `cfef7e2` = [PR #444](https://github.com/gasyoun/Systema-Sanscriticum/pull/444). Audit confirmed the ticket's falsifiable constraint (reuse `forSchedule()` row-wise, no new counting logic) holds. |
| **GC-B3** webinar provider seam | B | ⚠ **PARTIAL** (roadmap says "Later"; audit demoted from DONE) | 2 — finish | [`WebinarProvider.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Webinar/WebinarProvider.php), `ZoomService implements`, BBB skeleton, `meeting_*` alias columns — all shipped in `b4d8a2c` = [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549). **Three gaps:** no `webinar_provider_abstraction` flag; the container binding has **zero consumers** ([`ZoomWebhookController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/ZoomWebhookController.php) line 100 resolves the concrete `ZoomService`); no `services.bbb` block, so `isConfigured()` is structurally always false. |
| **GC-B1** one recurring Zoom meeting per course | B | **DONE** (26-07-2026, H1642) | 2 — shipped | Rescoped 19-07-2026 (MG, weekly `@DECIDE` → option (b)): auto-create ONE recurring meeting **per course**, never per `Schedule` — the single-course-link model (`eda8059`, 27-06-2026) stands. `ZoomService::createMeeting()` now makes a real Zoom API call (`type=8` recurring); trigger is the first schedule-stream generation (`ScheduleGenerator::generate()`) for a course without `zoom_meeting_id`, idempotent on that column. Flag `zoom_auto_create` (default `false`). Tests: `ZoomAutoCreateTest` (5) + rescoped `WebinarProviderSeamTest::test_create_meeting_requires_configured_credentials`. See §7 F1. |
| **GC-C1** `Deal` + kanban | C | **DONE** (25-07-2026, H1641) | 2 — shipped | F2 ruled by MG 21-07-2026 → separate `Deal` entity. Shipped: [`Deal`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php)/[`DealStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DealStage.php)/[`DealTransition`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DealTransition.php), `deals`/`deal_stages`/`deal_transitions` migrations, [`DealKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/DealKanbanBoard.php), [`PaymentDealBridgeObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php), flag `crm_pipeline_board` (default `false`). `LeadKanbanBoard`/`LeadStage` (H451) deliberately **untouched** — the one question this ticket did NOT resolve, opened as §7 F9 and **ruled + implemented 26-07-2026** (H1658): both boards stay, and a THIRD board [`UnifiedSalesBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnifiedSalesBoard.php) shows leads and deals in four shared columns ([`UnifiedSalesStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedSalesStage.php)) — view layer only, same flag, no migration. |
| **GC-C2** manager sales attribution | C | **NOT_BUILT** | **2** (§4) | `manager_sales_report` absent; `OrderPaymentConversionService` groups by course and channel only (lines 223, 246). Across the whole tree `assigned_to` is read by **zero** reports. |
| **GC-C3** `FollowUpTask` | C | **SHIPPED** (H1836, 29-07-2026, [PR #837](https://github.com/gasyoun/Systema-Sanscriticum/pull/837), [v1.66.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.66.0)) | — | [`FollowUpTask`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/FollowUpTask.php) висит на `Deal`, не на `Lead`; `WorkQueueReport` получил пятый бакет `followUpTasksDue()`. **F6 закрыт в пользу НОВОГО флага** `crm_follow_up_tasks` (default `false`): `crm_reminders` оставлен ровно тем, чем был — гейтом [`RemindLeadsForFollowup`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/RemindLeadsForFollowup.php), поведение которого закреплено регрессией в обе стороны. |
| **GC-D1** quiz engine, translit-aware | D | **NOT_BUILT** | 3 — head | No `Quiz`/`Question`/`QuizAttempt` model or table. Only grading code is `MarathonController::completeLevelQuiz()` (config-driven). **No runtime IAST/Cyrillic/Devanāgarī → SLP1 transcoder exists in `app/`** — SLP1 appears only as pre-computed data. See §7 F7. |
| **GC-D3** progress gating | D | **NOT_BUILT** | 3 | [`Lesson::isUnlockedBy`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php) lines 255–262 is still exactly the preview short-circuit plus the tariff-key check. No per-course opt-in column. Depends on GC-D1. |
| **GC-D2** homework scoring | D | **NOT_BUILT** | 3 | `HomeworkSubmission` `$fillable` carries status + review metadata only; no score/rubric column, no later ALTER. Nearest precedent for nullable decimal scores: the certificates exam-scores migration. |
| **GC-D4** auto-certificates + progress showcase | D | **NOT_BUILT** | 3 — tail | `CertificateService` is a 124-line PDF/JPEG renderer with zero issuance criteria; certificates are created by hand via a bare Filament `CreateRecord`. Quiz-pass half of the criterion has no substrate (depends on GC-D1). |
| **GC-A1** segment engine | A | **DONE** (25-07-2026, H1637) | 4 — head, shipped | `segments` migration + [`Segment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Segment.php) model + [`SegmentResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/SegmentResource.php) (Filament) behind `marketing_segments` (default `false`). Three built-in segments (`SegmentSeeder`) wrap `ReactivationReport`/`DebtorsReport`/`StuckStudentsReport` query-for-query; custom segments evaluate a typed, AND-combined `criteria` JSON column (group/last-activity/completed-lesson/tariff-owned/attendance/lead-status/debtor/UTM). 14 tests ([`SegmentTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SegmentTest.php)) including a boundary-rule guard (SQL-verb + row-count invariant) that fails if evaluation ever writes to ranks 1-5. |
| **GC-A2** unified channel router | A | **NOT_BUILT** | 4 | No `MessageRouter`, no per-user channel preference. `DebtorReminderDispatcher::send()` still takes three booleans and fans out. **But** `app/Services/Messaging/` already has a `DeliveryChannel` contract + manager keyed telegram/vk/max — the transport layer exists, the routing layer does not. |
| **GC-A3** campaigns with tracking | A | ⚠ **NOT_BUILT** (audit demoted from PARTIAL) | Later (Q4) | No `Campaign` model, no throttle, no open/click tracking, no per-campaign unsubscribe. `Announcement` channels + scheduler (H816) and `MessageTemplate` (H221) predate the ticket and are its named reuse base — not delivery. Both prerequisites (A1, A2) absent. |
| **GC-A4** linear automation flows | A | **NOT_BUILT** | Later (Q4) | `AutomationFlow` has only ever appeared as roadmap prose. The marathon drip remains hardcoded against `config/marathon.php`, exactly as the ticket describes the problem. |

**Wave map.** Wave 1 = this document (no parity feature ships — R-1's accepted cost).
**Wave 2 = GC-C1 → GC-C2**, plus the small GC-B3 finish. Wave 3 = GC-D1 → GC-D3 → GC-D2 → GC-D4 →
GC-C3. Wave 4 = GC-A1 → GC-A2. Later = GC-A3, GC-A4. GC-B1 shipped 26-07-2026 (H1642), outside the
numbered waves — it was rescoped and queued independently once F1 was ruled (see bookkeeping below).

This preserves H438 §4's ordering (cheap webinar wins → CRM → quizzes → marketing) and H438 §5.6's
ruling that CRM precedes quizzes. **GC-C1 as wave-2 head is a settled ruling and is not re-opened
here.** The only change to the roadmap's own sequencing is bookkeeping: B2 and most of B3 have
since shipped, so the "Now" bucket is spent.

**Bookkeeping (25-07-2026, H1637):** Wave 4 head (GC-A1) has shipped — see the composition table
above. It was built independently of Waves 2-3 (per MG's in-chat ruling), which were then still
stuck on GC-C1's Deal/kanban fork and GC-B1's Zoom-auto-create fork respectively; GC-A1 carried no
open fork of its own. Wave 4's remaining member, GC-A2 (unified channel router), is unaffected and
still `NOT_BUILT`.

**Bookkeeping (25-07-2026, H1641):** F1 and F2 turned out to have been **ruled by MG on the
20-07-2026 weekly `@DECIDE` sheet** (F1 on 19-07, F2 on 21-07) and simply never applied to these
docs — the week-long stall was a propagation gap, not an open question.
[`DECISIONS_roadmap_forks_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md)
§R2 is now marked superseded. **Wave 2 head GC-C1 has shipped** (this pass); GC-C2 is now the head
of the remaining wave-2 work. **GC-B1 has now shipped too** (26-07-2026,
[H1642](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1642-Sonnet_Systema-Sanscriticum_getcourse-gc-b1-zoom-recurring-meeting_25.07.26.md)) —
one recurring meeting per course, behind `zoom_auto_create` (default `false`). One genuinely new
fork opened in the process: **F9** (the fate of the now-parallel `LeadKanbanBoard`) — **ruled and
implemented 26-07-2026** (H1658): both boards stay, and the shared stage layer ships as an
additional third board, in the view layer only.

---

## 2. Precedence and boundary rules — what makes wave 2 executable

R29's load-bearing invention was **offer precedence**: one ordering that resolves every conflict
between organs, so a builder never escalates. The parity programme's equivalent is a **write-authority
ordering over the money core**. This section is the reason wave 2 can be executed by an agent; without
it, every wave-2 step escalates.

### 2.1 The money-core boundary rule

Stated as a rule, not an aspiration
([ARCHITECTURE §2.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)):

> **The `Deal` layer observes the money core and never authorises it.** `Payment` success closes a
> `Deal` (read by `PaymentObserver`'s existing hook point, additively). A `Deal` never grants access,
> never sets a price, never reverses a payment. If a `Deal` and a `Payment` disagree, the `Payment`
> is right and the `Deal` is stale. `Lead` keeps its current meaning (person/interest, 5 statuses,
> auto-convert on payment) untouched.

### 2.2 The parity precedence ladder — generalised to all 14 tickets

The rule above governs `Deal`. Generalised, it is the ordering that resolves any conflict between
parity organs. **Authority descends; writes never ascend.**

| Rank | Layer | Authority | May be written by a parity organ? |
|---|---|---|---|
| 1 | `Payment` · `Tariff` · revenue recognition | What was owed and what was paid. Final on every money question. | **Never.** Out of scope for every wave (PLAN §2.2). |
| 2 | Purchased access — `Lesson::isUnlockedBy` tariff-key check | What the student bought. | **Never widened.** |
| 3 | Pedagogical gate (GC-D3) | May **narrow** rank 2, never widen it; evaluated strictly after it. | Yes — narrowing only, per-course opt-in, flag-gated. |
| 4 | `Deal` (GC-C1) | Sales-process state. Observes ranks 1–2. | Yes — but never authorises. On disagreement with rank 1, rank 1 wins and the `Deal` is stale. |
| 5 | `Lead` | Person/interest. Meaning unchanged by this programme. | Only as ranks 1–4 already write it (see §2.4). |
| 6 | Reporting + marketing (GC-C2, GC-A1–A4) | Read-only over ranks 1–5. | **No writes to ranks 1–5, ever.** A segment, a campaign or an attribution report that mutates money, access or stage is a defect, not a feature. |

**The one-line form a builder needs:** *if a parity organ at rank N would write to a rank below N,
the design is wrong — stop and escalate.* GC-D3's already-ruled gate rule ("педагогический гейт
проверяется ПОСЛЕ денежного и никогда не расширяет доступ, только сужает") is rank 3 of this ladder;
the `Deal` rule is rank 4. They are the same rule at two ranks.

### 2.3 Where the bridge attaches — precisely

The rule says "`PaymentObserver`'s existing hook point". That point is
[`app/Observers/PaymentObserver.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentObserver.php)
**line 63**, the `if ($justBecamePaid)` block, alongside the two existing `rewardForPayment` calls
(the Tochka webhook `pending → paid` path). Secondary: **line 33**, the `created()` success block,
for rows born paid. Reversal mirror: **lines 71–76**, the `failed|canceled|cancelled` block.

Three facts a builder must have, none of which is in the roadmap:

1. **`PaymentObserver` does *not* grant access.** [`CLAUDE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)
   and [`README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/README.md) both say it
   calls `Payment::grantAccess()`. It does not — the observer does Sheet sync, referral/partner
   rewards and the revenue subledger. Access is granted from `Payment::booted()` →
   `fireOnPaid()` → `processSuccessfulPayment()` → `grantAccess()`. Those docs are wrong; a bridge
   written from them would attach to the wrong class.
2. **Firing order puts the observer last.** `Payment::observe()` boots the model first, so
   `Payment::booted()`'s closures run **before** `PaymentObserver`. By the time the bridge fires,
   access is already granted and groups synced. This is exactly what rank 4 requires: the `Deal`
   observes a completed money transition.
3. **The observer's success test is broader than the money path.** Lines 33/63 test status only, so
   they fire for **every** paid row — including the accounting rows `fireOnPaid()` early-exits on.
   A bridge must re-apply the exclusions itself: `isExpense()`, `isSalaryPayout()`, `isDeposit()`,
   `isTrial()`, `isMarathonPaid()`, and `! $payment->is_conditional`. The existing precedent for
   that exclusion set is `config('conversion.excluded_tariffs')`.

### 2.4 Two corrections to the rule's own parenthetical

The invariant in §2.1 is sound and is stated verbatim above. Its parenthetical description of `Lead`
is **stale against the tree**, and a builder who trusts it will be wrong twice:

- **"5 statuses" are no longer PHP constants.** `Lead::STATUSES` / `FINAL_STATUSES` were removed;
  stages are now DB rows in `lead_stages`, seeded by its create-migration and reachable via
  `Lead::statuses()` / `finalStatuses()` / `firstStageKey()`. There is no `stage_id` — `leads.status`
  is a string joined to `lead_stages.key`.
- **"auto-convert on payment" does not hold on the main path by default.** `Lead::markConverted()`
  is always called from deposit / trial / marathon paid paths (`Payment::markLinkedLeadConverted`).
  **A plain course payment converts its lead only when** `features.lead_converted_at_on_course_paid`
  is ON (env `LEAD_CONVERTED_AT_ON_COURSE_PAID`, default **OFF** — H2186; closes NOBORING Rate B
  instrumentation gap without inventing product conversion). With the flag OFF, prod behaviour is
  unchanged. A `Deal` bridge keyed on `lead_id` must not assume the lead's status reflects an
  ordinary purchase unless that flag is deliberately enabled.

Neither correction changes the invariant; both change what a builder must not assume while honouring it.

---

## 3. GC-C1 — production detail (`Deal` + kanban)

Wave-2 head. **A fresh agent should be able to start from this section without reading the H438
roadmap.** Sits next to the money core → Opus/Fable tier, adversarial review before merge.

### 3.1 What is already built, and what it is not

[`LeadKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/LeadKanbanBoard.php)
+ [`LeadStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/LeadStage.php)
(H451) already deliver the "настраиваемые стадии в данных + канбан drag-drop" half of the ticket —
**over `Lead`, not `Deal`**. That is not a defect of H451: it correctly executed the ruling in force
at the time. See §7 F2, which is a live contradiction and a human's to resolve. **This section
specifies the `Deal` shape; it does not presume F2's outcome, and a builder must read F2 first.**

Absent on every ref, ever: `Deal` model, `deals`/`deal_stages`/`deal_transitions` tables,
`crm_pipeline_board` flag, `PaymentObserver` → `Deal` bridge.

### 3.2 The model

`Deal` = one concrete potential sale. A person may have several (a second course, a tariff upgrade)
— which is precisely what `Lead` alone cannot express. Fields per
[ARCHITECTURE §2.4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md);
the data bill is §5.

Reuse, not invention:

| Need | Reuse | Note |
|---|---|---|
| Transition history | `LeadAudit` shape (`action` + JSON `changes`, **no FK** so rows outlive the parent) | `deal_transitions` is the ticket's named equivalent |
| Data-driven stages | `LeadStage` (`key`/`label`/`sort_order`/`is_final`/`color`, `scopeOrdered`) | Either a parallel `deal_stages` or a shared table — §7 F3 |
| Final-stage guard | `Lead::blocksRollbackToFirstStage()` | The single guard shared by all three Lead status writers |
| Board | `mokhosh/filament-kanban` **v2.11.0**, already required and locked | Confirmed present; the one existing board is `LeadKanbanBoard` — **not** a marathon board, contrary to the roadmap's aside |

### 3.3 The board — exact contract

`KanbanBoard` extends `Filament\Pages\Page`, so a board is an auto-discovered Filament page
(`discoverPages` in `AdminPanelProvider`; no registration entry). Copy `LeadKanbanBoard`'s shape:

- `$model`, `$recordTitleAttribute` (a model accessor — `Lead` uses `kanban_title`),
  `$recordStatusAttribute`, `$slug`, nav group `Продажи`, `public bool $disableEditModal = true`.
- **`$statusEnum` may be omitted** *only* when `statuses()` is overridden **and** the status column
  is not enum-cast — `filterRecordsByStatus()` calls `static::$statusEnum::from()` on enum-cast
  columns and will fatal otherwise.
- Override `statuses()` to read stages from the DB, returning `['id' => key, 'title' => label]`.
  **A `color` key is inert with the stock views** — `kanban-header.blade.php` never reads it.
  Colouring columns requires overriding `$headerView`/`$statusView`.
- Override `onStatusChanged()` for guards. The base implementation does a bare
  `find($recordId)->update([...])` with **no null check**; `LeadKanbanBoard` adds one and does not
  call `parent::`.
- Modal labels default to English (`'Edit Record'`, `'Save'`) — a Russian-UI board must override the
  getters, or keep `$disableEditModal = true` as `LeadKanbanBoard` does.
- Access gate: both `canAccess()` and `shouldRegisterNavigation()`, flag first then `RoleGate`
  (§6 house idiom).

### 3.4 The bridge

Attachment point, scope and exclusions: **§2.3**, which is normative for this section.

Two structural facts that make the bridge cheap:

- **`payments.lead_id` already exists** (fillable, `Payment::lead()` relation) — no migration on the
  payments side.
- **A separate observer is the house pattern for additive read-only chains.**
  `PaymentAuditObserver` and `PaymentTelemetryObserver` both exist precisely so that audit and
  telemetry are *not* mixed into `PaymentObserver`; `PaymentTelemetryObserver` is the closest
  template (constructor-injected dependency, `updated()` guarded by `wasChanged('status')`,
  `created()` catching born-paid rows, one shared private emitter). Note both use
  `wasChanged('status')` where `PaymentObserver` uses `isDirty('status')` — pick one deliberately
  (§7 F4).

Per H438: **open `Lead`s are not mass-converted.** A `Deal` is created going forward only.

---

## 4. GC-C2 — production detail (manager sales attribution)

Small ticket, one real design problem. Sonnet tier — **except** that §4.1 must be ruled by a human
before code (§7 F5).

### 4.1 The join problem — there is no manager column on `payments`

The roadmap says "добавить разбивку по `assigned_to`". `payments` **has no assignee column**, and
each candidate path is defective in a different way:

| Path | Status | Defect |
|---|---|---|
| `payments.lead_id` → `leads.assigned_to` | column exists | Populated on **only three paths** — deposit, trial, marathon. Ordinary course checkout never sets it. **And `deposit`/`trial` are in `config('conversion.excluded_tariffs')`** — so the rows that carry `lead_id` are exactly the rows excluded from the order denominator. Near-empty by construction. |
| `payments.user_id` → `users.lead_id` → `leads.assigned_to` | column exists | `users.lead_id` is set by `AttributionService` via **email match**, so attribution is only as good as that match. **Cheapest structural extension** — the channel breakdown already does `leftJoin('users', …)`. |
| `payments.created_by_user_id` | column exists | **Blame, not ownership.** Deliberately non-fillable; `null` for every self-serve checkout and webhook order; has **no read site anywhere** in the app. |

This is a genuine fork about what "the manager who closed the sale" *means*, not an implementation
detail — §7 F5. **Do not pick a path in code before it is ruled.**

### 4.2 What the extension costs once the path is ruled — very little

`OrderPaymentConversionService` is already dimension-agnostic where it matters:

- One canonical base builder `orders()` (lines 317–324) is the shared denominator for headline,
  both breakdowns and the unclosed list.
- `decorateBreakdown($rows, $keyField, …)` (lines 259–276) **already reads `$r->{$keyField}`** — a
  third dimension needs no change to it.
- The blade renders breakdowns as a **generic loop over a literal array of tuples**
  (`[$heading, $keyField, $colLabel, $rows]`). A third dimension is one more tuple, plus a layout
  decision on the `lg:grid-cols-2` wrapper.
- Cohort semantics are load-bearing and must be preserved: counted by **order creation date**, right
  boundary **half-open** (`>= $from`, `< $to`) so adjacent trend periods never double-count.

Clone the channel breakdown verbatim as the template, swapping the join and the group key.

### 4.3 Two adjacent facts

- **Visibility.** `OrderPaymentConversion` is gated `RoleGate::finance()` = ADMIN + ACCOUNTANT.
  **`MANAGER` is excluded, and a test locks that in.** A manager scoreboard modelled on this page
  would be invisible to the managers it scores — §7 F5 covers this as part of the same ruling.
- **Two pre-existing defects in that page**, found while specifying and *not* in this ticket's scope:
  the nav badge computes `count($snap['unclosed'])` on an associative array, so it always reads `4`;
  and the purpose-built `unclosedCount()` has no caller. Worth a separate small fix — do not bundle.

**No conflict with [ATTRIBUTION_FIELDS_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ATTRIBUTION_FIELDS_SPEC_2026.md).**
That spec governs *acquisition-source* attribution at registration and never mentions staff, manager
or `assigned_to`. Its two live constraints — no new form field without a conversion-cost argument
(§3), fixed-dictionary/no-free-text on PDn grounds (§4) — are not engaged by aggregating an
already-populated column. Its §1 caveat does carry over: `utm_source` sees only tagged links, so any
manager-vs-channel comparison inherits that blind spot.

---

## 5. Data / engineering bill

**Additive only. Nothing alters `leads`, `payments` or `tariffs`.** No collision: `deals`,
`deal_stages`, `deal_transitions` exist in none of the 108 tables.

```
deals
  id, lead_id -> leads.id, user_id -> users.id NULL, course_id -> courses.id NULL,
  amount, currency, stage_id -> deal_stages.id, assigned_to -> users.id NULL,
  closed_at NULL, closed_reason NULL ('won'|'lost'|...), timestamps
deal_stages
  id, key, name, position, is_won, is_lost
deal_transitions
  id, deal_id, from_stage_id NULL, to_stage_id, user_id, created_at
```

House migration conventions, verified against the directory (290/290 files):

1. `return new class extends Migration` — anonymous class, universal. No named-class migration survives.
2. A **docblock naming the handoff ID and the design rationale** — the strongest convention in the
   directory; every recent migration carries one.
3. `declare(strict_types=1);` in new files (present in 3 of the newest 5; not universal, but the
   direction of travel).
4. **`foreignId()->constrained()` exclusively** — `->references(`, `table->foreign(` and
   `foreignIdFor` have **zero** occurrences. Use `cascadeOnDelete()` for a required owner,
   `nullOnDelete()` for an optional one; `dropConstrainedForeignId()` in `down()`.
5. **Deliberate no-FK escape hatch for append-only journals** — `unsignedBigInteger(...)->index()`
   with a comment, so the log survives the parent's deletion. `lead_audits` and `promise_events` both
   do this. **`deal_transitions` should follow it** if transition history must outlive a deleted deal.
6. `$table->id()` first; `$table->timestamps()` for entity tables; a lone indexed `created_at` for
   journals; no `timestampsTz` anywhere (app timezone is pinned to `Europe/Moscow`).
7. **No soft deletes** unless argued — only two tables in the repo use them.
8. Never set charset/collation/engine; they inherit from `config/database.php`.
9. Tests run on **SQLite in-memory** — any MySQL-only feature needs a `getDriverName()` guard in both
   `up()` and `down()` (three migrations already do this for FULLTEXT).
10. Timestamp prefixes are hand-authored round times (`_120000_`); same-second collisions are tolerated.

Engineering beyond the tables: the observer bridge (§2.3/§3.4), a `DealKanbanBoard` (§3.3), one
breakdown dimension plus a blade tuple (§4.2), and per-ticket flags (§6). No new package —
`mokhosh/filament-kanban` v2.11.0 is already locked.

---

## 6. Flag plan

**One flag per ticket, every one default OFF.** Flag defaults are release policy, not an engineering
choice: changing any default other than the one a deliverable exists to fix requires a human
(PLAN §2.2).

House conventions, verified across the 20 existing keys:

- Shape: `'key' => (bool) env('UPPER_SNAKE', false),` in
  [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php).
  19 of 20 are `(bool)`; 18 of 19 booleans default `false`.
- Every flag carries a Russian block comment naming what it does, its owning `H###`/ticket, the
  default in caps, the exact OFF behaviour, and any second gate.
- Read via `config('features.x')` — **there is no facade and no Pennant**. Idioms: `abort_if(! …, 404)`
  for routes; flag-then-`RoleGate` in `canAccess()` for Filament pages; warn-and-return-`SUCCESS` for
  console commands.

| Ticket | Flag | Default | Notes |
|---|---|---|---|
| GC-C1 | `crm_pipeline_board` | `false` | ✅ Shipped 25-07-2026 (H1641). Gates **both** `DealKanbanBoard` and `PaymentDealBridgeObserver`'s write path — while OFF the bridge early-returns and not one `deals` row is written. Default pinned by `DealFlagDefaultTest` (config value + admin-visible surface), closing the §6 hygiene gap for this flag. |
| GC-C2 | `manager_sales_report` | `false` | |
| GC-C3 | `crm_follow_up_tasks` | `false` | **RESOLVED 29-07-2026 (H1836):** a NEW flag was minted rather than widening `crm_reminders`, which keeps gating only the reminder command that writes to people. §7 F6 closed. |
| GC-B3 | `webinar_provider_abstraction` | `false` | **Owed retroactively** — the seam shipped unflagged against the roadmap's blanket "всё за фича-флагом" rule. §7 F8. |
| GC-D1 / D2 / D3 / D4 | `quizzes` · `homework_scoring` · `progress_gating` · `auto_certificates` | `false` | |
| GC-A1 / A2 / A3 / A4 | `marketing_segments` · `unified_channel_router` · `marketing_campaigns` · `marketing_automation_flows` | `false` | `marketing_segments` **shipped 25-07-2026 (H1637)** — flag now live in `config/features.php`, default unchanged (`false`). |

Two hygiene facts a wave-2 handoff should carry:

- **No test pins any `features.*` default**, and `phpunit.xml` declares no feature-flag env key — so
  a developer's local `.env` can silently change what an un-overridden default resolves to under
  test. The house precedent for closing this is `SrsFlagDefaultTest` (config value **plus** the
  user-visible 404). Wave-2 flags should ship with that two-assertion shape.
- [`config/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/README.md)
  still claims "Сейчас флагов нет" while the file holds 20. Stale; fix in passing.

---

## 7. Open `@DECIDE` forks — named, none resolved

Naming forks is this document's job. **Resolving them is a human's** (PLAN §2.2). A spec with zero
forks would be a red flag: R-1's whole premise is that this programme is under-specified, so a pass
surfacing no open question would have skipped the analysis. Nothing below is pre-empted.

**F1 · GC-B1 — per-schedule Zoom auto-create vs. the single-link model. ✅ RESOLVED 19-07-2026
(MG, weekly `@DECIDE` sheet) → option (b): rescope to "auto-create ONE recurring meeting per
course", matching the shipped single-link model. ✅ BUILT 26-07-2026
([H1642](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1642-Sonnet_Systema-Sanscriticum_getcourse-gc-b1-zoom-recurring-meeting_25.07.26.md)).**
Per-schedule `ZoomService::createMeeting()` is NOT re-added and the 27-06-2026 rewrite stands —
`createMeeting()` now performs a real Zoom API call (`type=8` recurring), but the only caller is
`ScheduleGenerator::generate()` at the point where a course's schedule stream is first generated
and the course has no `zoom_meeting_id` yet; idempotent on that column. Flag `zoom_auto_create`
(default `false`). _Original framing, kept for the record:_

Auto-create was built and deliberately removed in
[`eda8059`](https://github.com/gasyoun/Systema-Sanscriticum/commit/eda8059e8ae16086ee0ef22f6b78c4a91def3b71);
implementing the ticket as written would silently revert a considered decision, and a regression test
now locks the removal. **New detail:** what was removed was a *manual admin action*
(«Создать Zoom-встречу»), never automatic-on-`Schedule`-creation — so the ticket as literally written
was never fully built even at its peak. A human must either re-confirm per-schedule auto-create and
say what the June single-link model got wrong, or reformulate the ticket (e.g. one recurring meeting
per course at stream generation). Until then it is out of scope.

**F2 · GC-C1 — two live decision records disagree about the shape, and the tree implements the
older one. ✅ RESOLVED 21-07-2026 (MG, weekly `@DECIDE` sheet) → option (a): the reversal is
confirmed, a separate `Deal` entity is built; §R2 marked superseded 25-07-2026 (H1641).** Built and
merged the same day — `Deal`/`DealStage`/`DealTransition`, `DealKanbanBoard`,
`PaymentDealBridgeObserver`, flag `crm_pipeline_board`. `LeadKanbanBoard`/`LeadStage` were left
**exactly as they were**: what becomes of them is NOT part of this ruling and is now tracked
separately as **F9** below. _Original framing, kept for the record — it is why this fork survived
a week:_

The most consequential fork in this document.

- [`Uprava/docs/DECISIONS_roadmap_forks_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md)
  §R2 (10-07-2026, H448) rules **"extend `Lead`"**, reasoning that a `Deal` entity would re-plumb
  `LeadResource`, the reports and the payment auto-convert path — and names `crm_pipeline_board` as
  the flag it unblocks.
- [ROADMAP §5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md)
  row 4 (commit `719d9d3`, 11-07-2026 00:01) rules **"отдельная сущность `Deal`, НЕ расширение
  `Lead`"** — MG's reversal, about fourteen hours later.
- H451 shipped `LeadStage` + `LeadKanbanBoard` at 10-07-2026 11:06, i.e. **between** the two. It
  correctly executed the ruling then in force.

R2 was never marked superseded and still reads as authoritative. So the tree contains a
production-wired, correctly-built implementation of the **rejected** architecture, and two governing
documents contradict each other. A human must decide: (a) confirm the reversal and specify what
becomes of `LeadKanbanBoard`/`LeadStage` — replaced, kept alongside a `Deal` board, or promoted to a
shared stage layer; or (b) re-confirm R2 and close GC-C1 as substantially delivered. **Either way,
mark the losing record superseded** — leaving both live is how this fork survived a week undetected.
Recommended for the CONTRADICTIONS registry regardless of outcome.

**F3 · Stage storage for `Deal`.** A new `deal_stages` table (per the ARCHITECTURE sketch) or a
polymorphic/shared reuse of `lead_stages`. The sketch says `deal_stages`; `lead_stages` already
solves the same problem and is joined by string `key`, not `id`. Note that `deals.stage_id` in the
sketch is an **id**, whereas `leads.status` is a **string key** — the two shapes are not compatible
as drawn, so this is not purely a DRY question. Downstream of F2.

**F4 · Bridge event predicate — `isDirty` vs `wasChanged`.** `PaymentObserver` uses
`isDirty('status')`; both additive observers added since (`PaymentAuditObserver`,
`PaymentTelemetryObserver`) use `wasChanged('status')`. A `Deal` bridge must pick one deliberately.
Small, but it is exactly the kind of silent divergence that produces a double-close or a missed
close under retry.

**F5 · GC-C2 — what "the manager who closed the sale" means, and who may see the scoreboard.** Two
halves of one ruling. (a) Which join path defines attribution — all three are defective in different
ways (§4.1), and the roadmap's implied `payments.lead_id` path is near-empty by construction.
(b) `OrderPaymentConversion` is gated to ADMIN + ACCOUNTANT with `MANAGER` excluded by a test; a
manager scoreboard on that gate is invisible to its own subjects. Whether managers see their own row,
everyone's, or none is a management decision, not an engineering one.

**F6 · GC-C3 — reuse `crm_reminders` or mint a new flag. ✅ RESOLVED 29-07-2026 (H1836) — new flag.**
The flag exists and today gates
`RemindLeadsForFollowup`. Promoting `next_contact_at`/`assigned_to` into a `FollowUpTask` under the
same flag silently widens what one switch controls; a new flag splits control but leaves two flags
for one feature. **Ruled in favour of the new flag** `crm_follow_up_tasks` (default `false`): the two
surfaces differ in *risk*, not just in scope — `crm_reminders` gates a command that autonomously
writes to people in Telegram, while `FollowUpTask` is a read-mostly operator object. One switch over
both would mean nobody can enable the task board without also arming outbound messaging. The "two
flags for one feature" cost is real but bounded, and is paid once at deploy time; it is documented in
[`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) so the
deployer is not left to guess. `RemindLeadsForFollowup` behaviour is regression-locked in both
directions (tasks ON + reminders OFF → no-op; tasks OFF + reminders ON → ping as before).

**F7 · GC-D1 — where translit-aware answer checking gets its transcoder.** The roadmap defers this
("порт в PHP или вызов через внутренний сервис — решить при сборке"), and it is still open. **No
runtime transcoder exists in `app/`** — SLP1 appears only as pre-computed data fields, so this is
build-from-nothing, not wire-up. Compounding it, the canonical ecosystem converters carry known
defects ([`SHARED_CODE.md`](https://github.com/gasyoun/github-spine/blob/main/SHARED_CODE.md) §1:
`devanagari_to_slp1` ळ→x; `iast_to_devanagari` broken). Two waves out, but naming it now stops a
wave-3 session from assuming a transcoder is at hand.

**F8 · GC-B3 — is the flag owed retroactively, and should the seam get a live consumer?** The seam
shipped without `webinar_provider_abstraction`, against the roadmap's blanket rule that everything
sits behind a flag. Defensible (the default driver is still Zoom and the webhook parse is
byte-identical) — but the binding currently has **zero consumers**, so the abstraction is inert: the
webhook resolves the concrete `ZoomService`, and `services.bbb` does not exist, making
`isConfigured()` permanently false. Either finish it (flag + `services.bbb` + point consumers at the
abstraction + flip the roadmap row) or record deliberately that the seam is schema-and-interface only
until BBB lands in Q4. The roadmap row still says "Later" while the CHANGELOG and DEPLOY_QUEUE record
it as shipped — those disagree today.

**F9 · What becomes of `LeadKanbanBoard` now that a `Deal` board exists? RULED 26-07-2026 by MG →
(a) + (c) additively; IMPLEMENTED the same day by [H1658](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1658-Sonnet_Systema-Sanscriticum_crm-unified-stage-board-alt-ui_26.07.26.md).**
The ruling keeps **both** existing boards — «Заявки — доска» and «Сделки — доска» stay exactly as
they are, neither retired nor hidden — and additionally builds the shared-stage layer as an
**alternative third UI**. It is explicitly *not* option (b), and *not* the destructive reading of
option (c): there is **no physical merge** of `lead_stages` and `deal_stages`. That merge is fork
**F3**, already settled in favour of separate tables — the string `key` ↔ numeric `id`
incompatibility would need a migration touching live `leads`. The unification lives in the **view
layer only**; no migration of any kind was made, and `leads.status`, `lead_stages`,
`LeadResource`, `Lead::statuses()` and `RemindLeadsForFollowup` are untouched.

Shipped: [`UnifiedSalesBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnifiedSalesBoard.php)
(slug `sales-board`, nav group «Продажи», sort 70 — above both existing boards) over
[`UnifiedSalesStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedSalesStage.php),
a view-only vocabulary of four common columns — Новые · В работе · Выиграно · Проиграно — that maps
each side's stages on and is **never persisted into either stage table** (pinned by
`UnifiedSalesBoardTest::the_common_vocabulary_is_never_persisted_into_either_stage_table`). Deal
stages map **structurally** from live data (`is_won` → Выиграно, `is_lost` → Проиграно, first by
`position` → Новые, rest → В работе), so an admin-added stage needs no code change; lead stages
need an explicit key table because `lead_stages` carries only `is_final` and cannot tell
«Конверсия» from «Отказ». An unknown stage on either side falls back to «В работе» rather than
vanishing from the board. Cards carry a «Заявка»/«Сделка» badge and a composite DOM id
(`lead-12` / `deal-12`) because the two key spaces overlap. Drag-drop writes back to the owning
entity — `leads.status` directly, deals through `Deal::moveToStage()` so `deal_transitions` keeps
its journal — and both `blocksRollbackToFirstStage` guards refuse exactly as on the single boards.
A move *within* one column (e.g. «Квалифицирован» → «В работе») is a deliberate no-op, so a merged
column cannot silently demote a card or write a spurious transition row. Gated behind the same
`crm_pipeline_board` flag plus the same `RoleGate` — **no new flag**, this is the same GC-C1
surface. Tests: `UnifiedSalesBoardTest` (15).

_Original framing, kept for the record — it is why this fork existed._ F2's ruling settled the
*architecture* (build `Deal`)
but said nothing about the board H451 already shipped. The tree now carries **two** drag-drop
boards in the same `Продажи` nav group: «Заявки — доска» over `Lead.status`/`lead_stages`
(unflagged, live) and «Сделки — доска» over `Deal.stage_id`/`deal_stages` (behind
`crm_pipeline_board`, default OFF). That is coherent — a lead is a person/interest, a deal is one
potential sale, and one person may have several — but it is two boards a manager must learn, and
nobody has ruled whether that is the intended end state. Three options, all cheap while the new
board is still flag-OFF: **(a)** keep both permanently and document the division of labour in the
UI; **(b)** retire `LeadKanbanBoard` once `Deal` proves out, leaving `LeadResource`'s flat table
for lead triage; **(c)** promote stages into one shared layer serving both. Not urgent — nothing
is broken and no data is at risk — but it should be ruled before `crm_pipeline_board` is switched
on in production, otherwise managers meet two boards with no guidance. Mirrored to
[`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)
as an `MG @DECIDE` row.

**RULED 26-07-2026 — both questions of that row, rulings R5/R6 in
[`Uprava/docs/DECISIONS_roadmap_forks_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md).**
(1) *Boards:* **(a)+(c) additively** — both existing boards stay untouched; the shared layer is
built as a SEPARATE alternative UI, a third view. No physical merge of `lead_stages`/`deal_stages`
(that is fork F3, whose migration would touch live `leads`) — unification in the presentation
layer only → H1658, **implemented the same day**; the shipped shape is described at the head of
this fork. (2) *Instalments:* **explicit marker.** The H1641 mitigation ("a won deal for
this person + this course already exists → the second payment creates nothing") suppressed genuine
repurchases along with instalment inflation; it is replaced by the `Payment` →
`linked_promise_id` → `PaymentPromise` → `installment_group_id` chain, with the resolved group
materialised on an additive nullable `deals.installment_group_id` → H1659. Neither ruling flips
the prod flag: `crm_pipeline_board` stays default OFF.

---

## 8. Sequence — one handoff per step

Each step is its own handoff at execution time, minted when the step starts. Tier is set by
proximity to the money core, not by size.

| # | Step | Tier + why | Gate |
|---|---|---|---|
| 1 | **Resolve F2** (GC-C1 shape) and mark the losing decision record superseded | **Human** | Blocks steps 2–4. Nothing below may start on a guess. |
| 2 | GC-C1a — `deals`/`deal_stages`/`deal_transitions` migrations + `Deal` model + flag | **Opus/Fable** — new entity adjacent to the money core; §5 conventions are unforgiving | F2 |
| 3 | GC-C1b — `DealKanbanBoard` + stage guards (§3.3) | **Sonnet 5** — a well-trodden Filament page, `LeadKanbanBoard` is the template | step 2 |
| 4 | GC-C1c — `PaymentObserver` → `Deal` bridge (§2.3, §3.4) | **Opus/Fable** — touches the money core's event chain; **mandatory adversarial review before merge** | step 2 |
| 5 | **Resolve F5** (attribution path + visibility) | **Human** | Blocks step 6 |
| 6 | GC-C2 — `assigned_to` breakdown + Filament page | **Sonnet 5** — one dimension into a dimension-agnostic service | F5; step 4 if keying on `Deal` |
| 7 | GC-B3 finish — flag, `services.bbb`, point consumers at the abstraction, flip the roadmap row | **Sonnet 5** — mechanical, but see F8 | F8 |
| 8 | Wave-3 opening: GC-D1 spec pass (the transcoder question, F7, is a design problem before it is a build) | **Opus/Fable** | F7 |

Steps 2–4 are independently landable and share no file with step 6 or 7. **One deliverable = one
branch = one PR; do not bundle.** Systema runs a watcher — use
[/watcher-safe-commit](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md)
for every commit, never commit in the main tree, and never `git pull`.

---

## 9. What this spec deliberately does not do

- **It does not re-open settled rulings.** H438 §5's seven rulings and §6's non-goals stand. GC-C1
  remains the wave-2 head (§5.6). The site-builder-for-other-schools non-goal remains struck forever.
- **It does not resolve any fork.** §7 names eight and rules on none.
- **It does not reach production depth outside GC-C1/GC-C2.** Wave-3 and wave-4 tickets have table
  rows and reuse pointers, not designs — deliberately, per
  [ARCHITECTURE §2.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md).
- **It ships no code.** R-1 buys a spec; the parity feature count after wave 1 is zero.

_Dr. Mārcis Gasūns_
