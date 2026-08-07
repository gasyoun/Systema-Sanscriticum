# Prod acceptance — remediations 1–8 + Tochka/#1146 + Horizon + guards residual

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Handoff:** [H2336](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2336-Grok_Systema-Sanscriticum_prod-accept-remediation-1-8-tochka-queue-horizon_07.08.26.md) (**Grok 4.5**) — prod acceptance packet (not re-implementation).

**Host:** `samskrtam150` / samskrte.ru · **Checked at (UTC):** `2026-08-07T10:31:26Z`  
**Executor:** Grok 4.5 (`grok-4.5`) via SSH (read-only first; no money-flag flips).

---

## Verdict

| Gate | Result |
|---|---|
| Prod HEAD includes remediations + #1146 + #1148 | **PASS** |
| Money uniqueness migration (#1105) applied | **PASS** |
| Horizon supervisors fast / webhook / long / lecture | **PASS** (running) |
| `deploy:webhook-preflight` | **PASS** (`OK`) |
| PayPal subscriptions + partner flags | **PASS** (still **OFF**) |
| Hold ≠ access / capture grants access | **PASS with N/A hold window** — no live `hold`/`authorized` webhooks; all 32 Tochka events `APPROVED`→`applied` |
| H2322 `server_guards_apply` residual | **PASS — current** (`guards:verify` exit 0; soft fuse absent) |

**Overall:** acceptance criteria met. No secrets recorded below.

---

## 1. Prod HEAD + ancestry

| Field | Value |
|---|---|
| `git rev-parse HEAD` | `644e1c0d8aff08f55fdca5333bcb2990321ebd1c` |
| Tip subject | `H2339: support canreply templates (magic-link E2 + 2m TG revision) (#1167)` |
| Tracking | `main...origin/main` (clean vs remote tip at check) |
| Environment | `production` · Laravel 12.64.0 · PHP 8.3.32 · debug OFF · maintenance OFF |

Merge commits are ancestors of prod HEAD (`git merge-base --is-ancestor <sha> HEAD` → YES for each):

| PR | Title (short) | Merge SHA (12) | Ancestor |
|---|---|---|---|
| [#1103](https://github.com/gasyoun/Systema-Sanscriticum/pull/1103) | Remediation 1/8 Tochka settlement entitlements | `50e751b54a3e` | YES |
| [#1105](https://github.com/gasyoun/Systema-Sanscriticum/pull/1105) | Remediation 2/8 serialize promise/settlement writes | `c563a9840756` | YES |
| [#1104](https://github.com/gasyoun/Systema-Sanscriticum/pull/1104) | Remediation 4/8 queue reservations / timeouts | `435ac64a9519` | YES |
| [#1107](https://github.com/gasyoun/Systema-Sanscriticum/pull/1107) | Remediation 5/8 fail-closed webhook secrets | `630936bd0c49` | YES |
| [#1109](https://github.com/gasyoun/Systema-Sanscriticum/pull/1109) | Remediation 6/8 protect automated delivery paths | `787947d34dc9` | YES |
| [#1108](https://github.com/gasyoun/Systema-Sanscriticum/pull/1108) | Remediation 7/8 platform + MySQL verification | `014defde7c62` | YES |
| [#1106](https://github.com/gasyoun/Systema-Sanscriticum/pull/1106) | Remediation 8/8 inventory config / clean repo | `3d6df28c16cd` | YES |
| [#1146](https://github.com/gasyoun/Systema-Sanscriticum/pull/1146) | Accept Tochka `APPROVED` as paid again | `6e640daeb540` | YES |
| [#1148](https://github.com/gasyoun/Systema-Sanscriticum/pull/1148) | Money-route parity fail-closed (H2304) | `31426cd282c9` | YES |

Release trail for remediations on `main` starts at **1.87.2+** (changelog); live tip is newer (`#1167` / H2339).

---

## 2. Migrations (#1105)

`php artisan migrate:status` — batch **[140] Ran**:

- `2026_08_04_120000_make_fulfilled_payment_id_unique_on_payment_promises` → **Ran**

This is the uniqueness migration shipped in #1105 (nullable unique `fulfilled_payment_id` + concurrent fulfilment serialization). No pending money uniqueness migrations observed in the tail of `migrate:status`.

---

## 3. Horizon (#1104)

| Check | Evidence |
|---|---|
| Status | `Horizon is running.` |
| Master | `samskrtam150-mcqo` PID `1501820` · status **running** |
| Supervisors | `supervisor-1`, `supervisor-long`, `supervisor-lectures`, `supervisor-webhooks` |
| Config (`config/horizon.php` production defaults) | fast (`supervisor-1`, timeout 120s) · long (600s, `redis-long`) · lectures (960s, `redis-lectures`) · webhooks (tries 3, timeout 120s, queue `webhooks`) |
| Queue depths | `redis:default` 0 · `redis:webhooks` 0 · `redis-long:imports` 0 |

No Horizon restart required — live supervisors already match the #1104 reservation layout.

---

## 4. Webhook preflight

```text
deploy:webhook-preflight OK.
```

Re-run same session → still OK. Soft auto-deploy fuse: **absent** (`storage/auto_deploy.disabled` not present).

---

## 5. Feature flags (names only — values boolean, no secrets)

| Flag key | Live value | Expected for this packet |
|---|---|---|
| `features.paypal_subscriptions` | `false` | OFF |
| `services.paypal.subscriptions.enabled` | `false` | OFF |
| `partner.enabled` | `false` | OFF |

No human `@DO` to enable PayPal subscriptions or partner program was applied. **No enable action taken.**

---

## 6. Access spot-check (hold ≠ access / capture grants)

### Live traffic matrix (`payment_webhook_events`)

| bank_status | decision | count |
|---|---|---|
| `APPROVED` | `applied` | **32** |
| (any) | `hold_not_captured` | **0** |

- Every recorded Tochka webhook since the table exists is `APPROVED` → `applied` (includes post-#1146 samples, e.g. event id 32 → payment 14109, `2026-08-06T13:45:07Z`).
- **Hold / `authorized` path:** zero live events with `decision=hold_not_captured` or bank hold status in the window. Code path remains in [`WebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php) (`$holdStatuses = ['authorized']` → `DECISION_HOLD_NOT_CAPTURED` without marking paid). **Hold traffic = N/A window** for empirical proof; no contradictory paid-from-hold rows found.
- **Pending ≠ automatic course-group grant:** sample pending payment `14106` (user 937, course 442) → course groups **not** attached from that pending alone. Other pending rows may still show group membership from **other** paid history on the same user/course (expected; not hold-grant).

**In-repo status matrix (H2337):** [docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md) — bank status → payment status → access yes/no, locked by `TochkaWebhookTest`.

### Product rule restated

| Bank / payment state | Access |
|---|---|
| Hold / `authorized` | Must **not** grant (decision `hold_not_captured`) |
| `APPROVED` / capture / paid | May grant per product rules (#1146 restores APPROVED as paid) |

---

## 7. H2322 residual — `server_guards_apply`

| Check | Result |
|---|---|
| `php artisan guards:verify` | **exit 0** — «Предохранители на месте: все проверки пройдены.» |
| Soft fuse | **absent** |
| Installed sbin mtimes | `systema-schedule-run.sh` 2026-08-06 07:32Z · `systema-auto-deploy-run.sh` 2026-08-06 18:24Z |
| Repo template vs installed md5 | Differ by design (templates use `@@APP_DIR@@` / `@@PHP_BIN@@` placeholders; apply substitutes) |

**Disposition:** residual **current** — re-`apply` not required this pass (verify green). Prior triage (soft-alert playbook, 06-08-2026) already refreshed managed files after the #1109 / GUARDS DRIFT incident. If a future deploy only updates `scripts/server_guards/sbin/*` without apply, run:

```bash
cd /var/www/html && sudo bash scripts/server_guards_apply.sh && php artisan guards:verify
```

---

## 8. What was **not** done (guardrails)

- No `PARTNER_PROGRAM_ENABLED` / PayPal subscription enable.
- No money flag flips, no migration run (already applied), no Horizon restart (already aligned).
- No secret values, `.env` dumps, or webhook signing secrets in this memo.
- No private audit gap list published.

---

## Reproduce (ops)

```bash
ssh -o BatchMode=yes -o ConnectTimeout=10 root@193.232.229.92
cd /var/www/html
git rev-parse HEAD
git merge-base --is-ancestor 50e751b54a3e5d1e5fe19257ed612557e9d09af5 HEAD && echo OK
php artisan migrate:status | grep make_fulfilled_payment_id_unique
php artisan horizon:status
php artisan horizon:list
php artisan deploy:webhook-preflight
php artisan guards:verify
php artisan tinker --execute='echo json_encode(["paypal"=>config("features.paypal_subscriptions"),"partner"=>config("partner.enabled")]);'
```

---

## Evidence checklist (handoff close)

- [x] Prod HEAD SHA + remediations are ancestors
- [x] Migrate + Horizon + `deploy:webhook-preflight` evidence
- [x] Flag OFF table + hold≠access / capture spot-check or explicit N/A window

_Dr. Mārcis Gasūns_
