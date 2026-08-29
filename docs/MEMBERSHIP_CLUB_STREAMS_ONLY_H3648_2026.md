# Club streams-only recordings + 1/3/12 D20 tariffs (H3648)

_Created: 29-08-2026 · Last updated: 29-08-2026_

On 26-08-2026 MG ruled «только эфиры/стримы клуба»: regular course recordings are not a Club benefit. That supersedes autumn-plan D10. This document is the operator note for the dark ship. It does not rebuild H2744's Free/Basic/Club spine.

## Flag

`MEMBERSHIP_CLUB_STREAMS_ONLY` → `config('features.membership_club_streams_only')`. **Default false.** Merge + deploy of the code is not an enable. Enable is a separate human ops step: `.env` + `php artisan config:cache`. Rollback: set false, `config:cache`. No payment, group, or membership row is changed.

## Recording class

Column `lessons.recording_kind` (default `course_lesson`). Filament «Класс записи». Values:

- `course_lesson` — opened by purchase of that course (no Club required when the flag is ON)
- `club_stream` / `club_efir` — opened by Club or Top membership

Missing column is treated as `course_lesson` (H2971-class failsafe).

## Tariffs

`php artisan membership:ensure-club-stream-tariffs` (dry-run default) then `--apply`. Writes three Club-tier rows at D20 prices (₽2 000 / ₽5 700 / ₽20 400) on the membership course (`CLUB_COURSE_SLUG`, default `club`). Does **not** rewrite live ₽1 500 / ₽4 000 / ₽15 000 checkout rows. `is_active` follows the flag.

Rehearsal: `php artisan membership:rehearse` step `1d`. Payments inside `withoutEvents()`; no Tochka.

## Prod enable (not this PR)

1. Tag club-stream / club-efir lessons in Filament.
2. `--apply` the three D20 tariffs (inactive until the flag).
3. `MEMBERSHIP_CLUB_STREAMS_ONLY=true` + `config:cache` — human ops.
4. `membership:rehearse` green.

_Dr. Mārcis Gasūns_
