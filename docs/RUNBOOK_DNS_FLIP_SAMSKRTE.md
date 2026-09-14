# RUNBOOK — samskrte.ru DNS A-record flip (reg.ru)

_Created: 25-08-2026 · Last updated: 25-08-2026_

Companion to [H3391](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3391-Sonnet_Systema-Sanscriticum_regru-dns-ttl-prep_23.08.26.md)
and the incident record
[docs/INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md).
Zone `samskrte.ru` is authoritative at `ns1.reg.ru` / `ns2.reg.ru`.

## Current state (verified 25-08-2026)

Verified directly against public resolvers (never trust the local machine's
resolver — see Pitfall below):

```
dig @8.8.8.8 +noall +answer A samskrte.ru
# samskrte.ru.  21600  IN  A  193.232.229.92

dig @1.1.1.1 +noall +answer A samskrte.ru
# samskrte.ru.  86400  IN  A  193.232.229.92

dig @8.8.8.8 +noall +answer NS samskrte.ru
# samskrte.ru.  IN  NS  ns1.reg.ru.
# samskrte.ru.  IN  NS  ns2.reg.ru.
```

- **A-record**: `193.232.229.92` — this is the correct, live, primary host.
  No flip is currently in effect or needed.
- **TTL**: still the pre-incident default (21600–86400s depending on
  resolver cache), **not yet lowered to 300**. Lowering it is the actual
  open item in H3391 — see [Target 0](#target-0-lower-ttl-to-300-prerequisite-for-fast-future-flips) below. A low TTL is what makes a future
  emergency flip (Target 2) propagate in minutes instead of hours.

## Pitfall: don't trust your local resolver

During verification, this machine's default resolver returned
`240.0.0.112` (a reserved Class-E address — never a legitimate hosting IP)
with TTL 1 for `samskrte.ru` — almost certainly a local cache/VPN
interception artifact, not real authoritative state. Always verify against
a public resolver directly:

```
dig @8.8.8.8 +noall +answer A samskrte.ru
dig @1.1.1.1 +noall +answer A samskrte.ru
```

On Windows, the handoff's equivalent is:

```
Resolve-DnsName -Server 8.8.8.8 -Type A samskrte.ru
Resolve-DnsName -Server 1.1.1.1 -Type A samskrte.ru
```

Query from two independent vantages (different public resolvers) before
trusting a read as ground truth — a single resolver can be stale or
cached.

## Target 0 — lower TTL to 300 (prerequisite for fast future flips)

**Via reg.ru API** (preferred; requires `~/.secrets/regru.env` filled per
the ask-via-prepared-env house rule —
file-first, never chat, never echoed):

```
POST https://api.reg.ru/api/regru2/zone/update_records
  domains[0].dname=samskrte.ru
  action=update
  record_type=A
  ttl=300
  ...auth via REGRU_LOGIN/REGRU_PASSWORD or REGRU_API_TOKEN
```

Confirm the exact endpoint/params against the current reg.ru API docs at
call time — the API surface changes between tariffs. Read back the result
and capture a **redacted** transcript (strip login/token/password) as
evidence.

**Manual path (fallback if the tariff has no DNS API):**

1. Log in to the reg.ru client cabinet (`https://www.reg.ru/`).
2. Domains → `samskrte.ru` → DNS-записи (DNS records).
3. Edit the `A` record → set TTL to `300` (seconds) → save.
4. Wait for the old TTL to expire, then re-verify with `dig @8.8.8.8` /
   `dig @1.1.1.1` from a fresh (uncached) vantage.

Do not touch any other record or zone — this changes the `samskrte.ru` A
TTL only.

## Target 1 — restore to .92 (verification-only; already live)

`193.232.229.92` is the current live target (see [Current state](#current-state-verified-25-08-2026)
above) — this is the steady-state, "nothing to do" recipe, kept here so a
future session doesn't need to rediscover it during an incident.

1. Verify the incident is actually over on the host side first — see
   [docs/INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_SAMSKRTE92_HOST_DOWN_23-08-2026.md)
   and confirm the box answers (`ssh`, `curl` the app port) before touching
   DNS.
2. If the A-record was flipped away from `.92` during an incident, restore
   it via the reg.ru API `zone/update_records` call above (or the manual
   cabinet path), setting the A-record value back to `193.232.229.92`.
3. Re-verify from two public resolvers:
   ```
   dig @8.8.8.8 +noall +answer A samskrte.ru
   dig @1.1.1.1 +noall +answer A samskrte.ru
   ```
4. Confirm the app is reachable at the domain, not just the raw IP.

## Target 2 — temp emergency host (generic scripted cutover)

There is **no currently-live standing emergency host**. The specific Aeza
VPS stood up during the 23-08-2026 incident (`SWEs-1`, `178.236.251.98`,
service `624477` on `my.aeza.ru`) was **decommissioned by MG** — not
renewed (expired 30-08-2026) and repurposed personally as a VPN, unrelated
to this migration procedure. Do not treat that IP as a live fallback
target; it is historical precedent only, documented in
[ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md)'s
"Кейс 23-08-2026" section.

The **generic, still-valid** procedure for standing up a new temp host and
flipping DNS to it lives in
[ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md).
Summary (do not re-derive — follow that file directly at execution time,
it is scripted and idempotent):

1. Phase 0 — human-input table (target VPS credentials, TTL confirmation,
   etc.) — filled by a human before scripts run.
2. Numbered scripts `00-preflight.sh` … `06-verify-cutover.sh` provision
   and validate the new host **before** any DNS change.
3. `*** GATE ***` — the actual A-record cutover only happens after a human
   says the word **«переключай»** in chat. No script or agent flips
   production DNS on its own judgment.
4. After the flip: verify via `dig @8.8.8.8` / `dig @1.1.1.1` from two
   vantages, then confirm the app itself (not just DNS) is serving from
   the new host.

**Why TTL=300 matters here:** with the pre-incident TTL (21600–86400s), a
flip to a temp host would take up to 24h to fully propagate to cached
resolvers. At TTL=300, propagation completes in minutes — this is the
whole reason Target 0 exists.

## Evidence checklist (for closing H3391)

- [ ] Redacted API transcript (or manual-cabinet screenshot-level steps)
      proving TTL=300 read-back via `dig @8.8.8.8` / `dig @1.1.1.1`.
- [x] This runbook, covering both flip targets + two-vantage verification.

_Dr. Mārcis Gasūns_
