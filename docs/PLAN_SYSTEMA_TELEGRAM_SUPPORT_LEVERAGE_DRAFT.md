# DRAFT — /ask rulings: Telegram support successor plan (curator leverage ×N)

_Created: 02-09-2026 · Last updated: 02-09-2026_

**Model:** Fable 5.1 (`claude-fable-5-1`) · crash-safe ruling log for the `/ask` interview; converted to `PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md` in Phase 3 and then deleted.

**Predecessor (locked, never re-litigated):** [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md) R1–R12 (31-08-2026). Baseline 02-09-2026: H3765 + H3766 executed 31-08; H3799 live FAQ auto-send category F only; GPU node live 01-09 19:00 UTC; FINDINGS §635 (~2 % of DM traffic is FAQ-shaped).

## Round 1 — goal & priorities (02-09-2026)

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| S1 | Goal metric | **Zero-typing share + response time**: ≥60 % of inbound support DMs closed with zero curator typing (auto-send + one-tap draft) by 31-10-2026; first response ≤15 min in working hours; precision ≥95 % on every auto-send category | Measures curator leverage directly; both halves already have telemetry in `SupportAiReplyEvent` |
| S2 | Channels | **Support-account DMs + @rusamskrtam second session** (one MadelineProto lane, one `SupportDmAutoReply` path) | The two overloaded people work here; cabinet bot keeps #1633 track; inbound email stays dark |
| S3 | Priority order | **Facts → one-tap → retrieval → KB loop**: 1) extend LMS fact resolution + raise linked-user share; 2) ready draft on every hint + one-tap send + SLA escalation; 3) hybrid retrieval on the GPU node; 4) KB-gap loop | FINDINGS §635: traffic is about one student, not the FAQ |
| S4 | GPU / H3234 | **Fold #1633 stages 3–5 into this plan** (hybrid retrieval, indexer, shadow local generation) as Opus 5 handoffs; H3234 (Grok 4.6) is superseded by the new handoffs, stage 6 flip stays human | Non-default ruling: Grok stayed idle on the gate for a week; the node is live now |

## Round 1b + Round 2a — student surface, span, first architecture forks (02-09-2026)

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| S5 | Student-visible surfaces | **Auto-replies + ONE bounded clarifying question** — when a fact resolver lacks a slot (which group? which date?) the bot asks one question, then answers; no reaction buttons | Non-default ruling: coverage over caution; the second turn must sit behind the same precision gate and a bounded state (one question, then hand to curator) |
| S6 | Span | **02-09-2026 → 31-10-2026** | Aligns with the GPU stage-6 human flip (01-10) and the ≥60 % zero-typing target; re-ask before the winter cohort |
| A1 | D (payment) / E (access) lane | **Deterministic LMS-fact drafts + curator one-tap send + escalation to the finance lead**: extend `SupportAnswerFactResolver` with payment-balance / access-state resolvers for linked users (numbers only from `Payment`/`Tariff`/`Group`); D drafts whose balance disagrees with the student's claim open a finance follow-up task via `SupportFollowUpService`. Zero auto-send in D/E (fence kept) | Non-default ruling: touches `SupportFollowUpService` and reads the money contour (read-only); the money-contour rule applies to the handoff (worktree, flag default OFF, money tests) |
| A2 | Inbound router | **Keep `categorize()`; message-intent-classifier runs in shadow**, verdict logged per plane into `SupportAiReplyEvent`, weekly agreement report; a plane replaces `categorize()` only after ≥93 % precision on a manual week | Same gate as the self-serve plan; H3526's gate was not met on 31-08 |

_Dr. Mārcis Gasūns_
