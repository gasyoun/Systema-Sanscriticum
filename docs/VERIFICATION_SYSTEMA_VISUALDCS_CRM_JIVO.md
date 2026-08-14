# VERIFICATION — Systema VisualDCS, CRM and literal-Jivo programme

_Created: 08-08-2026 · Last updated: 14-08-2026_

Parent: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).

## Learner release gate

- Import rejects wrong version, schema, size and SHA-256 and retains the previous promoted release.
- Importing the same release twice is a no-op; rollback restores the exact prior manifest.
- Native surfaces match VisualDCS fixtures for representative complete and sparse data.
- Entitlement matrix covers public preview, unpaid, partial, full, expired/recovery and admin cases.
- Progress upserts are idempotent, duplicate-resistant and resume on a second device.
- Aggregate projection counts reconcile with activity telemetry.
- Each surface flag can disable its route/UI without affecting the other two.
- Browser evidence at 1440px/390px proves keyboard/focus/contrast/reduced-motion/no-overflow.
- Targeted tests, full relevant Feature slice, `npm run build` and Pint pass.

## School-parity gate

Use the H2382 matrix: code, flag, production config, approved canary and KPI are separate columns.
Web/TG/VK/email, visitor intelligence, operator workflow, topics/follow-ups and outcome reporting
must all have evidence. Missing evidence is INCONCLUSIVE, never PASS.

## CRM gates

### Customer 360

- Timeline values reconcile against their canonical owners; no mirrored fact diverges.
- Stage/owner/next action writes use existing services and audits.
- Access fault, payment promise and learning follow-up scenarios take fewer/equal operator steps and
  produce the correct next action.

### Automation

- Dry-run and live eligibility counts share the same query; suppression and recovery exclusions are
  explicit; retries do not double-send.
- No production send without approval in that run; AI never sends autonomously.
- Campaign→paid attribution prints denominators and never changes Payment rows.

### Forecasting

- Open-Deal totals reconcile with pipeline; actual revenue reconciles with the qualifying Payment
  denominator; probabilities/aging come from config. ✅ H2485 tests `SalesForecastServiceTest`.
- Historical backtest reports unavailable history honestly and never fabricates snapshots. ✅
  `test_backtest_labels_pre_history_windows_unavailable_without_inventing_forecast`.
- Manager self-scope and admin all-scope match existing role gates. ✅ `SalesForecastPageTest`.

## Product evidence

Before learner rollout save a baseline; at 7/14/30 days report eligible, first action ≤24h,
learning return, support load, paid conversion and revenue/active learner. For CRM report stage
aging, next-action coverage, response/follow-up completion, campaign outcomes and forecast error.
No mandatory lift is invented; scale/hold/revert follows comparable observed cohorts.

## Literal-Jivo telephony gate (H2486)

- All five flags default OFF (`TelephonyGateConfigTest`).
- `config('telephony.preferred_carrier')` is `none`; Jivo default AON is listed as forbidden.
- `CallEvent` rejects unknown types; audio bytes are not on the DTO.
- Live volume is re-read from `support:parity-report` / `crm:forecast-report` before any
  phase unlocks. Missing evidence is INCONCLUSIVE, never PASS.
- No production number, call, recording or contract in this pass.
- **H2749 (14-08-2026 23:18 MSK) STOP.** Live `FollowUpTask` type=call = 0 (created / done / all-time). H2747 not shipped. `telephony_pstn` and `telephony_recording` stay false. No adapter PR. See packet section 10.

## Stop/rollback

Immediate halt and rollback on entitlement/payment regression, contract/data loss, privacy
exposure, ambiguous identity/thread routing, uncontrolled send, destructive migration or repeated
verification failure. Flags default OFF; previous dataset release and pre-wave behavior remain the
rollback anchors.

_Dr. Mārcis Gasūns_
