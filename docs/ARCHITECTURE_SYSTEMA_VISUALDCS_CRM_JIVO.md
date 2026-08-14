# ARCHITECTURE — native VisualDCS learning, CRM and full-Jivo layers

_Created: 08-08-2026 · Last updated: 14-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).

## 1. Verified external-learning import

`VisualDcsReleaseImporter` reads a pinned manifest, validates schema/version/hash/size, stages the
new release, then promotes it atomically. The previously promoted release remains the rollback.
No request-time fetch from VisualDCS `main` is allowed.

Suggested ownership:

- `resources/data/visualdcs/<release-id>/` — verified immutable payloads and manifest;
- `visualdcs_releases` — release ID, version, manifest hash, status, promoted/rolled-back times;
- `ExternalLearningCatalog` — read-only adapter over the promoted payload;
- one feature flag each for verb, nominal and concordance→passage.

## 2. Native learner surfaces and progress

Routes live under the existing `/dvaram` cabinet. Public previews use bounded records from the same
catalog; full routes call canonical course/tariff access services. Never infer payment state from a
contract field.

Existing `lesson_progress` is lesson-specific and `ActivityEvent` is append-only telemetry. Add the
smallest generic projection required for durable cross-device state:

```text
external_learning_progress
  user_id
  provider = visualdcs
  object_id = vdcs:v1:...
  surface = verb|nominal|passage
  status = started|completed|mastered
  score? attempts
  first_started_at last_seen_at completed_at?
  metadata JSON (bounded, non-authoritative)
  UNIQUE(user_id, provider, object_id)
```

`ExternalLearningProgressService` validates the object against the promoted catalog, performs an
idempotent upsert and emits allow-listed `ActivityEvent` rows for aggregate reporting. The
projection is current state; `ActivityEvent` is the audit/measurement stream.

## 3. School-operational JIVO parity

The existing support owners remain canonical: `SupportConversation`, `UnifiedInboxReader`, reply
router, identity mappings, topics, follow-ups and operator context. H2381/H1200/H2382 close and
prove the school-inbox boundary before CRM automation consumes its outcomes.

## 4. CRM waves on existing owners

### Wave 1 — customer 360

`CustomerTimelineService` composes—without copying—Lead/Deal/Payment/access/group/activity/support/
campaign events. A Filament customer workspace shows current pipeline stage, responsible operator,
next `FollowUpTask`, last/next contact, support outcome and attributable conversion. Writes go only
through existing owner services.

### Wave 2 — lifecycle automation

Reuse `Campaign`, `CampaignRecipient`, `MessageTemplate`, existing channel transports, suppression,
attribution and follow-ups. A small rules layer maps audited lifecycle transitions to a draft or
approved campaign/follow-up. Default is draft/human approval; no AI or rule auto-sends by default.

### Wave 3 — forecasting

`SalesForecastService` reads open Deals and the same qualifying Payment denominator used by
conversion reporting (`OrderPaymentConversionService::qualifyingPaidRevenue()`). It publishes
pipeline-weighted forecast, stage aging, next-action coverage, manager view and
forecast-vs-actual. Probabilities live in `config/crm_forecast.php`. No invented revenue rows
and no denominator fork. Shipped H2485; flag `crm_sales_forecast` default OFF.

## 5. Literal-Jivo expansion after CRM

✅ H2486 packet: [PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md).
**HOLD** — do not activate PSTN / departments / capacity routing.

Telephony is a channel adapter onto `FollowUpTask::TYPE_CALL`, `CallEvent` and
`CustomerTimelineService`. Identity stays on `social_accounts` + `users.phone`.
Jivo VATS is Voximplant resale with a Jivo-owned default AON — rejected as a
second inbox. Flags `telephony_callback_request`, `telephony_pstn`,
`telephony_recording`, `support_departments`, `support_capacity_routing` default
OFF. Thresholds live in `config/telephony.php`.

_Dr. Mārcis Gasūns_
