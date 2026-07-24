# VERIFICATION — VK/ORS content calendar

_Created: 24-07-2026 · Last updated: 24-07-2026_

Index: [`docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md).

---

## 1. Acceptance per wave

### W1

| ID | Criterion | Proof |
|---|---|---|
| A1 | Migrations clean | CI migrate |
| A2 | Fixture import + seed ≥20 slots | feature test |
| A3 | Flag OFF hides Filament | feature test |
| A4 | Keep/Cancel transitions | unit/feature |
| A5 | Pint clean | CI |
| A6 | Filament smoke | human or Livewire render test + DEPLOY_QUEUE |

### W2

| ID | Criterion | Proof |
|---|---|---|
| B1 | Evergreen pick respects age≥12m + topics | unit |
| B2 | De-dupe 6m | unit |
| B3 | Body equals source excerpt/text | feature |
| B4 | Promo exclusion | unit |

### W3

| ID | Criterion | Proof |
|---|---|---|
| C1 | Free clip → slot with lesson/clip ref | feature |
| C2 | No ffmpeg/VK upload from bridge | code review / no Http::post to cut |

### W4

| ID | Criterion | Proof |
|---|---|---|
| D1 | Forward drafts status=draft | feature |
| D2 | Skip-review does not schedule forward | feature |

### W5

| ID | Criterion | Proof |
|---|---|---|
| E1 | Due publisher no-ops when autopilot OFF | feature |
| E2 | Cancel blocked inside last 24h | unit |
| E3 | n8n called once per due slot (Http::fake) | feature |
| E4 | No live VK in CI | Http::fake only |

### Post-activation metric (D20)

| ID | Metric |
|---|---|
| M1 | ≥20 `status=published` slots per calendar month |

---

## 2. Commands

```bash
php artisan test --filter=Content
php artisan test --filter=Calendar
./vendor/bin/pint --dirty
php artisan content:import-vk-ors --path=tests/Fixtures/vk_ors
php artisan content:seed-month 2026-09
```

---

## 3. Risk register

| Risk | Sev | Mitigation |
|---|---|---|
| Verbatim recycle re-posts stale prices/dead links | **High** | D17 locked; W2 later optional link-check job; monthly Cancel for bad ones |
| ≥20/month with NEW held on skip-review | Med | Evergreen+bridge must supply ≥20; seed density |
| Double-post same VK content | Med | source_ref de-dupe + publish ledger |
| ContentCandidate fork vs lecture engine | Med | Step 0 probe; single table |
| vk-ors CSV drift / missing refresh | Med | IndologyScholars export handoff; pin snapshot date in meta |
| n8n VK token abuse | Med | Flag OFF; secrets in n8n only |
| Filament smoke blocks unattended merge | Low | Livewire render proxy + human activation (D19) |
| Rights: closed nagari mixed in | High | **Never** import nagari; vk-ors public wall only |

---

## 4. Spikes

1. Confirm whether H1547 ContentCandidate already merged before W1 migration.
2. VK delayed post vs Laravel-side schedule (prefer Laravel ticker for cancel audit).

_Dr. Mārcis Gasūns_
