# Support subsystem — what actually exists (agent reference)

_Created: 01-07-2026 · Last updated: 01-07-2026_

> **Update 01-07-2026 (Step 1 done):** the naming landmine below is **resolved in code** — the daily-rollup model is now [`SupportDailyRollup`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportDailyRollup.php) (table `support_daily_rollups`, FK `support_daily_rollup_id`, aggregator `SupportDailyRollupAggregator`). The name `SupportConversation` is now **free** for the future operational thread. Display vocabulary (`conversations()` relation, `conversation_date` column, dashboard `conversations` key) was intentionally left as-is.

> Companion to [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md).
> jivo.md is product strategy benchmarked against Jivo; **its "current state" claims are unreliable** (sourced from Jivo help pages, not the repo). This file is the ground truth: what the support code actually is, verified against models on `main`. Read this before building anything in the support area so you don't rebuild what exists or trip a naming landmine.

## TL;DR for the next agent

- A model named `SupportConversation` **already exists and is NOT an operational thread** — it's a per-day analytics rollup. Do not build "the conversation model" under that name.
- Topic taxonomy, AI event logging, responder/owner mapping, and per-message `ai_state` **already exist** on the Telegram side. The gap is the *web* side and *unification*, not absence.
- External identity is stored **three+ different ways that don't reconcile**. The task is consolidation, not greenfield creation.

## Two separate message stores (this is the core fact)

There is **no unified inbox.** Support messages live in two structurally different tables:

| | [`ChatMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/ChatMessage.php) — web chat | [`TelegramSupportMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/TelegramSupportMessage.php) — imported TG support account |
|---|---|---|
| Identity | `user_id` | `telegram_support_contact_id` → `linked_user_id` |
| Grouping | **none** (flat log per user) | `telegram_support_chat_id` |
| Direction | inferred from `role` | explicit `direction` |
| Owner | `answered_by` | `responder_user_id`, `responder_type`, `responder_marker` |
| AI state | none | `ai_state` |
| Raw source | none | `raw_payload` (array) |
| Status | `is_read` only | none (status lives in the rollup) |

`TelegramSupportMessage` is the richer, more normalized of the two. If you unify, it is the *target shape*; `ChatMessage` is what needs upgrading (add grouping, `direction`, `responder_type`, `ai_state`). **Do not merge the tables** — channels differ; build a read layer over both.

## The `SupportConversation` naming landmine — RESOLVED (Step 1, 01-07-2026)

[`SupportDailyRollup`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportDailyRollup.php) (formerly `SupportConversation`) belongs to a `TelegramSupportChat` and holds `conversation_date`, `incoming_count`, `outgoing_count`, `human_reply_count`, `ai_sent_count`, `is_unanswered`, `first_response_seconds`. **It is a daily metrics aggregate, not an operational case.**

The model, its table (`support_daily_rollups`), the FK on topic assignments (`support_daily_rollup_id`), and the aggregator service (`SupportDailyRollupAggregator`) were renamed so the clean name **`SupportConversation` is now free** for the future reopenable operational thread. Its semantics (one row = one chat × one day) remain incompatible with a reopenable thread — build the thread as a new object, don't overload the rollup.

## Support infra that ALREADY exists (don't rebuild)

| Concern | Model | Notes |
|---|---|---|
| Topic taxonomy | [`SupportTopicRule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportTopicRule.php) (`category`, `keywords[]`, `priority`), [`SupportTopicAssignment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportTopicAssignment.php) | keyword→category rules; assignments hang off the daily rollup |
| AI event log | [`SupportAiReplyEvent`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php) (`event_type`, `meta`) | per Telegram message |
| Owner / responder | [`SupportResponderMapping`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportResponderMapping.php) (`marker_label`→`user_id`), `responder_user_id` on messages | how a marker resolves to a curator |
| AI handoff state | `ai_state` column on `TelegramSupportMessage` | exists Telegram-side only |
| Import account | [`TelegramSupportAccount`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/TelegramSupportAccount.php) (`sync_state`, `last_sync_error`) | the imported support account, integrated models — **not** a separate microservice/subdirectory |

The whole `TelegramSupport*` stack lives **inside the main Laravel app**, sharing the DB. There is no `telegram-support-analytics` directory despite jivo.md calling it a separate layer.

So jivo.md's "missing" items — topics, owner, AI layer — are missing **only on the web-chat side**. The real gap is asymmetry between the two stores, not absence.

## External identity: three+ unreconciled mappings

The same person (e.g. one Telegram user) can be represented in all of these, with nothing joining them:

