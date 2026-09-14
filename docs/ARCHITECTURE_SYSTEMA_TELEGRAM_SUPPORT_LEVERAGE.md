# ARCHITECTURE — Telegram support leverage

_Created: 03-09-2026 · Last updated: 03-09-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md). Component boundaries, schema, contracts and the build-versus-reuse verdict per piece. The 31-08 architecture ([ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md)) still holds; this document records only what changes.

## 1. The two channels (unchanged; never mix them)

- **The support DM lane** — MadelineProto user sessions on the support account and on @rusamskrtam, ingested into `telegram_support_messages`, handled by `SupportDmAutoReply`. This is where both curators work and where every deliverable in this plan lands.
- **The cabinet bot** — the Bot API bot serving logged-in students, `BotKnowledgeBase` and the #1633 retrieval track. It shares the retriever built in wave 3 and nothing else. No support-lane code may write to the bot's session state, and no bot code may send into a support thread.

The GPU node `.92` is reachable only through the sshd reverse tunnel. Both channels talk to it through one client (§4); neither ever holds a direct address, and no student traffic passes through the n8n node `.91`.

## 2. Data flow after this plan (DM lane)

1. **Ingest.** The sync command pulls new DMs into `telegram_support_messages`. Inbound updates dedupe through `TelegramSendGuard::claimUpdate()`; nothing downstream may assume single delivery.
2. **Identify.** The contact is resolved to a `User` when it is linked. Unlinked contacts get the link invite (wave 1) and no fact resolution at all — an unlinked contact has no student to answer about.
3. **Classify.** `SupportDmAutoReply::categorize()` decides, as today. The message-intent-classifier runs beside it in wave 2, writing its per-plane verdict into `SupportAiReplyEvent` and deciding nothing.
4. **Resolve facts.** For a linked user, `SupportAnswerFactResolver::resolve()` is tried first — eight resolvers after wave 1. A resolver returns either a complete answer, a missing-slot marker (wave 3b), or null.
5. **Clarify (wave 3b).** A missing-slot marker writes `pending_slot` plus `pending_slot_expires_at` on the `SupportConversation` and asks one fixed Russian question. The next inbound within six hours fills the slot and re-enters step 4 with the same resolver. A second miss abandons the slot and hints the curator.
6. **Retrieve.** Only when no resolver answers does the FAQ path run: `HybridRetriever` in wave 3, `Bm25FaqRetriever` alone before it and whenever the tunnel is down.
7. **Compose.** Deterministic template, or `SupportLlmDraftComposer` for the LLM categories. Wave 3b adds a second, shadow-only generation on `qwen3:14b`; the curator never sees it.
8. **Deliver.** One of four outcomes: auto-send for a live category inside the daily cap; a hint with a one-tap send button; a draft parked in the Filament queue for editing; or, for categories D and E, a draft with no send affordance plus a finance follow-up when the balance is disputed.
9. **Record.** Every branch writes a `SupportAiReplyEvent` row. The event type is the whole telemetry contract — shadow decisions never change traffic, only rows.
10. **Escalate.** The SLA job sweeps open conversations with no outbound message and pings curators on their own Telegram at 15 and 60 working-hour minutes.

Steps 4 and 5 are new ahead of step 6; that inversion is the plan's central architectural claim, and it follows from FINDINGS §635.

## 3. Data model

Three additive changes, no rewrites:

- **`knowledge_chunks`** (wave 3): `id`, `faq_chunk_id` (one row per `FaqChunk`), `model` (string, `bge-m3`), `dims` (integer, 1024), `embedding` (binary, float32 little-endian, 4096 bytes), `content_hash` (string, guards re-embedding), `created_at`/`updated_at`. Roughly 22 MB at the current corpus size, so it stays in MySQL and no external vector store is introduced. A row is stale when `content_hash` no longer matches its chunk.
- **`support_conversations`** (wave 3b): two nullable columns, `pending_slot` (string, the slot name the resolver asked for) and `pending_slot_expires_at` (timestamp). Nothing else on the table changes.
- **`telegram_support_messages`**: unchanged. Additive nullable columns only, and this plan needs none.

New `SupportAiReplyEvent` event types, all additive strings in the existing `event_type` column with their payload in the `meta` array cast: `dm_shadow_would_ask` (clarifier shadow), `dm_router_shadow` (classifier verdict per plane), `dm_local_draft` (shadow local generation), `dm_queue_sent` (Filament queue send), `dm_sla_ping` (SLA escalation). No migration is required for these.

