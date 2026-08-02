# Soft-alert webhook → issue → agent (skeleton)

_Created: 02-08-2026 · Last updated: 02-08-2026_

**Status:** skeleton shipped (H2148 C). Default **OFF** on prod until n8n/issue path is wired.  
**Playbook:** [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)

---

## 1. Flow

```
cabinet:probe soft-only (cooldown ok)
    → SoftAlertWebhookNotifier POST JSON
    → n8n (193.232.229.91) OR GitHub Actions webhook
    → open/update Issue label ops-soft-alert @pe4kinsmart-tech
    → (optional) Windows agent runner: dry-run ops:soft-remediate over SSH
    → human only for diverging dirty / hard fuse / money
```

**Never:** root daemon on prod that auto-clears hard fuses or runs LLM with full shell without allowlist.

---

## 2. Laravel env (prod, after n8n ready)

```env
# empty = off (default)
SOFT_ALERT_WEBHOOK_URL=https://context-ai.ru/webhook/systema-soft-alert
SOFT_ALERT_WEBHOOK_SECRET=<random shared secret>
SOFT_ALERT_WEBHOOK_TIMEOUT=8
```

Config keys: `config/cabinet_probe.php` → `soft_webhook_*`.

Code: [`SoftAlertWebhookNotifier`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/ServerGuards/SoftAlertWebhookNotifier.php)  
Called from soft path of [`ProbeCabinetHealth`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php) after the same cooldown/fingerprint gate as soft TG.

---

## 3. Payload schema (v1)

```json
{
  "event": "cabinet.soft_alert",
  "schema_version": 1,
  "scope": "guards",
  "fingerprint": "sha256-hex",
  "app_url": "https://samskrte.ru",
  "host_hint": "193.232.229.92",
  "playbook": "docs/SERVER_SOFT_ALERT_PLAYBOOK.md",
  "suggested": {
    "diagnose": "php artisan ops:soft-remediate --dry-run --json",
    "safe_apply": "php artisan ops:soft-remediate --apply --apply-breaker-clear",
    "verify": "php artisan guards:verify && php artisan cabinet:probe"
  },
  "failures": [
    { "message": "guards/auto-deploy: …", "severity": "soft" }
  ],
  "occurred_at": "2026-08-02T12:00:00+00:00"
}
```

Header when secret set: `X-Soft-Alert-Secret: <secret>`.

---

## 4. n8n workflow skeleton

Import template:  
[`docs/n8n/soft-alert-to-github-issue.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/soft-alert-to-github-issue.workflow.json)

Nodes (minimal):

1. **Webhook** path `/systema-soft-alert` (or Caddy path) — check secret header.  
2. **IF** fingerprint already open issue (optional cache).  
3. **GitHub** create issue:
   - title: `[soft-alert] {scope} {fingerprint[:8]}`
   - body: failures + playbook link + suggested commands
   - labels: `ops-soft-alert`
   - assignees: `pe4kinsmart-tech`
4. **Respond** 202.

Do **not** put bot tokens in exportable node JSON (use n8n credentials / env).

---

## 5. Agent runner skeleton (dev machine, not prod)

Script: [`scripts/ops/soft_alert_agent_stub.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/ops/soft_alert_agent_stub.py)

```text
# Manual / Task Scheduler when issue label ops-soft-alert is open:
python scripts/ops/soft_alert_agent_stub.py --ssh root@193.232.229.92 --dry-run
# Prints SSH diagnose; with --apply-safe runs ONLY:
#   php artisan ops:soft-remediate --apply --apply-breaker-clear
# Never: force-push, migrate, money artisan, blind rm of hard fuse
```

Grok/Claude session: read the issue body + playbook + run stub; post comment with JSON dry-run.

---

## 6. Enablement checklist (human)

1. Import n8n workflow on `.91`; set GitHub credential.  
2. Caddy route + secret.  
3. Set prod `.env` `SOFT_ALERT_WEBHOOK_URL` + `SECRET`.  
4. `php artisan config:clear` after deploy.  
5. Force soft alert once: dirty equal-origin staging OR `--force-alert` with synthetic guards (careful).  
6. Confirm issue opened; agent stub dry-run once.

Until then: TG soft path alone remains the signal (current behaviour).

_Dr. Mārcis Gasūns_
