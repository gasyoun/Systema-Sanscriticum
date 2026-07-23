# PLAN — n8n lecture content engine (Systema-Sanscriticum, 2026 H2)

_Created: 23-07-2026 · Last updated: 23-07-2026_

Cover index for a layered `/ask` plan. Goal: turn **weekly lecture video +
transcript + AI timecodes** (the data already flowing through n8n / Deepgram /
`RunLectureAiJob`) into a full content engine — five products, one at a time —
without rebuilding what already ships and without live public posts during the
build.

Provenance: `/ask` interview 23-07-2026 (5 rounds, Grok 4.5 `grok-4.5`) + repo
audit of Anton ops-gaps, Content-AI, and the merged H1452 clip pipeline on
`origin/main` (`5ce7c92`).

---

## 1. The honest gap (one paragraph)

Systema already **ingests** lecture media and text (n8n-shaped Deepgram JSON via
[`TranscriptParser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/TranscriptParser.php),
AI structure/correct/slides/timecodes, searchable `seekTo()` transcript). It
already **can cut clips** (H1452 / PR #662: `LectureClip`, `ClipSpanPlanner`,
`DispatchLectureClipExtractionJob`, n8n
[`docs/n8n/lecture-clip-extract.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/lecture-clip-extract.workflow.json),
flag `clip_marketing` OFF). Support-side **content-gap detection** exists
(`content:detect-gaps`, CAI3) but is **out of wave-1 data** by ruling (lecture
sources only). What is missing is the **product loop**: rank the best fragments,
draft social/FAQ/long-form/study units from the same lecture corpus, review in
one place, and (after human activation) auto-publish on a pilot of **Telegram +
VK wall with clips attached**, plus an **August email** one-shot. The bottleneck
remains **built ≠ running** — activation and ranking quality beat new media
plumbing.

---

## 2. Goal for this span (H2 2026)

Ship five sequential, flag-gated product PRs that share one `ContentCandidate`
backbone, reuse H1452 clips + CuratorAi, and leave a single activation checklist
a human can walk (n8n import, VK/TG tokens, flags, August SMTP). "Done" for the
span = all five products merged inert behind flags + pilot auto-publish path
code-complete but OFF until human arming.

---

## 3. Decisions taken (interview 23-07-2026 — do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Span / deliverable | **Full multi-wave H2 engine** (not activate-only, not plan-only) |
| D2 | Products (all five, sequenced) | **Clips → Social text → FAQ → Long-form → Student materials** |
| D3 | Wave-1 data sources | **Lecture video + transcript + AI timecodes only** (no support-gap CAI3 input in early waves) |
| D4 | Publish gate during build | **No live public posts** (flags OFF, mocked APIs in tests) |
| D5 | Pilot after activation | **Telegram + VK wall with VK clips attached**; **email campaign arming in August** |
| D6 | Home | **Systema-Sanscriticum** (`docs/` + `app/Services/Content/*`) |
| D7 | Shared model | **One `ContentCandidate` table + type enum** (`clip`, `social_post`, `faq_draft`, `article`, `study_artifact`, `email_blast`) |
| D8 | Heavy media | **n8n + ffmpeg only**; Laravel orchestrates + records (H1452 boundary) |
| D9 | Relation to prior roadmaps | **Umbrella** that sequences/extends Anton W4 + Content-AI CAI4–7; does not delete them |
| D10 | Text generation | **CuratorAi / OpenRouter** (same caps/privacy pattern as SupportAi) |
| D11 | Clip trigger | **On lesson publish** when timecodes + video URL + transcript exist; idempotent |
| D12 | Clip selection | **AI ranks top-N (default 5)** spans; staff can expand; free flags editorial |
| D13 | VK wall shape | **One wall post per free clip**: blurb + attached clip video; TG mirror |
| D14 | Email | **Minimal one-shot mailer in this plan**; August activation (depends on live SMTP / campaign stack from H1449) |
| D15 | Rights | **Quotes ≤2 sentences** from transcript in public blurbs; full transcript stays login-gated |
| D16 | Merge bar | Green PHPUnit + Pint + flag OFF; **staging n8n dry-run is human activation**, not merge-block when agent has no staging |
| D17 | Success metric (post-activation) | **≥1 free clip published/week for 4 weeks** + **zero paid full-transcript leaks** |
| D18 | STOP conditions | Money/payment code; paid full-text leak path; secret required to *compile/test* without stub |
| D19 | Quote enforcement | **Deterministic validator** on publish/auto (max 2 sentence-ending marks in quote fields) |
| D20 | PR shape | **One plan, five sequential PRs** (same week if capacity allows) — not one mega-PR |
| D21 | Ambiguity | **Pick plan default, log in handoff, continue** |
| D22 | Git authority | **Commit → PR → merge** when green + flag OFF (Systema worktree discipline) |
| D23 | Fence | No money code; no prod creds/deploy; no live public posts during build; no full-transcript publish |
| D24 | Handoffs | **Five execution handoffs**, one product each (Sonnet-tier build) |

---

## 4. Autonomy contract (verbatim — execution agents obey this)

- **On unplanned ambiguity (D21):** choose the option this plan marks as default,
  write the decision + rationale into the wave handoff log, continue. Do not
  stall for chat, do not invent a third architecture.
- **Stop conditions (D18):** halt only for money-code touch, a path that would
  publish full paid transcript text, or a secret that cannot be stubbed for
  tests/compile. Missing VK/n8n/SMTP in the agent environment → stub +
  `DEPLOY_QUEUE.md` activation row, do not block merge.
- **Staging n8n dry-run (D16):** merge on unit/feature tests with mocks; human
  dry-run is a **flag-ON prerequisite**, listed in DEPLOY_QUEUE.
- **Commit authority (D22):** each product handoff authorises commit → PR →
  merge under Systema worktree/PR rules when D16 merge bar is met.
- **Fence (D23):** never edit payment/Tochka/checkout/installments/receivables;
  never deploy or touch prod credentials; never send real VK/TG/email to real
  audiences during the build; additive schema only.

---

## 5. Human prerequisites (activation — do not block code merge)

| When | Need |
|---|---|
| Clips flag ON | Import/update n8n `lecture-clip-extract` workflow; ffmpeg worker URL; VK Video token; `CLIP_MARKETING_ENABLED=true` |
| Pilot auto-publish | TG channel bot admin; VK wall+video scopes; `content_auto_publish_pilot=true` |
| August email | SMTP live (H1449 path); from-address; segment list; one-shot mailer flag |
| Editorial | Policy for `is_free` (~3/lecture or free among top-5) |

---

## 6. Layer docs

- **Roadmap:** [`docs/ROADMAP_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md)
- **Architecture:** [`docs/ARCHITECTURE_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md)
- **Implementation (W1 Clips extension):** [`docs/IMPLEMENTATION_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md)
- **Verification + risks:** [`docs/VERIFICATION_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE.md)
- **Metadoc:** [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.meta.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.meta.md)

Siblings (not replaced): [`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md),
[`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md).

---

## 7. Autonomy-readiness gate verdict

**PASS** for wave-1 (Clips extension) and for the five-handoff fan-out:

- Architecture, ordered steps, acceptance, and risks exist per wave.
- Zero blocking forks inside wave-1 (ranker default top-5; reuse H1452; staging dry-run demoted to activation).
- Prior-art recorded: **do not rebuild** `LectureClip` / n8n extract / `TranscriptParser` / CuratorAi.
- Autonomy contract covers ambiguity, stop, commit, fence.

Residual non-blocking: August SMTP availability is an activation `@DO`, not a
wave-1 code stop.

_Dr. Mārcis Gasūns_
