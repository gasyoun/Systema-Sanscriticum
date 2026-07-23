# IMPLEMENTATION — n8n lecture content engine (Wave 1 Clips extension)

_Created: 23-07-2026 · Last updated: 23-07-2026_

Index: [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md).
Architecture: [`docs/ARCHITECTURE_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md).

**Scope of this file:** Wave 1 only (first of five sequential PRs). Waves 2–5
get their own short implementation sections at the bottom as agent checklists
(not full file-level until that handoff starts — defaults below unstick them).

**Prerequisite:** worktree off **current** `origin/main` (includes H1452
`LectureClip` at `5ce7c92`). Do not reimplement H1452.

---

## Wave 1 — step-ordered build

### Step 0 — Inventory (read-only, 15 min)

Confirm on the branch tip:

- `app/Models/LectureClip.php`
- `app/Services/Lecture/ClipSpanPlanner.php`
- `app/Jobs/DispatchLectureClipExtractionJob.php`
- `docs/n8n/lecture-clip-extract.workflow.json`
- `config/features.php` key `clip_marketing`

If any missing, stop (wrong base). Log commit SHA in the handoff.

### Step 1 — Feature flag + config

**Touches:** `config/features.php`, `.env.example`

- Add `content_from_lectures` default false.
- Document `CONTENT_FROM_LECTURES`, keep `CLIP_MARKETING_*` / n8n clip webhook
  keys as H1452 left them; add only if missing:
  `N8N_LECTURE_CLIP_WEBHOOK`, callback secret (read existing names from H1452
  code — do not invent parallel names).

### Step 2 — `ContentCandidate` model + migration

**Touches:** `database/migrations/*_create_content_candidates_table.php`,
`app/Models/ContentCandidate.php`

- Columns per architecture §2.
- Relations: `lesson()`, `lectureClip()`, `parent()`, `children()`.
- Scopes: `draft()`, `ofType($type)`, `publishable()`.

### Step 3 — `QuotePolicy`

**Touches:** `app/Services/Content/QuotePolicy.php`, unit test

- `assertPublicQuote(?string): void` throws `InvalidArgumentException` if >2
  sentence enders.
- Unit tests: empty, 1 sentence, 2 sentences, 3 sentences, Cyrillic.

### Step 4 — `SpanRanker` (top-N, default 5)

**Touches:** `app/Services/Content/SpanRanker.php`

- Input: list from `ClipSpanPlanner::planSpans($lesson)`.
- Output: reordered/truncated list length ≤ N (config `content.clip_rank_n`,
  default 5).
- **Default algorithm (D12):** score spans by (title keyword richness + mid-lesson
  preference + duration in [45,120]s); optional second pass CuratorAi re-rank when
  `content_from_lectures` and API key present — **tests must force heuristic-only**
  via fake/bound client.
- Never recompute timecode boundaries.

### Step 5 — Wire ranked spans into dispatch

**Touches:** `app/Jobs/DispatchLectureClipExtractionJob.php` (minimal edit)

- Replace raw `ClipSpanPlanner::planSpans` call with
  `SpanRanker::rank(ClipSpanPlanner::planSpans(...), $n)`.
- Keep flag early-return for `clip_marketing`.
- Preserve payload shape to n8n.

### Step 6 — Publish trigger (idempotent)

**Touches:** Lesson observer or existing publish hook; job
`SyncLessonContentCandidatesJob` optional

When lesson becomes published AND `content_from_lectures` AND has
`transcript_file` + video URL:

1. If no `LectureClip` rows yet and `clip_marketing` → dispatch extraction.
2. Do not double-dispatch if clips already exist for `lesson_id` (idempotent).

Exact observer class: find existing Lesson publish path first (prior-art);
prefer one listener over a new observer if one exists.

### Step 7 — Sync clip → ContentCandidate

**Touches:** `app/Services/Content/ContentCandidateSync.php`

- On `LectureClip` created/updated: upsert `ContentCandidate` type=`clip`,
  copy title, mirror `is_free`, link FKs.
- On `is_free` toggle in Filament: update candidate status/meta.

### Step 8 — Filament thin surface

**Touches:** `ContentCandidateResource` or pages under Editor panel

- List filter by type/status/lesson.
- Actions: mark free (for clip type → also set `LectureClip.is_free`), discard.
- No live publish buttons that call real APIs without pilot flag (W2).

### Step 9 — Tests

**Touches:** `tests/Unit/Content/*`, `tests/Feature/Content/*`

Synthetic fixture:

- Fake public disk transcript JSON (Deepgram-shaped or n8n-wrapped).
- Lesson factory published + video URL + transcript path.
- Http::fake for n8n webhook.
- Assert: ranker returns ≤5; dispatch payload span count ≤5; QuotePolicy;
  ContentCandidate upsert; flags OFF → no HTTP.

### Step 10 — DEPLOY_QUEUE + changelog

**Touches:** `DEPLOY_QUEUE.md`, `CHANGELOG.md` `[Unreleased]`

Activation row: import n8n workflow if not live; dry-run one lesson; set free
clips; enable `clip_marketing` + `content_from_lectures`.

### Step 11 — Quality gate

```
php artisan test --filter=Content
php artisan test --filter=LectureClip
./vendor/bin/pint --dirty
```

(Windows agent without PHP: push and rely on CI — note in PR.)

---

## Waves 2–5 — agent checklists (defaults if handoff is thin)

### Wave 2 Social + mailer scaffold

1. `SocialDraftGenerator` from free clip + ≤2-sentence quote.
2. `PublishSocialPostJob` — VK wall + TG; gated by `content_auto_publish_pilot`.
3. `EmailBlastComposer` + `SendContentOneShotMailJob` gated by
   `content_email_oneshot` (August).
4. Tests with Http::fake; never real send in CI.

### Wave 3 FAQ

1. `FaqDraftGenerator` from transcript chunks (no support DB).
2. Accept → append knowledge FAQ source (locate CAI5 path or
   `resources/knowledge/faq.md` pattern).
3. Tests.

### Wave 4 Long-form

1. `ArticleDraftGenerator` weekly pack from new lessons.
2. Draft-only; markdown export optional.
3. Tests + QuotePolicy on any embedded quotes.

### Wave 5 Study materials

1. `StudyArtifactGenerator` (summary, card seeds, homework prompts).
2. Staff review; no pilot auto-publish.
3. Tests.

---

## Defaults log (for D21)

| Fork | Default |
|---|---|
| Ranker without API key | heuristic only |
| Observer vs job for publish | prefer existing Lesson publish hook |
| Filament resource vs custom page | Resource if faster |
| N for expand | config override, default 12 max pack then rank |

_Dr. Mārcis Gasūns_
