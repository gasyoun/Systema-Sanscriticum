# ROADMAP — Telegram RAG support, 2026H2

_Created: 31-08-2026 · Last updated: 31-08-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2.md). Waves map 1:1 to the three execution handoffs (ruling R12). Waves A and B are independent and can run in parallel; wave C is independent of both.

## Wave A — the DM lane (hard)

Deliverables, in order; each unblocks the next:

1. **Stale-skip diagnosis.** Measure the ingest-latency distribution of `telegram_support_messages` (sent_at vs created_at) over the last 30 days; identify why 1070/14d messages exceed the 6 h ceiling (sync cadence? backlog replay? one dead account?). Deliverable: a dated results doc with the histogram and the named cause.
2. **Stale fix + ceiling raise (R2).** Fix the named cause where it is a config/cadence fix (respecting the cron cgroup budget — the `telegram-support:sync` 10 470 s incident is prior art); then raise the hint ceiling to 24 h (`auto_reply_max_age_hours` split into hint ceiling vs auto-send ceiling; auto-send stays ≤6 h).
3. **Shadow mode for RAG auto-send (R3+R9).** New flag `support_dm_auto_reply_shadow`: for every hinted DM, additionally log question, top chunk, BM25 score, would-send draft, and later the curator's actual reply, into `SupportAiReplyEvent` meta. Zero student-visible change (autonomy contract §5).
4. **Inline send button (R8).** The Telegram hint gains a one-tap button: queue the draft to the student via `queueAiReply` under a `TelegramSendGuard` claim; admin `pending` suggestions auto-expire after 7 days.
5. **Shadow report.** After ≥7 days of shadow data: precision-at-floor table by category → a human reads it and rules on the live enable (the one human-gated flip, R10).

## Wave B — retriever quality (hard)

1. **100Q gold set (R6).** Mine real student questions from `telegram_support_messages` (anonymised: no names, no phones, no @handles), label expected `chunk_id`s, extend [faq_rag_eval.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/faq_rag_eval.json) 20→100.
2. **recall@5 + MRR gate.** Extend [FaqRagEvalTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/FaqRagEvalTest.php) with recall@5 and MRR metrics; keep deterministic, no LLM, no network; set the gate at the measured baseline minus nothing (ratchet, never regress).
3. **BM25 tuning (R7).** Fix the failure classes the 100Q eval exposes: ё/е normalisation, RU stemming/synonym pairs (зум/zoom, оплата/платёж, запись/видео), chunk `faq_from_lectures.md` into the corpus. Each tweak must move recall@5 on the fixture, or it reverts.
4. **Cabinet bot stage 1 (R5).** `bot_faq_retrieval` flag (default OFF): top-K chunks into `BotKnowledgeBase::systemPrompt` instead of the whole faq.md; catalog always whole; `BotKnowledgeBaseTest`; smoke on ≥20 real cabinet questions (side-by-side old vs new prompt answers); flip ON after green smoke (R10).
5. **Auto-send floor derivation.** From the 100Q set: the BM25 score above which top-1 is correct ≥95% of the time in safe categories → feeds Wave A step 5.

## Wave C — park the ORS-FAQ bot lane (trivial)

1. Mark `bot.py` / `answerer.py` / `BOT_DEPLOY.md` in [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ) as reference/undeployed (README + CLAUDE.md note + `.ai_state.md`), pointing at the Systema lane as the prod line. Keep the `ors_faq/eval/` gold sets as a question source for Wave B. Delete nothing (R4).

## Non-goals (explicitly out of scope)

- Embeddings/hybrid retrieval, Ollama, bge-m3, `knowledge_chunks` — October, [H3234](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md), needs the 16 GB GPU node (this box is a GTX 1050).
- Local generation / dropping OpenRouter (#1633 stages 5–6).
- Auto-send for payment (D) or access (E) categories — permanently fenced, not deferred.
- Deploying the ORS-FAQ bot anywhere.
- The lecture corpus (#1633 stage 4) beyond chunking the already-synced `faq_from_lectures.md`.

_Dr. Mārcis Gasūns_
