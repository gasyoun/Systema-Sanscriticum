# IMPLEMENTATION — Systema learner/CRM/Jivo sequence

_Created: 08-08-2026 · Last updated: 08-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).

## Wave L — native VisualDCS learning (one coordinated release)

1. Add three OFF-by-default flags and a pinned release configuration; no route or job is live yet.
2. Implement `VisualDcsReleaseImporter` with staging, schema/hash verification, atomic promotion and
   rollback. Commit complete+sparse fixtures from the VisualDCS producer contract.
3. Add `visualdcs_releases` and the import/rollback command; never rewrite a promoted release.
4. Add `external_learning_progress`, model and service with unique idempotency key and allow-listed
   object validation. Emit bounded `ActivityEvent` telemetry through the existing tracker pattern.
5. Implement a shared native cabinet shell and three adapters/controllers/views: verb trainer,
   nominal trainer, concordance→passage. All read the promoted catalog.
6. Wire public preview and canonical entitlement checks. Matrix-test unpaid, paid-full,
   paid-partial, expired/recovery and admin/impersonation cases.
7. Add progress resume, score/completion, duplicate retry handling and cabinet continue-learning
   links. No person-level export.
8. Browser-verify complete+sparse fixtures at 1440px and 390px; fix keyboard/focus/contrast/reduced
   motion/overflow before enabling any flag.
9. Promote all three surfaces in one release window but flip/rollback flags independently. Save the
   baseline and schedule 7/14/30-day reports.

Likely files: `config/features.php`, migrations/models under `ExternalLearning*`,
`app/Services/Learning/`, `app/Console/Commands/VisualDcs*`, `routes/web.php`, student controllers,
`resources/views/student/visualdcs/`, feature/browser tests and the cabinet baseline report.

## Wave S — school parity

Consume the existing H2381/H1200/H2382 deliverables. Do not edit their live worktrees from this
programme. School parity is code+flag+production-canary evidence, not merged-code inventory.

## CRM Wave 1 — customer 360

1. Inventory canonical read/write owners and define a timeline DTO; do not add a customer/event
   mirror table unless measured query cost proves it necessary.
2. Build `CustomerTimelineService` and a Filament workspace on the existing User/Lead/Deal records.
3. Surface stage, owner, next action, support topic/outcome, access/payment status and conversion
   attribution. Every field links back to its owner.
4. Add next-action create/complete through `FollowUpTask`; preserve CRM/support/academic semantics.
5. Test three own-data-shaped journeys: anonymous lead→paid learner, support→recovery, learner→
   repeat purchase.

## CRM Wave 2 — automation

1. Reuse Campaign/Recipient/MessageTemplate/suppression and current transports.
2. Add versioned lifecycle rules with dry-run counts, dedup/idempotency and human approval.
3. Start with three bounded journeys: uncompleted checkout, first-cabinet-action missing, and
   post-course next-product eligibility. Recovery suppresses offers; no auto-send by AI.
4. Report eligible/excluded/sent/delivered/clicked/paid denominators.

## CRM Wave 3 — forecasting

1. Lock stage probabilities and aging windows in config, never a Blade page.
2. Build forecast service on open Deals plus canonical qualifying Payment actuals.
3. Add manager/all-company views, next-action coverage and forecast-vs-actual.
4. Backtest on historical cohorts; if Deal history cannot reproduce a period, label it unavailable
   rather than reconstructing fictional history.

## Literal-Jivo Wave 4+

Produce the provider/legal/volume decision packet, then implement telephony/callback and later
departments/capacity routing as adapters to the existing inbox/customer timeline. Each activation
gets its own flag, approved canary and rollback.

_Dr. Mārcis Gasūns_
