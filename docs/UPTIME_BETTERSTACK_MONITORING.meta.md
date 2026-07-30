# Metadoc — UPTIME_BETTERSTACK_MONITORING.md

_Created: 30-07-2026 · Last updated: 30-07-2026 (samskrtam heartbeat script on VPS)_

| | |
|---|---|
| **Purpose** | Single agent-facing inventory of Better Stack HTTP + heartbeat monitors and prod wiring (samskrte / samskrtam / Cologne). |
| **Audience** | Agents operating Systema VPS or answering «how is X monitored?»; humans wiring env/UI. |
| **Provenance** | Session 30-07-2026 (soft-fail H1941 → Better Stack migration after healthchecks outage). Model: Grok 4.5 (`grok-4.5`). Issue [#891](https://github.com/gasyoun/Systema-Sanscriticum/issues/891). |
| **Not for** | Live outage board (→ Uprava SERVER_OUTAGES); OS memory guards detail (→ server-resource-guards.md). |

## Ranked improvement backlog

1. Add monitor id table rows when new HTTP checks are created (keep UI ids).  
2. Optional: commit a non-secret render of cologne script under `scripts/` if we want git-managed copy (today lives only on VPS).  
3. One-line pointer from org CLAUDE.md spine table if agents still miss the doc.  
4. After any token rotation: confirm smoke; never backfill tokens into git history.

## Limitations

- Tokens and full heartbeat URLs are intentionally absent.  
- samskrtam monitor UI names may drift; URLs are the stable key.  
- Cologne probe proves reachability **from samskrte VPS**, not global PoP diversity (HTTP monitor 4751491 covers Better Stack’s view).

## Related

- [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)  
- [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)  
- [Uprava SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md)

_Dr. Mārcis Gasūns_
