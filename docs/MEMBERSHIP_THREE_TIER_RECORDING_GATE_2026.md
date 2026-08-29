# Free / Basic / Club membership and the recording gate

_Created: 16-08-2026 · Last updated: 29-08-2026_

> **H3648 (29-08-2026):** when `MEMBERSHIP_CLUB_STREAMS_ONLY` is on, Club/Top grant **club-stream / club-efir** recordings only. The Club row «purchased recordings» below is the H2744 D10 contract and is superseded. Flag default OFF — merge/deploy is not an enable. Operator note: [MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_CLUB_STREAMS_ONLY_H3648_2026.md).

This runbook extends H2644. It does not create a second payment path, change a historical
payment, detach a course group, accrue debt, or introduce automatic renewal.

## Product contract

| Tier | Monthly | 1 month | 3 months (−5%) | 12 months (−15%) | Minimum benefits |
|---|---:|---:|---:|---:|---|
| Free | ₽0 | — | — | — | Explicit free/preview lessons and campaign grants |
| Basic | ₽1,000 | ₽1,000 | ₽2,850 | ₽10,200 | Enhanced cabinet, library, standard exercises, benefits |
| Club | ₽2,000 | ₽2,000 | ₽5,700 | ₽20,400 | Basic + purchased recordings, personalised exercises, maximum tooling |
| Top | ₽5,000 | unavailable | unavailable | unavailable | Separate 01-10 go/no-go; `MEMBERSHIP_TOP=false` by default |

Paid periods retain H2644's three-day grace and same-tier stacking. A higher tier starts
immediately without shortening the lower paid period. A lapse creates no invoice, debt, or
retroactive due. New membership restores the eligible recording immediately.

## Safe migration and classification

The schema migrations are additive and reversible. They add nullable explicit tier codes to
`tariffs` and `club_memberships`, plus the shadow-verdict table. They do not backfill silently.

```bash
php artisan migrate --force
php artisan membership:classify-tiers
php artisan membership:classify-tiers --apply \
  --expected-memberships=<dry-count> --expected-tariffs=<dry-count>
```

The classifier uses table/course semantics only: H2644 membership rows and tariffs on the
configured membership course become `club`. It never reads or guesses from payment amounts.
It refuses to apply while `MEMBERSHIP_TIERED=true` and refuses if either expected count moved.

After classification, create six Filament tariffs (Basic and Club × 1/3/12) with the exact
totals above. `membership:rehearse` fails on a missing or mismatched tier/term/price.

## Staged recording gate

The single `RecordingAccessPolicy` is called by the web lesson and cabinet API surfaces. The
policy gates only video payloads. Course pages, lesson text, schedule, live links, homework,
notes and communication continue to use the ordinary purchase/group rules.

Activation order:

1. Deploy with every new flag OFF.
2. Classify legacy rows and create the six exact tariffs.
3. Set `MEMBERSHIP_TIERED=true`; rehearse.
4. September: set `MEMBERSHIP_RECORDING_SHADOW=true`. Denials are recorded but video stays open.
5. Review `php artisan membership:recording-report --hours=48`; live-function closures must be 0.
6. Configure exactly 20 user IDs and a start timestamp, then enable the 48-hour pilot:

```dotenv
MEMBERSHIP_RECORDING_PILOT_USERS=1,2,...,20
MEMBERSHIP_RECORDING_PILOT_STARTED_AT=2026-09-29T00:00:00+03:00
MEMBERSHIP_RECORDING_PILOT=true
```

An invalid pilot configuration fails open into shadow mode and logs a critical event. After
48 hours, pilot enforcement expires automatically. Full enforcement is a separate flag:
`MEMBERSHIP_RECORDING_ENFORCE=true`, only after the report has zero live-function closures,
wrong closures, archive leaks, double-charge symptoms and PII incidents.

## Rehearsal and rollback

```bash
php artisan membership:rehearse
php artisan membership:rehearse --apply --user=<id-or-email>
php artisan membership:recording-report --hours=48
php artisan membership:recording-rollback
```

Rollback is configuration-only: set `MEMBERSHIP_RECORDING_ENFORCE`,
`MEMBERSHIP_RECORDING_PILOT`, and `MEMBERSHIP_RECORDING_SHADOW` to `false`, then run
`php artisan config:clear`. No payment, membership period, group, lesson, or course row is
changed. `membership:recording-rollback --assert-off` verifies the inverse state.

Stop the affected lane on double-charge risk, a live/schedule/homework/text/communication
closure, wrong recording access, PII exposure, or an irreversible migration.

_Executor: Codex (GPT-5)._
