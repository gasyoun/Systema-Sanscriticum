# ROADMAP — Telegram support leverage, 02-09-2026 → 31-10-2026

_Created: 03-09-2026 · Last updated: 03-09-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md). Four waves map 1:1 to four execution handoffs. Wave 1 is the critical path and runs first; waves 2 and 3 are independent of each other and of wave 1 and may run in parallel; wave 3b depends on wave 3 only for its retrieval half and can start its clarifier half any time after wave 1.

Ordering rule for the whole span (ruling S3): facts before retrieval. The 31-08 programme proved the FAQ lane; FINDINGS §635 then measured that only ~2 % of the ~226 monthly inbound DMs are FAQ-shaped. Every hour spent on retrieval before fact resolution buys less than an hour spent on wave 1.

## Wave 1 — LMS facts, one-tap delivery, SLA (hard)

Deliverables, in order; each unblocks the next:

1. **Five new fact resolvers (I2).** `SupportAnswerFactResolver` grows from three resolvers (next class, class link, latest recording) to eight: homework status, payment balance, access state, certificate status, schedule changes. Balance, access and certificate are draft-only forever (A1); homework status and schedule changes may auto-send after their own shadow week (V1). Deliverable: eight resolvers, one feature test each on SQLite with pinned time, zero student-visible change while the new categories stay out of the live list.
2. **Finance escalation on a disputed balance (A1).** A payment draft whose computed balance disagrees with the student's stated claim opens a follow-up task through `SupportFollowUpService` addressed to the finance lead instead of offering a send. Depends on the payment-balance resolver.
3. **One-tap send everywhere (I1a).** `SupportHintSendButton` is extended from the FAQ hint to every hint type — fact, template, LLM draft, and the deterministic D/E drafts, which offer no send button at all. Every path claims through `App\Support\TelegramSendGuard` and counts against `support_ai_daily_cap`. Depends on deliverable 1.
4. **Filament draft queue (I1b).** A new admin page beside `telegram-support-analytics` listing pending drafts with Send, Edit and Skip, so a curator can correct a draft before it goes out. Same guard, same cap. Depends on deliverable 3 for the send path.
5. **Auto link-invite live (I3).** `support_dm_link_invite` flipped ON for both accounts, plus a weekly report of unlinked contacts with at least two DMs for hand-linking. No auto-matching by phone or username, ever. Independent of deliverables 1–4; raises the share of DMs the resolvers can answer at all.
6. **SLA timer (A5).** A scheduled job over open `SupportConversation` rows with no outbound message: 15 minutes in working hours pings the curator, 60 minutes pings the second curator, quiet hours 22:00–09:00 MSK are paused. Curator Telegram IDs come from a new `support.sla.curators` config list. No student-facing SLA message. Independent of the resolvers.
7. **Shadow week, then the first flag flips.** Homework status and schedule changes accumulate seven days of shadow data; the agent produces the precision sheet; a human flips each category into the live list separately (V1). This is the wave's exit.

## Wave 2 — router shadow and unified KPI (medium)

1. **Message-intent-classifier in shadow (A2).** The classifier runs beside `SupportDmAutoReply::categorize()` on every inbound DM, its per-plane verdict logged into `SupportAiReplyEvent`; `categorize()` keeps deciding. A plane replaces the keyword path only after a manual week measures it at 93 % precision or better.
2. **One report builder (I4).** A single builder in the `SupportParityReportBuilder` shape feeds all three surfaces: the `support:shadow-report` command, the weekly Telegram digest behind `support_auto_reply_weekly_report`, and the Filament `telegram-support-analytics` page. Every metric is printed next to its mining funnel, so a reader can tell a small numerator from a small denominator.
3. **Weekly precision sheets (V4).** The builder emits a 30-sample sheet per live category into the wave-1 Filament queue page for a curator to mark correct or incorrect; below 95 % the category leaves the live list, and two consecutive misses keep it out until it is re-shadowed. Depends on deliverables 1 and 2 of this wave and on wave 1 deliverable 4.

## Wave 3 — hybrid retrieval and a fresh eval set (hard)

1. **`knowledge_chunks` and the embedding provider (A3).** New table storing one float32 BLOB per `FaqChunk` (1024-dim `bge-m3`, roughly 22 MB, no Qdrant), an `EmbeddingProvider` interface with an `OllamaEmbeddingProvider` talking to the `.92` tunnel and a `NullEmbeddingProvider` that degrades to BM25 with a log line. New `config/knowledge.php` carries the base URL, model and timeout.
2. **`knowledge:index` (A3).** A Horizon-queued command that embeds the corpus and refreshes the table; retries live inside the job, never in the request path.
3. **`HybridRetriever` (A3).** BM25 union dense with reciprocal-rank fusion, wrapping the existing `Bm25FaqRetriever` rather than replacing it, so BM25 remains the floor. Depends on deliverables 1 and 2.
4. **A fresh 100Q eval set (V3).** Mined from September traffic in `telegram_support_messages` through `faq:eval-set-build`, PII-masked before it is committed, and never from the ORS-FAQ dialog store. Used alongside the 80Q set from 31-08 so the retriever cannot be tuned onto one fixture.
5. **Acceptance run.** Recall@5 at least 83 % and MRR at least 0.713 on 80Q, hybrid at least matching BM25 on the fresh set, floors re-derived with `faq:score-floor`, p95 retrieval latency at most 2 s through the tunnel. Depends on 3 and 4.

## Wave 3b — shadow local generation and the clarifying question (hard)

1. **Shadow local generation (A4).** Every cloud draft is generated a second time by `qwen3:14b` on the GPU node; both are logged to `SupportAiReplyEvent` and the curator sees only the OpenRouter draft. A weekly cloud-versus-local agreement report is the evidence the #1633 stage-6 flip will need. Depends on wave 3 deliverable 1 for the tunnel client.
2. **The clarifying-question slot (S5, A6).** Two additive nullable columns on `SupportConversation` hold one pending slot and its six-hour expiry. When a resolver lacks a slot the bot asks one Russian question from a fixed per-slot template, the next inbound fills it and re-runs the same resolver, and a second miss hands the thread to a curator. It ships in shadow as a new `dm_shadow_would_ask` event before any student sees a question. Depends on wave 1 deliverable 1.
3. **Clarifier shadow week, then the flip.** Same gate as every other student-visible surface: seven days, at least twenty would-ask events, curator-reviewed precision at or above 95 %, then a human flips the flag.

## Non-goals (explicitly out of scope)

- Flipping local generation live (#1633 stage 6). Wave 3b produces the evidence; the flip is a human decision after 01-10-2026.
- Auto-send for payment (D) or access (E). Permanently fenced, not deferred — those categories get drafts and escalation only.
- Auto-matching a Telegram contact to a student by phone number or username. A wrong match answers one student with another's balance.
- Inbound email, the cabinet bot's own #1633 track, and any new channel. The two overloaded people work in Telegram DMs.
- Qdrant or any external vector store; a second embedding model; any model install on `.92`.
- Reworking `categorize()` in this span. The classifier only observes until its gate is met.
- Touching the ORS-FAQ `ors_faq/dialogs/` store for eval mining or anything else.

_Dr. Mārcis Gasūns_
