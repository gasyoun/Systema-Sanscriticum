# Cabinet adoption KPI experiment — H2380 pre-baseline + missing-gate packet

_Created: 07-08-2026 · Last updated: 07-08-2026_

**Handoff:** [H2380 (Grok 4.5) — Run the post-release cabinet adoption and revenue KPI experiment](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2380-Grok_Systema-Sanscriticum_cabinet-adoption-kpi-experiment_07.08.26.md)

**Executor:** Grok 4.5 (`grok-4.5`) · prod read-only probes via SSH `root@193.232.229.92` · **no** `CABINET_HYBRID` flip · **no** live invite `--send`

**Disposition:** **HUMAN-GATED / not done.** H1582 hybrid is **not live** on prod (`cabinet_hybrid=false`; no `CABINET_HYBRID=` line in `.env`). Per H2380: deliver the missing-gate packet + pre-invitation aggregates, then stop.

Aggregate evidence only — no emails, names, Telegram IDs, or student-level CSVs.

---

## 1. H1582 / DEPLOY №52 missing-gate packet

Captured **2026-08-07 ~22:00 MSK** (`php artisan cabinet:hybrid-readiness`).

| Gate | Result | Evidence |
|---|---|---|
| A baseline ≥14d | **PASS** | clock start **2026-07-21**, live **17d**, `cabinet.home.view` since start: **2092** |
| B code present | **PASS** | Recovery/Lapse/Catalog/Ladder + routes `library` / `progress` / `access` |
| C walkthrough | **HUMAN open** | [CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md) §3 — staging checklist still unchecked in pack |
| D MG GO | **RULED (GTD)** / readiness CLI still prints HUMAN | GTD row **✅ RULED 29-07-2026**: Phase 4 GO + agent may flip after baseline window («You have deploy access…»). Baseline window closed ~04-08-2026. |
| Flag currently | **OFF** | `config(features.cabinet_hybrid)=false`; `.env` has **no** `CABINET_HYBRID=` line (defaults false) |

CLI overall: **MECHANICAL GO — still need human gates C+D before prod flip** (CLI does not know the 29-07 GTD ruling).

### Activation remaining (DEPLOY_QUEUE №52)

1. Staging walkthrough pack §3 signed (gate C).
2. On prod: set `CABINET_HYBRID=true` in `.env` → `php artisan config:clear` (or `config:cache` if used).
3. Smoke: student → hybrid Сегодня shell; `/library` `/progress` `/access` 200; recovery not false-positive on bare pending.
4. Instant revert: `CABINET_HYBRID=false` → `config:clear`.

**H2380 does not flip the flag in this pass** (handoff guardrail: no flip without explicit approval *in that run*; residual walkthrough C still open).

---

## 2. Pre-flip telemetry snapshot (`cabinet:baseline --days=14`)

Window: from **2026-07-24 21:58** (14d).

| Event §4 | Source | Total | Unique students |
|---|---|---|---:|
| cabinet.home.view | activity_events | 2055 | 60 |
| cabinet.continue.click | activity_events | 105 | 29 |
| cabinet.homework.rework.click | activity_events | 0 | 0 |
| lesson.mark.mastered | activity_events | 41 | 12 |
| course.tab.view | activity_events | 31 | 12 |
| library.shelf.view | activity_events | 0 | 0 |
| library.rail.jump | activity_events | 0 | 0 |
| path.station.view | activity_events | 0 | 0 |
| path.station.lit.impression | activity_events | 0 | 0 |
| offer.impression | activity_events | 981 | 14 |
| offer.click | activity_events | 7 | 6 |
| access.renewal.start | activity_events | 10 | 2 |
| access.renewal.complete | activity_events | 1 | 1 |
| lesson.view.heartbeat | lesson_views | 93 | 37 |
| cabinet.live.zoom.click | schedule_join_clicks | 1 | 1 |

Notes:

- Hybrid-only surfaces (library / path) are **zero** — consistent with flag OFF.
- Continue CTR proxy: 29 unique continue / 60 unique home.view ≈ **48%** among viewers who hit home in 14d (not a full population rate).
- Access self-resolution signal thin: 10 starts / 1 complete in 14d.

---

## 3. Population denominators (pre-invitation / adoption)

Probed 2026-08-07 22:05 MSK. **Two different denominators** — do not conflate.

### 3a. Access-granted stamp (`note` contains `[Доступ отправлен`)

Same population as `students:send-login-invites` / weekly onboarding digest.

| Metric | n |
|---|---:|
| access_granted_total | 374 |
| access_granted real email | 374 |
| never login (`login_count=0`) | 215 |
| ever login | 159 |
| **ever-login share** | **42.5%** |
| active 30d (logged in, `last_login_at`) | 76 |
| invite stamp already set | 219 |
| **invite pending** (never login + null stamp + real email) | **0** |
| login_count hist | 0: 215 · 1–5: 108 · 6–20: 45 · 21+: 6 |

### 3b. Paid students (`payments.status = paid`, distinct `user_id`)

