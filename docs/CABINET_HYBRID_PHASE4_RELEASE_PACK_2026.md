# Cabinet hybrid — Phase 4 release pack (R20 flag flip)

_Created: 24-07-2026 · Last updated: 24-07-2026_

Release packaging for the hybrid student cabinet (R29). Phases 0–3 are built
behind `cabinet_hybrid` / `CABINET_HYBRID` (**default OFF**). This pack is the
**GO/NO-GO** apparatus for the one-shot flag flip — not the flip itself.

Spec: [STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
§5–§6. Handoff [H1582](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1582-Opus_Systema-Sanscriticum_cabinet-hybrid-phase4-flag-flip-release_24.07.26.md).

**Executor note:** Grok 4.5 (`grok-4.5`) — packaging only; a human decides
activation after the gates below.

## 1. What is already on `main`

| Phase | Deliverable | PR |
|---|---|---|
| 0 | Baseline telemetry (`cabinet:baseline`, §4 events) | [#534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534) — deploy queue №25 archived 21-07-2026 |
| 1 | Chassis, job-nav, recovery resolver, today band | [#673](https://github.com/gasyoun/Systema-Sanscriticum/pull/673) |
| 2 | Записи shelves, lapse, rail, ownership offer | [#674](https://github.com/gasyoun/Systema-Sanscriticum/pull/674) |
| 3 | Прогресс ladder, lighting, course vehi | [#678](https://github.com/gasyoun/Systema-Sanscriticum/pull/678) |
| 4 | This pack + DEPLOY_QUEUE №52 | (this PR) |

Flag remains `false` until DEPLOY_QUEUE №52 is executed on prod.

## 2. R20 gates (all required before flip)

| # | Gate | How to verify | Status at pack time (24-07-2026) |
|---|---|---|---|
| A | Phase 0 events live **≥14 days** | `php artisan cabinet:hybrid-readiness` (baseline clock) or `cabinet:baseline --days=14` | **HOLD** — №25 clock started **21-07-2026** → earliest GO ~**04-08-2026** |
| B | Hybrid code on prod `main` (Phases 1–3) | Deploy current `main`; readiness lists route/class checks | Code on GitHub; prod deploy is human |
| C | Spec walkthrough signed | §3 checklist below (or review-sheet) | Human |
| D | MG `@DECIDE` GO | Explicit human ruling | Human |

No canary (ruled R20). Instant revert = set `CABINET_HYBRID=false` + `config:clear`.

## 3. Walkthrough checklist (against R29)

Run with `CABINET_HYBRID=true` on **staging** first (never flip prod without A–D).

| # | Page / organ | Check | Pass? |
|---|---|---|---|
| R29.0 | Job nav | Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь visible | [ ] |
| R29.0 | Course tabs | Hash tabs on course home work (`#uroki` etc.) | [ ] |
| R29.1 | Сегодня band | Continue + live; homework rework **only** when needs_revision | [ ] |
| R29.2 | Recovery | Failed payment → recovery banner, **zero** offers, owned lessons open | [ ] |
| R29.2 | Non-recovery | Bare `pending` does **not** enter recovery | [ ] |
| R29.3 | Записи shelves | Watching / owned / lapsed / completed sections | [ ] |
| R29.4 | Rail | Recording course without homework shows progress rail | [ ] |
| R29.5 | Ownership offer | At most one; gone under recovery | [ ] |
| R29.6 | Ladder | Station map on Прогресс (4 stations) | [ ] |
| R29.7 | Lighting | Offer only after station complete; «Станция подождёт»; no countdown | [ ] |
| R29.8 | Vehi | Course home landmarks from blocks; wording is orientation not payment | [ ] |
| R8 | Membership | «Скоро» card still on Записи | [ ] |
| Flag off | Prod inert | With flag false: legacy dashboard; `/library` 404 | [ ] |

## 4. Activation procedure (prod — human)

1. Confirm readiness GO:
   ```bash
   php artisan cabinet:hybrid-readiness
   php artisan cabinet:baseline --days=14
   ```
2. Deploy current `main` (`sudo bash deploy.sh` / usual path).
3. Staging walkthrough §3 green + MG GO.
4. On prod:
   ```bash
   # .env
   CABINET_HYBRID=true
   php artisan config:clear
   # if config is cached in prod:
   # php artisan config:cache
   ```
5. Smoke: log in as a real student → Сегодня shows hybrid shell; calendar still works;
   one lesson opens; recovery not false-positive on pending.
6. Post-flip readout (day 7 / day 14):
   ```bash
   php artisan cabinet:baseline --days=7
   php artisan cabinet:baseline --days=14
   ```
   Compare to pre-flip baseline snapshot (save CLI output in this doc or GTD).

## 5. Instant revert

```bash
CABINET_HYBRID=false
php artisan config:clear
```

No migration rollback. Money core untouched by the flag.

## 6. Post-release KPI (north star reminder)

From research ledger / spec §4: continue-CTR, time-to-first-action, lesson completion,
offer CTR by kind, renewal rate, access self-resolution, **revenue per active student**.
`cabinet:baseline` is the counting surface — not the full KPI dashboard.

## 7. Money / risk note

Recovery + offer suppression are money-adjacent UI predicates. They do **not** grant or
revoke access. Ownership/ladder offers are CTA/impression only; checkout stays the
existing money path. Flip still wants a human skim of recovery scenarios on staging.

_Dr. Mārcis Gasūns_
