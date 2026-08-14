# Grammar Lab G4 — human activation and rollback packet

_Created: 14-08-2026 · Last updated: 14-08-2026_

This packet is the only production-enable checklist for Grammar Lab Wave 1.
Merging H2495 (Grok 4.6) — Grammar Lab G4: hybrid entitlement, sandbox matrix, and learner pilot
does **not** charge anyone, flip a flag, or invite students.

## What is already live in code (still dark)

| Switch | Env | Default | Effect when ON |
|---|---|---|---|
| Product | `GRAMMAR_LAB` | OFF | `/grammar-lab` and `/dvaram/grammar-lab` leave 404 |
| Semantic search | `GRAMMAR_LAB_SEMANTIC` | OFF | Keep OFF until Recall@5 ≥ 0.85 |
| Pilot consent | `GRAMMAR_LAB_PILOT` | OFF | `/dvaram/grammar-lab/pilot` leaves 404 |
| Included courses | `GRAMMAR_LAB_COURSE_SLUGS` | empty | Comma-separated course slugs whose **paid** key unlocks the lab |
| Standalone SKU | `GRAMMAR_LAB_SUBSCRIPTION_COURSE_SLUG` | empty | Course slug of the standalone Grammar Lab product |
| Pilot eligibility | `GRAMMAR_LAB_PILOT_COURSE_SLUGS` | empty (falls back to included slugs) | Who may see the consent form |

Authorization is one question: `GrammarLabAccess::canUse()`. Course ownership, an active
standalone subscription grant, and a time-bounded admin/pilot grant all resolve through it.
PayPal / Tochka rows only emit lifecycle events; the product never reads a gateway field.

## Fields a human fills before any flag flip

1. **Price / SKU** — Filament course + tariff for the standalone slug. Leave empty to sell
   the lab only as a course add-on.
2. **Included courses** — exact slugs of current Kochergina / intermediate products.
3. **Pilot roster** — 5–10 current Russian-speaking intermediate students. Run
   `php artisan grammar-lab:pilot-eligibility --json` and pick by hand. Do not invite from
   this packet.
4. **Support copy** — students without access see HTTP 403 «Нет доступа к Лаборатории
   грамматики». Public landing says access is via a selected course or a standalone
   subscription and that production enable is a separate step.

## Production smoke (after a human flips flags)

1. `php artisan config:cache`
2. Guest: `/grammar-lab` 200, no topic IDs; `/dvaram/grammar-lab` → login.
3. Unentitled student: `/dvaram/grammar-lab` 403, body has no topic title / topic id.
4. Course-entitled student: index + one compare page 200.
5. Sandbox subscription (never live charge): activate → `canUse` true; cancel → deny.
6. `php artisan grammar-lab:rehearse-entitlement --json` on staging (dry-run default).

## Rollback (no data loss)

1. Set `GRAMMAR_LAB=false` and `GRAMMAR_LAB_PILOT=false`, then `php artisan config:cache`.
2. Routes 404. Entitlement and pilot rows stay; they grant nothing while the flag is OFF.
3. Do **not** delete `grammar_attempts`, bookmarks, or topic views.
4. Exercise kill-switch from G3 (`GRAMMAR_LAB_AUTO_PUBLISH=false` / `grammar-lab:rollback-exercise`)
   is independent.

## Fence

No production charge, no live subscription activation, no cohort launch, no feature-flag
flip is part of this merge. A 5–10-person pilot cannot support population-level claims.

_Dr. Mārcis Gasūns_
