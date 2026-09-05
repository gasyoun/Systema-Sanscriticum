# Cabinet adoption + revenue KPI — post-flip day-7 / day-14 readout (H4134, pass 2)

_Created: 05-09-2026 · Last updated: 05-09-2026_

**Handoff:** [H4134 (Opus 4.8) — Cabinet adoption + revenue KPI experiment pass 2](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4134-Opus_Systema-Sanscriticum_cabinet-adoption-kpi-experiment-pass2_05.09.26.md)
**Pass-1 record (pre-baseline, stays open):** [RESULTS_CABINET_ADOPTION_KPI_H2380_20260807.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESULTS_CABINET_ADOPTION_KPI_H2380_20260807.md) · [H2380 (Grok 4.5)](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2380-Grok_Systema-Sanscriticum_cabinet-adoption-kpi-experiment_07.08.26.md)

**Executor:** H4134 Opus lane; executing model per session env block **Opus 5 (`claude-opus-5`)** · prod **read-only** probes via SSH `root@193.232.229.92` · **no** `CABINET_HYBRID` flip in this run · **no** live invite `--send`

**Disposition:** **DELIVERED (measurement).** Both gates were already clear before this pass — DEPLOY №52 fired **21-08-2026 07:51Z**, so day-7 and day-14 post-flip windows are both complete. Verdict: **keep the flag ON**, and **one P0 instrumentation defect blocks the headline KPI**.

Aggregate evidence only — no emails, names, Telegram IDs or student-level rows.

---

## 1. Gate status — both cleared before this pass

