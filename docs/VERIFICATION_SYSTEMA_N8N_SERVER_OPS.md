# VERIFICATION — n8n server ops

_Created: 30-07-2026 · Last updated: 30-07-2026_

Index: [PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_SERVER_OPS_2026H2.md)

---

## Wave-1 acceptance (docs PR)

| # | Criterion | How to prove |
|---|---|---|
| A1 | All 76 workflows named | Count rows in CATALOG §4 + JSON inventory `len==76` |
| A2 | 5 Active deep-dived | CATALOG §3 has ZOOM, clip, payments, YT list, titles |
| A3 | Bridge table complete | Every `N8N_*` in `config/services.php` appears in CATALOG §2 |
| A4 | Zero secrets in git | `rg -n "bot\\d+:[A-Za-z0-9_-]{20,}" docs/n8n` empty; no password literals in exports |
| A5 | Credential findings table | `CREDENTIAL_AUDIT` has C01+ with severity/remediation/owner |
| A6 | Gap table | schedule-sheet, vk-calendar, monthly OFF, clip errors, social missing |
| A7 | PLAN layers linked | PLAN §4 URLs resolve in repo |
| A8 | Autonomy contract present | PLAN §3 |

### Commands (local repo)

```bash
# inventory length
python -c "import json; print(len(json.load(open('docs/n8n/_server_inventory_2026-07-30.json',encoding='utf-8'))))"
# secret scan
rg -n "bot[0-9]+:[A-Za-z0-9_-]{20,}|auto_order\\.py.*@" docs/n8n || true
```

---

## Later-wave acceptance (cleanup DAG)

| Wave | Acceptance |
|---|---|
| Secret rotation | C01/C02 closed: nodes no longer contain plaintext; human confirms login works |
| Pin image | compose uses non-`latest` tag; `docker compose pull` documented |
| Prune | `du -sh /opt/n8n/storage/storage` reduced; Active ZOOM still succeeds once |
| Archive tags | inactive ZOOM + My workflow* tagged; still present; none Active by mistake |
| Clip fix | ≥1 success execution for lecture-clip with callback rows in Laravel |
| Imports | workflow exists in live n8n with expected webhook path; Laravel env points to it **or** GTD defers |

---

## Risks & spikes register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Secrets already copied from n8n UI/backups | High | High | Rotate C01/C02; shred host `credentials.json` if obsolete |
| `:latest` breaks nodes overnight | Med | High | Pin version Wave 2 |
| 5.6G disk growth / fill | Med | Med | Prune binaryData; prefer `/data` + delete |
| Webhook auth=none (payments, titles, ZOOM secondary) | High | High | Header Auth; money-gated |
| Dual ZOOM webhook paths / stale copies | Med | High if dual Active | Never activate legacy; archive-tag |
| socks-nl tunnel down | Med | High for YT | Document systemctl status; alert |
| Clip extract all errors | High | Med (marketing blocked) | Debug before flags ON |
| Export scrub miss | Med | High (git leak) | A4 rg gate on every export PR |
| Concurrent agent edits shared main tree | Med | High | Worktree only |

### Spikes (unknown until probed)

| Spike | Question | When |
|---|---|---|
| S1 | Exact lecture-clip error message in last execution | H-bridge-fix-lecture-clip |
| S2 | Whether payments caller already sends a secret header | Before C03 change |
| S3 | n8n version after pin — breaking change notes | H-hygiene-pin-image |

---

## Autonomy-readiness gate (plan quality)

| Check | Status |
|---|---|
| Zero blocking forks in wave-1 path | Pass — D1–D26 locked |
| No rebuild-what-exists | Pass — reuse host tools, existing templates |
| Autonomy contract covers ambiguity/stop/fence | Pass — PLAN §3 |
| Wave-1 has arch + steps + acceptance + risks | Pass |

---

_Dr. Mārcis Gasūns_
