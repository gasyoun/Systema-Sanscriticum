# H6851 — Pilot protocol (user-simulator eval, support lane)

_Created: 20-10-2026 · Executor: OxAlpha (opencode/z-ai/glm-5.3-flash)_

## Spend ceiling (named BEFORE any paid call)

| mode | ceiling | status |
|---|---|---|
| scripted-stub (deterministic) | **0.00 USD** — no API, pure functions of committed fixtures | **used for the H6851 pilot** |
| LLM persona mode (live) | **0.50 USD hard cap = 25 calls × 0.02 USD est.**; enforced by `LlmPersonaDriver` (refuses to start without `USERSIM_LLM_ENABLED=1` + declared `USERSIM_LLM_MAX_CALLS`) | **not exercised — residual** |

## Pilot run (scripted-stub, zero spend)

`python3 evals/user_sim/harness.py --pilot` → `runs/2026-10-20-pilot-stub/`
(transcripts.jsonl + report.md; payload sha256 `5c436aee…`, byte-identical on
cold re-run). Verdicts: 5× PASS, 1× INCONCLUSIVE (PS-06, unreachable
per-student-fact persona — excluded from denominator per contract), 1×
BUDGET_TRIPPED (PS-07 chatterbox). 152-FZ fence hits: 0.

**This pilot exercises the pipeline (persona script → dialog → scoring), NOT
the live support lane.** The target is the deterministic `StubFaqTarget` over
the committed gold pairs of `tests/fixtures/faq_rag_eval.json`.

## Live-lane pilot — NAMED RESIDUAL (never claimed as run)

The lane's offline entry point exists in code — `php artisan knowledge:eval`
(BM25/hybrid over `knowledge_chunks`, H3234/H4001) — but was **not runnable in
the H6851 worktree** (no `vendor/`, no `.env`; probing confirmed this within
the 5-minute prior-art budget). Per the H6851 contract the live pilot is parked
as a residual:

1. `composer install` + `.env` (copy from main tree; never commit secrets).
2. `php artisan knowledge:index resources/knowledge/faq.md` (seed `knowledge_chunks`).
3. Wire the LLM persona driver (`LlmPersonaDriver` ceiling is ready) to a real
   provider and set the Telegram-support answer surface (or
   `SupportAnswerSuggester`) as the target adapter.
4. Run with `USERSIM_LLM_ENABLED=1 USERSIM_LLM_MAX_CALLS=25 python3 evals/user_sim/harness.py --pilot --out evals/user_sim/runs/<date>-pilot-live`.
5. Score vs the scripted-stub baseline in this report; publish verdicts from
   saved transcripts only.

A stdin/stdout bridge sketch for the real retriever sits in
`faq_target_bridge.php` (untested here — requires step 1–2 first).
