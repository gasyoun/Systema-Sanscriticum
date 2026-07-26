# VERIFICATION — Online Sanskrit Games

_Created: 26-07-2026 · Last updated: 26-07-2026_

Index: [PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md)

---

## 1. Acceptance criteria by deliverable

### Wave 0 (this `/ask` session)

| Criterion | Proof |
|---|---|
| Five layered docs exist | PLAN, ROADMAP, ARCHITECTURE, IMPLEMENTATION, VERIFICATION under `docs/` |
| Invent catalogue ≥15 | ROADMAP three sections; ≥15 IDs |
| Decisions + autonomy contract recorded | PLAN tables |
| Handoffs minted deferred | Registry rows QUEUED; no product code required |
| Metadoc for PLAN | `PLAN_*.meta.md` |

### Wave 1 — Platform

| Criterion | Proof command / flow |
|---|---|
| Guest UUID persisted | Browser: localStorage key set; DB row has `guest_id` |
| Events accept guest_id | `php artisan test --filter=GameTelemetry` (or new test class) |
| 5 plays/family | Smoke: complete 5 in `sort`, 6th gated; `match` still free |
| Authenticated ungated | Login → unlimited play |
| Funnel stages visible | `php artisan games:funnel --days=30` includes started/completed/gate/cta |
| CTA→register ≥15% | Report: among guests with `cta_click`, share that gain `user_id` within 7 days ≥15% **or** explicit note if sample &lt; 50 (baseline period) |

**KPI definition (locked D6):**

```
cta_clickers = distinct guest_id with event=cta_click in window
registered   = subset where same guest_id later has user_id on any game_event
             OR user.created_at within 7d after first cta_click and same guest merge ran
rate         = registered / cta_clickers
pass         = rate ≥ 0.15 when cta_clickers ≥ 50; else "baseline only, do not kill"
```

### Wave 1 — Packs P0/P1

| Criterion | Proof |
|---|---|
| Pack loads offline | Open index.html; no network required for engine |
| Completes a round | Manual or Playwright: finish → success feedback |
| Telemetry | `completed` event with correct `drill` id |
| Provenance | HTML/README cites fixture path or “curated phonology” |
| Gloss quality (if RU meanings) | Spot-check ≥20 rows logged in PR body |
| Catalogue linked | Card on `/exercises/` |

### Wave 1b — csl-guides

| Criterion | Proof |
|---|---|
| Page loads Systema drill | Smoke embed/link |
| No drift | Script: hash of Systema `data.js` matches meta |

### Wave 2 — SRS onboarding

| Criterion | Proof |
|---|---|
| Import cap 20 | Unit/feature test |
| Idempotent | Second login no duplicate cards |
| Flag OFF default | Deck routes 404 without `SRS_ENABLED` |
| Empty guest no-op | User with no events → 0 cards |

### Wave 2 — Cabinet skill drills

| Criterion | Proof |
|---|---|
| Not FSRS UI | Separate route/component from `/dvaram/srs` |
| Optional lesson attach | Filament or config field tested |

---

## 2. Smoke checklist (Wave 1 packs)

For each new pack:

1. Cold load (logged out) → play 1 → event `started`/`completed`
2. Locale toggle RU↔EN (if strings present)
3. After 5 plays in family → gate UI → CTA
4. Login → gate disappears
5. Mobile width 375px: playable (drag/tap)

---

## 3. Risks & spikes register

| ID | Risk | Severity | Mitigation | Spike? |
|---|---|---|---|---|
| R1 | CTA→register join is noisy (no guest merge) | High for KPI | Implement guest→user merge on first auth event (IMPL step 3) | No — required |
| R2 | 5-play gate lowers urgency vs 1-play | Med | Keep strong CTA copy; measure 30d | No |
| R3 | Kochergina/Memrise gloss mismatch for A0 | Med | Spot-check ≥20; prefer H1431 verified subset | No |
| R4 | Dual-surface drift Systema↔csl-guides | Med | Hash check (D17) | Wave 1b |
| R5 | Temptation to build sandhi engine early | High scope | D16 park `needs-engine` | No |
| R6 | SRS import surprises users | Med | Toast “Добавили N слов в повторения”; cap 20 | Wave 2 |
| R7 | Audio games expected by A1 cohort | Med product | Explicit non-goal until audio wave | No |
| R8 | Concurrent sessions on Systema main tree | High process | Worktree mandatory (D20) | No |
| R9 | EN copy quality | Low | Fallback RU; EN can be thinner | No |
| R10 | `item_seen` payload bloat | Low | Cap list length server-side (e.g. 50 ids/event) | Optional |

---

## 4. What “fail Wave 1” means

- Gate broken for authenticated users (blocked when logged in)
- Money/access code touched
- New engine introduced under Wave-1 handoff
- Packs ship without provenance for gloss data
- Tests for telemetry regression red and merged anyway

**Does not fail Wave 1:** KPI &lt;15% with sample &lt;50 (baseline window). KPI miss with large sample → iterate copy/gate, not abandon platform.

---

## 5. Autonomy-readiness (Wave 1 handoff)

| Deliverable | Arch | Steps | Acceptance | Risks |
|---|---|---|---|---|
| Guest UUID + events | ARCH §2–3 | IMPL 1, 3 | VER §1 platform | R1, R10 |
| 5-play gate | ARCH §2.2 | IMPL 2 | VER §1 platform | R2 |
| Locale shell | ARCH §3.1 | IMPL 4 | VER smoke #2 | R9 |
| P0 packs C01–C03 | ARCH §2.1 | IMPL 5–7 | VER packs | R3 |
| P1 packs C04–C06 | ARCH §2.1 | IMPL 8 | VER packs | — |

All four columns present → **ready for deferred `/go`**.

---

_Dr. Mārcis Gasūns_
