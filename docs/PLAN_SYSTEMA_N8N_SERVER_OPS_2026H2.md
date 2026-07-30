# PLAN — n8n server ops (context-ai.ru / Systema bridge, 2026 H2)

_Created: 30-07-2026 · Last updated: 30-07-2026_

Cover index for a layered `/ask` plan. Goal: **durable documentation of the live n8n estate** on `samskrtam50` (`193.232.229.91`, `https://context-ai.ru`) plus an **execution-ready cleanup DAG** (credentials, storage, archive tags, import gaps) — without changing Active production workflows in the docs wave.

Provenance: `/ask` interview 30-07-2026 (5 rounds, Grok 4.5 `grok-4.5`) + SSH read-only audit of live sqlite/workflows/host scripts.

Metadoc: [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.meta.md)

---

## 1. Goal (one paragraph)

Make the n8n instance **operable by a future agent and skimmable by a human**: full catalog of 76 workflows + host scripts + Laravel bridge table; credential findings without secret values; layered roadmap for hygiene and product contours (bookbuilder, webinar, Systema content). Wave-1 **authors docs only** (this PR). Later handoffs execute cleanup under the autonomy contract — never silent edits to ON workflows or money paths.

---

## 2. Decisions taken (interview 30-07-2026 — do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Primary outcome | **Full catalog + improvement plan** (not activate-only, not ops-manual-only) |
| D2 | Doc home | **Systema-Sanscriticum `docs/n8n/`** (+ PLAN layers under `docs/`) |
| D3 | Wave-1 done | **Catalog + gap-analysis + cleanup plan only** — no live ON workflow mutation |
| D4 | Dead workflows | **Archive-tag + never delete yet** (export before any future purge) |
| D5 | Secrets | **Full credential audit** deliverable; rotation is human-gated follow-up |
| D6 | Scope | **n8n + host scripts + Laravel bridge** end-to-end |
| D7 | Canonical ZOOM | **`ZOOM 1.4 (Final) + АДМИНКА ТЕСТ`** only Active truth |
| D8 | Bridge model | **Table N8N_* env → path → workflow id → status → gap** |
| D9 | Heavy media | **Host SSH + `/data`** (status quo; document) |
| D10 | Git vs live | **Git = templates + redacted exports; live = runtime** |
| D11 | Proxy stack | **Document socks-nl + privoxy as required yt-dlp infra** |
| D12 | Bookbuilder | **First-class product contour**; wave-1 = **security plan**, product roadmap = separate handoff |
| D13 | Artifacts | Catalog + PLAN stack + credential audit |
| D14 | Language | **RU operator prose + EN ids/paths** |
| D15 | Exports | **5 Active + key inactive** (monthly, book, schedule stub) redacted |
| D16 | Audit shape | Severity × location × remediation × owner |
| D17 | Catalog proof | Inventory JSON + date stamp (+ regen notes) |
| D18 | Acceptance | All 76 named · 5 Active deep · bridge complete · **zero secrets in git** |
| D19 | Gaps | Table with severity + recommended action (no auto-import in wave-1) |
| D20 | Risks named | Secrets · `:latest` · storage · webhook auth · dual Zoom webhooks |
| D21 | Reader | Future agent first · human skim second |
| D22 | Ambiguity | **Pick plan default, log, continue** |
| D23 | Stop | Would need secret values · change ON workflows · money code |
| D24 | Git authority | **Worktree → PR → merge** when green |
| D25 | Fence | Live n8n DB/UI mutations · `.env` secrets · docker recreate · ON logic · payment money paths |
| D26 | After plan | **Mint full cleanup DAG** handoffs (not one mega) |

---

## 3. Autonomy contract (verbatim)

- **On ambiguity:** choose the default marked in this plan / IMPLEMENTATION, log one line in the handoff, continue.
- **Stop if:** task requires writing production secret values into git; changing Active workflow graphs; touching payment settlement logic; `docker compose up` recreate without human; deleting workflows.
- **Commit authority:** Systema worktree → PR → merge when docs-only and secrets scrubbed.
- **Fence:** read-only SSH for re-inventory OK; no n8n UI API writes in docs wave; no `/opt/n8n/.env` edits; no money path code in Laravel beyond documentation references.
- **Money-adjacent:** `АДМИНКА+ТАБЛИЦА ОПЛАТ` auth hardening is a **separate human-gated** handoff, not silent agent work.

---

## 4. Layer links

| Layer | File |
|---|---|
| Roadmap / waves | [ROADMAP_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_N8N_SERVER_OPS_2026H2.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS.md) |
| Implementation (wave-1) | [IMPLEMENTATION_SYSTEMA_N8N_SERVER_OPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_N8N_SERVER_OPS.md) |
| Verification + risks | [VERIFICATION_SYSTEMA_N8N_SERVER_OPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_N8N_SERVER_OPS.md) |
| Catalog | [docs/n8n/CATALOG_N8N_SERVER_CONTEXT_AI_2026-07-30.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/CATALOG_N8N_SERVER_CONTEXT_AI_2026-07-30.md) |
| Credential audit | [docs/n8n/CREDENTIAL_AUDIT_N8N_CONTEXT_AI_2026-07-30.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/CREDENTIAL_AUDIT_N8N_CONTEXT_AI_2026-07-30.md) |

Related product plans (do not merge scopes): lecture content engine H2; VK/ORS content calendar H2.

---

## 5. Wave-1 deliverables (this session / docs PR)

- [x] SSH inventory of 76 workflows + host scripts  
- [x] Catalog markdown  
- [x] Credential audit  
- [x] Redacted exports of Active + key inactive  
- [x] Layered PLAN/ROADMAP/ARCH/IMPL/VERIFY  
- [x] README index update under `docs/n8n/`  
- [x] Cleanup DAG handoffs minted (see ROADMAP)  

---

_Dr. Mārcis Gasūns_
