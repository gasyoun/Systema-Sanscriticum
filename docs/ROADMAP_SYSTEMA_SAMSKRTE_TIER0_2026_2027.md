# ROADMAP — samskrte.ru Tier-0 · 2026–2027

_Created: 30-07-2026 · Last updated: 30-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** Wave 1's 28-08 window has passed and its
> handoff [H1939 (Grok, 🔴3 hard) — Marathon 28-08 wave-1 live](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1939-Grok_Systema-Sanscriticum_marathon-28-08-wave1-live_30.07.26.md)
> is archived-executed, as are the revenue-overlay bets H2378 (measurement), H2379 (Arzamas polish),
> H2381 (JIVO operator workflow), H2382 (parity acceptance) — see
> [ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md).
> Still open from Wave 2: [H2380 (Grok, 🔴3 hard) — cabinet adoption/KPI experiment](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2380-Grok_Systema-Sanscriticum_cabinet-adoption-kpi-experiment_07.08.26.md)
> (⛔ human-gated on DEPLOY №52 / H1582 flag). Analytics residue now minted as Claude wave 1:
> [PLAN_UPRAVA_ASK_CLAUDE_SYSTEMA_ROADMAP_MINT_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_UPRAVA_ASK_CLAUDE_SYSTEMA_ROADMAP_MINT_2026-08.md).

**Umbrella ID:** SAMSKRTE-TIER0 · **Wave-1 handoff:** [H1939](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1939-Grok_Systema-Sanscriticum_marathon-28-08-wave1-live_30.07.26.md) · **Pack:** /ask samskrte.ru 30-07-2026 · **Stem:** *_SYSTEMA_SAMSKRTE_TIER0_*

Index: [PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).

**Revenue overlay (audit 07-08-2026):** Wave 1 remains the hard gate. Immediately after it,
execute the evidence-backed sequence in
[`ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md):
repair measurement (H2378) → polish the already-shipped Arzamas/Синхронизация funnel (H2379) →
hybrid-cabinet adoption/KPI after H1582 (H2380) → JIVO workflow/production completion
(H2381/H2382, consuming H1200 rather than duplicating it).

## Waves

### Wave 1 — Marathon 28-08 fully live (hard gate · now → 28-08-2026) · ✅ window passed, H1939 archived (сверка 30-08-2026)

**Done when:** smoke A–E green + runbook Evidence complete (DR legs green or explicitly PARKED with secret gap).

| Track | Deliverable | Unblocks |
|---|---|---|
| Funnel | Landing branded + H1067 comms pack publish path executed | Registration volume |
| Money | Existing ₽500 Tochka path activated/smoke | Paid track |
| Notify | TG bot drip + SMTP transactional (homegrown mailbox) | Day 1–3 + password reset |
| DR | Weekly backup off-site Yandex + uptime TG dry-fire | Survive outage class of 24–26.07 |
| Deploy | CI deploy not no-op **or** agent `deploy.sh` fallback after every merge | Code reaches prod |
| ORS | ≥1 FAQ/bot/surface CTA → marathon landing | Joint Tier-0 funnel |
| Auth hygiene | Login CSRF / session fixes already on main are **on prod** | Teacher/student entry |

**Non-goals (W1):** new money semantics; GetCourse new domains; membership product; mobile app; media S3 migration; full ORS WP publish; research/csl; CabinetProbe perfection beyond quarantine; CT host migration.

### Wave 2 — Post-cohort ops + revenue GC gaps (Sep–Oct 2026)

Unblocked by W1 live + first cohort telemetry.

| Priority order (revenue-ranked default) | Item | Source |
|---|---|---|
| 1 | Repair card/checkout goals and publish the reconciled funnel | H2378 / revenue overlay |
| 2 | Hybrid cabinet Phase 4 via existing H1582, then adoption/KPI experiment | DEPLOY №52 + H2380 |
| 3 | Browser-verified Arzamas/Синхронизация polish of the shipped funnel | H2379; reuse H323/H387 |
| 4 | Flip remaining **Anton** student-comfort flags that are code-complete after smoke | [PLAN_SYSTEMA_ANTON_OPS_GAPS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md) / DEPLOY_QUEUE |
| 5 | Email campaigns bulk path once deliverability proven | DEPLOY №45 |
| 6 | JIVO operator workflow completion, then production parity acceptance | H2381/H2382; H1200 residual first |
| 7 | CRM pipeline board flag GO if manager needs it | GC-C1 residual / GTD `@DECIDE` |
| 8 | Homework auto-open wave-1 **code** if ops pain | [PLAN_SYSTEMA_HOMEWORK_AUTO_OPEN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA_2026H2.md) |
| 9 | Proxmox CT power-off root-cause policy + alert | GTD outage row |

### Wave 3 — GetCourse-parity spine core (Q4 2026)

Follow [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) / production spec — do **not** re-derive. Default order unless revenue data re-ranks:

1. Remaining **Domain B** (webinar provider abstraction / BBB skeleton) if Zoom risk materializes.
2. **Domain C** CRM completeness (`Deal` attribution, follow-up tasks already partly shipped).
3. **Domain D** quiz engine + translit-aware check (flagged; money access additive only).
4. **Domain A** segments + channel router (marketing).

Always-on parallel: security Wave 4 (Laravel 10→11), media storage roadmap when stage-2 trigger hits (Yandex Object Storage first — already ruled).

### Wave 4 — Growth / membership (2027 H1)

Execute [GROWTH_STRATEGY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GROWTH_STRATEGY_2026_2027.md): membership tariff key, archive flag on courses, monthly content pulse — **no money-core rewrites beyond additive tariff type** under a dedicated plan + human merge.

### Wave 5 — ORS-FAQ year track (parallel after W1 CTA)

WordPress/Woo publish ladder, LTV collector, bot improvements — own docs under ORS-FAQ; linked from this roadmap only as Tier-0 peers, not rebuilt inside Systema.


### Parallel product packaging — «Старт чтения» (Akro-style, from 01-08-2026)

Not a substitute for Wave 1 marathon live. Separate 5-week sub-offer under samskrte; Systema code track H2105–H2111.

- Register: [PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md)
- Competitive cut: [ROADMAP_AKRO_STYLE_SANSKRIT_PRODUCT_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/ROADMAP_AKRO_STYLE_SANSKRIT_PRODUCT_2026.md)

## Explicit non-goals (plan-wide)

- Sanskrit research repos, csl-orig corrections, paper pipeline.
- Replacing Tochka or rewriting Payment state machine.
- Migrating off Beget/Proxmox unless a separate cost/reliability `@DECIDE` appears.
- EN product / international full launch (growth strategy: light steps only).

---

_Dr. Mārcis Gasūns_
