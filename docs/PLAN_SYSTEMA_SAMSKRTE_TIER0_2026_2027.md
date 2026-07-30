# PLAN — SAMSKRTE-TIER0 · samskrte.ru / Tier-0 (Systema + ORS-FAQ) · 2026–2027

_Created: 30-07-2026 · Last updated: 30-07-2026_

**Umbrella ID:** `SAMSKRTE-TIER0` · **Wave-1 handoff:** [H1939](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1939-Grok_Systema-Sanscriticum_marathon-28-08-wave1-live_30.07.26.md) · **Pack:** `/ask samskrte.ru` 30-07-2026 · **Stem:** `*_SYSTEMA_SAMSKRTE_TIER0_*`

> One family: every layer file shares the stem **`SAMSKRTE_TIER0`**. Search the repo for `SAMSKRTE-TIER0` or `SAMSKRTE_TIER0` to pull the whole pack.

**Goal (one paragraph).** Make **samskrte.ru** (Laravel LMS [Systema-Sanscriticum](https://github.com/gasyoun/Systema-Sanscriticum)) and the linked Tier-0 funnel surfaces ([ORS-FAQ](https://github.com/gasyoun/ORS-FAQ)) a reliable revenue engine for 2026–2027: **wave-1** lands the **28-08-2026 marathon cohort fully live** (funnel → money path → notify → smoke A–E) with pre-launch DR (off-site backup + uptime Telegram) and deploy truth; **later waves** grow along the **GetCourse-parity spine** plus **growth/membership revenue bets**, with research/dictionary work out of scope.

**Launch (no full path required):** paste one of these into a fresh session:

```
/go H1939
```

```
Execute umbrella SAMSKRTE-TIER0 wave-1 (H1939).
```

Agent resolves H1939 + this PLAN by ID/stem; worktree off `origin/main`. Layer docs:

| Layer | Doc |
|---|---|
| Roadmap (year + waves) | [ROADMAP_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_SAMSKRTE_TIER0.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_TIER0.md) |
| Implementation (wave-1) | [IMPLEMENTATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md) |
| Verification (wave-1) | [VERIFICATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md) |
| Ops runbook (evidence log) | [RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md) |

Companion metadoc: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.meta.md).

---

## Decisions taken (interview 30-07-2026)

| # | Topic | Ruling | Rationale |
|---|---|---|---|
| D1 | Horizon | **2026–2027 (year)** | Strategic plan, not a single sprint. |
| D2 | Wave-1 done | **Marathon 28-08 fully live** | Hard revenue gate. |
| D3 | Competing priority | **Revenue / marathon funnel** | Tier-0 default. |
| D4 | Non-goals | **Research / dictionaries / papers out**; LMS + ops (+ joint Tier-0 FAQ) | Protect focus. |
| D5 | 28-08 hardness | **Hard gate for wave-1** | Anything not needed for live → wave-2+. |
| D6 | Marathon structure | **Layers: funnel content → money path → notify → smoke** | Ordered accept, not big-bang only. |
| D7 | Prod ownership W1 | **Agent runs `deploy.sh` + flags** when credentials allow | Supersedes older “agent has no SSH” checklist wording for this plan. |
| D8 | Year spine | **GetCourse-parity + revenue bets** | Existing [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) + [GROWTH_STRATEGY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GROWTH_STRATEGY_2026_2027.md). |
| D9 | Money code | **Do not edit payment/access/grant code**; activate existing only | Fence for unattended safety. |
| D10 | ORS-FAQ | **Full joint Tier-0 plan**; W1 = marathon CTA surfaces only | Year owns WP/bot/CRM unlock. |
| D11 | Hosting | **In scope:** uptime, DR, deploy truth, CT power-off prevention | Post 24–26.07 outage. |
| D12 | W1 artifact | **One runbook + evidence log** | [RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md). |
| D13 | ESP/SMTP | **Homegrown mailbox (mail.ru / Yandex 360) + SPF/DKIM** (H1449 path) | Close [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504). |
| D14 | Deploy truth | **Fix CI deploy + agent post-merge deploy fallback** | [#828](https://github.com/gasyoun/Systema-Sanscriticum/issues/828). |
| D15 | DR in W1 | **Yes — pre-launch gate** | Yandex off-site + uptime TG dry-fire. |
| D16 | GC after marathon | **Wave-2+ ranked by revenue** | Anton ops gaps / remaining GC tickets. |
| D17 | Smoke set | **A–E:** landing 200 → register → ₽500 Tochka → TG day msg → email deliver | All required for “fully live”. |
| D18 | Code quality W1 | **Targeted Feature tests + suite on touched scope; no money edits** | Pint + phpunit. |
| D19 | DR pass | **Yandex archive this week + uptime TG dry-fire OK** | Not full restore drill in W1. |
| D20 | Red CI | **Quarantine prod-coupled flaky tests** (e.g. CabinetProbe); don’t block real deploy | [#865](https://github.com/gasyoun/Systema-Sanscriticum/issues/865). |
| D21 | ORS W1 proof | **≥1 live surface links to marathon landing + path documented** | Not Metrika OAuth required. |
| D22 | Ambiguity | **Pick Recommended default; log in Evidence** | Only halt on money/access semantics. |
| D23 | Stop | **Money/access change needed · prod data-loss risk · Tochka live-charge ambiguity** | Rollback first if deploy breaks login/checkout. |
| D24 | Git authority | **Handoff-scoped commit→PR→merge non-money; money PR no auto-merge** | House rule. |
| D25 | Fence | **Payment/access/grant · csl-orig · secrets in git · force-push · no CT power-off · no drop DB · no commit of production `.env`** | Absolute. |
| D26 | Human in 8h window | **Zero human required; agent only what credentials allow** | Missing secret → park that gate with evidence, do not invent credentials. |

---

## Autonomy contract (verbatim)

1. **On ambiguity:** apply the Recommended default from this PLAN; append one Evidence line in the runbook (`YYYY-MM-DD · default · why`). Do not invent new product scope.
2. **Stop immediately when:** (a) a correct fix would require editing payment/access/grant code; (b) an action risks irreversible prod data loss; (c) Tochka live charge behaviour is ambiguous (refund/double-charge). If a deploy breaks login or checkout: **rollback** (`git` previous deploy or known-good commit via `deploy.sh` path), then stop with evidence.
3. **Commit authority:** non-money changes: commit → PR → merge under the handoff. Money-touching PRs (if any slip past fence): open PR, **no auto-merge**, stop for human. Docs/runbook may ship with the same PR as ops code.
4. **Fence:** never edit `Payment*`, access grant materializers, tariff unlock paths for semantics; never commit secrets; never force-push; never power off Proxmox CT; never drop/truncate production DB.
5. **Credentials:** if Yandex WebDAV / SMTP / GitHub Actions secrets / SSH deploy key are missing, **park** that gate with a FAIL/PARK row in Evidence and continue other layers. Wave-1 may not claim “fully live” until smoke A–E pass; DR legs may be PARK if secrets absent (D26) — document explicitly, do not fake green.

---

## Prior-art (do not rebuild)

| Asset | Reuse |
|---|---|
| Marathon product (H440 phases) | Code on `main`; activate via [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md) + this plan’s layered runbook |
| Weekly backup | Spatie `backup:run` Mon 02:00; [config/backup.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/backup.php); [Uprava/BACKUPS.md](https://github.com/gasyoun/Uprava/blob/main/BACKUPS.md) |
| Uptime | [`.github/workflows/uptime-samskrte.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/uptime-samskrte.yml) — needs TG secrets |
| Deploy | [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) + [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) + CI [deploy.yml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml) (truth fix) |
| GetCourse spine | [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) + [GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) |
| Growth / membership | [GROWTH_STRATEGY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GROWTH_STRATEGY_2026_2027.md) |
| Anton ops flags | [PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md) |
| Email campaigns path | H1449 / DEPLOY_QUEUE №45 |
| Org backup scripts | `Uprava/tools/backup_*.py` (local census/Drive — not W1 server) |

---

## Autonomy-readiness gate (Phase 4)

| Wave-1 deliverable | Arch | Impl steps | Acceptance | Risks |
|---|---|---|---|---|
| L1 Funnel content | ✅ | ✅ | Landing 200 + H1067 path | Landing slug/Filament row missing |
| L2 Money path | ✅ | ✅ | ₽500 Tochka success | Agent must not edit Payment; Tochka env only |
| L3 Notify | ✅ | ✅ | TG day + email deliver | SMTP secrets may PARK |
| L4 Smoke A–E | ✅ | ✅ | All five green | Partial green ≠ live |
| DR backup off-site | ✅ | ✅ | Archive this week on Yandex | Creds may PARK (D26) |
| DR uptime TG | ✅ | ✅ | Dry-fire alert | Actions secrets may PARK |
| Deploy truth | ✅ | ✅ | Real deploy or agent fallback logged | #828 |
| ORS CTA | ✅ | ✅ | ≥1 live link path | Soft joint |

**Gate: PASS** for authoring — zero blocking `@DECIDE` inside wave-1 path; residual secrets are explicit PARK under D26, not open product forks.

---

_Dr. Mārcis Gasūns_
