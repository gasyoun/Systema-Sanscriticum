# ARCHITECTURE — n8n lecture content engine

_Created: 23-07-2026 · Last updated: 23-07-2026_

Index: [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md).

---

## 1. Component map

```
Lesson publish (video + transcript_file + AI timecodes)
        │
        ▼
 ClipSpanPlanner (existing) ──► SpanRanker (new, top-N)
        │
        ▼
 DispatchLectureClipExtractionJob ──webhook──► n8n ffmpeg/VK
        │                                              │
        │◄──────── callback LectureClip rows ──────────┘
        ▼
 ContentCandidate (type=clip, lecture_clip_id, is_free mirrored)
        │
        ├── Span / SocialDraftGenerator ──► type=social_post
        ├── FaqDraftGenerator            ──► type=faq_draft
        ├── ArticleDraftGenerator        ──► type=article
        ├── StudyArtifactGenerator       ──► type=study_artifact
        └── EmailBlastComposer           ──► type=email_blast
        │
        ▼
 Filament Content digest (Accept / Edit / Discard)
        │
        ├── knowledge publish (FAQ Accept)
        └── PublishSocialPostJob / OneShotMailer  [flags OFF until activation]
                 │
                 ├── Telegram channel
                 └── VK wall.post + clip attachment
```

---

## 2. Data model

### `content_candidates` (new)

| Column | Notes |
|---|---|
| `id` | PK |
| `type` | enum string: `clip`, `social_post`, `faq_draft`, `article`, `study_artifact`, `email_blast` |
| `status` | `draft`, `accepted`, `published`, `discarded`, `failed` |
| `lesson_id` | FK nullable |
| `lecture_clip_id` | FK nullable → `lecture_clips` |
| `parent_id` | nullable self-FK (e.g. social_post → clip candidate) |
| `title` | short |
| `body` | main draft text |
| `quote` | optional transcript quote (validator: ≤2 sentences) |
| `channel_target` | `vk_wall`, `telegram`, `email`, `cabinet_faq`, `internal`, null |
| `meta` | JSON (spans, scores, model usage, error) |
| `published_at` | nullable |
| `created_at` / `updated_at` | |

Indexes: `(type, status)`, `(lesson_id)`, `(lecture_clip_id)`.

### Existing (reuse, do not fork)

- `lecture_clips` — physical cut result (H1452).
- `lessons.transcript_file`, video URL fields, publish flag.
- `features.clip_marketing` and new feature flags in `config/features.php`.

---

## 3. Interfaces / contracts

### Laravel → n8n (existing H1452 shape)

Outbound (extend only if needed for ranked span subset):

```json
{
  "action": "clip_lecture",
  "lesson_id": 123,
  "lesson_title": "…",
  "video_url": "https://…",
  "callback_url": "https://app…/api/webhooks/lecture-clip-callback",
  "spans": [
    { "start_seconds": 120, "end_seconds": 210, "title": "…" }
  ]
}
```

W1 change: `spans` = **top-N ranked** only (default 5), not all duration packs.
Staff "expand" re-dispatches with a larger N or explicit span list.

### n8n → Laravel callback (existing)

Creates/updates `LectureClip` with `vk_video_id` / `vk_owner_id`. Content layer
syncs a `ContentCandidate` type=`clip` row (observer or job after callback).

### CuratorAi prompts (new service methods)

`App\Services\Content\*` — each generator returns structured DTO →
`ContentCandidate::create`. Daily cap / privacy gates mirror SupportAi
(prefilter first; no student PII in prompts; lecture text only).

### Quote validator

`App\Services\Content\QuotePolicy::assertPublicQuote(?string $quote): void`

- null/empty OK
- count of `.` `!` `?` (sentence enders after trim) ≤ 2
- hard-fail on publish/auto; soft-flag on draft save

---

## 4. Build-vs-reuse verdict

| Piece | Verdict | Evidence |
|---|---|---|
| Transcript parse | **Reuse** | `TranscriptParser` n8n-wrapped Deepgram |
| Timecodes | **Reuse** | `LectureAiClient::verifyTimecodes` / `RunLectureAiJob` |
| Clip cut + VK upload | **Reuse** | H1452 n8n workflow + `DispatchLectureClipExtractionJob` |
| Clip admin | **Reuse + thin extend** | `LectureClipResource` + free flags |
| Span packing | **Reuse** | `ClipSpanPlanner` |
| Top-N rank | **Build** | New `SpanRanker` (LLM or score) — gap vs duration-only packer |
| ContentCandidate | **Build** | Unified review/publish unit (D7) |
| Social/FAQ/article/study generators | **Build** | CuratorAi wrappers |
| Support gap detector | **Do not use as W1–W3 input** | D3; keep for later dual-signal |
| One-shot mailer | **Build thin** | D14; do not replace H1449 campaign engine |
| Monthly schedule n8n | **Leave alone** | Unrelated schedule posts |

---

## 5. Feature flags

| Flag | Default | Role |
|---|---|---|
| `content_from_lectures` | OFF | Master switch for generators / publish trigger |
| `clip_marketing` | OFF | Existing H1452 outbound n8n |
| `content_auto_publish_pilot` | OFF | TG + VK wall auto after Accept-or-policy |
| `content_email_oneshot` | OFF | August one-shot send |

---

## 6. File layout (D, Round 3)

```
app/Models/ContentCandidate.php
app/Services/Content/
  SpanRanker.php
  QuotePolicy.php
  SocialDraftGenerator.php
  FaqDraftGenerator.php
  ArticleDraftGenerator.php
  StudyArtifactGenerator.php
  EmailBlastComposer.php
  ContentCandidateSync.php
app/Jobs/PublishSocialPostJob.php
app/Jobs/SendContentOneShotMailJob.php
app/Filament/.../ContentCandidateResource.php  (or ContentDigest page)
docs/n8n/lecture-clip-extract.workflow.json     (existing; edit only if payload needs rank metadata)
tests/Feature/Content/...
tests/Unit/Content/...
```

_Dr. Mārcis Gasūns_