| Gate (H4134 brief) | State | Evidence |
|---|---|---|
| C — staging walkthrough or explicit waive | **CLEARED** | human «C» recorded on the A/B brief at flip time — [Uprava FEATURE_FLAGS_REGISTRY.md §1.4](https://github.com/gasyoun/Uprava/blob/main/FEATURE_FLAGS_REGISTRY.md) |
| DEPLOY №52 — `CABINET_HYBRID=true` + `config:clear` + smoke | **DONE 21-08-2026 07:51:25Z** | prod `.env` line 376 `CABINET_HYBRID=true`; `config('features.cabinet_hybrid') === true`; pre-flip backup `.env.bak.h-cabinet-hybrid.20260821T075125` (the last `.env` snapshot **without** the key; the next one, `.env.bak.h3280.20260821` 15:59Z, **has** it) |

This pass therefore performed **no** privileged action. It read prod, ran the two baselines the handoff names, and wrote this packet.

**Flip anchor used for every window below: `2026-08-21 07:51:25`.**
Because `cabinet:baseline --days=N` counts back from *today*, the exact flip-anchored windows come from a read-only probe, [`scripts/h4134_cabinet_kpi_probe.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/h4134_cabinet_kpi_probe.php) (SELECT-only; run and deleted from prod in the same command).

---

## 2. `cabinet:baseline` as the handoff names it (today-anchored)

`php artisan cabinet:baseline --days=7` (from 2026-08-29 20:42) / `--days=14` (from 2026-08-22 20:42) / `--days=15` (from 2026-08-21 20:42 — full post-flip span):

| Event §4 | Source | 7d total/uniq | 14d total/uniq | 15d total/uniq | **pre-flip 14d (H2380)** |
|---|---|---:|---:|---:|---:|
| cabinet.home.view | activity_events | 1846 / 54 | 3671 / 84 | 3899 / 85 | 2055 / 60 |
| cabinet.continue.click | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 105 / 29 |
| cabinet.homework.rework.click | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| lesson.mark.mastered | activity_events | 18 / 8 | 24 / 11 | 28 / 12 | 41 / 12 |
| course.tab.view | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 31 / 12 |
| **library.shelf.view** | activity_events | 49 / 12 | 69 / 21 | 70 / 21 | **0 / 0** |
| **library.rail.jump** | activity_events | 6 / 1 | 6 / 1 | 6 / 1 | **0 / 0** |
| **path.station.view** | activity_events | 818 / 10 | 1678 / 16 | 1791 / 16 | **0 / 0** |
| path.station.lit.impression | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 0 / 0 |
| offer.impression | activity_events | 0 / 0 | 3 / 1 | 3 / 1 | 981 / 14 |
| offer.click | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 7 / 6 |
| access.renewal.start | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 10 / 2 |
| access.renewal.complete | activity_events | 0 / 0 | 0 / 0 | 0 / 0 | 1 / 1 |
| lesson.view.heartbeat | lesson_views | 40 / 26 | 90 / 45 | 94 / 48 | 93 / 37 |
| cabinet.live.zoom.click | schedule_join_clicks | 5 / 3 | 6 / 4 | 6 / 4 | 1 / 1 |

**The hybrid is demonstrably live:** `library.shelf.view`, `library.rail.jump` and `path.station.view` were flat zero for the whole pre-flip baseline and are now the busiest surfaces after the home shell. That is the flag flip showing up in telemetry, exactly as the release pack predicted.

---

## 3. Flip-anchored comparison — the honest day-7 / day-14 table

Symmetric windows: post-flip D1–7 = 21-08 07:51 → 28-08 07:51; D1–14 = 21-08 → 04-09. Pre-flip mirrors are the 7 and 14 days immediately **before** the flip (14-08 → 21-08, 07-08 → 21-08), so seasonality is as close as our data allows.

Format: `total / unique students`.

| Event | pre-flip 7d | **post-flip d7** | pre-flip 14d | **post-flip d14** |
|---|---:|---:|---:|---:|
| login | 1590 / 62 | **1812 / 77** | 3049 / 91 | **3615 / 113** |
| cabinet.home.view | 1635 / 50 | **1818 / 51** | 3158 / 80 | **3732 / 86** |
| first_cabinet_action | 33 / 33 | **24 / 24** | 78 / 78 | **49 / 49** |
| cabinet.continue.click | 37 / 17 | **3 / 1** | 93 / 35 | **3 / 1** |
| course.tab.view | 8 / 4 | **0 / 0** | 21 / 10 | **0 / 0** |
| library.shelf.view | 0 / 0 | **17 / 10** | 0 / 0 | **62 / 20** |
| library.rail.jump | 0 / 0 | **0 / 0** | 0 / 0 | **6 / 1** |
| path.station.view | 0 / 0 | **842 / 9** | 0 / 0 | **1692 / 17** |
| lesson_open | 359 / 39 | **204 / 42** | 593 / 55 | **430 / 57** |
| lesson.mark.mastered | 36 / 12 | **11 / 8** | 51 / 15 | **28 / 12** |
| offer.impression | 347 / 10 | **3 / 1** | 689 / 16 | **3 / 1** |
| offer.click | 14 / 3 | **0 / 0** | 16 / 5 | **0 / 0** |
| access.renewal.start / complete | 0/0 · 0/0 | **0 / 0** | 1/1 · 1/1 | **0 / 0** |
| begin_checkout | 8 / 7 | **16 / 12** | 37 / 32 | **52 / 38** |
| payment_success | 4 / 4 | **9 / 9** | 37 / 31 | **39 / 35** |
| cabinet.homework.rework.click | 0 / 0 | **4 / 1** | 0 / 0 | **4 / 1** |

---

## 4. The seven KPIs the handoff asks for

| KPI | Pre-flip | Post-flip d7 | Post-flip d14 | Verdict |
|---|---|---|---|---|
| **Login adoption — access-stamp cohort** (ever logged in) | 159/374 = **42.5%** | — | **195/385 = 50.6%** | **UP +8.1 pp** |
| **Login adoption — paid cohort** | 188/931 = **20.2%** | — | **227/936 = 24.3%** | **UP +4.1 pp** |
| Active 30d (access-stamp · paid) | 76 · 94 | — | **105 · 127** | **UP +38% · +35%** |
| Unique students logging in, 14d | 91 | 77 (7d) | **113** | **UP +24%** |
| **First cabinet action** (new activations) | 78 / 14d | 24 | **49** | **DOWN −37%** |
| **Continue-learning CTR** (uniq continue ÷ uniq home.view) | 35/80 = **43.8%** | 1/51 = 2.0% | **1/86 = 1.2%** | ⛔ **instrumentation defect — see §5** |
| **Support contacts** (`support_conversations` rows) | 174 / 14d | **44** | **66** | **DOWN −62%** |
| **Access self-resolution** (`access.renewal.*`) | 1 start / 1 complete | 0 / 0 | 0 / 0 | **INCONCLUSIVE** — too thin either side |
| **Repeat checkout / paid** | begin_checkout 37/32 · repeat payers 6 | 16/12 · — | **52/38 · 2** | checkout intent **UP +40%**, repeat payers **DOWN** (small n) |
| **Revenue per active paid student** | 389 221 ÷ 94 = **4 141** | 53 600 (9 payments) | **240 200 ÷ 127 = 1 891** | **NOT ATTRIBUTABLE** — see §6 |

Denominator note: the two cohorts are **not interchangeable** (access-stamp 385 vs paid 936); H2380 §3 fixed that discipline and this pass keeps it.

### Invite cohort (natural, no send this pass)

| Metric | H2380 (07-08) | **Now (05-09)** |
|---|---:|---:|
| invite_sent_total | 219 | **275** |
| still never login | 215 | **239** |
| login after stamp | 4 = **1.8%** | **36 = 13.1%** |

Post-stamp login conversion is **7× the pass-1 figure**. The pass-1 **HOLD** was argued on 1.8%; that argument no longer holds at 13.1%. It is still **below** the ⅔ adoption goal and the cohort is not randomised, so this pass does **not** unilaterally lift the HOLD — it hands a human a materially different number to rule on.

---

## 5. ⛔ P0 finding — the headline KPI is currently unmeasurable

`cabinet.continue.click` fell from **93 events / 35 unique students** (pre-flip 14d) to **3 / 1** (post-flip 14d), while `cabinet.home.view` **rose** (3158/80 → 3732/86) and unique logins rose 24%. Traffic went up and the continue event went to near-zero: that is the signature of a **lost emitter**, not of students refusing to click.

Three events show the same shape, all of them legacy-shell surfaces the hybrid replaced:

| Event | pre-flip 14d | post-flip 14d |
|---|---:|---:|
| `cabinet.continue.click` | 93 / 35 | 3 / 1 |
| `course.tab.view` | 21 / 10 | 0 / 0 |
| `offer.impression` · `offer.click` | 689/16 · 16/5 | 3/1 · 0/0 |

`offer.impression` going to ~zero is **money-adjacent**: the release pack's recovery-mode offer suppression is by design, but suppression across 14 days on 86 active students is not what the pack describes. Either the hybrid shell never mounts the offer rail, or it mounts it without the impression beacon.

**Consequence:** continue-learning CTR, offer funnel and course-tab engagement — three of the seven KPIs this experiment exists to read — are **INCONCLUSIVE, not regressed**. They cannot be re-read until the emitters are restored in the hybrid Blade/JS.

This is a measurement defect, not an access or money defect. It does **not** justify rolling the flag back.

---

## 6. Revenue — measured, deliberately not attributed

| Window | paid payments | distinct payers | sum |
|---|---:|---:|---:|
| pre-flip 7d | 4 | 4 | 16 300 |
| **post-flip d1–7** | **9** | **9** | **53 600** |
| pre-flip 14d | 80 | 36 | 389 221 |
| **post-flip d1–14** | **39** | **35** | **240 200** |

Payment **count** halved while distinct **payers** stayed flat (36 → 35) — the pre-flip fortnight contains an instalment-heavy enrolment wave (80 payments from 36 people, repeat payers 6). Post-flip is 39 payments from 35 people, repeat payers 2. Revenue/active-paid-student therefore falls from 4 141 to 1 891 mostly because the *denominator grew 35%* (94 → 127 active) while an instalment wave rolled off.

Per the H2380/H4134 guardrail — *do not attribute seasonal revenue movement to the cabinet without a comparable cohort* — this pass records the numbers and **declines the attribution**. Forward-looking intent is the healthier signal: `begin_checkout` **37/32 → 52/38** and `payment_success` **37/31 → 39/35** across the same fortnights.

---

## 7. Access / payment smoke (post-flip)

| Check | Result |
|---|---|
| `php artisan cabinet:probe` | **OK** — «Кабинет жив: все проверки OK (4220 ms)»; synthetic homework upload saved + linked; deploy not behind (`behind=0`) |
| `config('features.cabinet_hybrid')` on prod | **true** |
| Guest `GET /library` `/progress` `/access` | **302 → login** (routes live; a 404 would mean the flag is OFF, per `abort_unless` in `StudentController`) |
| Guest `GET /dvaram` | **302 → login** |
| Public `GET /` | **200** |
| Payments flowing post-flip | **39 paid payments / 35 payers** in d1–14 — no payment-path regression |
| OS fuses / soft-alert | in place; one **waived** pre-existing host finding (`/tmp` tmpfs without `size=`, Proxmox-side, waiver to 2026-09-30) — unrelated to the cabinet |

**No access or payment regression attributable to the flip.**

---

## 8. Decision

1. **Keep `CABINET_HYBRID=true`.** Adoption is up on every denominator we trust (ever-login 42.5% → 50.6% access-stamp, 20.2% → 24.3% paid; active-30d +38%/+35%; unique logins +24%), hybrid surfaces are carrying real traffic, support contacts are down ~62%, and neither access nor payments regressed. Nothing here argues for a rollback.
2. **Fix the emitters before the next readout.** `cabinet.continue.click`, `course.tab.view` and `offer.impression`/`offer.click` must fire from the hybrid shell. Until then the continue-CTR and offer-funnel KPIs are INCONCLUSIVE and any product decision resting on them is unsafe.
3. **Invite policy — a human should decide.** Post-stamp conversion is 13.1% (36/275) against the 1.8% that justified the pass-1 HOLD. This pass sends nothing and changes no policy.
4. **Re-read d30 after the emitter fix**, with the same flip-anchored windows, before touching the 100→200/day ramp.

---

## 9. Reproduce (prod, read-only)

```bash
ssh root@193.232.229.92
cd /var/www/html
php artisan cabinet:baseline --days=7
php artisan cabinet:baseline --days=14
php artisan cabinet:probe
# flip-anchored windows + denominators (SELECT-only; copy in, run, delete):
php scripts/h4134_cabinet_kpi_probe.php
```

Guest HTTP smoke: `curl -s -o /dev/null -w '%{http_code}' https://samskrte.ru/library` → `302`.

---

## 10. Evidence checklist vs H4134 acceptance

| Required | Status |
|---|---|
| Gate-C walkthrough (or waive) + DEPLOY №52 smoke artifacts | **YES** — §1 (gate cleared 21-08 by a human) + §7 |
| day-7 / day-14 aggregate KPI table with decision | **YES** — §2–§4 (today-anchored **and** flip-anchored), decision §8 |
| Access/payment smoke results | **YES** — §7, green |
| Login adoption · first action · support · access self-resolution · repeat paid · revenue/active | **YES** — §4; continue-CTR + offer funnel **INCONCLUSIVE** by instrumentation defect §5; access self-resolution **INCONCLUSIVE** by thin n |
| No live `--send`, no flag change, no personal rows | **YES** — read-only pass |

_Dr. Mārcis Gasūns_
