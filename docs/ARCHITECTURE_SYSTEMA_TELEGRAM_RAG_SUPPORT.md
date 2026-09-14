# ARCHITECTURE — Telegram RAG support

_Created: 31-08-2026 · Last updated: 31-08-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md).

## 1. The two channels (unchanged; never mix — from the [21-08 gap doc](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md))

| | Cabinet bot | Support-account DMs |
|---|---|---|
| Transport | `STUDENT_TELEGRAM_BOT_TOKEN` webhook | MadelineProto userbot session (sync + queued outgoing) |
| Answering today | LLM answers itself, whole faq.md in prompt | Curators by hand; bot hints + rare template auto-sends |
| This plan | Wave B step 4 (`bot_faq_retrieval`) | Wave A (stale fix, shadow, send button) |

## 2. Data flow after this plan (DM lane)

Incoming DM → sync into `telegram_support_messages` → [SupportDmAutoReply](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportDmAutoReply.php):

1. freshness check — hint ceiling 24 h, auto-send ceiling ≤6 h (two config keys, Wave A step 2);
2. deterministic self-service / template path (unchanged);
3. RAG path: `Bm25FaqRetriever::retrieve(text, K)` → score ≥ floor AND category ∈ {A,B,C,F} AND linked user → **shadow log** (flag `support_dm_auto_reply_shadow`) or, post-enable, **auto-send with citation** via `queueAiReply` + `TelegramSendGuard`;
4. everything else → Telegram hint to `hint_recipients` with FAQ citations **plus the new inline send button** (callback → queue the shown draft, mark the `SupportAnswerSuggestion` accepted).

Every branch already writes a [SupportAiReplyEvent](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php); shadow adds `event_type=dm_shadow_would_send` with score/chunk/draft meta. The weekly digest reads these events — no new telemetry store.

## 3. Component verdicts (build vs reuse)

| Piece | Verdict | Evidence |
|---|---|---|
| Retrieval | **Reuse** `Bm25FaqRetriever` + `FaqCorpusParser`; tune, don't replace | H2448, eval fixture exists |
| Sending | **Reuse** `queueAiReply` + `TelegramSendGuard` (at-most-once claims) | incident 24-08-2026 |
| Telemetry | **Reuse** `SupportAiReplyEvent` + weekly digest | live since H3233 |
| Hint routing | **Reuse** H3393 `hint_recipients` | live |
| Inline button | **Build** — a callback handler on the hint bot; the only genuinely new surface | nothing tappable exists today |
| Shadow evaluator | **Build** — small: one new event type + a report command | nothing comparable exists |
| Eval set/metrics | **Extend** the 20Q fixture + `FaqRagEvalTest` to 100Q, recall@5, MRR | R6 |
| Cabinet prompt | **Extend** `BotKnowledgeBase::systemPrompt` behind `bot_faq_retrieval`; `$userQuestion` param is already reserved for exactly this | gap doc, #1633 stage 1 |
| Embeddings | **Don't build** until the GPU node | R7, H3234 |

## 4. Config surface

New keys (all in `config/features.php` / `config/services.php`, inventory regenerated same pass — the `Environment inventory` CI gate reddens main otherwise): `support_dm_auto_reply_shadow`, `bot_faq_retrieval`, split freshness ceilings, auto-send score floor + top-K. All default OFF/conservative; every flip per ruling R10.

_Dr. Mārcis Gasūns_
