# PLAN — Noboring «дожим» → samskrte / Systema (2026H2)

_Created: 01-08-2026 · Last updated: 07-08-2026_

**Goal (one paragraph).** Перенять кейс [Нескучные финансы — «Нашли и обезвредили слабое место в продажах»](https://noboring-finance.ru/cases/dozhimali-klientov/) (рубрика Education): у них конверсия **оформленный заказ → оплата** выросла с **47% → 63% → 67%** после замены аутсорс-колл-центра на собственный отдел продаж (сценарии, CRM, upsell, решение возражений). У нас «свой отдел» = **продукт**: Deal-воронка + очередь недоплативших + шаблоны/drip + student recovery CTA — не найм 5 менеджеров. Success metric: **заявка→оплата** с двумя знаменателями (unpaid Order + Lead without payment). Primary home: Systema; витрина — CRM 70% + 1–2 фронт-рычага на том же шаге funnel. После wave-1 — следующий education-кейс НФ: [антикейс языковой школы](https://noboring-finance.ru/cases/antikejs-yazykovoj-shkoly-produkt-horoshij-deneg-net/).

**Interview:** Grok 4.5 (`grok-4.5`), `/ask`, 4 rounds, 01-08-2026.

## Layer docs

| Layer | Doc |
|---|---|
| Roadmap | [ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_Systema_NOBORING_DOZHIM_2026H2.md) |
| Architecture | [ARCHITECTURE_Systema_NOBORING_DOZHIM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_Systema_NOBORING_DOZHIM.md) |
| Implementation | [IMPLEMENTATION_Systema_NOBORING_DOZHIM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_Systema_NOBORING_DOZHIM.md) |
| Verification | [VERIFICATION_Systema_NOBORING_DOZHIM.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_Systema_NOBORING_DOZHIM.md) |
| Metadoc | [PLAN_Systema_NOBORING_DOZHIM_2026H2.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_Systema_NOBORING_DOZHIM_2026H2.meta.md) |

## Prior art (do not rebuild)

| Asset | State | Implication for this plan |
|---|---|---|
| [Deal](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Deal.php) + DealKanban + PaymentDealBridgeObserver | **GC-C1 DONE** (H1641) | H-A is **gap-fill for dozhim**, not greenfield Deal |
| [UnifiedSalesBoard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnifiedSalesBoard.php) | Shipped (H1658) | Reuse as operator surface |
| [FollowUpTask](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/FollowUpTask.php) | GC-C3 DONE (H1836) | Hang tasks on Deal; dozhim queue feeds WorkQueue |
| [WorkQueue](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/WorkQueue.php) / MessageTemplate | CRM cockpit | Add unpaid-order bucket + templates category `dozhim` |
| [OrderPaymentConversionService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php) | Course/channel + **GC-C2** `managerScoreboard()` (H2058 residual / PR #1098) | Baseline + manager sales page (flag OFF) |
| [roadmap_samskrte_sales.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md) | Phase 0 partial | Cross-link; do not fork a third sales roadmap |
| [increase_course_sales.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/increase_course_sales.md) | Diagnosis still valid | Front friction sibling, not wave-1 core |
| H261 NPV invest model | Earlier NF education case | Same programme family; different lever |
| Money core / PaymentObserver grant | Rank 1 | Fence — Deal observes only |

## Decisions taken (interview)

| # | Round | Decision | Rationale |
|---|---|---|---|
| D1 | R1 | Corpus: **this case end-to-end**, then Education vertical one-by-one | User: not all NF industries first |
| D2 | R1 | Surfaces: **both** shop/cabinet + admin; wave-1 still **narrow dozhim** | Tension resolved in D6 |
| D3 | R1 | Metric: **заявка→оплата** (combo denominators) | Direct map of 47%→67% |
| D4 | R1 | Extend existing sales + GetCourse CRM + LTV roadmaps | No standalone parallel empire |
| D5 | R1 | People model: **product/CRM = sales team** | No 5-hire program |
| D6 | R2 | Wave-1 = **CRM 70% + 1–2 front levers on order→pay step** | Not full «С чего начать» |
| D7 | R2 | Denominators: **Order unpaid + Lead without payment** | Two rates, Order primary |
| D8 | R3 | UI stack: **admin queue + templates + auto-drip** | Resolved multi-select conflict |
| D9 | R2/R3 | User asked Full GC-C1 first — **prior art: GC-C1 already DONE** → H-A = **dozhim readiness gap-fill** on Deal (open Deal on pending intent, unpaid queue, C2 if needed) | Honors ruling without rebuild |
| D10 | R3 | Recovery page = **Systema student cabinet route** | samskrte is Systema |
| D11 | R3 | Installment = **CTA copy/link only** | Real Tochka installment later |
| D12 | R3 | Baseline from **Systema DB script** in tools/ | Reproducible |
| D13 | R3 | Next NF case = **language school anti-case** | After dozhim metric works |
| D14 | R4 | Accept: tests green + baseline script + flag-gated smoke | Not live lift required for ship |
| D15 | R4 | Handoffs: **H-A → H-B → H-C** sequential | Full-GC-C1-first spirit |
| D16 | R4 | Flags: default OFF prod; **admin-only ON staging** | |
| D17 | R5 | Ambiguity: PLAN default + log; **park money-touching** | |
| D18 | R5 | Stop: money grant/webhook, watcher, red money tests, Deal schema conflict | |
| D19 | R5 | Fence: PaymentObserver grant paths, prod .env, force-push, live flags ON | |

## Autonomy contract

| Item | Rule |
|---|---|
| On ambiguity | Apply marked default in this PLAN; log one line in `.ai_state.md`; continue |
| Money-touching ambiguity | **Park-and-skip** that item; open/leave GTD `@DO` for human; never invent grant/price behavior |
| Stop conditions | Money grant/webhook change needed; watcher reverts tree; money-related tests red after fix attempt; Deal schema conflicts with production rows |
| Commit authority | Worktree → PR → auto-merge OK for non-money; **money UX PR** (`payment_recovery_cta`) → **no auto-merge**, GTD human-merge |
| Fence | Do not edit `PaymentObserver` grant paths; do not flip prod flags; no force-push; no prod `.env` |
| Watcher | Systema: land via worktree + `/watcher-safe-commit` discipline if editing afflicted paths |

## Execution handoffs (minted with this plan)

| ID | Scope | Starter |
|---|---|---|
| H-A | Deal dozhim readiness + GC-C2 residual | See Uprava handoff file |
| H-B | Baseline script + unpaid queue + templates + drip | after H-A |
| H-C | Cabinet recovery route + installment CTA (flag-gated) | after H-B or parallel after H-A if A green |

## Autonomy-readiness gate

| Wave-1 deliverable | Arch | Steps | Accept | Risks |
|---|---|---|---|---|
| H-A Deal readiness | Y | Y | Y | Y — mostly exists; gap is pending-intent Deal |
| H-B queue+drip | Y | Y | Y | Y — channel router partial (A2 NOT_BUILT) → use existing Messaging |
| H-C recovery CTA | Y | Y | Y | Y — money-adjacent, flag + no auto-merge |

**Gate: PASS** for docs + sequential handoffs. Residual non-blocking: live % lift is post-ship measurement, not ship blocker (D14).

_Dr. Mārcis Gasūns_
