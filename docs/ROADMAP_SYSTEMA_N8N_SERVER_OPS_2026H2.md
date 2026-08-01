# ROADMAP — n8n server ops 2026 H2

_Created: 30-07-2026 · Last updated: 01-08-2026_

Index: [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md)

---

## Non-goals

- Rewriting ZOOM into a new 2.0 architecture in H2 without a separate product interview  
- Deleting workflows before archive-tag + git export  
- Live public posts / enabling content autopilot flags  
- Payment provider or bank integration changes  
- Moving media pipeline into Laravel (contradicts lecture-engine D8)

---

## Waves

### Wave 0 — Docs baseline (DONE this plan)

| Deliverable | Unblocks |
|---|---|
| Full catalog + inventory JSON | All later ops |
| Credential audit (no values) | Rotation handoffs |
| Redacted exports Active+key | Diff / restore |
| Layered plan | Unattended execution |

### Wave 1 — Human-gated secret rotation (HIGH)

| Item | Notes |
|---|---|
| Rotate libfl password; `/root/.libfl-env` | Finding C01 |
| Rotate Telegram bot token inlined in monthly workflow | C02 |
| Review `/opt/n8n/credentials.json` + sqlite backups | C15 |
| Optional: payments + titles Header Auth | C03/C06 — **money-adj / high traffic** |

**Stop:** agent does not rotate without human confirmation of new secrets.

### Wave 2 — Instance hygiene (agent-safe)

| Item | Notes |
|---|---|
| ✅ Pin `n8nio/n8n` image version/digest (H1961, 01-08-2026 → `2.27.5@sha256:d53243d06c7f…`) | C07 closed |
| Prune binary storage (5.6G) offline | Keep Active; clear stale workflow binary dirs |
| Archive-tag inactive: `My workflow *`, old ZOOM lineage | D4 — no delete |
| Rename unnamed credentials | C11 |
| Document socks-nl healthcheck one-liner | D11 |

### Wave 3 — Systema bridge parity

| Item | Notes |
|---|---|
| Debug lecture-clip 6-error streak | Before any marketing flag |
| Import `schedule-sheet-sync` if product still needs Sheets | **H1965 deferred to GTD** (01-08-2026) — not product-priority; re-arm when Sheets export wanted |
| Import `vk-calendar-post` only when calendar autopilot staged | **H1965 deferred to GTD** — flag OFF; import at DEPLOY_QUEUE №60 staging |
| Activate monthly post after token fix + smoke | **H1965 defer-activate** — live `eixPIvFjfPdOSrYo` OFF; blocked on H1959 |
| Social-post workflow when Wave-2 content pilot armed | Product plan |

### Wave 4 — Bookbuilder product (after security)

| Item | Notes |
|---|---|
| Reliability: retries, OCR fail modes, sheet status | Separate product handoffs |
| Queue / rate limits vs libfl | Avoid ban |
| Rights note for redistributed PDFs | Human |

### Wave 5 — Webinar / social bot consolidation (optional)

| Item | Notes |
|---|---|
| Pick one Registration + one Warming canonical | Archive rest |
| VK warming skeletons → single | |

---

## Cleanup DAG (handoffs — mint order)

Parallel-safe groups; arrows = dependency.

```
W0 docs (this PR)
    │
    ├─► H1958 libfl rotate           [human]  ──┐
    ├─► H1959 TG token rotate        [human]  ──┼─► monthly activate (after H1959)
    ├─► H1960 payments Header Auth   [human]    │
    │                                           │
    ├─► H1961 pin n8n image                     │
    ├─► H1962 prune storage 5.6G                │
    ├─► H1963 archive-tag legacy                │
    │                                           │
    ├─► H1964 fix lecture-clip errors ──────────┤
    └─► H1965 import schedule/calendar gaps ────┘
```

| ID | Slug | Gate |
|---|---|---|
| [H1958](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1958-Grok_Systema-Sanscriticum_n8n-sec-libfl-rotate_30.07.26.md) | n8n-sec-libfl-rotate | human secrets |
| [H1959](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1959-Grok_Systema-Sanscriticum_n8n-sec-tg-token-rotate_30.07.26.md) | n8n-sec-tg-token-rotate | human secrets |
| [H1960](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1960-Grok_Systema-Sanscriticum_n8n-sec-payments-auth_30.07.26.md) | n8n-sec-payments-auth | human money-adj |
| [H1961](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1961-Grok_Systema-Sanscriticum_n8n-hygiene-pin-image_30.07.26.md) | n8n-hygiene-pin-image | ops |
| [H1962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1962-Grok_Systema-Sanscriticum_n8n-hygiene-prune-storage_30.07.26.md) | n8n-hygiene-prune-storage | ops |
| [H1963](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1963-Grok_Systema-Sanscriticum_n8n-hygiene-archive-tags_30.07.26.md) | n8n-hygiene-archive-tags | ops |
| [H1964](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1964-Grok_Systema-Sanscriticum_n8n-bridge-fix-lecture-clip_30.07.26.md) | n8n-bridge-fix-lecture-clip | ops |
| [H1965](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1965-Grok_Systema-Sanscriticum_n8n-bridge-import-gaps_30.07.26.md) | n8n-bridge-import-gaps | product-gated |

---

## Success metrics

| Metric | Target |
|---|---|
| Catalog freshness | Inventory ≤ 30 days or after major workflow change |
| 🔴 findings open | 0 after Wave 1 human rotations |
| Active workflow error rate | Clip extract ≥1 success dry-run; ZOOM error share trending down |
| Storage | binaryData under 2G or documented exception |
| Bridge gaps | schedule/calendar/monthly/social each: imported XOR explicitly deferred in GTD |

---

_Dr. Mārcis Gasūns_
