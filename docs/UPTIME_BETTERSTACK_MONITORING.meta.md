# Metadoc — UPTIME_BETTERSTACK_MONITORING.md (+ RU human twin)

_Created: 30-07-2026 · Last updated: 30-07-2026_

| | |
|---|---|
| **Purpose** | Agent-facing inventory of Better Stack HTTP + heartbeat monitors, prod wiring, and §5 runbook when something is red. Russian short twin for humans. |
| **Audience** | EN: agents. RU: humans when site is down or red alert (email / [@rusamskrtam](https://t.me/rusamskrtam)); no secrets. |
| **Provenance** | Session 30-07-2026 (soft-fail H1941 → Better Stack). Model: Grok 4.5 (`grok-4.5`). Issue [#891](https://github.com/gasyoun/Systema-Sanscriticum/issues/891). FAQ §5 + RU twin same day. |
| **Not for** | Live outage board (→ Uprava SERVER_OUTAGES); OS memory guards detail (→ server-resource-guards.md). |
| **Inbound links** | Systema `CLAUDE.md` § Ops/uptime · `README.md` · DEPLOY_QUEUE H1794 · server-resource-guards · SERVER_OUTAGES top |

## Ranked improvement backlog

1. Add monitor id table rows when new HTTP checks are created (keep UI ids).  
2. Optional: commit non-secret probe scripts under `scripts/` (today only on VPS).  
3. Optional: org-level CLAUDE.md spine row if agents still miss the doc outside Systema cwd.  
4. After any token rotation: confirm smoke; never backfill tokens into git history.

## Limitations

- Tokens and full heartbeat URLs are intentionally absent.  
- samskrtam monitor UI names may drift; URLs are the stable key.  
- Cologne probe proves reachability **from samskrte VPS**, not global PoP diversity (HTTP monitor 4751491 covers Better Stack’s view).

## Related

- [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) (EN, agents)  
- [UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) (RU, humans)  
- [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)  
- [Uprava SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md)

_Dr. Mārcis Gasūns_
