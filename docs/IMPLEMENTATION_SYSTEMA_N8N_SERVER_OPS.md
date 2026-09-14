# IMPLEMENTATION — n8n server ops wave-1 (docs)

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md)

Wave-1 is **documentation only**. Steps below are the ordered build sequence for the docs PR and for re-running inventory later.

---

## Step 0 — Preconditions

1. SSH key access to `root@193.232.229.91` (BatchMode).  
2. Systema worktree off `origin/main` (main-tree guarded).  
3. Fence: no `docker compose` recreate; no n8n UI writes; no secret values in files.

---

## Step 1 — Live inventory (read-only)

1. `docker cp n8n-n8n-1:/home/node/.n8n/database.sqlite /tmp/n8n_audit.sqlite` (+ wal/shm if needed).  
2. Python: list `workflow_entity` (id, name, active, nodes JSON summary, triggers).  
3. Count credentials by type/name only.  
4. `du -sh /opt/n8n/storage/storage/workflows/*`.  
5. List `/opt/bookbuilder`, `/data/*`, `socks-nl.service` status.  
6. Write `docs/n8n/_server_inventory_YYYY-MM-DD.json` (no secrets).

**Depends on:** Step 0.

---

## Step 2 — Redacted exports

Export workflows:

- All **Active** (5)  
- `Ежемесячный пост…`, `СБОРКА КНИГ`, `РАСПИСАНИЕ + ТАБЛ`

Scrub before write:

- `bot\d+:TOKEN` in URLs  
- password/token/secret fields  
- `credentials.*.id`  
- `auto_order.py` CLI args  

Output: `docs/n8n/exports/*.live.json`.

**Depends on:** Step 1. **Verify:** ripgrep no `bot\d+:[A-Za-z0-9_-]{20,}`.

---

## Step 3 — Catalog + credential audit markdown

1. Author `CATALOG_N8N_SERVER_CONTEXT_AI_YYYY-MM-DD.md` (RU + EN ids).  
2. Author `CREDENTIAL_AUDIT_…` findings table.  
3. Bridge table from `config/services.php` N8N_* × live webhooks.  
4. Gap table vs `docs/n8n/*.workflow.json` templates.

**Depends on:** Steps 1–2.

---

## Step 4 — Layered plan docs

Write/update:

- `PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md` + `.meta.md`  
- `ROADMAP_…` · `ARCHITECTURE_…` · `IMPLEMENTATION_…` · `VERIFICATION_…`  
- Update `docs/n8n/README.md` index  

**Depends on:** interview rulings (locked in PLAN §2).

---

## Step 5 — Changelog + PR

1. `[Unreleased]` bullet in `CHANGELOG.md` (durable artifact threshold).  
2. Commit on branch `docs/n8n-server-catalog-…`.  
3. PR → merge when secrets scrub OK.

**Depends on:** Steps 3–4.

---

## Step 6 — Mint cleanup DAG handoffs (Uprava)

Mint separate handoffs (see ROADMAP DAG), each with starter line pointing at PLAN + specific wave item.  
Do **not** auto-execute secret rotation.

**Depends on:** Step 5 merge or same-pass if registry allows.

---

## Later waves (pointers only)

| Wave | Where steps live |
|---|---|
| Secret rotation | Credential audit C01–C03 + human checklist |
| Pin image / prune / tags | ROADMAP Wave 2 — operator runbook one-liners in handoff body |
| Bridge imports | Use existing JSON under `docs/n8n/`; Header Auth; flags stay OFF |
| Bookbuilder product | New handoff after C01 closed |

Default on ambiguity: **document the gap, do not invent live node config**.

---

_Dr. Mārcis Gasūns_
