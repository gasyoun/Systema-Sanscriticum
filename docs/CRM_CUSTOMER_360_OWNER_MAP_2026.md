# CRM customer 360 — canonical owner map

_Created: 13-08-2026 · Last updated: 13-08-2026_

H2483 (Grok 4.6 `grok-4.6`) Wave 1 workspace. Compose only: no `customers` / `customer_events` mirror table.

## Owners

| Fact shown on 360 | Canonical owner | Read | Write from 360 |
|---|---|---|---|
| Contact / UTM / lead stage | `Lead` | `leads` (+ `lead_stages`) | No (edit via [LeadResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/LeadResource.php)) |
| Pipeline stage / deal owner | `Deal` | `deals` + `deal_stages` | Yes — only [`Deal::moveToStage()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php) |
| Next action | `FollowUpTask` | `follow_up_tasks` | Yes — `create` / `markDone()`. CRM = `deal_id`; support = `support_conversation_id` |
| Academic reminder | `ScheduledReminder` / `ReminderSuggestion` | not written here | No — 360 links out |
| Support topic / outcome | `SupportConversation` + `SupportConversationTopic` | latest topic row | No — Helpdesk `/admin/dialogs` |
| Payment / access | `Payment`, `PaymentPromise`, `course_user` | paid / pending / overdue promise | **Never** |
| Learning | `ActivityEvent` | last cabinet / lesson / VisualDCS event | No |
| Attribution | `Lead.utm_*` and/or `PartnerConversion` | first matching lead + conversion row | No |

Composer: [`CustomerTimelineService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Crm/CustomerTimelineService.php). Surface: [`Customer360`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Customer360.php) behind `crm_customer_360` (default OFF).

## Next-action order

1. Overdue `FollowUpTask` (CRM or support source kept on the row).
2. Overdue / expired `PaymentPromise` → recovery.
3. Open `SupportConversation` → close-topic / Helpdesk.
4. Open `Deal` without an open task → create contact.
5. Unconverted `Lead` without a paid payment → contact lead.
6. Paid / won and no cabinet learning → first-action nudge.
7. Paid / won, has learning, no open deal → repeat purchase.

## Fail conditions (must not ship)

- A customer/event table that copies owner facts.
- `deals.stage_id = …` outside `Deal::moveToStage()`.
- Re-deriving access or rewriting `payments`.

H2382 (Grok 4.5) production parity remains **HOLD** ([acceptance note](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SUPPORT_PARITY_PRODUCTION_ACCEPTANCE_2026-08-07.md)). Wave 1 only reads whatever support rows exist.

_Dr. Mārcis Gasūns_
