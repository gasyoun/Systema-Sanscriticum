# ARCHITECTURE — Noboring dozhim (order→pay)

_Created: 01-08-2026 · Last updated: 01-08-2026_

Index: [PLAN_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_Systema_NOBORING_DOZHIM_2026H2.md)

## 1. Funnel model

```
Traffic → intro/marathon/content
       → Lead (interest) and/or Payment intent (pending)
       → Deal (open)  ←—— dozhim operator surface
       → Payment paid → Deal won (existing bridge)
       → access grant (money core only)
```

**Denomination A (primary):** eligible unpaid/pending Payment or Order-like intent → paid.  
**Denomination B (secondary):** Lead created in period → Lead has ≥1 successful Payment in window.

## 2. Components

| Component | Reuse / build | Notes |
|---|---|---|
| Deal / DealStage / DealTransition | **Reuse** | GC-C1 H1641 |
| PaymentDealBridgeObserver | **Extend carefully** | Today syncs on paid/reversal; may open Deal earlier on pending if qualifies — still **no grant** |
| UnifiedSalesBoard / DealKanban | **Reuse** | View layer |
| FollowUpTask + WorkQueue | **Extend** | New bucket / auto-create |
| MessageTemplate | **Reuse + seeds** | category `dozhim` |
| Messaging/* DeliveryChannel | **Reuse** | GC-A2 router NOT_BUILT — fan-out with existing flags OK for wave-1 |
| Baseline CLI | **Build** | `tools/` or `app/Console/Commands` |
| Cabinet recovery | **Build** | Student route under cabinet; flag-gated |
| Installment product | **Out** | CTA only |

## 3. Authority ladder (hard)

From [GETCOURSE_PARITY_PRODUCTION_SPEC §2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md):

1. Payment / grant (rank 1) — **fence**
2. …
3. Deal (rank 4) — **observes**, never authorises access or price

If Deal and Payment disagree → **Payment wins**.

## 4. Flags

| Flag | Default | Staging |
|---|---|---|
| `crm_pipeline_board` | false | admin ON |
| `crm_follow_up_tasks` | false | admin ON |
| `dozhim_queue` | false | admin ON |
| `dozhim_drip` | false | admin ON |
| `payment_recovery_cta` | false | admin ON; prod human |

## 5. Cross-repo

| Repo | Role |
|---|---|
| Systema | All product + measurement |
| ORS-FAQ | Cross-link in sales roadmap; UTM if recovery linked from content |
| Uprava | Handoffs, GTD, ROADMAP_INDEX |

_Dr. Mārcis Gasūns_
