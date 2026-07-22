# ARCHITECTURE — Anton operational gaps (Systema-Sanscriticum)

_Created: 22-07-2026 · Last updated: 22-07-2026_

Component boundaries, data model, and the build-vs-reuse verdict per piece for the four waves.
Cover + decisions: [`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md).
Stack is **Laravel 12 / PHP 8.3 / Filament v3 / Vite 8 / Tailwind 4** (the repo `CLAUDE.md`
still says Laravel 10/PHP 8.1/Vite 5 — stale; `composer.json` + `package.json` are
authoritative — fix in passing).

---

## W1 · Email — transactional revival + homegrown campaigns

### W1a transactional (reuse, don't build)

- **Reuse**: the 27 `app/Mail/*` `Mailables`, Horizon queues, the `mail` config. The only
  change is making the mailer real: `MAIL_MAILER=smtp` against a mailbox relay (D6), plus a
  send throttle + a `SuppressedEmail` list so a bad address never loops. No new model for W1a.
- **Verdict**: reuse. Building a mailer is reinvention; the gap is *configuration + a safety
  valve*, not code.

### W1b campaign engine (build — no prior art)

Prior-art check (verified): there is **no** `Campaign`/`EmailCampaign` model and **no** open/
click tracking anywhere in the app. Nearest analogues are `Announcement` (segment broadcast,
no tracking), `MessageTemplate`, `LeadStepEmail`, `SubscriberMagnet` — none is a trackable
campaign. So this is a genuine build, kept deliberately small (Anton's is a "коленка" too).

Data model (all additive):

| Model / table | Purpose | Key columns |
|---|---|---|
| `Campaign` | one рассылка | `subject`, `body_html`, `segment` (json filter), `status` (draft/sending/sent), `sent_at`, `flag`-gated |
| `CampaignRecipient` | the "статус рассылки" ledger — one row per person per campaign | `campaign_id`, `user_id`, `email`, `sent_at`, `opened_at`, `clicked_at`, `bounced_at`, `pixel_token` (unique), `resend_of_id` (nullable, for догон) |
| `SuppressedEmail` | hard-bounce / unsubscribe suppression | `email`, `reason`, `suppressed_at` |

Endpoints (public, unauthenticated by necessity, token-scoped, no PII in query — see privacy
rule): `GET /e/o/{pixel_token}.gif` (1×1 open pixel → stamps `opened_at`), `GET /e/c/{pixel_token}/{link_token}` (click redirect → stamps `clicked_at`, 302 to the real URL). Tokens are
opaque; the recipient's identity is resolved server-side from the token, never passed in the URL.

Send path: `CampaignSender` (queued job per recipient, respects the W1a throttle + suppression),
renders `body_html` with per-recipient pixel + rewritten links. Resend: a `Campaign` action
that creates a new `Campaign` targeting `CampaignRecipient` rows with `opened_at IS NULL`
(Anton's догон), linked via `resend_of_id`.

Admin: a Filament `CampaignResource` (compose, pick segment, send, read open/click stats). Reuse
the existing Filament patterns (`AnnouncementResource` is the closest template to copy).

- **Verdict**: build `Campaign`/`CampaignRecipient`/`SuppressedEmail` + two tracking endpoints +
  `CampaignSender` + `CampaignResource`. Reuse Mailables, queues, `Lead`/`User` segments,
  Filament scaffolding.

---

## W2 · In-video resume (build small, reuse the beacon)

### Storage (reuse the model, add columns)

`app/Models/LessonView.php` (verified fillables: `first_opened_at`, `last_opened_at`,
`last_heartbeat_at`, `open_count`, `total_time_on_page`, `is_completed`) gains three additive
columns:

| Column | Meaning |
|---|---|
| `last_position_seconds` | where the student last was (for "resume here") |
| `max_position_seconds` | furthest point reached (progress signal, monotonic) |
| `video_duration_seconds` | denominator for a %-watched progress bar (nullable) |

### Transport (reuse — no new endpoint)

The existing `POST /api/heartbeat` → `HeartbeatController` (`routes/web.php:335`,
`auth`+sanctum) already fires from the lesson player. **Extend its payload** with an optional
`position` + `duration`; the controller writes the three columns. No new route, no new auth
surface. This is the single most important reuse decision — it keeps resume inside a proven,
throttled beacon rather than a new write endpoint.

### Player layer (build — the only genuinely new surface)

A host-agnostic JS module in the lesson player (`resources/views/student/lesson.blade.php`,
which already has the `seekTo()` timecode logic at lines 84–91 / 420–421 per
`docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md:152`) with one adapter per host:

| Host | API used | resume read/write |
|---|---|---|
| YouTube | IFrame Player API | `getCurrentTime()` / `seekTo()` |
| RuTube | `postMessage` player events (`player:changeState`, `player:currentTime`) | message in/out |
| VK | VK Open API `VK.VideoPlayer` | `getCurrentTime()` / `seek()` |
| Kinescope | Kinescope Player SDK (`postMessage`) | SDK getters/setters |
| Vimeo | Player.js | `getCurrentTime()` / `setCurrentTime()` |

On load, if `last_position_seconds > 0` and not completed, offer "продолжить с HH:MM"; on
periodic tick, POST position through the heartbeat. Hosts with no usable API degrade to today's
behaviour (start from 0) — no error.

- **Verdict**: build the JS adapter layer + the migration + the `HeartbeatController` payload
  extension. Reuse `LessonView`, `/api/heartbeat`, and the existing `seekTo()` timecode code.

---

## W3 · Kinescope pilot (extend, don't fork)

- **Extend** `app/Support/VideoEmbed.php` (currently YouTube/RuTube/VK/Vimeo — verified) to
  recognise Kinescope URLs and emit its embed, **scoped to one flagship course** via config
  (e.g. `config/video.php` `kinescope_pilot_course_id`). Kinescope is already referenced as an
  embed host in `app/Filament/Resources/LandingPageResource.php`, so the admin surface exists.
- **Reuse** W2's Kinescope resume adapter — the pilot's whole point is native resume + chapters
  + analytics with zero migration. Output: a comparison memo (native vs iframe+API).
- **Verdict**: extend `VideoEmbed` + a config scope + reuse the W2 adapter. No new model.

---

## W4 · Clip-marketing pipeline (orchestrate outside, record inside)

### Boundary (the load-bearing decision)

Heavy media work lives **in n8n + `ffmpeg`** (D7), *outside* Laravel; the app only orchestrates
and records. This mirrors Anton and matches the existing n8n boundary (`docs/n8n/README.md`,
`config/services.php` `n8n`), which already does schedule→Sheets and monthly VK/Telegram posts.

### Cut boundaries (reuse — do not rebuild)

Clip spans come from the **existing AI-verified timecodes** (`LectureAiClient::verifyTimecodes`,
`RunLectureAiJob` `timecodes` task, `TranscriptParser`). W4 does not compute new boundaries; it
consumes the ones we already generate. This is the prior-art win that keeps W4 small.

### Data model (additive)

| Model / table | Purpose | Key columns |
|---|---|---|
| `LectureClip` | one emitted fragment | `lesson_id`, `start_seconds`, `end_seconds`, `title`, `vk_video_id`, `vk_owner_id`, `is_free`, `published_at` |

### Flow

Laravel enqueues a "clip this lecture" request (webhook to n8n with lesson id + timecode spans)
→ n8n pulls the source, runs `ffmpeg` per span, uploads each fragment to VK Video/Clips, calls
back a Laravel webhook with VK ids → Laravel writes `LectureClip` rows. Staff mark ~3 `is_free`
in a Filament `LectureClipResource`; free clips can surface on landing/материалы pages and the
funnel is measurable (clip → VK views → lead).

- **Verdict**: build `LectureClip` + a Filament resource + two thin webhooks (out to n8n, back
  from n8n) + the n8n workflow itself. Reuse the AI timecodes, the n8n boundary, and VK auth
  patterns already in the repo.

---

## Cross-cutting

- **Flags**: four new `config/features.php` entries following the repo idiom exactly
  (`'feature' => (bool) env('FEATURE', false)` + a doc comment) — `email_campaigns`,
  `video_resume`, `kinescope_pilot`, `clip_marketing`. All default OFF.
- **Fence** (D12): none of this touches `PaymentObserver`, the Tochka webhook, checkout,
  installments, receivables, or the prana wallet. Migrations are additive only.
- **Privacy**: tracking-pixel/click tokens are opaque and resolved server-side; no `user_id`
  or email ever appears in a tracking URL (org privacy rule).

_Dr. Mārcis Gasūns_
