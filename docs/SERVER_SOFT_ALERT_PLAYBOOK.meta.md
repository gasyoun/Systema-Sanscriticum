# Metadoc — SERVER_SOFT_ALERT_PLAYBOOK.md

_Created: 02-08-2026 · Last updated: 21-08-2026 (H3227 cgroup MiB fingerprint)_

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
4. ~~Dry-run fixtures + operator smoke one-liner~~ — done H2187 (`tests/fixtures/soft_remediate/`, playbook §4.0).

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
| 23-08-2026 | Incident log: yandex WebDAV uplink measured 130 KB/s, PUT dies ~31 MiB; off-site restored via .92→MG→.91 relay (sha256 ×3); H3371+H3372 minted | ox-alpha (`opencode/x-preview-f-free`) SOS |
| 21-08-2026 | Fingerprint: `guards/<name>:` ignores live RSS/MiB (H3227 host/ops 15-min spam) | Grok 4.6 (`grok-4.6`) H3227 |
| 20-08-2026 | Catalog: host-only guards are not SOS; sticky TG state file (H3197) | Grok 4.6 (`grok-4.6`) H3197 |
| 20-08-2026 | Incident log: Filament `/admin` 500 from 8 root-owned compiled views after `deploy.sh` `fail` skipped post-probe chown; H3194 | Grok 4.6 (`grok-4.6`) H3194 |
| 15-08-2026 | Incident log: `[rolled-back]` ×2 from Horizon master 64 MB / exit 12; #1729 already on main | Grok 4.6 (`grok-4.6`) H2803 |
| 02-08-2026 | Dry-run fixtures + SoftRemediateDryRunFixturesTest + §4.0 smoke one-liner | Grok 4.5 (`grok-4.5`) H2187 |
| 02-08-2026 | Initial playbook + catalog + incident log skeleton | Grok 4.5 (`grok-4.5`) H2148 |

_Dr. Mārcis Gasūns_
