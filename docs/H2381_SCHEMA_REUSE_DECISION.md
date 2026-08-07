# H2381 — schema / reuse decision (JIVO operator workflow)

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Model:** Grok 4.5 (`grok-4.5`) · handoff [H2381](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2381-Grok_Systema-Sanscriticum_jivo-operator-workflow-completion_07.08.26.md)

## Decision summary

| Concern | Reuse | Additive change | Not built |
|---|---|---|---|
| EdTech context panel | `Payment` paid tariffs, `Lesson::isUnlockedBy()`, `User::activeGroups()`, `Schedule`, `ClassAttendanceService`, existing payments/promises/discounts | Read-only `HelpdeskStudentContextService` (no new tables) | New access/identity tables |
| Required close topic | `SupportTopicRule` taxonomy categories | New `support_conversation_topics` history table (conversation-level); flag `support_required_close_topic` OFF | New topic taxonomy; forced false classification |
| Support follow-up | Existing `FollowUpTask` model/table | Nullable `deal_id`, `support_conversation_id`, `note`; flag `support_follow_up_tasks` OFF | `SupportFollowUpTask` table; academic `ScheduledReminder` conflation |
| CRM cockpit tasks | `WorkQueueReport::followUpTasksDue()` | Scoped with `FollowUpTask::forDeals()` so support rows never mix into deal queue | Shared flag with CRM |

## Owners reused

- **Money/access:** `payments.tariff` + `Lesson::isUnlockedBy(array $ownedKeys)` — same path as cabinet/API.
- **Groups:** `User::activeGroups()` (pivot `left_at` null).
- **Topics (taxonomy):** `SupportTopicRule.category` + explicit `other` / `uncategorized`.
- **Daily analytics topics:** still `SupportTopicAssignment` → `support_daily_rollups` (unchanged).
- **Operational thread:** `SupportConversation` + `SupportConversationManager`.
- **Follow-up object:** `FollowUpTask` (GC-C3 / H1836).

## Feature flags (default OFF)

- `support_required_close_topic` — close without topic remains valid while OFF.
- `support_follow_up_tasks` — Helpdesk follow-up UI + `SupportFollowUpService` gate.

EdTech context enrichment is read-only on the existing student-info modal (no flag); it does not mutate money/access.

## Migrations

1. `2026_08_07_190000_create_support_conversation_topics_table`
2. `2026_08_07_190100_add_support_conversation_to_follow_up_tasks`

_Dr. Mārcis Gasūns_
