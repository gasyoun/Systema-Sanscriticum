# VERIFICATION — PWG Arzamas-style material

_Created: 24-07-2026 · Last updated: 24-07-2026_

Parent index: [PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md)

---

## 1. Acceptance criteria (wave-1)

| ID | Criterion | Proof command / check |
|---|---|---|
| A1 | ≥15 chapter `h2` in published body | `php artisan materials:import-pwg-arzamas --publish` then assert in test / count in body.html |
| A2 | Article show returns 200 | `GET /s/peterburgskiy-slovar-pwg` → 200 |
| A3 | TOC lists chapters | show blade JS builds `#tocList` from h2 (manual or feature assert h2 count) |
| A4 | Byline MG | `author_name = Dr. Mārcis Gasūns` |
| A5 | Soft CTA present | body contains link to Materials hub and/or Cologne/csl-guides; no payment form |
| A6 | Materials hub surfaces card | `/online/materialy` HTML contains article title when published |
| A7 | FACTS complete | every factual sentence has `verified` or `hedged` row |
| A8 | Assets rights | every `used=yes` image has license + source in ASSETS.md |
| A9 | RWS councils | reports in `docs/materials/pwg-arzamas/rws/`; Majors fixed |
| A10 | Import idempotent | PHPUnit double-import → single slug row |
| A11 | No money fence break | PR diff excludes payment/access RoleGate finance paths |
| A12 | Live publish | production `is_published=true` **or** explicit STOP log if no prod access |

---

## 2. Exact commands

```bash
# from worktree
php artisan materials:import-pwg-arzamas
php artisan test --filter=PwgArzamasMaterialTest
# final
php artisan materials:import-pwg-arzamas --publish
```

Optional visual:

```bash
# pixelbrowse / manual
# open https://samskrte.ru/s/peterburgskiy-slovar-pwg
# open https://samskrte.ru/online/materialy
```

RWS (paths depend on local RuWritingStyles install):

```bash
# run sanskrit + indology councils on SOURCE.md — archive JSON/MD under docs/materials/pwg-arzamas/rws/
```

---

## 3. Risks & spikes register

| Risk | Severity | Mitigation |
|---|---|---|
| Prod DB/import access missing for agent | High for A12 | STOP with runbook; still merge code+docs; GTD `@DO` human import |
| RWS CLI unavailable | Medium | Document attempt; fall back to `/litredaktor` + log as exception to D15 with DECISIONS_LOG entry (default only if CLI truly absent) |
| Thin evidence for Russian reception chapter | Medium | Merge chapter or hedge; never invent Soviet/Russian anecdotes |
| Image rights murky | Medium | Drop image (D21); ship text |
| A36 numbers mis-copied | High | Read committed paper only; FACTS row with blob path |
| Outline bloat / AI filler register | Medium | Councils + cut length; Arzamas is sharp not encyclopedic |
| Concurrent Systema WIP / watcher | High ops | Worktree + watcher-safe-commit only |
| Slug collision | Low | Check first; append `-2026` if needed and log |
| Scope creep into pwg_ru | High | Fence D5/D23 |

---

## 4. Spikes before architecture changes

None required — architecture is reuse-first. Only spike if:

- Article body cannot host figures at needed quality → then evaluate Curator inline images (still no new model beyond existing pivot)
- Materials hub filters out long reading_time items → read MaterialsController and adjust only if proven

---

## 5. Autonomy-readiness gate (Phase 4)

| Wave-1 deliverable | Arch? | Steps? | Accept? | Risks? |
|---|---|---|---|---|
| Research + FACTS | Yes §2 | Impl 2,5 | A7 | thin chapters |
| Prose SOURCE | Yes | Impl 4 | A1 | register |
| Images | Yes §2.3 | Impl 3 | A8 | rights |
| Councils | Yes | Impl 6 | A9 | CLI missing |
| Import + tests | Yes §1–2 | Impl 8–9 | A2,A10 | — |
| Publish live | Yes | Impl 10 | A12 | prod access |

**Blocking forks remaining:** none — D1–D25 closed. Residual non-blocking: prod import may become human `@DO` with logged STOP (not a plan defect).

**Prior-art:** recorded in PLAN §6 — no rebuild of Article/Materials/entry-anatomy.

**Autonomy contract:** PLAN §4 covers ambiguity, stop, fence, commit, publish.

**Gate verdict: PASS** — safe to mint and execute wave-1 unattended under the contract.

---

_Dr. Mārcis Gasūns_