Closer to the historical «~800 paid» story; larger than the access-stamp set.

| Metric | n |
|---|---:|
| paid_users | 931 |
| paid ever login | 188 |
| paid never login | 739 |
| **paid ever-login share** | **20.2%** |
| paid active 30d | 94 |
| paid with invite stamp | 204 |
| paid never-login still invite-pending (real email, null stamp) | **13** |
| paid never-login with real email | 215 |
| channel among paid never + real email | TG **0** · VK **0** · SMS/email rest **215** |

**Interpretation:**

- Historical «~800 paid / ~⅓ ever logged-in» is **worse on the paid denominator today** (~20% ever login), and **~42%** on the narrower access-granted stamp.
- `students:send-login-invites` dry-run reports **0** because the **access-stamp never-login cohort is fully stamped** (`cabinet_invite_sent_at` set). Idempotency works.
- Residual unsent paid never-login with real email: **13** only — not a 100–200/day ramp surface until a human re-opens policy (and/or widens the command population beyond the access stamp).

---

## 4. Invite cohort natural experiment (already sent)

`php artisan students:send-login-invites` (no `--send`) confirmed 0 pending.

| Metric | Value |
|---|---|
| invite_sent_total | **219** |
| invite send window | **2026-07-13** → **2026-08-07** (includes same-day sends) |
| still never login after invite | **215** |
| ever login after stamp (`last_login_at >= cabinet_invite_sent_at`) | **4** |
| home.view after invite | **4** |
| continue.click after invite | **2** |
| active 30d among invited | **4** |
| never-login age of invite | 0–7d: **66** · 8–14d: **50** · 15–30d: **99** |

### Day-7 / day-14 style readout (decision)

| Horizon | Still never-login (invited) | Login after invite (cumulative among 219) | Decision |
|---|---:|---:|---|
| ≤7d window slice | 66 never-login with invite age 0–7d | overall conversion **4/219 ≈ 1.8%** | **HOLD — do not scale** |
| 8–14d slice | 50 | same global 1.8% | **HOLD** |
| 15–30d+ | 99 | same | **HOLD / investigate channel** |

**Decision (agent recommendation):** **HOLD / do not scale batch invites.** Conversion after invite is far below the ≥⅔ adoption goal. Likely causes to investigate before another live batch:

1. Channel mix of historical sends unknown in aggregate (current residual paid-never has **zero** linked Telegram — email-only path; spam risk).
2. Message/link friction (reset URL one-shot).
3. Population mismatch: access-stamp vs paid (many paid never got the access note stamp and were never in the invite query).
4. Hybrid flag still OFF — post-login UX is legacy, not the remake under measurement for H1582.

Warm-up guard (≤100–200/day) is moot while pending=0 on the command population.

**No live `--send` this session.**

---

## 5. Access / money smoke (pre-flip)

| Check | Result |
|---|---|
| `php artisan cabinet:probe` | **OK** — «Кабинет жив: все проверки OK (1535 ms)» |
| Hybrid flag | OFF (inert code path) |
| Access renewal (30d events) | start 10 · complete 1 |
| Payments sum 30d (`status=paid`) | **420 025** (currency units as stored) |
| Revenue / active paid student 30d proxy | **4 468** (420025 / 94) — **not** attributed to cabinet |

No access/payment regression from this pass (read-only).

---

## 6. Reproduce (prod, read-only)

```bash
ssh root@193.232.229.92
cd /var/www/html
php artisan cabinet:hybrid-readiness
php artisan cabinet:baseline --days=14
php artisan students:send-login-invites --limit=100   # dry-run, no --send
php artisan cabinet:probe
```

Aggregate denominators: temporary `php` probe over Eloquent/`payments` counts only (script deleted after run; no PII logged).

---

## 7. Evidence checklist vs H2380 acceptance

| Required | Status |
|---|---|
| pre-flip readiness/smoke artifacts | **YES** — this doc §1–§2, §5 |
| post-flip readiness/smoke | **NO** — flag OFF; blocked |
| cohort denominator + channel split + idempotency | **YES** — §3–§4; pending=0; no personal rows |
| day-7/day-14 own-data KPI + decision | **PARTIAL** — natural experiment on 219 prior invites → **HOLD**; not a post-H1582 controlled experiment |
| approved live send ramp 100→200 | **NO** — human gate; conversion HOLD argues against |

---

## 8. What a human decides next

1. **Gate C** — complete staging walkthrough pack §3 (or waive explicitly).
2. **DEPLOY №52** — add `CABINET_HYBRID=true` + smoke (GTD GO already ruled 29-07; H2380 still wants C clear).
3. **Invite policy** — do **not** blind-scale; investigate 1.8% conversion + email-only residual; optional widen of invite population beyond access stamp (paid never-login 739 vs stamp never-login 215) only after a human ruling.
4. **Resume H2380** after flag live: save post-flip baseline, then day-7/day-14 hybrid KPI table (continue CTR, first action, support, revenue/active — comparable cohort discipline).

_Dr. Mārcis Gasūns_
