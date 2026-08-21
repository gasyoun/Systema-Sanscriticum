# PLAN — Moyklass gap: trial Deal + public book CTA (Systema-Sanscriticum, 2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

## Goal

Close the language-school CRM gap that [Мой Класс](https://moyklass.com/crm-dlja-jazykovyh-shkol) names as «пробное занятие → дожим» and «виджет записи», **for our school only**: keep the LMS and money truth, add a live-group trial object on existing `Deal` + `Schedule`, then a book button on the already-shipped public schedule iframe.

Not a Moyklass clone. Not a multi-tenant CRM. Not native apps this span.

## Layer docs

- [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_MOYKLASS_LIVE_GROUP_OPS_2026H2.md) — waves, non-goals.
- [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TRIAL_DEAL_PUBLIC_BOOKING.md) — Deal columns, Zoom fence, widget token.
- [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_TRIAL_DEAL_PUBLIC_BOOKING.md) — file-level DAG.
- [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TRIAL_DEAL_PUBLIC_BOOKING.md) — tests, smoke, risks.

## Prior-art (audit before the interview)

- [GetCourse-parity spec](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md): `Deal` is the work unit (GC-C1); Rank 4 never grants access.
- [CRM / Jivo architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_VISUALDCS_CRM_JIVO.md): lifecycle drafts only; telephony HOLD.
- `Course.trial_price` / `trial_lesson_id` / `trial_schedule_id` plus paid-trial checkout already exist. [NextIntroSession](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/NextIntroSession.php) already picks the next free-intro date. Gap is CRM state (booked / attended / no-show / converted), not a second shop SKU.
- [PaymentDealBridgeObserver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php) already opens a Deal on payable intent. Paid trial must **tag** that Deal, not open a second one.
- Public schedule feed + iframe already shipped ([H1427](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1427-Sonnet_Systema-Sanscriticum_public-schedule-widget-w1b_21.07.26.md)): [`PublicScheduleResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php) allowlist (no Zoom, no numeric ids), [`/widgets/schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicWidgetController.php), [`/api/public/schedule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/PublicScheduleController.php). Live `samskrtam.ru/raspisanie/` was **not** pasted — still a human in-chat go.
- `Group::min_size` / `activeUsers()` already exist (recruitment). Reuse for capacity.
- `FollowUpTask` already hangs on `Deal`. Zoom `webinar_attendances` already ingest joins.
- `hub_grep moyklass` was empty 21-08-2026 — this plan is the first comparison.

## Decisions taken (interview 21-08-2026)

Rounds 1–4 answered in chat. Round 5 (autonomy) was **declined**; recommended defaults are logged here and are binding.

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| 1 | Span “done” | Close **our-school** ops gaps; consume GetCourse + CRM/Jivo plans | Not productize Systema as SaaS |
| 2 | Product shape | **Hybrid**: keep LMS, add live-group language-school ops | CEFR/филиалы/parent LK stay out |
| 3 | Wave-1 cluster 1 | Trial as funnel object + auto follow-up | Highest conversion; reuses Deal + FollowUpTask |
| 4 | Wave-1 cluster 2 | Public booking CTA on existing schedule iframe | Moyklass widget without a new calendar |
| 5 | Wave size | Two handoffs, sequenced DAG | Cluster 2 depends on cluster 1’s book object |
| 6 | Primary user vs native apps | **Native apps out of wave 1** (overrides Q4 C) | Round 1 listed apps as out-of-scope; that list wins |
| 7 | Trial meaning | **Both** free intro seat **and** paid trial SKU, one type | `trial_source=free\|paid` |
| 8 | Data model | **Deal** with `kind=trial`, no new table | GC-C1 already forbade a fourth pipeline object |
| 9 | Free-seat access | Schedule-scoped Zoom only; **no** course unlock | Rank 4 fence; `LessonAccessGrant` / `course_group` untouched |
| 10 | Follow-up | Auto-create `FollowUpTask` **draft** only; no auto-send | Matches CRM Wave 2 “draft / human approval” |
| 11 | Flags | Two keys in `config/features.php`, both default **false**: `crm_trial_booking`, `crm_trial_widget_public` | Round 3 chose one flag; Round 4 chose staff-only enable — two keys reconcile them (Round 5 default A) |
| 12 | Schema | Real columns `kind`, `schedule_id`, `trial_source`, `trial_outcome` | Not JSON, not a DealStage named «Пробник» |
| 13 | Guest identity | Lead-first; email required; User only if already exists | Newsletter/support pattern; no password |
| 14 | Paid-trial observer | Tag the existing Deal; never a second Deal | H2102 path stays |
| 15 | Capacity | Reuse Group min_size / course cap; else unlimited + staff warning | No new capacity engine |
| 16 | Attended / no-show | Zoom attendance first; staff override; unmatched email stays `booked` + confirm task | Do not invent attendance |
| 17 | Tests | Feature tests + money fence + observer idempotency; extend `PublicScheduleFeedTest` | No Dusk gate; no live WP paste |
| 18 | Prod enable | Deploy code OFF. After staff smoke, flip **only** `CRM_TRIAL_BOOKING`. Widget POST stays 404 until `CRM_TRIAL_WIDGET_PUBLIC` | Standing default-OFF; not the known-path auto-deploy flip |
| 19 | Widget host | Extend `/widgets/schedule` iframe; no VK app; no new `/trial` page required | H1427 is the surface |
| 20 | Out of this programme | Native apps, IP telephony, WhatsApp, branches, parent login, public CRM API, warehouse, CEFR occupancy, attendance-payroll, visit-pack абонемент, tax/contract PDF | Named in Round 1 |

## Autonomy contract (Round 5 defaults — interview declined)

- **On ambiguity:** take the recommended default in this table, log it in the PR body under “unattended default”, continue. Do not mint a mid-build `@DECIDE`.
- **Halt** when: a change would grant course access or mutate Payment amounts/status; `PublicScheduleResource` would emit Zoom URLs or raw numeric schedule ids; watcher reverted the worktree; fence tests red. **Press on** through Filament copy nits, extra kanban badges, empty Zoom email match.
- **Commit:** session worktree off `origin/main`, watcher-safe commit, PR, merge `gasyoun/*` when green. Touching `PaymentDealBridgeObserver` → `/money-pr-land` marker, flags still default OFF. Do not direct-push Systema `main`.
- **Fence:** do not change `PaymentObserver::grantAccess()`, `LessonAccessGrant` rules, `TeacherSalaryService` formula, Jivo telephony packet, `CABINET_HYBRID`, live `samskrtam.ru` WordPress, or any `csl-*` repo. Observer may **tag** Deals only.
- **Executor:** Grok 4.6 (`grok-4.6`). Non-Fable. This run is the delivery — no Claude Code re-run residual.

## Autonomy-readiness gate

| Wave-1 deliverable | Architecture | Implementation steps | Acceptance | Risks named |
|---|---|---|---|---|
| Trial Deal + observer tag + FollowUpTask + Zoom reconcile | ARCHITECTURE §§1–4 | IMPLEMENTATION steps 1–8 | VERIFICATION C1 | unmatched Zoom email; double Deal |
| Public book CTA on existing widget | ARCHITECTURE §§5–6 | IMPLEMENTATION steps 9–12 | VERIFICATION C2 | allowlist leak; WP paste |

**Gate: PASS.** Zero blocking forks remain. Round 5 defaults are written. Prior-art is consume-not-rebuild.

## Execution handoffs

- [H3247 (Grok 4.6) — Wave 1 cluster 1: Deal.kind=trial, TrialBookingService, Zoom reconcile, FollowUpTask draft](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3247-Grok_Systema-Sanscriticum_trial-deal-kind-booking_21.08.26.md)
- [H3248 (Grok 4.6) — Wave 1 cluster 2: book CTA on /widgets/schedule after cluster 1](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3248-Grok_Systema-Sanscriticum_public-schedule-trial-book-cta_21.08.26.md) — blocked on H3247 merge

Starter lines:

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md and execute cluster 1 (trial Deal) only.
```

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md and execute cluster 2 (widget book CTA) only after cluster 1 is merged.
```

_Dr. Mārcis Gasūns_
