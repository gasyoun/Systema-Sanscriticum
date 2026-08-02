# Metadoc — SERVER_SOFT_ALERT_PLAYBOOK.md

_Created: 02-08-2026 · Last updated: 02-08-2026_

| Field | Value |
|---|---|
| **Purpose** | Single agent entry for soft TG / auto-deploy fuse / prod dirty-tree: catalog of causes, safe vs never-auto, ladders, incident log |
| **Audience** | Agents (Grok/Claude/Codex), Ivan ops |
| **Provenance** | H2148 Grok 4.5 (`grok-4.5`); consolidates H2066/H2104/H2147 + §8.1 worked case |
| **Not for** | Critical outage playbook (Better Stack + resource-guards §7); money contour |

## Ranked backlog

1. Wire incident-log append from `ops:soft-remediate` JSON (optional).  
2. Add more historical rows once recovered from `auto_deploy.log`.  
3. Keep catalog tags in sync with `ServerGuardsAuditor` + `systema-auto-deploy-run.sh`.

## Limitations

- Catalog is operational, not a substitute for reading breaker file on the live host.  
- Safe auto rules intentionally narrow (origin-equal dirty only).

## Related

- Subject: [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)  
- [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)  
- [docs/ops/SOFT_ALERT_WEBHOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ops/SOFT_ALERT_WEBHOOK.md)

## Revision history

| Date | Change | Who |
|---|---|---|
| 02-08-2026 | Initial playbook + catalog + incident log skeleton | Grok 4.5 (`grok-4.5`) H2148 |

_Dr. Mārcis Gasūns_
