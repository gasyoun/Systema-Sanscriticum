# H3648 close evidence — Club streams-only, flag OFF

_Created: 29-08-2026 · Last updated: 29-08-2026_

Enable readiness (same-day SSH probe): **not ready**. 0 tagged `club_stream`/`club_efir` of 1742 lessons; 0 D20 Club 1/3/12 rows; flag still false. Detail: [MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md).

Executor: Grok 4.6 (`grok-4.6`). Handoff: [H3648 (Grok 4.6, 🟡2 medium) — Club streams-only entitlement plus 1/3/12 tariffs flag OFF](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3648-Grok_Systema-Sanscriticum_club-streams-only-entitlement-tariffs_28.08.26.md). No Claude dual-run residual.

## Gates

| Gate | Result |
|---|---|
| Feature PR | [Systema #2187](https://github.com/gasyoun/Systema-Sanscriticum/pull/2187) merged (`eb6ba683`) |
| CI follow-up | `CabinetController` lessons map `use ($user, $course, $club, …)` — PHP 8.3 tests green |
| Prod deploy | `deploy.sh` `e68b8011` → `eb6ba683`; `https://samskrte.ru/` HTTP 200 |
| Flag | `/var/www/html/.env` absent `MEMBERSHIP_CLUB_STREAMS_ONLY`; `config('features.membership_club_streams_only')` false |
| Migration | `2026_08_29_140000_add_recording_kind_to_lessons` Ran |
| Release | [v1.90.32](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.90.32) ([#2188](https://github.com/gasyoun/Systema-Sanscriticum/pull/2188)) |
| Docs | D10 supersede on the three-tier runbook + `.ai_state` ([#2190](https://github.com/gasyoun/Systema-Sanscriticum/pull/2190)) |
| Registry | H3648 closed ✅ |

Flag OFF keeps the H2744 recording predicate. Club D20 ₽2000/5700/20400 rows stay inactive until a human applies them. Live ₽1500 checkout was not rewritten. No Tochka charge. `cabinet:probe` only showed the existing restic soft guards (not a rollback).

Operator note: [MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md). Stay-OFF: [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) №84.

## Enable later (not this pass)

When club-stream / club-efir lessons are tagged `recording_kind`:

1. `php artisan membership:ensure-club-stream-tariffs --apply`
2. Set `MEMBERSHIP_CLUB_STREAMS_ONLY=true` in `/var/www/html/.env`
3. `php artisan config:cache`
4. `php artisan membership:rehearse`

Rollback: key `false` or delete it, then `config:cache`.

_Dr. Mārcis Gasūns_