## 4. Contracts

- **`EmbeddingProvider`** — `embed(string $text): array` returning 1024 floats, and `embedBatch(array $texts): array`. `OllamaEmbeddingProvider` calls the tunnel base URL from `config/knowledge.php` with a 5 s timeout and no retries in the request path; retries belong to the indexing job. `NullEmbeddingProvider` returns an empty result and logs one warning line, which is what the DM lane binds to whenever the tunnel is unreachable. Callers must treat an empty embedding as "dense leg unavailable", never as "no results".
- **`HybridRetriever`** — same public shape as `Bm25FaqRetriever` so it is a drop-in at the call site. It runs both legs, fuses by reciprocal rank, and returns the BM25 ranking unchanged when the dense leg is empty. BM25 is the floor: a fusion that scores below BM25 on the eval sets is a defect, not a tuning result.
- **`SupportAnswerFactResolver::resolve()`** — keeps its `(string $category, User $user): ?array` signature. The returned array grows two keys used by the new delivery paths: a delivery mode (auto-send eligible, draft-only, or escalate) and an optional missing-slot name. Existing callers that read only the answer text keep working.
- **Every new Telegram send point** claims through `App\Support\TelegramSendGuard` before the API call, per the 24-08-2026 incident: a suppressed claim returns success quietly, a deterministic 4xx or 5xx releases the claim and rethrows, a transport failure with no response keeps the claim so the send is at-most-once, and Redis being down fails open with a loud warning.

## 5. Component verdicts (build vs reuse)

| Piece | Verdict | Why |
|---|---|---|
| DM lane, categorisation, hint path | **Reuse, extend** | `SupportDmAutoReply` already owns the flow and the shadow invariant |
| Fact resolution | **Reuse, extend 3 → 8 resolvers** | `SupportAnswerFactResolver` already has the shape and the formatting helpers |
| One-tap send | **Reuse, extend** | `SupportHintSendButton` already claims through the guard and queues the reply |
| Draft queue UI | **Build** | No Filament page today lists pending drafts with an edit affordance |
| Link invite | **Reuse, flip on** | `SupportDmLinkInvite` including `census()` is built and idle behind its flag |
| Follow-up / escalation records | **Reuse** | `SupportFollowUpService` covers create, list-open and complete |
| SLA timer | **Build** | Scheduler job plus a `support.sla.curators` list; nothing equivalent exists |
| Intent router | **Reuse in shadow** | `message-intent-classifier` is cloned with a PHP loader; do not rebuild it |
| KPI reporting | **Reuse the pattern, consolidate** | Three surfaces exist and disagree; one builder in the `SupportParityReportBuilder` shape feeds all three |
| BM25 retrieval | **Reuse as the floor** | `Bm25FaqRetriever` and `FaqChunk` stay; hybrid wraps them |
| Vector store | **Build the table, not a service** | 22 MB of float32 in MySQL beats operating Qdrant for this corpus |
| Embedding + local generation client | **Build one client** | Nothing talks to Ollama yet; both waves share it |
| Eval harness | **Reuse, extend the fixtures** | `faq:eval-set-build`, `faq:score-floor` and the 80Q set are current |
| Money reads | **Reuse, read-only** | `Tariff::calculateFinalPriceForUser`, `Lesson::isUnlockedBy`, `Payment`, `Group` — never written from support code |
| Degradation path | **Reuse** | `App\Services\Bot\StudentSelfService` plus the curator handover line |

## 6. Config surface

- **New file `config/knowledge.php`** — Ollama base URL through the tunnel, embedding model `bge-m3`, generation model `qwen3:14b`, dimension 1024, 5 s request timeout, index batch size. Every value reads from an env key.
- **New key in `config/support.php`** — `sla.curators`, an ordered list of curator Telegram IDs; the first is pinged at 15 minutes, the next at 60. Working hours 09:00–22:00 MSK, all seven days.
- **New flags in `config/features.php`**, all defaulting to false: the SLA timer, the Filament draft queue, the router shadow, hybrid retrieval, shadow local generation, and the clarifying question. `support_dm_link_invite` already exists and is flipped ON in wave 1 by a human editing the environment, not by the code default.
- **Live-category list** — the per-category kill switch stays where category F lives today; new categories are added to it only by a human after their shadow week.
- Any new `env()` key in a `config/*.php` file requires regenerating [ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md) with `php scripts/generate_env_inventory.php` in the same pass, or the CI gate reddens `main`.

_Dr. Mārcis Gasūns_
