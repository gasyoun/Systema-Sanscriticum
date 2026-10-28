# Telegram natural-language class rescheduling — decision log

_Created: 27-10-2026 · Owner: Dr. Mārcis Gasūns_

## Evidence established before questions

- Source announcement: https://t.me/c/3994169966/93, sent 27-10-2026 08:24:54 MSK in the group 63 chat. It explicitly says that today's class did not take place and announces a one-time move to Friday at 08:00.
- Canonical target interpretation: schedule #2856, group #150, moves from Tuesday 27-10-2026 08:00–10:00 MSK to Friday 30-10-2026 08:00–10:00 MSK. Schedule #2857 on 03-11 must remain unchanged.
- `ScheduleMover::reschedule()` already moves exactly one row, preserves duration, resets reminder lifecycle through the `Schedule` model hook, and rejects an occupied target slot.
- `ProcessTelegramZapisiUpdate` already receives the group messages. `AnnounceCancelService` detects the word «перенос», but only offers cancellation of future classes after an explicit button tap; it does not reschedule.
- The example announcement was sent 24 minutes after the original class start. Existing announcement/cancel paths reject past classes.
- Sender Telegram ID 5487293147 is the @rusamskrtam persona account. The current `TelegramCommandAcl` does not recognize it, so existing teacher/staff commands ignore this sender.
- `ScheduleKeeperService` is read-only, OFF by default, and is not the mutation path for this feature.
- The schedule title embeds `27.10.26`; a rescheduling implementation must update embedded dates as well as `start`/`end`, without changing the class number.
- Existing documentation says a one-off move changes only one class and preserves duration. Zoom-specific behavior must remain that of the existing mover; this feature must not silently introduce a new Zoom integration.

## Rulings

### D0 — Default meaning of an explicit reschedule announcement (MG, 27-10-2026)

An explicit announcement of this kind should let the bot move the class to the mentioned day. If no new time is mentioned, the existing start time and duration remain unchanged. The example is a one-time move of one class, not a shift of the recurring series.

### D1 — Execution and confirmation mode (MG, 27-10-2026)

Move immediately only when all confidence and safety checks pass, then post a receipt and provide an undo window. Use confirmation for every ambiguous message.

### D2 — Authorization of the @rusamskrtam sender identity (MG, 27-10-2026)

Link exactly the @rusamskrtam Telegram identity (5487293147) to MG's existing staff identity through the existing identity resolver. Never infer schedule authority from Telegram group-administrator status; all other senders retain the existing teacher/staff ACL.

### D3 — Selecting a class whose start has just passed (MG, 27-10-2026)

Allow an explicitly identified same-calendar-day class, including message 93, when exactly one matching class exists. Keep every older or ambiguous past-start case behind confirmation.

### D4 — Student-facing receipt and notification behavior (MG, 27-10-2026)

Post one reply receipt with the old and new times and a 1-hour Undo button for the authorized sender and staff. Do not post a duplicate standalone announcement; the existing class reminders are rearmed by the move.

### D5 — Treatment of the linked historical announcement (MG, 27-10-2026)

Approved: replay message 93 once through the same production path after deployment and successful safety checks. Move schedule #2856 to 30-10-2026 08:00–10:00 MSK, keyed idempotently by the Telegram message ID, and post the ruled receipt with Undo.

### D6 — Initial rollout scope

Pending.
