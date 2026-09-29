_Created: 16-09-2026 · Last updated: 16-09-2026_

# H4966: trial-pin freshness monitor + split cabinet invite from the 60-min password-reset link (Sonnet 5 `claude-sonnet-5`, 16-09-2026)

Two live-proven acquisition defects, both a hand-pinned single row/one-shot marker that silently expired with no freshness gate and no monitor. Evidence: Uprava [reports/BLEED_AUDIT_TRIAL_FUNNEL_AND_CABINET_INVITES_16-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/reports/BLEED_AUDIT_TRIAL_FUNNEL_AND_CABINET_INVITES_16-09-2026.md).

- **`Course::hasStaleTrialPin()`** — a visible course with `trial_price > 0` whose `trial_schedule_id` points into the past (or a deleted row). Used by both the new monitor and the public feed.
- **Daily monitor `trial:check-freshness`** (09:00 MSK, [`app/Console/Concerns/SchedulesStudentsAndContent.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/SchedulesStudentsAndContent.php)) — alerts admins in Telegram via `TelegramAdminNotifier` on every stale course found; never a silent no-op.
- **Feed honesty:** [`PublicScheduleResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php) now carries `course.trial_pin_stale` — a stale pin is now distinguishable from a disabled trial, not just a silent `bookable:false`.
- **Cabinet invite split from password reset:** new `MagicLinkToken` purpose `cabinet_invite` (7-day TTL) + [`CabinetInviteLinkController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/CabinetInviteLinkController.php) (`/cabinet-invite/{token}`, route `cabinet.invite`), replacing the 60-minute password-reset broker link that `SendCabinetInvites` used on ALL four channels (Telegram/VK/SMS/email). Email branch now sends a dedicated [`CabinetInviteMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/CabinetInviteMail.php) instead of the built-in `Password::sendResetLink()`.
- **Re-invite is no longer permanent:** `SendCabinetInvites` no longer excludes a user forever once `cabinet_invite_sent_at` is set — without `--resend`, a user still `login_count=0` past `AUTO_RESEND_AFTER_DAYS` (7) re-enters the batch automatically.
- **Tests:** [`CheckTrialFreshnessTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CheckTrialFreshnessTest.php) (3), extended [`SendCabinetInvitesTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SendCabinetInvitesTest.php) (12, incl. invite-link-valid-past-60-minutes and auto-resend-after-window), [`PublicScheduleFeedTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PublicScheduleFeedTest.php) unaffected (8/8 green — additive field only).
- OUT OF SCOPE (per handoff): re-pointing the four live `trial_schedule_id` values — that stays a human action in Filament (`/admin` → course → «Пробное занятие»).
- [H4966](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4966-Sonnet_Systema-Sanscriticum_trial-freshness-gate-and-cabinet-invite-lifetime_16.09.26.md)

_Гасунс_
