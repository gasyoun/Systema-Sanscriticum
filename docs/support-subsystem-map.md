# Support subsystem — what actually exists (agent reference)

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

## The `SupportConversation` naming landmine

[`SupportConversation`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportConversation.php) belongs to a `TelegramSupportChat` and holds `conversation_date`, `incoming_count`, `outgoing_count`, `human_reply_count`, `ai_sent_count`, `is_unanswered`, `first_response_seconds`. **It is a daily metrics aggregate, not an operational case.**

If you build an operational thread/case object, you have a name collision. Decide up front:
- rename the existing model to `SupportDailyRollup` (or similar), **or**
- name the operational object differently (`SupportThread` / `SupportCase`).

Don't overload the existing model — its semantics (one row = one chat × one day) are incompatible with a reopenable thread.

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

1. **Operational conversation object** — define it as a reopenable thread keyed to user/identity; resolve the `SupportConversation` name collision first.
2. **Unification strategy** — read layer over `ChatMessage` + `TelegramSupportMessage`, upgrading `ChatMessage` toward the richer shape. Not a table merge.
3. **Identity reconciliation** — pick a canonical store (likely `SocialAccount`-shaped) and a migration path for the three existing mappings.
4. **AI scope** — extend the existing `SupportAiReplyEvent` / `ai_state` model; add the two genuinely-missing functions (suggested-reply, summary) rather than a single on/off toggle. Triage (`SupportTopicRule`) and event logging already exist.

## Where jivo.md is still useful

Keep it for: the Jivo product decomposition, "copy inbox patterns not the whole product," the EdTech side-panel widget priorities (P0/P1/P2), and the phased roadmap *shape*. Ignore its "Как у нас сейчас" column and its open-questions inventory — both predate reading the code.
