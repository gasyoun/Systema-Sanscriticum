# Support subsystem — what actually exists (agent reference)

_Created: 01-07-2026 · Last updated: 07-08-2026_

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

> **Scope of that rule (clarified 29-07-2026, H1837).** "Do not merge the tables" is about these two **message stores** and still holds absolutely. It does *not* forbid a shared **aggregate**: `support_daily_rollups` is now written from both sides with a `channel` discriminator, because a daily count is not a message and a two-table deflection report cannot be summed without manual reconciliation. The read path into each store stays a separate branch (see [`SupportTopicClassifier::dayText()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportTopicClassifier.php)); nothing is copied between stores.

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

## Decisions made (Steps 1–4, resolved 01-07-2026)

This was previously labeled "Open decisions" — all four were closed the same day they were raised. Keeping this as a dated log, not a live task list; see **Actually open** below for what's genuinely left.

1. **Operational conversation object** — defined as a reopenable thread keyed to user/identity. Name collision resolved (Step 1): `SupportConversation` is free; used for the thread.
2. **Unification strategy** — read layer built (Step 2): [`UnifiedMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedMessage.php) DTO + [`UnifiedInboxReader`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/UnifiedInboxReader.php)`::forUser()` merge both stores chronologically. No table merge. `ai_state` added to `chat_messages`; `direction`/`responder_type` are **derived from `role`** in the DTO (not stored — `role` stays the single source on the web side). The write path and thread grouping landed with the operational-thread object (see the integration-wave table below: `support_conversation_id` FK on both message tables) — this is no longer open.
3. **Identity reconciliation** — resolved (Step 3): [`social_accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_25_130000_create_social_accounts_table.php) is canonical; see [docs/support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md). Backfill: [`identity:backfill-social-accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ConsolidateSocialIdentities.php) (dry-run default, idempotent, non-clobbering). Denormalized `users.telegram_id/vk_id/max_user_id` kept as outbound caches. No 4th table.
4. **AI scope** — done: [`SupportAiService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAiService.php) adds the two genuinely-missing functions (`suggestReply`, `summarize`) over the unified thread, logging each to the existing `SupportAiReplyEvent` (`suggested`/`summary`). Behind `features.support_ai_assist` (off). Reuses `CuratorAi::chat()`; never auto-sends.

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

## Actually open (verified against code, 05-07-2026)

Real remaining gaps toward jivo.md Phase 0–6 parity — not decisions, punch-list items:

| Gap | Phase | Detail |
|---|---|---|
| Security hardening — remaining audit items | 0 | [SECURITY_AUDIT_money_2026-07-02.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY_AUDIT_money_2026-07-02.md)'s 20 money/pricing/access/salary findings are **all closed (07-07-2026)** — and it never contained an XSS finding (the earlier "XSS" here was a confabulation). Real still-open items live in [AUDIT_REPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AUDIT_REPORT.md) (2026-06-12): the editor/admin-gated **path-traversal** 🔴 in [`LectureDraftController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Editor/LectureDraftController.php) (~L73), plus #2/#4/#5 (rate-limiting, request-body logging, guest-identity attach). None are XSS; none block the web-chat build (H536). |
| Status filter tabs missing in UI | 1 | `SupportConversation.status` exists but `Helpdesk` doesn't surface New/In-Progress/Resolved filter tabs yet |
| ~~EdTech sidebar incomplete~~ | 2 | **✅ Closed 07-08-2026 (H2381).** [`HelpdeskStudentContextService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/HelpdeskStudentContextService.php) adds P0: active groups/courses, block access via `Lesson::isUnlockedBy`, nearest schedule/Zoom, next lesson, recent conversations. Schema note: [docs/H2381_SCHEMA_REUSE_DECISION.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/H2381_SCHEMA_REUSE_DECISION.md). |
| ~~Topics web-chat-side~~ | 4 | **✅ Closed 29-07-2026 (H1837, S10).** [`SupportTopicClassifier`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportTopicClassifier.php) now reads the day's text from *either* store depending on the rollup's `channel`, so the same `SupportTopicRule` set classifies web/VK/TG-bot rollups. Rules and assignments are unchanged — only the text source branched. |
| ~~No required-close-topic workflow~~ | 4 | **✅ Closed 07-08-2026 (H2381).** Conversation-level history in `support_conversation_topics`; `closeWithTopic` requires category when `support_required_close_topic` ON (other/uncategorized allowed). Taxonomy = `SupportTopicRule` + explicit other/uncategorized. |
| ~~No support-ops follow-up-task model~~ | 4 | **✅ Closed 07-08-2026 (H2381).** Reuse `FollowUpTask` with nullable `deal_id` + `support_conversation_id` + `note`; flag `support_follow_up_tasks` OFF. CRM WorkQueue scoped `forDeals()`. |
| ~~Web-chat analytics missing~~ | 5 | **✅ Closed 29-07-2026 (H1837, S10).** `support_daily_rollups` gained a `channel` discriminator plus nullable `support_conversation_id` / `web_user_id` subject keys, so [`WebSupportRollupAggregator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/WebSupportRollupAggregator.php) writes web/VK/TG-bot rollups into the SAME table as the Telegram side. Metric arithmetic is shared ([`SupportRollupMetrics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SupportRollupMetrics.php)), so `TelegramSupportAnalytics` and `SupportObservability` show a per-channel breakdown next to a single total, with no manual reconciliation. Behind `features.support_web_rollups` (OFF by default); the `support:rollup-web` command runs hourly. **The "do not merge the tables" rule above is untouched** — it governs the two *message stores*, which stay separate; the rollup always was an aggregate. |
| No support↔outcome correlation | 5 | No dashboard linking support topics to payment/access/attendance failures. The **unresolved-after-N-hours KPI half is closed** (29-07-2026, H1837): `support_daily_rollups.unresolved_after_hours`, threshold `support.rollup.unresolved_after_hours` (24 h default), computed identically for both channels. The support↔outcome correlation dashboard remains open. |
| Reply-out delivery — 30-07 blockage **RECOVERED, verified live 31-07-2026** | 6 | **Обновление 31-07-2026 (Fable 5 `claude-fable-5`):** сессия здорова — один воркер (запущен 07:32), `telegram-support:sync` зеленый каждую минуту, `safe.php` пишется; **застрявший canary-джоб H594 (`failed_jobs` id 1445) перезапущен `queue:retry` и прошел без повторного падения**, свежие outgoing-строки несут реальные `telegram_message_id`. Ретрай W1.3 разблокирован. Историческая запись о сбое ниже сохранена как есть. — **H594 canary (30-07-2026, live-fire):** `support_unified_reply=true` flipped on prod for one canary thread ([`SupportConversation`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportConversation.php) id 12, the internal `tech_group_peers` chat `-1003671345641` — chosen over a real student chat specifically to keep the blast radius internal), a reply sent via [`SupportReplyService::replyToUnlinkedThread()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportReplyService.php). **Delivery failed**: [`DeliverSupportReply`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/DeliverSupportReply.php) → `TelegramSupportSyncService::openClient()` hit `danog\MadelineProto\Exception: Could not connect to MadelineProto` / `storage/logs/madelineproto.log`: `"It seems like the session is busy. Telegram does not support starting multiple instances of the same session"` — a **pre-existing** IPC-socket lock contention on `storage/app/telegram-support/session.madeline/ipc`, 54 failed `telegram-support:sync` attempts logged in the 10 min window *before* the canary ran, so this is not something the canary caused. The job landed in `failed_jobs` (tries=1, no auto-retry hammering the stuck socket). **`support_unified_reply` was reverted to false immediately after** — leaving it on would have additionally routed *all* curator TG-support replies (not just the technical queue, which was **already** silently failing through this same broken path before this canary, per `Helpdesk.php`'s `$forceTechTg` branch) through a known-broken delivery mechanism. **Root-cause fix (stuck MadelineProto session) needs a fresh human/ops look** — W3.1's supervised-deploy + healthcheck work already landed ([`H595`](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H595-Sonnet_Systema-Sanscriticum_telegram_w31_userbot_supervised_deploy_11.07.26.md), archived), yet the session is stuck again as of 30-07-2026 — the healthcheck evidently didn't catch (or auto-recover from) this specific IPC-lock-contention failure mode. W1.3 cannot be retried until the session is confirmed healthy. Controlled canary = WS1.3 of [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) |
| ~~VK/Telegram-student-bot channel tagging~~ | 6 | **✅ Closed 18-07-2026 (H1200).** `chat_messages.source` (nullable, NULL=web) tags origin; `ProcessVkBotMessage`/`TelegramWebhookController` set `vk`/`telegram_bot`; `UnifiedMessage` gained `CHANNEL_VK`/`CHANNEL_TELEGRAM_BOT` with distinct Helpdesk badges — was previously lumped into `CHANNEL_WEB` indistinguishably. |
| No email channel | 6 | Not started; jivo.md treats this as later-phase anyway |
| Production parity acceptance packet | 5–6 | **H2382 (07-08-2026) HOLD:** `php artisan support:parity-report --days=14` + [SUPPORT_PARITY_PRODUCTION_ACCEPTANCE_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SUPPORT_PARITY_PRODUCTION_ACCEPTANCE_2026-08-07.md). Distinguishes code / flag / verified-live. Full GO blocked on H2381 + H1200 residual + geo driver null. |

## Where jivo.md is still useful

Keep it for: the Jivo product decomposition, "copy inbox patterns not the whole product," the EdTech side-panel widget priorities (P0/P1/P2), and the phased roadmap *shape*. Ignore its "Как у нас сейчас" column and its open-questions inventory — both predate reading the code.

_Dr. Mārcis Gasūns_
