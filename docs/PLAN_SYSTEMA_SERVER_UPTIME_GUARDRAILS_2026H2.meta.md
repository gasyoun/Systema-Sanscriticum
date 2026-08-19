# Metadoc — PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md

_Created: 19-08-2026 · Last updated: 19-08-2026_

| Field | Value |
|---|---|
| **Purpose** | Cover index for the four-wave `/ask` plan taking **both** prod LXC guests (`193.232.229.92`, `193.232.229.91`) to a "no unattended outage >15 min" bar. Carries the 20 interview rulings and the autonomy contract |
| **Audience** | The execution agent for Waves 1–4 first; MG and Ivan second |
| **Provenance** | `/ask` interview 19-08-2026 (5 rounds, 20 rulings, MG) + a live read-only SSH audit of both hosts — Opus 5 (`claude-opus-5`) |
| **Not for** | Incident response in the moment (use [server-resource-guards.md §7](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) and [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)) · monitor inventory (use [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)) · the n8n workflow estate (use [docs/n8n/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/n8n)) |

## Ranked backlog

1. Wave 1 — the four live hazards on `.92`; H-1 (`/tmp` tmpfs) is the one actively consuming swap **on both boxes right now**.
2. Wave 2 — `.91` parity. Spike S3 (n8n steady-state memory) must precede setting `mem_limit`.
3. Wave 3 — watch layer, remediation ladder, incident ledger.
4. Wave 4 — firewall + `.91` SSH hardening. Human-present only; the one wave that legitimately waits.
5. Fold the measured `guard = none` count from the ledger back into this plan after one quarter — it is the only honest measure of whether the span worked.

## Limitations

- **The audit is a snapshot of 19-08-2026.** Re-probe before executing a wave; `.91`'s swap
  figure in particular will change the moment `/tmp/hindi_audio` is removed.
- **Both boxes share one Proxmox host.** No in-container work addresses a host-level event;
  D4 puts it out of scope and the plan says so rather than implying coverage.
- **Wave 1 is the only wave with a file-level implementation doc.** Waves 2–4 get theirs when
  picked up — authoring them now would guess at state Wave 1 changes.
- The audit found `.91`'s guard state by direct probe; **absence of a Better Stack monitor for
  `.91` is inferred** from the inventory doc and from the box having no cron/heartbeat, not
  from reading the Better Stack account.

## Related

- Subject: [PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md)
- [ROADMAP_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md)
- [ARCHITECTURE_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md)
- [IMPLEMENTATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md)
- [VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md)
- Prior art it consumes: [server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md) · [scripts/server_guards/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards) · [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md)

## Revision history

| Date | Change | Who |
|---|---|---|
| 19-08-2026 | Initial five-layer plan + audit of both hosts + 20 rulings | Opus 5 (`claude-opus-5`) |

_Dr. Mārcis Gasūns_
