# ROADMAP — n8n lecture content engine (2026 H2)

_Created: 23-07-2026 · Last updated: 19-08-2026_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** у программы есть текущий план — [PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md).

Index: [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md).

Five waves = five sequential PRs = five handoffs. Each wave unblocks the next by
filling `ContentCandidate` rows that later waves can reference (e.g. social posts
attach free clips from W1).

---

## Non-goals

- Support-gap / CAI3 as **input** to early waves (D3) — may join later as a
  second signal after lecture-only loop works.
- Instagram / new social networks.
- Rebuilding H1452 ffmpeg/n8n boundary inside Laravel.
- Public full-transcript dumps or paid-lesson body republish.
- Money/payment code.
- Live public posts during the build (D4/D23).
- One mega-PR landing all five production surfaces (D20).

---

## Wave 1 — Clips extension (reuse H1452)

**Unblocks:** physical fragments + free flags + ranked shortlist.

**Prior art (already on `main` via PR #662):** `LectureClip`, `ClipSpanPlanner`
(duration packing, max 12), `DispatchLectureClipExtractionJob`, callback webhook,
Filament `LectureClipResource`, n8n `lecture-clip-extract.workflow.json`, flag
`clip_marketing`.

**This wave builds only the gap:**

1. `ContentCandidate` model + migration (types include `clip`; FK optional
   `lecture_clip_id` / `lesson_id`).
2. **AI top-N ranker** (default N=5) over planned spans — LLM via CuratorAi or
   scored heuristic + optional LLM re-rank; staff expand beyond N in Filament.
3. **Publish trigger** (D11): on lesson publish when video URL + transcript +
   non-empty spans exist → dispatch extraction (idempotent).
4. Map free `LectureClip` rows into `ContentCandidate` type=`clip` for the shared
   digest UI scaffold (minimal list page OK if full digest is W2).
5. Quote/title fields prepared for social blurbs (no auto-publish yet).
6. Tests: synthetic lesson fixture; mocks for n8n; flag OFF default.
7. `DEPLOY_QUEUE.md` row: human n8n dry-run + VK token before `clip_marketing` ON.

**Flag:** `clip_marketing` (existing) + `content_from_lectures` (new master).

**Done when:** merge green; no live VK; DEPLOY_QUEUE activation row present.

---

## Wave 2 — Social text (VK wall + Telegram)

**Unblocks:** pilot copy surface; one post per free clip (D13).

1. Generator: for each free clip candidate, draft VK blurb + TG blurb (≤2-sentence
   quote rule, D15/D19).
2. `ContentCandidate` type=`social_post` linked to clip/lesson.
3. Filament review: Accept / Edit / Discard.
4. `PublishSocialPostJob` (mocked in tests): VK wall.post with video attachment +
   TG channel message — **behind** `content_auto_publish_pilot` (default OFF).
5. Minimal **one-shot mailer** scaffold (D14): type=`email_blast` draft from same
   weekly free clips; **send** behind separate flag, August activation only.
6. Tests + DEPLOY_QUEUE for TG/VK tokens.

**Depends on:** W1 free clips existing (or synthetic fixtures).

---

## Wave 3 — FAQ from lectures

**Unblocks:** knowledge-base drafts grounded in what was taught (not support gaps).

1. Mine transcript+timecode titles for FAQ-shaped Q/A candidates (CuratorAi).
2. type=`faq_draft`; Accept appends to cabinet FAQ source / knowledge path
   (reuse CAI5 target pattern from Content-AI roadmap without CAI3 input).
3. No public auto-publish; Accept is the publish to knowledge base.
4. Tests with synthetic transcript fixtures.

**Depends on:** W1 data model; published lessons with transcripts.

---

## Wave 4 — Long-form (SEO / blog / newsletter body)

**Unblocks:** search + newsletter body assets.

1. type=`article` — outline + sections from one or more lessons (weekly pack).
2. Draft-only Filament; optional export markdown.
3. Newsletter body can feed August `email_blast` (still no send without flag).
4. Hard quote/length validators; no full lecture paste.

**Depends on:** ContentCandidate + CuratorAi path.

---

## Wave 5 — Student study materials

**Unblocks:** pedagogy reuse of the same lecture corpus.

1. type=`study_artifact` — summary, flashcard seed list, homework prompts.
2. Surfaces: staff review first; optional later student cabinet (out of scope to
   redesign whole UX — additive panel only if cheap).
3. Not on pilot auto-publish channels.
4. Tests.

**Depends on:** ContentCandidate backbone.

---

## Activation track (human, parallel)

| Flag / date | Action |
|---|---|
| After W1 merge | n8n dry-run one lesson; set free clips; `clip_marketing=true` |
| After W2 merge | Arm pilot: `content_auto_publish_pilot=true` on TG + VK wall+clips |
| August | SMTP + segment + one-shot mailer flag for email campaign |
| Ongoing metric | ≥1 free clip/week × 4 weeks; audit quote leaks = 0 |

---

## Mapping to older tickets

| Older | This plan |
|---|---|
| Anton W4 / H1452 | **Done as media plumbing**; W1 extends ranker + ContentCandidate + publish trigger |
| CAI4 weekly digest | Shared Filament digest over `ContentCandidate` (grows W1–W2) |
| CAI5 FAQ drafts | W3, lecture-sourced not support-sourced |
| CAI6 social drafts | W2 |
| CAI7 one-click publish | W2 pilot auto path (flagged) |

_Dr. Mārcis Gasūns_