| Mapping | Where | Purpose |
|---|---|---|
| `telegram_id`, `vk_id`, `max_user_id` columns | [`User`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/User.php) (~lines 33–42) | **outbound** bot sends — `User::sendTelegramMessage()` uses `telegram_id` as `chat_id` |
| `SocialAccount` (`provider`, `provider_id`, `user_id`) | [`SocialAccount`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SocialAccount.php) | OAuth **login** |
| `TelegramSupportContact.telegram_user_id` → `linked_user_id` | [`TelegramSupportContact`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/TelegramSupportContact.php) | **analytics import**; a `saved()` hook propagates `linked_user_id` up to [`TelegramSupportChat`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/TelegramSupportChat.php) |

`SocialAccount`'s `(user_id, provider, provider_id)` shape is effectively a generic external-identity row already — the natural consolidation target. The denormalized `users.telegram_id` exists deliberately so outbound sends avoid a join; weigh that before "normalizing" it away.

**Before any omnichannel work, document one identity story.** Adding jivo.md's proposed `external_identity` table verbatim would create a *fourth* mapping — the exact identity-chaos failure its own roadmap warns about.

## Open decisions (the ones grounded in code)

1. **Operational conversation object** — define it as a reopenable thread keyed to user/identity. ✅ Name collision resolved (Step 1): `SupportConversation` is free; use it for the thread.
2. **Unification strategy** — ✅ read layer built (Step 2): [`UnifiedMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedMessage.php) DTO + [`UnifiedInboxReader`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/UnifiedInboxReader.php)`::forUser()` merge both stores chronologically. No table merge. `ai_state` added to `chat_messages`; `direction`/`responder_type` are **derived from `role`** in the DTO (not stored — `role` stays the single source on the web side). Still open: a write path and thread grouping (come with the operational-thread object).
3. **Identity reconciliation** — ✅ resolved (Step 3): [`social_accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_25_130000_create_social_accounts_table.php) is canonical; see [docs/support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md). Backfill: [`identity:backfill-social-accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ConsolidateSocialIdentities.php) (dry-run default, idempotent, non-clobbering). Denormalized `users.telegram_id/vk_id/max_user_id` kept as outbound caches. No 4th table.
4. **AI scope** — ✅ done: [`SupportAiService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAiService.php) adds the two genuinely-missing functions (`suggestReply`, `summarize`) over the unified thread, logging each to the existing `SupportAiReplyEvent` (`suggested`/`summary`). Behind `features.support_ai_assist` (off). Reuses `CuratorAi::chat()`; never auto-sends.

## What now exists on top of the two stores (integration wave, 01-07-2026)

The read layer, operational thread, reply router, and AI assist were all built. Current state:

| Concern | Where | Notes |
|---|---|---|
| Unified read | [`UnifiedInboxReader`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/UnifiedInboxReader.php) + [`UnifiedMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedMessage.php) | merges both stores per user; DTO carries presentation helpers |
| Operational thread | [`SupportConversation`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportConversation.php) + [`SupportConversationManager`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportConversationManager.php) | one reopenable thread per user; `support_conversation_id` FK on both message tables |
| Curator UI | [`Helpdesk`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) | shows both channels in one stream (channel badges + thread status); sidebar includes TG-linked users |
| Reply routing | [`SupportReplyService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportReplyService.php) | flag `support_unified_reply` (off); TG-support delivery via userbot wired ([`DeliverSupportReply`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/DeliverSupportReply.php)) |
| Userbot send | [`TelegramSupportSyncService::deliverMessage()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php) | MadelineProto `messages.sendMessage`; queued job marks the record delivered + sets the real `telegram_message_id`. Only dispatched when `TELEGRAM_SUPPORT_ENABLED` |
| AI assist | [`SupportAiService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAiService.php) | flag `support_ai_assist` (off). **Privacy:** imported Telegram DMs are sent to OpenRouter only when `support_ai_include_telegram` (off) is also set — default context is web-chat only |

**Fully wired.** Enabling the imported-TG reply path end-to-end needs: `features.support_unified_reply=true`, a configured+logged-in userbot (`TELEGRAM_SUPPORT_ENABLED=true` + api creds + session), and a queue worker for `DeliverSupportReply`.

> **Test note:** the userbot is force-disabled in `phpunit.xml` (`TELEGRAM_SUPPORT_ENABLED=false`) so `class_exists()` never autoloads real MadelineProto — its shutdown handler crashes amp on teardown under Windows. Tests that need it set the config explicitly with a fake client.

## Where jivo.md is still useful

Keep it for: the Jivo product decomposition, "copy inbox patterns not the whole product," the EdTech side-panel widget priorities (P0/P1/P2), and the phased roadmap *shape*. Ignore its "Как у нас сейчас" column and its open-questions inventory — both predate reading the code.

_Dr. Mārcis Gasūns_
