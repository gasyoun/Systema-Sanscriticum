# ROADMAP — Anton operational gaps (Systema-Sanscriticum, 2026 H2 → 2027 Q1)

_Created: 22-07-2026 · Last updated: 22-07-2026_

Waves that close the three operational capabilities Anton has and we lack, in the order ruled
in the interview (D9): **Email → Resume → Kinescope → Clips**. Cover, decisions, and the
autonomy contract: [`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md).

Each wave lands **behind a `config/features.php` flag, OFF**, merged with green tests, plus an
activation-checklist row (D10). No money code, no live sends during build (D12).

---

## Wave 1 · Email channel revival + homegrown campaign engine (highest ROI · Q3 2026)

**Why first:** the channel is dead (`#504`) and a *live* student-facing function is broken
(password reset). Anton's strongest real lead. Reviving it also unblocks the drafted marathon
email sequence (`marketing/marathon-2026-08/marathon-email-sequence.md`).

Two deliverables:

- **W1a — transactional revival.** Wire mailbox SMTP (mail.ru / Yandex 360, D6) as the mailer,
  behind a config switch, so the 27 existing `Mailables` actually send: password reset,
  purchase confirmation, onboarding. Resolves the functional half of `#504`. No new campaign
  concept — just make `MAIL_MAILER` real and safe (per-minute throttle, suppression list).
- **W1b — homegrown campaign engine.** A `Campaign` + `CampaignRecipient` model pair (the
  "статус рассылки" ledger — who got what, when, opened, clicked), an **open-pixel** endpoint,
  a **click-redirect** tracker (personalised per-recipient links, like Anton's n8n+user_id),
  and a **resend-to-non-openers** ("догон") action. Segments reuse the existing `Lead`/`User`
  audience. A Filament `CampaignResource` to compose + send + read stats. All OFF behind
  `email_campaigns`.

**Unblocks:** nothing upstream — this is the root wave.
**Prerequisite (human, activation-time):** sending mailbox + SMTP creds + SPF/DKIM/DMARC (§5 of PLAN).
**Flag:** `email_campaigns` (new); transactional switch via `MAIL_MAILER` + `config/mail.php`.

---

## Wave 2 · In-video resume across all API-capable hosts (Q3 2026)

**Why second:** smallest, self-contained, pure-UX win; the one place Anton's Kinescope beats
us for the *student*. Both Anton and our own `docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md`
flag "continue where you stopped" as weak.

One deliverable:

- Persist playback position on `LessonView` (new additive columns) via the **existing
  `POST /api/heartbeat`** beacon — no new endpoint. A per-host JS resume layer in the lesson
  player (`resources/views/student/lesson.blade.php`) reads/writes `currentTime` through each
  host's player API — YouTube IFrame API, RuTube postMessage, VK VideoPlayer, Kinescope Player
  SDK, Vimeo Player.js (D8) — and offers "продолжить с HH:MM". Graceful no-op where a host
  exposes no API. OFF behind `video_resume`.

**Unblocks:** W3 (the Kinescope pilot reuses this resume layer's Kinescope branch).
**Flag:** `video_resume` (new). **No external prerequisite** — works on hosts already in use.

---

## Wave 3 · Kinescope pilot on one flagship course (Q4 2026)

**Why third:** evaluates the "real video host" gap (D4) at minimal cost — one course, not a
migration. Depends on W2's resume layer already speaking Kinescope's API.

One deliverable:

- Extend `VideoEmbed` + the lesson player to treat Kinescope as a first-class host for **one
  chosen flagship course** (config-scoped), exercising native resume (via W2), chapters, and
  Kinescope's playback analytics. Produce a short comparison memo (native Kinescope resume/
  analytics/DRM vs our iframe+API approach) to inform any later broader migration. OFF behind
  `kinescope_pilot`.

**Unblocks:** a future migration decision (out of scope here).
**Prerequisite (human):** Kinescope account + the pilot course pick (§5 of PLAN).
**Flag:** `kinescope_pilot` (new).

---

## Wave 4 · Clip-extraction marketing pipeline (Q4 2026 → Q1 2027)

**Why last:** biggest, most external-dependency-heavy (VK API), and purely additive lead-gen —
nothing else depends on it. This is Anton's self-feeding-content play.

One deliverable:

- An **n8n workflow** (D7, matching Anton's stack) that: takes a published lecture + its
  **existing AI-verified timecodes** as cut boundaries → runs `ffmpeg` to emit ~N standalone
  fragment files → uploads them to **VK Video/Clips** via the VK API → marks ~3 (staff-
  approved) as free/public. A thin Laravel side records each clip (`LectureClip` model:
  source lesson, timecode span, VK id, is_free) so the funnel is measurable and the free-3
  can surface on landing/материалы pages. The heavy media work stays outside Laravel (n8n +
  `ffmpeg`), the app only orchestrates + records. OFF behind `clip_marketing`.

**Unblocks:** nothing downstream in this plan; extends `docs/ROADMAP_CONTENT_AI_2026_2027.md`.
**Prerequisite (human):** VK app + token; the editorial free-3 policy (§5 of PLAN).
**Flag:** `clip_marketing` (new).

---

## Quarterly layout

| Quarter | Wave(s) | Milestone |
|---|---|---|
| Q3 2026 | W1 (email), W2 (resume) | Dead channel revived + password reset fixed; students resume video |
| Q4 2026 | W3 (Kinescope pilot), W4 start (clips) | One course on Kinescope evaluated; clip pipeline built |
| Q1 2027 | W4 finish | Free fragments self-feeding on VK Video; funnel measurable |

---

## Non-goals (considered, ruled out — do not re-propose)

- **Full Kinescope migration.** D4 caps it at a one-course pilot. Do not migrate the catalogue.
- **An ESP campaign SaaS (SendPulse/Unisender/Postmark-as-campaign-tool).** D5 chose homegrown
  like Anton. Postmark/Mailgun stay unused for campaigns (may serve only as a dumb transactional
  relay if a human later overrides D6 for deliverability).
- **Rebuilding timecodes/transcripts.** We already have AI-verified timecodes + searchable
  transcript + `seekTo()`. W4 reuses them as clip boundaries; it does not rebuild them.
- **Prodamus / a second acquirer.** We have Tochka; Anton's screenshot-OCR grey-zone flow is
  behind us, not ahead. Out of scope.
- **A second/parallel Telegram support bot for scale.** Covered by
  `docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md`, not this plan.
- **Deploying any of this to prod.** Activation is a human step by design (the meta-lesson);
  every wave ends flag-OFF with a checklist row.

_Dr. Mārcis Gasūns_
