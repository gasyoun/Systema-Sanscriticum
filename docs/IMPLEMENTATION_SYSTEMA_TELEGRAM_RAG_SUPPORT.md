# IMPLEMENTATION — Telegram RAG support (wave-1 build sequence)

_Created: 31-08-2026 · Last updated: 31-08-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md). File-level steps; each step names its files and its dependency. All work in a worktree off `origin/main` ([scripts/worktree_bootstrap.ps1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/worktree_bootstrap.ps1) for vendor), landed by PR. Run `php scripts/generate_env_inventory.php` in the SAME pass as any new `env()` key.

## Wave A (handoff H-A)

**A1. Latency census (read-only, no deps).** New `app/Console/Commands/SupportIngestLatencyReport.php`: histogram of `created_at - sent_at` for `telegram_support_messages` last 30d, split by account; plus the stale-skip share per day from `SupportAiReplyEvent`. Output → `docs/RESULTS_SUPPORT_INGEST_LATENCY_<date>.md`. Name the cause.

**A2. Freshness split (dep: A1).** `config/services.php`: `telegram_support.hint_max_age_hours` (24) and keep `auto_reply_max_age_hours` (6) for sends. `app/Services/Support/SupportDmAutoReply.php`: the stale check branches — hints use the wide ceiling, `sendAuto` paths the strict one. If A1 names a sync-cadence bug, fix it in its own commit first (respect the cron cgroup budget; `telegram-support:sync` history is prior art). Tests: extend `tests/Feature/Support/` auto-reply tests with the two-ceiling matrix.

**A3. Shadow mode (dep: A2).** `config/features.php`: `support_dm_auto_reply_shadow` (default OFF). In `SupportDmAutoReply::hintComplex`: when shadow is ON and BM25 top score ≥ provisional floor and category ∈ {A,B,C,F} and user linked — also write `SupportAiReplyEvent` `dm_shadow_would_send` with meta (score, chunk_id, draft, question hash). Student-visible behaviour byte-identical (contract §5). Test: shadow ON produces the event and NO outgoing row.

**A4. Curator-reply join + report (dep: A3, 7 days of data).** New `app/Console/Commands/SupportShadowReport.php`: join each `dm_shadow_would_send` to the curator's actual outgoing reply to the same user within 48 h; emit precision-by-score-band and by-category table → `docs/RESULTS_SUPPORT_SHADOW_WEEK_<date>.md`. This report is what the human reads before the live flip (R9/R10).

**A5. Inline send button (dep: A2; parallel to A3).** The hint sender (`AdminNotifier`/`notifyRecipients` path) attaches an inline keyboard `[Отправить как есть]` carrying the `SupportAnswerSuggestion` id. New callback route on the hint bot's webhook → controller verifies the tapper is a hint recipient → `queueAiReply` under a fresh `TelegramSendGuard` claim → suggestion `status=accepted`, `resolved_by` set. Scheduled auto-expire: `pending` suggestions older than 7 days → `expired`. Tests: callback authorises, sends once (guard dedup), expires.

## Wave B (handoff H-B)

**B1. Mine 100Q (no deps).** New `app/Console/Commands/FaqEvalSetBuild.php` (or a `tools/` script): sample real inbound questions from `telegram_support_messages` stratified by category A–F, strip names/phones/@handles/emails, propose expected `chunk_id`s from BM25 top-10 for hand-check. Output: `tests/fixtures/faq_rag_eval.json` grown 20→100. Labeling is agent work with per-item evidence; ambiguous items dropped, never guessed.

**B2. Metrics gate (dep: B1).** `tests/Feature/Support/FaqRagEvalTest.php`: add recall@5 and MRR alongside top-3; gate = measured baseline (ratchet). Record the baseline table in `docs/FAQ_RAG_EVAL_H2448.md` (append a dated section).

**B3. BM25 tuning (dep: B2).** `app/Services/Support/Faq/Bm25FaqRetriever.php` + `FaqCorpusParser.php`: ё/е fold, light RU stem/synonym map (зум/zoom, оплата/платёж, запись/видео…), include `resources/knowledge/faq_from_lectures.md` chunks. One tweak per commit; each must raise recall@5 on the fixture or it reverts.

**B4. Cabinet stage 1 (dep: B3 for quality, can start after B2).** `config/features.php`: `bot_faq_retrieval` (OFF). `app/Services/Bot/BotKnowledgeBase.php`: when ON, replace the faq.md blob with top-K retrieved chunks for the incoming `$userQuestion` (the parameter already exists, reserved); `CourseCatalogProvider::markdown()` stays whole. New `tests/Unit/Bot/BotKnowledgeBaseTest.php`. Smoke: ≥20 real cabinet questions old-vs-new side by side → `docs/RESULTS_BOT_FAQ_RETRIEVAL_SMOKE_<date>.md`; green → flip ON on prod (R10) via env + `php artisan config:cache` through `deploy.sh`.

**B5. Floor derivation (dep: B2).** From the 100Q fixture: per-category score threshold where top-1 precision ≥95% → write into the shadow config (feeds A3/A4). Document in the B2 baseline section.

## Wave C (handoff H-C, trivial)

**C1.** In [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ): README + `CLAUDE.md` + `.ai_state.md` note — bot lane is reference/undeployed, Systema is the prod line, eval sets remain as question sources. No deletions, no deploy.

_Dr. Mārcis Gasūns_
