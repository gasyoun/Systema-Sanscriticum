# CRM lifecycle rules — version contract

_Created: 13-08-2026 · Last updated: 13-08-2026_

H2484 (Grok 4.6 `grok-4.6`) Wave 2. Reuses `Campaign` / `CampaignRecipient` / `MessageTemplate` / `SuppressedEmail` / existing mail transport. No second campaign engine.

## Rule versions

Windows and copy live in [config/crm_lifecycle.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/crm_lifecycle.php). Bumping `version` is a new identity: v1 sent-recipients stay deduplicated from v1 only.

| Rule | v | Candidate | Eligible after gates |
|---|---|---|---|
| `uncompleted_checkout` | 1 | Qualifying `pending` Payment older than `min_age_hours` and inside `lookback_days`, no later qualifying paid on the same course | consent + email + not suppressed + not recovery + not already sent this rule+version inside `dedup_cooldown_days` |
| `missing_first_cabinet_action` | 1 | Qualifying paid Payment in `(grace_hours, lookback_days]` with no `ActivityEvent::FIRST_CABINET_ACTION` | same gates |
| `next_product_eligible` | 1 | Qualifying paid on a course that has an active successor (`predecessor_course_id`), first cabinet action present, no paid/pending on the successor | same gates |

Qualifying Payment = `is_conditional = false` and tariff not in `conversion.excluded_tariffs` (same denominator as [OrderPaymentConversionService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php)). Recovery is [RecoveryStateResolver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Cabinet/RecoveryStateResolver.php) — declined unrecovered payment or expired promise.

## Dry-run / live parity

`LifecycleEligibility::evaluate($rule)` is the only query. Dry-run (`crm:lifecycle-prepare`) and live send (`CampaignSegmentResolver` type `lifecycle`) both call it. A denominator fork between those two paths is a fail.

## Approval

`--apply` and the Filament page write `Campaign.status = draft` only. Send is the existing human «Отправить» on [CampaignResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/CampaignResource.php), which still requires `email_campaigns`. AI never sends.

## Idempotency

- Second `--apply` for the same rule+version reuses the open draft.
- `CampaignSender::send` skips an email that already has a `CampaignRecipient` on that campaign.
- `SendCampaignRecipient` no-ops when `sent_at` is already set.
- A sent recipient of this rule+version inside the cooldown is `deduplicated`, not eligible.

## Flag

`crm_lifecycle_automation` / `CRM_LIFECYCLE_AUTOMATION` default **OFF**. Dry-run works while OFF. `--apply` and `/admin/lifecycle-campaigns` require ON.

## Commands

```
php artisan crm:lifecycle-prepare --json
php artisan crm:lifecycle-prepare --apply --follow-ups
php artisan crm:lifecycle-prepare --report --json
```

_Dr. Mārcis Gasūns_
