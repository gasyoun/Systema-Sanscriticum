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

_Dr. Mārcis Gasūns_
