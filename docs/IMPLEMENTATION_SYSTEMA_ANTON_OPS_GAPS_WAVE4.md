# IMPLEMENTATION — Wave 4 (n8n clip-extraction marketing pipeline)

_Created: 23-07-2026 · Last updated: 23-07-2026_

File-level, step-ordered build sequence for **Wave 4 only** (D9). Architecture:
[docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md).
Acceptance: [docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md) § W4.
Roadmap: [docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md).
Content-AI cross-link: [docs/ROADMAP_CONTENT_AI_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md).

Heavy media (ffmpeg + VK upload) lives **outside** Laravel in n8n (D7). Laravel only
orchestrates (outbound webhook) and records (inbound callback). No money code; flag
`clip_marketing` OFF by default; tests never call a real VK API (D12).

---

## Step 0 — this IMPLEMENTATION doc

This file. Depends on: none.

## Step B1 — feature flag

Files: `config/features.php` — `'clip_marketing' => (bool) env('CLIP_MARKETING_ENABLED', false)`
with the repo-idiom doc comment. Depends on: none.

## Step B2 — model + migration (additive)

Files: `app/Models/LectureClip.php`,
`database/migrations/2026_07_23_100000_create_lecture_clips_table.php`
(`lesson_id` fk, `start_seconds`, `end_seconds`, `title`, `vk_video_id`, `vk_owner_id`,
`is_free` default false, `published_at` nullable; index `(lesson_id, is_free)`).
`Lesson::clips()` hasMany. `LectureClip::scopeFree()`. Depends on: B1.

## Step B3 — span planner (reuse AI timecodes)

Files: `app/Services/Lecture/ClipSpanPlanner.php` — reads
`TranscriptParser::sentencesFromPublicFile($lesson->transcript_file)` (same source as
`LectureAiClient::verifyTimecodes` / `RunLectureAiJob` timecodes). Groups into ~90 s
spans, max 12. Does **not** recompute boundaries. Depends on: B2.

## Step B4 — outbound dispatch job

Files: `app/Jobs/DispatchLectureClipExtractionJob.php` — early-return if flag OFF /
unpublished / no spans / empty `services.n8n.clip_extract_webhook`. POSTs
`action=clip_lecture` + spans + `callback_url` with `X-Webhook-Secret` =
`clip_extract_secret`. Depends on: B3.

## Step B5 — inbound callback (secret-guarded)

Files: `app/Http/Middleware/VerifyLectureClipCallbackWebhook.php`,
`app/Http/Controllers/Webhooks/LectureClipCallbackWebhookController.php`,
`routes/api.php` (`POST /api/webhooks/lecture-clip-callback`, name
`webhook.lecture-clip-callback`), `app/Http/Kernel.php` alias
`verify.n8n.clipcallback`. Flag OFF → 404 before secret check. Idempotent
`firstOrCreate` on `(lesson_id, start_seconds, end_seconds)`. Depends on: B2.

## Step B6 — n8n workflow (importable JSON)

Files: `docs/n8n/lecture-clip-extract.workflow.json`, section in `docs/n8n/README.md`.
Linear flow: Webhook → Code (validate spans) → (operator-configured) ffmpeg/VK steps as
HTTP Request stubs with placeholders → HTTP Request callback to Laravel. Depends on: B4, B5.

## Step B7 — Filament admin

Files: `app/Filament/Resources/LectureClipResource.php` + pages — toggle `is_free`,
read-only lesson/VK ids; header action «Нарезать лекцию» dispatches B4. Gated on flag +
`RoleGate::adminOnly()`. Depends on: B5, B4.

## Step B8 — tests

Files under `tests/Feature/LectureClips/` and `tests/Unit/Lecture/ClipSpanPlannerTest.php`.
`Http::fake` for outbound; mocked JSON for inbound; free-scope surface; resource gate.
Depends on: all above.

## Step B9 — changelog + DEPLOY_QUEUE activation

Files: `CHANGELOG.md` `[Unreleased]`, `DEPLOY_QUEUE.md` row №47 (VK app + Video/Wall token,
import n8n workflow, set `N8N_CLIP_*` secrets, migrate, flip `CLIP_MARKETING_ENABLED`).
Depends on: all above.

---

## Dependency graph

```
B1 → B2 → B3 → B4 ─┐
        B2 → B5 ───┼→ B6 → B7 → B8 → B9
```

Ambiguity policy (D11): ffmpeg host path and VK upload node credentials are operator
placeholders in the n8n JSON — agents do not ship live VK tokens. Free-clip public
landing wire-up beyond `LectureClip::scopeFree()` is an activation/content step
(staff pick ~3 free; surface can reuse existing materials/landing partials).

_Dr. Mārcis Gasūns_
