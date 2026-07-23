# VERIFICATION — n8n lecture content engine

_Created: 23-07-2026 · Last updated: 23-07-2026_

Index: [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md).

---

## 1. Acceptance criteria per wave

### W1 Clips extension

| # | Criterion | How to prove |
|---|---|---|
| A1 | `ContentCandidate` migrates cleanly | `php artisan migrate --pretend` / CI migrate |
| A2 | Ranker returns ≤5 spans by default | unit test with >5 planned spans |
| A3 | Dispatch payload uses ranked spans only | feature test Http::fake captured body |
| A4 | Flags OFF → no n8n HTTP | feature test |
| A5 | QuotePolicy rejects 3-sentence quotes | unit test |
| A6 | Clip create upserts candidate | feature test on callback or factory |
| A7 | Idempotent re-publish | second publish does not double-dispatch |
| A8 | Pint clean on touched PHP | CI / local pint |
| A9 | DEPLOY_QUEUE activation row present | file review in PR |

**Merge bar (D16):** A1–A9 green. Staging n8n dry-run is **human**, listed in
DEPLOY_QUEUE, not required for merge when agent has no staging.

### W2 Social + mailer scaffold

| # | Criterion | How to prove |
|---|---|---|
| B1 | Free clip → social draft rows | feature test |
| B2 | Publish job no-ops when pilot flag OFF | feature test |
| B3 | Publish job posts to mocked VK+TG when ON | Http::fake |
| B4 | QuotePolicy hard-fails publish with long quote | feature test |
| B5 | One-shot mailer no-ops when email flag OFF | feature test |

### W3 FAQ

| # | Criterion | How to prove |
|---|---|---|
| C1 | Transcript fixture → ≥1 faq_draft | feature test |
| C2 | Accept writes knowledge target | feature/unit with fake filesystem or DB |
| C3 | No support-gap tables required | test without SupportTopic seed |

### W4 Long-form

| # | Criterion | How to prove |
|---|---|---|
| D1 | Lesson(s) → article draft | feature test with fake CuratorAi |
| D2 | Body does not contain full transcript dump | length/ratio assertion vs source |

### W5 Study materials

| # | Criterion | How to prove |
|---|---|---|
| E1 | study_artifact draft created | feature test |
| E2 | Not selected by pilot publish job | unit/feature |

### Post-activation success (D17)

| # | Metric | Window |
|---|---|---|
| M1 | ≥1 free clip with `published_at` or VK id per week | 4 consecutive weeks |
| M2 | Zero incidents of full paid transcript on public channels | sample audit + QuotePolicy logs |

---

## 2. Commands (agent / CI)

```bash
php artisan test --filter=Content
php artisan test --filter=LectureClip
php artisan test --filter=QuotePolicy
./vendor/bin/pint --dirty
```

Synthetic fixture location (create in W1):
`tests/Fixtures/content/transcript_deepgram_sample.json`.

---

## 3. Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| H1452 n8n workflow never activated | High (ops) | DEPLOY_QUEUE; W1 does not depend on live VK for merge |
| LLM ranker cost / nondeterminism | Med | Heuristic default in tests; cap N=5 |
| Paid transcript leak on auto-pilot | High | QuotePolicy hard-fail; ≤2 sentences; no full body in social generators |
| Dual publish (manual + auto) duplicates | Med | Idempotent external post ids in meta; status=`published` guard |
| One-shot mailer vs H1449 campaign engine drift | Med | Mailer only sends pre-accepted `email_blast`; no new campaign domain model beyond candidate |
| Email dead (#504 history) blocks August | High | Activation @DO; code path flag-OFF until SMTP live |
| Mega scope creep in one PR | High | D20: five sequential PRs enforced by five handoffs |
| Local clone behind origin (missing LectureClip) | Med | Handoff start: fetch + worktree from origin/main |
| ClipSpanPlanner max 12 then rank 5 | Low | Documented; expand path for staff |
| CAI3 support gaps unused early | Low | Intentional D3; revisit after pilot metrics |

---

## 4. Spikes (before or during W1 if blocked)

1. **Where is lesson publish signal?** — grep observers/Filament publish actions;
   if none clean, dispatch from Filament after-save when `is_published` flips.
2. **VK wall.attach video API** — confirm token scopes for pilot; stub until
   activation (W2 code can Http::fake the final shape).

_Dr. Mārcis Gasūns_
