# VERIFICATION — Wave 1 · Marathon 28-08 live

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).

## 1. Smoke A–E (required for “fully live”)

| ID | Criterion | How to prove | Pass |
|---|---|---|---|
| **A** | Landing HTTP 200 | `curl -sI https://samskrte.ru/<marathon-path>` → `200` | |
| **B** | Register creates enrollment | Submit form (or artisan tinker factory) → row in `marathon_enrollments` + Lead | |
| **C** | ₽500 Tochka path | Checkout reaches Tochka; success path returns to site (live charge only if ops-safe) | |
| **D** | TG day message | Bot delivers Day 1 (or manual `marathon:deliver-due` after start) within schedule window | |
| **E** | Email deliver | Transactional mail received (not stuck in mailpit); screenshot or IMAP | |

All five required (D17). Partial green ⇒ status **BLOCKED**, not LIVE.

## 2. DR gates

| ID | Criterion | How to prove | Pass / PARK |
|---|---|---|---|
| **DR1** | Off-site backup this week | `php artisan backup:list` or WebDAV listing under `/Backups/systema-sanscriticum` with mtime ≤ 8 days | |
| **DR2** | Uptime TG dry-fire | Actions secrets set + test notification **or** documented PARK if no admin | |

PARK allowed under D26; LIVE claim for marathon may still be made if A–E pass, but runbook must flag **DR incomplete** and residual `@DO` for secrets.

## 3. Deploy truth

| ID | Criterion | How to prove |
|---|---|---|
| **DEP1** | Prod SHA matches intended | SSH: `git rev-parse HEAD` equals merged SHA |
| **DEP2** | Not CI no-op only | Either successful Actions deploy log with remote commands **or** Evidence line of agent `deploy.sh` |

## 4. ORS CTA

| ID | Criterion | How to prove |
|---|---|---|
| **ORS1** | ≥1 live surface | URL + navigation steps in runbook; click reaches marathon landing |

## 5. Code quality (if app/workflow changed)

```bash
# from worktree, after non-money changes
vendor/bin/pint --dirty
php artisan test --filter=<TouchedTest>
# optional broader
php artisan test tests/Feature
```

Money-core test files may be run read-only for regression confidence; **do not** change money code to make them green in W1.

## 6. Risks & spikes register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Yandex/SMTP/GH secrets unavailable in zero-human window | High | PARK + local backup + TG-only notify; no fake green |
| Tochka live test charges real card | Medium | STOP if ambiguous; use existing ops test protocol |
| Deploy breaks login/checkout | Medium | Immediate rollback; stop |
| CabinetProbe red CI confuses gate | Medium | Quarantine (D20); DEP1 is source of truth |
| Marathon Schedule id missing | Medium | Day 3 silent skip — checklist §5 mandatory |
| H1067 landing still unpublished | Medium | L1 includes publish path |
| CT power-off recurs | Medium | Uptime alert; root-cause is W2 host policy |
| Agent lacks SSH despite D7 | Medium | Fallback: prepare runbook for Ivan; PARK deploy agent-leg |

## 7. Spike (only if blocked)

- **S1:** If deploy.yml cannot authenticate and SSH missing — spike ≤30 min to document exact secret names + who can set them; do not rebuild deploy platform.
- **S2:** If SMTP rejected — spike SPF/DKIM on mailbox DNS; no third-party ESP switch in W1.

---

_Dr. Mārcis Gasūns_
