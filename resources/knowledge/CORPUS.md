# RAG corpus contract — bands, fences, gates

_Created: 17-10-2026 · Last updated: 18-10-2026_

_Owner: Dr. Mārcis Gasūns (content + corpus). Machine owner of the index:
`knowledge_chunks` in the production database (`/var/www/html`)._

This file is the contract for every corpus that can reach the knowledge index
(`knowledge_chunks`) or the support bot. It exists because the FAQ corpus is
**generated elsewhere**: `resources/knowledge/faq.md` is exported from ORS-FAQ,
so a re-export can silently invalidate every citation key the eval fixture and
the stored `faq_chunk_id`s rely on. On 17-10-2026 exactly that happened (see
[Incident 17-10-2026](#incident-17-10-2026-the-sync-that-renamed-81-headings)).

## Bands

| Band | File / table | Status | Source | Licence | Fence | Index command | Eval set |
|---|---|---|---|---|---|---|---|
| FAQ (live) | `resources/knowledge/faq.md` | **live** — 82 chunks today | ORS-FAQ `ors_faq/wiki/` via `python -m ors_faq.tools.export_bot_kb` | Apache-2.0 (ORS-FAQ `LICENSE`) | prices, dates, schedules are **not** in the corpus — the bot injects them from the live course catalog; the file is generated, never hand-edited | `php artisan knowledge:index` | `tests/fixtures/faq_rag_eval.json` (100 Q) |
| Lesson transcriptions | `lessons` → `knowledge_chunks` (`source_type=lesson`) | **OFF** (`LESSON_QA_ENABLED=false`) | lesson transcripts (`knowledge.lesson.*`) | in-house, paid-course content | access is gated by `LessonGate::canWatch`; retrieval is local-only (no external provider); short quotes only (`knowledge.lesson.quote_chars`), never a whole lecture | `php artisan knowledge:index-lessons` (no-ops while the flag is off) | — |
| Support history | `telegram_support_messages` | **fenced** — never indexed | Telegram support sync (`TELEGRAM_SUPPORT_HISTORY_LIMIT`, default 50 dialogs) | in-house, contains student PII | only **anonymised** question text leaves the table, and only into the eval candidate build (`php artisan faq:eval-set-build --exclude-fixture=…`); no chunk is ever written from this table | none — by design | mined questions feed `tests/fixtures/faq_rag_eval.json` (q021–q100) |

Rules that follow from the table:

- **Only `faq` and `lesson` are indexable source types.** `KnowledgeChunk::SOURCE_FAQ`
  and `SOURCE_LESSON` are the whole set; support history has no `source_type`
  and must not grow one.
- The **FAQ-from-lectures band is retired** (18-10-2026, RAG grill card 6):
  `faq_from_lectures.md`, `KnowledgeFaqPublisher`, the parser `extra_paths`
  merge and the `faq_draft` Accept writer are deleted. Accepting a legacy
  `faq_draft` candidate is now editorial-only and writes no knowledge file;
  lecture material can only enter as lesson chunks with a timecode.
- The lesson band is a privacy contour as much as a retrieval one. Turning it on
  is an explicit step: index, verify on your own account, then
  `LESSON_QA_ENABLED=true` + `config:cache`.

## The chunk-id contract

`chunk_id` is the heading-path slug produced by
`FaqCorpusParser::chunkIdFromPath()` (`app/Services/Support/Faq/FaqCorpusParser.php`):
lowercase the heading, replace every run of non-letter/non-digit characters with
`-`, trim `-`, and prefix the H2. H1 is a document title, not a boundary;
`###` sections sit under the current H2.

That makes the id **stable across body edits and fragile across heading
edits**. Every stored `faq_chunk_id` and every `expected_chunk_ids` entry in the
eval fixture is a citation of a heading path, so renaming a heading is a
breaking change even when the answer text is untouched.

## Gates

### 1. Corpus drift gate (CI)

```sh
python scripts/check_faq_corpus_drift.py --check
```

Compares the corpus' chunk-id **set** with the keys the eval fixture cites
(citation-key drift, not byte drift), and refuses on duplicate chunk ids (a
duplicate heading path silently overwrites one body in `knowledge_chunks`,
where `faq_chunk_id` is unique). Exit `0` = every cited key resolves; exit `1` =
drift, with the moved keys printed and the eval items that cite them.

It is wired into CI (`.github/workflows/ci.yml`, job `faq-corpus-drift`) and runs
on every push and PR. It needs no network and no database.

### 2. Gate against ORS-FAQ HEAD (sync ritual)

Before syncing a new export in, render the upstream corpus and compare sets:

```sh
# in the ORS-FAQ checkout
python -m ors_faq.tools.export_bot_kb -o /tmp/faq_head.md

# in Systema-Sanscriticum
python scripts/check_faq_corpus_drift.py --check --upstream /tmp/faq_head.md
```

`--upstream` fails on keys that exist only upstream (local export is stale) and
on keys that exist only locally (renamed/removed upstream — every stored id for
them is dead). CI does not run this mode: it needs the ORS-FAQ checkout.

### 3. Eval ratchet (suite)

`tests/Feature/Support/FaqRagEvalTest.php` asserts that every cited id exists in
the parsed corpus, then measures top-3 / recall@5 / MRR against the ratchet.

### 4. Index parity (production)

```sh
php artisan knowledge:coverage --require-embedded   # exit 0 = every chunk embedded, none stale
php artisan knowledge:index --prune-orphans         # prints the orphan count
php artisan knowledge:coverage --json               # rows should equal chunk count
```

`--prune-orphans` deletes `knowledge_chunks` rows whose `faq_chunk_id` is no
longer in the corpus. It is an explicit flag, never the default, and it cannot
run on an empty parse. `knowledge:index` re-embeds only rows whose content hash
moved, so the sequence prune → index is cheap.

## Re-keying the eval fixture after a sync

When an export renames headings, the fixture's cited ids die. Do not hand-edit
them — derive the mapping from the corpora:

```sh
python scripts/rekey_faq_rag_fixture.py --from-commit <pre-sync-rev> --dry-run
python scripts/rekey_faq_rag_fixture.py --from-commit <pre-sync-rev>
python scripts/check_faq_corpus_drift.py --check    # must be green
php artisan test --filter=FaqRagEval                # ratchet must hold
```

The tool maps in three tiers — byte-identical body, the wiki note's own
`title: "…"` line, then a high-similarity title under the same H2 — and refuses
to write when any dead key has no derived mapping. The mapping it used is
recorded in the fixture under `rekeyed`.

## Incident 17-10-2026: the sync that renamed 81 headings

Commit `9aea235d` ("chore(bot): sync faq.md from ORS-FAQ wiki") re-exported the
corpus and renamed all 81 `###` headings — the export switched from slug-key
headings (`### оплата_блоки`) back to human titles
(`### Оплата: блоки (поблочно vs целиком)`).

- **Eval fixture:** 26 of the 29 distinct cited ids went dead while
  `FaqRagEvalTest` still asserted their presence. Re-keyed to v3 (88 of 100
  items changed one citation); all 26 mapped by body/title identity, none guessed.
- **Production index:** 159 rows against an 82-chunk corpus. `--prune-orphans`
  removed **77** rows; all 77 were **renamed** sections (76 body-identical, 1
  re-punctuated: `йога сутры` → `йога-сутры`), **zero deleted**. The 10 stale
  rows were re-embedded; `knowledge:coverage --require-embedded` now exits 0
  with `rows=82, embedded=82, stale=0, missing=0`.

Why the gate would have caught it: `check_faq_corpus_drift.py --check` names
`политика-и-поддержка/контакты-куда-писать` (cited by `q009`) when that one
heading is renamed in a temp copy — proven by the seeded-rename acceptance
transcript on the H6917 PR.

## Changing this contract

- New corpus band → a new row here **and** a `source_type` constant; never reuse
  `faq`.
- New fence (prices, PII, paid content) → name it in the Fence column and in the
  code comment that enforces it.
- A heading rename is a breaking change for **four** surfaces: the eval
  fixtures' `expected_chunk_ids`, every stored `faq_chunk_id` (prune or re-key),
  `StudentFaqPageTest::PINNED_SECTIONS` (pinned heading text), and any pinned
  corpus id in other tests. The drift gate covers the first two; the suite
  covers the last two.
- Changing the chunk-id derivation is a breaking change for every stored id:
  announce it, re-export, re-index, re-key the fixture, and keep the drift gate
  red until all three are done.

_Dr. Mārcis Gasūns_
