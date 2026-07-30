# ARCHITECTURE — samskrte.ru Tier-0

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).

## 1. System context

```
[Students / leads]
       │
       ├─► samskrte.ru (Systema Laravel) ── MySQL ── Redis/Horizon
       │         │              │
       │         │              ├─ Tochka (payments)     [money fence]
       │         │              ├─ Telegram / VK bots
       │         │              ├─ SMTP (mailbox)        [W1 fix]
       │         │              └─ Spatie backup → local + Yandex WebDAV
       │
       └─► ORS-FAQ / samskrtam surfaces ── CTA ──► marathon landing (W1)
                │
                └─ year: WP publish, bot, analytics (W5)

[Ops]
  Proxmox CT (Beget VPS) · nginx · php-fpm · schedule:run cron
  GitHub Actions: tests · uptime-samskrte · deploy.yml (truth fix)
  Agent path: SSH/deploy.sh when keys present (D7)
```

## 2. Component boundaries (wave-1)

| Component | Responsibility | Must not |
|---|---|---|
| Marathon product | Enrollment, drip days, ₽500 paid track, Day-3 schedule | New promo auto-issue (deferred H471) |
| Shop / LandingPage | Branded funnel URL | Invent prices |
| Notify | TG bot + mailers | Bypass preference router inventively |
| Backup | DB + `storage/app` weekly | Claim off-site without Yandex proof |
| Uptime | External GET + issue + TG | Silent fail without secrets logged |
| Deploy | Code to prod | Green no-op without Evidence of real rev |
| Money core | Existing Payment/Tochka | **Any semantic edit in W1** |

## 3. Data model (marathon — existing)

- `marathon_enrollments` + engagement timestamps (H440).
- `Lead` creation on register; optional paid `Payment` ₽500 without inventing new tariff semantics.
- `Schedule` row id → `MARATHON_SCHEDULE_ID` for Day 3.
- No new tables required for W1 activation if migrations already applied; if not, `php artisan migrate` only (additive).

## 4. Contracts

| Interface | Contract |
|---|---|
| Public landing | HTTP 200 on marathon slug; form creates Lead + MarathonEnrollment |
| Checkout ₽500 | Existing Tochka flow; success URL reachable |
| TG drip | `marathon:deliver-due` via `schedule:run`; bot token valid |
| Email | Transactional deliver (reset and/or marathon mail) via real SMTP not mailpit |
| Backup | Archive on `local` always; `yandex_disk` when env set |
| Uptime | Workflow green; TG on failure when secrets set |
| Deploy | Post-deploy `git rev-parse HEAD` on server matches intended SHA |

## 5. Build vs reuse (prior art)

| Need | Verdict |
|---|---|
| Marathon funnel | **Reuse** H440 code + activation checklist |
| SMTP | **Configure** existing Laravel mailers + H1449 campaign stack; no new ESP vendor in W1 |
| Backup | **Reuse** Spatie; only enable Yandex env |
| Uptime | **Reuse** workflow; set secrets |
| Deploy truth | **Fix** existing deploy.yml / Environment; fallback agent `deploy.sh` |
| GC year spine | **Reuse** GC roadmap + production spec |
| Membership 2027 | **Reuse** growth strategy; separate later plan for tariff key |
| New backup tool | **Do not build** |

## 6. Hosting / DR architecture

- **RPO (W1 target):** ≤ 7 days (weekly backup cadence already scheduled).
- **RTO (W1):** best-effort restore from local zip; off-site Yandex when enabled (desktop sync path documented in backup.php comments).
- **Outage class:** CT powered off (not firewall) — detect via uptime TCP fail + host-level human policy post-W1.
- **Deploy dual path:** (1) GitHub Environment `production` SSH deploy; (2) agent/operator `sudo bash deploy.sh` logged in runbook.

## 7. Year-spine architecture notes

- **GetCourse parity:** feature-flagged domains B/C/D/A; money core is observational only (PaymentObserver additive).
- **Growth membership:** new access key type additive to `Tariff::accessKey()` / `Lesson::isUnlockedBy()` — **out of W1**; requires dedicated money-adjacent review when built.
- **ORS-FAQ:** separate deployable; W1 only hyperlink/CTA contract into Systema landing.

---

_Dr. Mārcis Gasūns_
