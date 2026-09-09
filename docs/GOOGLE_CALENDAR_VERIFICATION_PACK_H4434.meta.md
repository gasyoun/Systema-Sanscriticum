# GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.meta.md — metadoc about `GOOGLE_CALENDAR_VERIFICATION_PACK_H4434`

_Created: 09-09-2026 · Last updated: 09-09-2026_

## Subject

- **Document:** [GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.md)
- **Purpose:** Step-by-step package for MG's manual Google Cloud Console walkthrough — submits the `calendar`-sensitive-scope app verification that gates Phases 2–4 of the integration roadmap (ready answers, screencast plan, redirect-URI note, lead-time expectation).
- **Audience:** MG (the only executor — Console submissions are a human step); engineers as context-readers.
- **Format/contract:** Console walkthrough + pre-filled form answers; not a build doc — nothing to implement from it, it only unblocks the external gate.

## Provenance

- **Subject created:** 09-09-2026 (H4434, OxAlpha `glm-5.3-flash`, MG ruling «Стартовать верификацию»).
- **Linked:** [GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md) §8 now cites it as the prepared package; submission state is NOT yet recorded (MG has not filed).
- **Students do not need this:** they consume the read-only ICS feed (Phase 1, live since 04-07-2026); OAuth is teachers/admin only.

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Record the actual submission date + Google's response in §8 once MG files | The pack states lead time 3–7 days but nothing tracks the real state; a dated row turns the blocker into schedulable state | open (after MG submits) |
| 2 | Confirm the Phase-2 callback path (`/auth/google/calendar/callback`) against the real implementation before filling the Console form | The pack's URI is a proposal; a mismatch discovered after submission costs a re-review round | open (mint alongside Phase 2) |

## Known limitations / caveats

- Google rejects text-only answers for sensitive scopes — a YouTube screencast (1–3 min) is mandatory; the pack lists the shot list but cannot record it.
- Privacy-policy URL must return 200 before submission; the pack flags this as a pre-check, not a built page.

## Revision history

- 09-09-2026 — authored as part of H4434 phase 7 (MG ruling «Стартовать верификацию»).

_Dr. Mārcis Gasūns_