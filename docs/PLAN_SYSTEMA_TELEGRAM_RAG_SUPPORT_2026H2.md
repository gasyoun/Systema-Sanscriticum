# PLAN — RAG in Telegram customer support: from "live but idle" to working relief (2026H2)

_Created: 31-08-2026 · Last updated: 31-08-2026_

**Source:** `/ask` interview, 30/31-08-2026, 12 rulings, live chat with MG. Interview model: Fable 5 (`claude-fable-5`).
**Repo:** [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum) (the only prod support lane — ruling R4).
**Supersedes nothing; extends** [GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md) (ruling B shipped) and [issue #1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633) (stages 1–2 land here; stages 3–6 stay with [H3234](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3234-Grok_Systema-Sanscriticum_ollama-gpu-experiment-oct1_21.08.26.md) / the 01-10 GPU node).

## 1. Goal

**Readiness verdict (audit, 30-08-2026):** RAG is already live in Telegram support — BM25 retrieval, feature flags ON in prod — but effectively idle: over the last 14 days the bot auto-answered **3** DMs, sent **810** "complex" hints to curators, skipped **1070** messages as stale (older than the 6 h ceiling at processing time), and produced **53** admin drafts of which **0** were ever opened. Two curators carry the September peak by hand. The goal of this plan is one fast, verifiable step in each of the three lanes (ruling R1): unblock the stale-skip leak, turn auto-send from 3/14d into a real relief valve behind a shadow-validated threshold, and make the curator hint one-tap actionable — plus stage 1 of #1633 for the cabinet bot and an eval set strong enough to calibrate all thresholds.

## 2. Decisions taken (all 12 rulings, none open)

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| R1 | Goal for the September peak | All three lanes, small steps each — no deep work in any single lane | Peak is now; breadth over depth |
| R2 | 1070 stale skips at the 6 h ceiling | Diagnose the root cause first (sync cadence/backlog), fix it, then raise `auto_reply_max_age_hours` to ~24 h for curator hints; a stricter ceiling stays for auto-send to students | Biggest traffic leak; don't widen blindly |
| R3 | Auto-send widening | RAG answer above a calibrated BM25 score floor, safe categories only (never D-payment, never E-access), always with the FAQ citation; money and no-chunk cases still go to the human hint. Behind a flag; rollback = flag off | 3 auto-sends per 14 days is not relief |
| R4 | ORS-FAQ undeployed bot (`bot.py`/`answerer.py`, ~78K tokens per prompt) | Officially park: Systema is THE prod support lane; mark the ORS-FAQ bot reference/undeployed, keep its eval set as a gold-question source, delete nothing | Kill the redundant third lane |
| R5 | Cabinet bot stage 1 (#1633) | Build `bot_faq_retrieval` (default OFF): top-K BM25 chunks instead of the whole 46K-char faq.md; `CourseCatalogProvider::markdown()` always whole (prices only from the catalog); `BotKnowledgeBaseTest`; smoke on real questions, then ON | Quality + token cost; safe rollout |
| R6 | Eval bar | 100 questions mined from real `telegram_support_messages` (anonymised), expected chunk ids labeled, recall@5 + MRR, deterministic CI gate (no LLM). The auto-send score floor is derived from this set | #1633 stage 2; calibration basis for R3 |
| R7 | Retriever work before the GPU node | BM25 tuning driven by expanded-eval failures: ё/е normalisation, stemming/synonyms (зум/zoom, оплата/платёж), chunking of `faq_from_lectures.md`. NO embeddings before the local GPU node (H3234, target 01-10) | Privacy goal of #1633 intact; no double work |
| R8 | Dead admin-draft surface | Inline "send" button in the Telegram hint — one tap queues the draft to the student via the existing `queueAiReply`; admin `pending` rows auto-expire; the surface stays as log/metrics | Curators live in Telegram, not in `/admin` |
| R9 | Acceptance bar for R3 | Shadow week first: log would-send (question, chunk, score, draft) without sending; compare against what the curator actually replied; live enable only after a human reads the shadow report | No experiments on live students at peak without numbers |
| R10 | Prod flag authority | Agent flips flags itself after a green smoke whose criteria are pre-written in this plan. Exception: the live auto-send enable after the shadow week stays a human decision (R9) | Speed everywhere except the one student-facing flip |
| R11 | Mid-wave ambiguity | Default + log: take the plan's marked default (or the most conservative option), record the ruling in the PR body, continue. Stop only for money/data/security | An unattended agent must not stall |
| R12 | Execution slicing | Three handoffs: H-A DM lane (hard), H-B retriever quality (hard), H-C park the ORS-FAQ bot (trivial); minted as a batch | Independent lanes, independent failure domains |

## 3. Open @DECIDE

None.

## 4. Autonomy contract (verbatim for every execution agent)

1. **On ambiguity:** apply R11 — plan default or most conservative option, log the ruling in the PR body, continue. Never block waiting for a human mid-wave.
2. **Stop conditions (halt the wave, report):** anything that would auto-send in categories D (payment) or E (access); any change to money-contour code (per the repo's [money contour rules](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)); destructive DB operations; the test suite red in a way the wave cannot fix; prod deploy failing its smoke.
3. **Commit authority:** worktree off `origin/main` → PR → merge without re-asking (handoff-scoped autonomy). Deploy after merge only via [deploy.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) per [docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) — never a hand `git pull` on prod. Flag flips per R10.
4. **The fence (never touch):** the MadelineProto support session and its auth files; `TelegramSendGuard` claims are mandatory before ANY new Telegram send point (incident 24-08-2026); no LLM model installs on `.92` and no student traffic through the n8n `.91` node (#1633 bans); never feed `docs/**` to students; prices only from the course catalog; the ORS-FAQ `ors_faq/dialogs/` PII store is out of scope entirely; no edits to tracked files on the prod VPS; the external watcher reverts uncommitted main-tree changes — use [/watcher-safe-commit](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md).
5. **Shadow-mode invariant (H-A):** while `support_dm_auto_reply_shadow` collects data, the student-visible behaviour of the lane must be byte-identical to today's. Any diff in outbound traffic during shadow = defect, halt.

## 5. Layer docs

- Roadmap: [ROADMAP_SYSTEMA_TELEGRAM_RAG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_TELEGRAM_RAG_2026H2.md)
- Architecture: [ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md)
- Implementation: [IMPLEMENTATION_SYSTEMA_TELEGRAM_RAG_SUPPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_TELEGRAM_RAG_SUPPORT.md)
- Verification: [VERIFICATION_SYSTEMA_TELEGRAM_RAG_SUPPORT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_TELEGRAM_RAG_SUPPORT.md)

## 6. Prior art consumed (do not rebuild)

[Bm25FaqRetriever](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/Bm25FaqRetriever.php) + [FaqCorpusParser](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/FaqCorpusParser.php) (H2448) · [SupportDmAutoReply](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportDmAutoReply.php) (H3233) · hint routing to real responders (H3393) · [SupportAiReplyEvent](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php) telemetry · [TelegramSendGuard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TelegramSendGuard.php) · the 20Q [faq_rag_eval.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/faq_rag_eval.json) fixture · 🍎 attribution ([SupportOutgoingAttribution](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportOutgoingAttribution.php)) · the wiki→faq.md KB sync from [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ) (`export_bot_kb.py`).

_Dr. Mārcis Gasūns_

## Autonomy gate — 31-08-2026

| Check | Verdict |
|---|---|
| all mechanical checks | PASS |

Mechanical verdict: **PASS** (exit 0). Human halves — no-rebuild-what-exists, contract coverage of plausible ambiguities — attested by the authoring session, not parsed here.

_Dr. Mārcis Gasūns_
