# VERIFICATION — Anton operational gaps (Systema-Sanscriticum)

_Created: 22-07-2026 · Last updated: 22-07-2026_

Acceptance criteria per deliverable + the risks/spikes register. Because the agent cannot reach
prod (D10: done = merged + green + flag-OFF + activation row), every criterion is provable in
CI/local with `php artisan test` and `./vendor/bin/pint`, never in prod. Cover:
[`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md).

---

## Acceptance criteria

### W1a — transactional revival

- With the flag/config set in a test env, sending any of the 27 `Mailables` uses the configured
  transport (assert via `Mail::fake()` that the mailable is queued/sent) — no `mailpit`
  swallowing in the code path.
- A `SuppressedEmail` address is never sent to (feature test: seed a suppression, attempt a
  send, assert nothing goes out).
- The per-minute throttle is honoured (unit test on the throttle guard).
- Password-reset mail is exercised by a feature test (the live `#504` break has a regression
  test).

### W1b — campaign engine (all with `email_campaigns` ON in the test)

- `CampaignSender` creates exactly one `CampaignRecipient` per resolved segment member, each
  with a unique `pixel_token`; suppressed addresses excluded.
- Hitting `/e/o/{token}.gif` stamps `opened_at` once and returns a 1×1 gif; a second hit does
  not double-stamp.
- Hitting `/e/c/{token}/{link}` stamps `clicked_at` and 302s to the decoded, allowlisted target;
  a non-allowlisted target is rejected (no open redirect).
- With the flag OFF: `CampaignResource` is hidden, tracking routes 404, `CampaignSender` early-
  returns — asserted by tests (the "prod-inert" guarantee).
- Догон: `resend()` builds a new campaign targeting only `opened_at IS NULL` recipients, linked
  via `resend_of_id` (feature test with mixed opened/unopened rows).
- No PII in any tracking URL (test asserts the URL contains only the opaque token).

### W2 — in-video resume

- `POST /api/heartbeat` with `position`/`duration` persists `last_position_seconds`,
  `max_position_seconds` (monotonic — never decreases), `video_duration_seconds` on `LessonView`
  (feature test).
- `max_position_seconds` never regresses below a previously stored value (unit test on the write
  path).
- The migration is additive (no change to existing `LessonView` columns) — asserted by the
  schema test / a `migrate:fresh` on SQLite passing the full suite.
- JS adapter layer: a lightweight test (or documented manual QA in the wave handoff) that each
  host adapter no-ops cleanly when the player API is absent.

### W3 — Kinescope pilot

- `VideoEmbed` emits a correct Kinescope embed for a Kinescope URL **only** for the configured
  pilot course id; all other courses/hosts behave exactly as before (feature test with the
  pilot-course config set and unset).
- The comparison memo exists (`docs/KINESCOPE_PILOT_COMPARISON_2026.md`) with native-vs-iframe
  findings.

### W4 — clip pipeline

- `LectureClip` rows are created from an n8n callback with correct `start/end_seconds` derived
  from the lesson's existing timecodes (feature test mocking the callback).
- With `clip_marketing` OFF: the outbound "clip this lecture" webhook is not dispatched and the
  callback route 404s (prod-inert test).
- Exactly the staff-marked clips carry `is_free = true`; the free-3 surface only where flagged.
- No real VK API call in the test suite (VK client faked).

### Span-level "done"

All four waves merged, `php artisan test` green, `./vendor/bin/pint` clean, all four flags OFF
by default, and a single consolidated activation section appended to `DEPLOY_QUEUE.md`.

---

## Risks & spikes register

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | **Mailbox SMTP (D6) deliverability at campaign volume.** mail.ru/Yandex 360 mailboxes rate-limit and can throttle/suspend on bulk send; spam-folder placement kills the channel Anton says is his most reliable one. | High | SPF/DKIM/DMARC on the sending domain (activation prerequisite); the A2 per-minute throttle; warm-up (small segments first); a hard suppression list. **Escalation note for the human:** if staging shows poor placement, D6 can be overridden to "Postmark/Mailgun as a dumb transactional relay" (already installed) without changing any W1b code — the campaign engine is transport-agnostic. Record in the activation checklist. |
| R2 | **Open/click tracking under-counts** (image-proxy pre-fetch inflates opens; privacy blockers suppress them). | Low | Treat opens as a soft signal; clicks (which require intent) as the primary metric — same as any ESP. Document the caveat on the `CampaignResource` stats. |
| R3 | **Player-API coverage gaps** — RuTube/VK postMessage APIs are under-documented and can change; some embeds expose no position API. | Medium | D8 says degrade gracefully; each adapter is isolated and no-ops on failure. Ship YouTube+Vimeo+Kinescope (best-documented) first inside the wave if RuTube/VK prove flaky; log which degraded. |
| R4 | **Kinescope cost/lock-in** beyond the pilot. | Low (pilot-scoped) | D4 caps exposure at one course; the memo informs any later decision. No catalogue migration in scope. |
| R5 | **VK API app approval + scope** (Video/Wall upload) is a human, possibly slow, step. | Medium | Activation prerequisite (§5 of PLAN); the build stubs the VK client behind the flag so code compiles/tests without it. |
| R6 | **The deploy/activation gate** — even fully built + flag-ready, none of this is live until a human with VPS creds runs the migrations + flips flags. This is the meta-lesson, not a code risk. | High (organisational) | Each wave ends with an explicit `DEPLOY_QUEUE.md` row; the PLAN foregrounds activation over construction. Out of the agent's hands by design (D12). |
| R7 | **Windows worktree hazard** — `git worktree` + junctioned `vendor/` can silently run wrong code / wipe real vendor (repo `.ai_state.md` dev note). | Medium | Author docs/tests in a worktree but **do not** junction `vendor/`; run tests in the main checkout or a clean `composer install`. The agent must not copy/junction `vendor` into the worktree. |

### Spikes to run before committing to the arch

- **S1 (W1, before B4):** confirm mail.ru/Yandex 360 either exposes a bounce webhook or is IMAP-
  scannable — decides A3's shape. 30-min check.
- **S2 (W2, before the adapter build):** confirm RuTube and VK expose a usable current-time API
  from an embedded player in 2026 (they shift). If not, R3's fallback ordering applies.

_Dr. Mārcis Gasūns_
