# Kinescope pilot comparison — native vs iframe+API (2026)

_Created: 23-07-2026 · Last updated: 24-07-2026_

Short decision memo for **Wave 3** (H1451 / Anton ops-gaps). One flagship course
only (D4). Not a catalogue migration. Architecture:
[ARCHITECTURE §W3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md).
Build: [IMPLEMENTATION W3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE3.md).

**Executor:** Grok 4.5 (`grok-4.5`) via xAI (Sonnet-lock override, user-authorized).
**True redo:** 24-07-2026 — adversarial re-pass after first merge (#665).

---

## True-redo audit (24-07-2026)

| Finding | Fix in redo |
|---|---|
| `.env.example` omitted VIDEO_RESUME / KINESCOPE_* | Documented with DEPLOY_QUEUE pointers |
| Staff may paste kinescope.io into `youtube_url` | `KinescopePilot::candidateUrlFromLesson` + `embedForLesson` |
| Path regex could accept reserved segments (`login`) | `VideoEmbed::kinescopeId` reserved-list + stricter boundary |
| Query/fragment on watch URLs | Strip via regex `(?:[/?#]|$)` |

---

## What the pilot ships

| Layer | Behaviour |
|---|---|
| Scope | `features.kinescope_pilot` + `video.kinescope_pilot_course_id` |
| Source | First Kinescope URL among `video_url` → `youtube_url` → `rutube_url` |
| Render | `#kinescope-player` iframe → `https://kinescope.io/embed/{id}` |
| SDK | `player.kinescope.io/latest/iframe.player.js` loaded only when pilot active |
| Resume | Reuses W2 `VideoResumeAdapters.kinescope` + heartbeat when `video_resume` ON |
| Chapters | Native Kinescope UI inside the player (if configured in Kinescope CMS) |
| Analytics | Native Kinescope dashboard (not mirrored into Laravel) |

All other courses and hosts stay on YouTube/RuTube as before.

---

## Comparison axes

| Axis | Native Kinescope (full host) | Our path (iframe + API + heartbeat) |
|---|---|---|
| **Resume** | Built-in per-viewer position in Kinescope accounts / cookies | Own `lesson_views.last_position_seconds` via existing `POST /api/heartbeat` (H1450); works across hosts; student identity is LMS user, not Kinescope anonymous |
| **Chapters / markers** | First-class in Kinescope editor; shown in player chrome | We already have AI/transcript timecodes on the lesson page (independent of host). Kinescope chapters are extra only when the human uploads markers in Kinescope |
| **Playback analytics** | Heatmaps, drop-off, device — in Kinescope UI | LMS has lesson view time + optional position; no per-second heatmaps unless we build them |
| **DRM / private video** | Kinescope paid plans, domain/token protection | iframe inherits Kinescope privacy settings; LMS access control remains our enrollment gates |
| **Catalogue cost** | Per-video / plan pricing; migration rewrites every `video_url` | Zero migration cost outside one pilot course id |
| **Ops dependency** | New vendor account, upload pipeline, billing | Reuses current YT/RuTube ops for the rest of the catalogue |
| **Student UX lock-in** | Student experience improves only on Kinescope courses | Dual-host switcher still offers YT/RuTube when those ids exist on the same lesson |

---

## Findings for a later migration decision

1. **Resume is not a reason to migrate.** W2 already delivers cross-host «продолжить с HH:MM» without Kinescope. The pilot proves the W2 Kinescope adapter lights up when the iframe+SDK exist — not that resume requires Kinescope.
2. **Analytics/chapters are the real Kinescope upsides** — and they stay inside Kinescope's product surface. If the school does not staff the Kinescope dashboard, the pilot adds little over iframe-only.
3. **D4 still holds.** Full catalogue migration multiplies upload + rights + cost work with no proportional LMS feature gain beyond what W2 already covers for resume.
4. **Recommended default after pilot:** keep hybrid — one (or few) flagship courses on Kinescope if analytics justify the account cost; leave the catalogue on YouTube/RuTube; leave both flags OFF until a human picks the course id and flips `KINESCOPE_PILOT`.

---

## Activation (human)

See [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) №48:
Kinescope account → pick pilot `Course.id` → set `video_url` on its lessons to
Kinescope links → `KINESCOPE_PILOT_COURSE_ID` + `KINESCOPE_PILOT=true` →
`config:clear`. Optional: `VIDEO_RESUME=true` to exercise native resume via W2.

_Dr. Mārcis Gasūns_
