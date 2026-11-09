# Systema architecture and logic — grill findings

_Created: 09-11-2026 · Last updated: 09-11-2026_

Scope: prod app only (`Systema-Sanscriticum`, Laravel 13, `origin/main e67bf0a9` = prod HEAD 09-11-2026). Evidence rule: each claim carries a receipt or is marked unverified. Lenses scored separately: architecture / logic / ops.

## P0 (triaged)

### P0-1 Money integrity H1/H8 — policy blocked
- Payment audit `docs/AUDIT_PAYMENT_LOGIC_CORRECTNESS_16-09-2026.md` H1–H8. H2–H7 fixed in H5007 PR #2624 behind `PAYMENT_FIX_WAVE1`; H1 (PayPal no amount-check) and H8 (fail-closed) await MG ruling.
- Live 09-11-2026 (read-only SSH `.92`): `PAYMENT_FIX_WAVE1=true` in prod `.env` (wave-1 now ON, Oct. audit stale); `GRANT_ACCESS_FAIL_CLOSED` line **absent** → `config/features.php:854` default `false`. Receipts: `config/features.php:854,1858`, `PROJECT_MAP.md` §3/§5.
- ADR: `docs/adr/0001`, `0002`.

### P0-2 Backup encryption + offsite
- Audit `AUDIT-PROD-READINESS-Systema-Sanscriticum-2026-10-05.md` §1.2–1.3.
- Live: `BACKUP_ARCHIVE_PASSWORD=` present (count 1) — gap narrowed but encryption not proven; `/root/.restic-s3.env` **absent**; 25-10 run log still `s3=SKIP reason=no-/root/.restic-s3.env`. H7058 queued (human gate MG).
- Spatie: `storage/logs/schedule.log` exists, `grep -c "backup:run" = 0` — schedule still never fires (H6124). Two backup contours: restic pushers alive to Oct. 25, S3 leg dead, spatie dead.

## Architecture flaws (P1 debt)

- **Monolith without boundaries.** `app/Services/` 50+ entries, `app/Models/` 60+ files; `PROJECT_MAP.md` §4 calls Services "прикладные и интеграционные" — no bounded contexts. Every price/access change re-reads `docs/domains/money-access.md`; invariants live in docs (`PROJECT_MAP.md` §5), not in code.
- **Two admin surfaces.** Filament `/admin` + `/editor` (`app/Filament/`) and Livewire cabinet (`app/Livewire/`). Same access truth must hold in both; FINDINGS §45 precedent (Eloquent shadows relation → silent referral-reward regression).
- **Support rules have no single truth.** `support:rules-sync` is partial; live set = package ∪ legacy tail (FINDINGS §3, standing quirk).
- **Payout canon is generated.** `config/teacher_rates.php` header: generated from Uprava `data/teacher_rate_timelines.json` hash `584b7e82…`, `DO NOT HAND-EDIT`. Era-scoped bank slice; НПД 6% trap; last-`from`-wins.

## Logic flaws (P1 debt, cross-checked code vs intent)

- Payouts: flat bank slice silently rewrites history; НПД double-count; generated config vanishes on hand-edit (FINDINGS §1). Surfaces: `app/Services/TeacherSalaryService.php`, `MutualSettlementService.php`, `PayoutRunService.php`.
- Promise→access mismatch: MG @DECIDE since 26-10 (`Uprava/GTD_NEXT_ACTIONS.md:627`, PR #3531 made no access change). Business rule lives in a queue, not in code.
- `tariff.is_active` governs purchase, not already-granted access; access keys `full/block_N/block_N_hH` only via `Tariff::accessKey()`, `Lesson::unlockingKeys()`, `Lesson::isUnlockedBy()` (`PROJECT_MAP.md` §5).
- TOCTOU anti-dup race outside transaction (`BUGHUNT-FINDINGS-04.10.2026.md:13`, MEDIUM-1, no fix evidence).

## Ops contour (scored)

- **Backups:** S3 OPEN proven, spatie OPEN proven, archive-password narrowed unverified. Double backup-verifier staleness OPEN/unknown.
- **Monitoring:** no `config/sentry.php`, no Sentry grep hits — §3.1 OPEN proven. `laravel.log` 186 B 09-11 17:47, no `/etc/logrotate.d/*lar*` — rotation OPEN proven. `failed_jobs` table exists (`'table' => 'failed_jobs'`) but no failed-jobs alert wiring found.
- **Incident:** many runbooks exist now (`RUNBOOK_BOTS_WEBHOOK_RELAY.md`, `RUNBOOK_FEATURE_FLAG_FLIP.md` etc.) — Oct. §4.3 "no webhook-relay runbook" is stale; verify content, not existence. Userbot recovery + admin 2FA still unaudited live.
- **PII / 152-FZ:** §2 (retention, OpenRouter student-text, orphaned PII, `raw_payload`, phone export, n8n perimeter) recorded 05-10; `docs/AUDIT_152FZ_SAMSKRTE_2026-10-11.md` not re-probed this pass — status **unverified**.

## Glossary (canonical)

`CONTEXT.md`: cabinet, lesson, payment (webhook-confirmed money movement), promise, payout, support rule. Payment ≠ promise ≠ payout is the H1/H8 fix.

## ADRs

`docs/adr/0001-grant-access-fail-closed-default.md`, `0002-payment-fix-wave1-flag.md`, `0003-teacher-rates-generated-canon.md`.

_Dr. Mārcis Gasūns_
