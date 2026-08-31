# VERIFICATION — Telegram RAG support

_Created: 31-08-2026 · Last updated: 31-08-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md).

## 1. Acceptance per deliverable

| Deliverable | Proof | Command / flow |
|---|---|---|
| A1 latency census | Dated results doc names the stale cause with a histogram | `php artisan support:ingest-latency-report` |
| A2 freshness split | Two-ceiling test matrix green; `dm_stale_skip` share drops in the next weekly digest (baseline: 1070/14d = ~56%) | `php artisan test --filter=SupportDmAutoReply` + prod digest |
| A3 shadow mode | Shadow ON writes `dm_shadow_would_send`, zero new outgoing rows; prod diff of outbound counts before/after = 0 | feature test + `SupportAiReplyEvent` counts |
| A4 shadow report | Report exists with precision by score band ≥7 days of data; human ruling recorded | `php artisan support:shadow-report` |
| A5 send button | Tap → exactly one queued outgoing (guard dedup proven by double-tap test); suggestion `accepted`; 7-day auto-expire test | feature tests + one live tap by a curator |
| B1 100Q set | `faq_rag_eval.json` has ≥100 items, zero names/phones/@handles (grep gate in the test) | fixture assertions |
| B2 metrics gate | recall@5 + MRR asserted at recorded baseline; CI deterministic (no network) | `php artisan test --filter=FaqRagEvalTest` |
| B3 tuning | Each tuning commit raises recall@5 on the fixture (commit message carries before/after) | same test, per commit |
| B4 cabinet stage 1 | `BotKnowledgeBaseTest` green; smoke doc shows ≥20 old-vs-new answers with no regression on prices/links; flag ON on prod + one live cabinet question answered correctly | test + smoke doc + prod probe |
| B5 floor | Documented per-category threshold with precision table | baseline section in eval doc |
| C1 parking | ORS-FAQ README/CLAUDE.md/.ai_state say reference/undeployed; no file deleted | git diff |

Suite-wide: full `php artisan test` green before each PR (~11 min); `./vendor/bin/pint` clean; env inventory check green.

## 2. Risks and spikes register

1. **Stale cause may be structural** (MadelineProto sync is batch-heavy under the 2G cron cgroup — the 10 470 s incident). If A1 shows the fix needs daemon rework, A2 ships only the ceiling split and the rework becomes a named follow-up, not scope creep (R11 conservative default).
2. **Auto-send duplicate risk**: a raised ceiling widens the window where a curator already answered. Mitigation: A4's join logic doubles as a pre-send "curator already replied" check before live enable; `TelegramSendGuard` covers retries but NOT semantic duplicates — spike this before the live flip.
3. **100Q labeling quality**: agent-labeled expected chunks can encode retriever bias (mined from BM25 top-10). Mitigation: ambiguous items dropped; the fixture stores evidence quotes; any dispute resolves by reading faq.md, not the retriever.
4. **Cabinet regression on rare topics**: retrieval can drop a chunk the full-stuffing prompt would have had. Mitigation: smoke includes the 6 lowest-frequency categories; rollback is the flag.
5. **Privacy**: eval fixture and shadow logs must never carry PII — grep gates in tests; shadow meta stores a question hash + truncated text only. The ORS-FAQ `ors_faq/dialogs/` store is not touched at all.
6. **September peak load**: all changes flag-gated; nothing deploys unflagged; deploys via `deploy.sh` only, each followed by its smoke (repo deploy rules).

_Dr. Mārcis Gasūns_
