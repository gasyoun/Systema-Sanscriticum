# Soft-alert webhook → issue → agent (skeleton)

_Created: 02-08-2026 · Last updated: 23-11-2026_

**Status:** **ENABLED** on prod 02-08-2026 (H2148 enablement).  
n8n workflow `softAlertH2148` active on `.91`; prod `SOFT_ALERT_WEBHOOK_URL` set.  
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

## 4. n8n workflow (H8562 v2: consistent-list dedup + scheduled sweep)

Import template:  
[`docs/n8n/soft-alert-to-github-issue.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/soft-alert-to-github-issue.workflow.json)

Приемник сходится в один issue на активный отпечаток, не теряя различения
новых классов. **Никаких обращений к `/search/issues`** — независимый review
(23-11-2026) дал FAIL по search-дизайну: две конкурентные доставки обе
«не находили» открытый issue, обе создавали, а немедленный search-reconcile
из-за лага индекса видел только свой свежий issue — никто не закрывался,
дубли оставались. Источник истины теперь строго консистентный REST-лист
(`GET /repos/…/issues`), а не лагающий поисковый индекс:

1. **Webhook** path `/systema-soft-alert` (or Caddy path) — check secret header.
2. **GitHub list open issues** — `state=open&labels=ops-soft-alert&sort=created&direction=desc&per_page=100`.
   Метка `ops-soft-alert` — часть контракта issue (create всегда ставит ее;
   снимать метку с живых issues нельзя, иначе дедуп слепнет). Окно скана —
   100 свежих ОТКРЫТЫХ ops-soft-alert issues (потолок GitHub); бэклог шторма
   21–23.11 (49) влезает с запасом, при превышении добавить пагинацию до
   поставки.
3. **Match exact title** (Code) — строит `expected_title`
   `[soft-alert] {scope} {fingerprint[:8]}` в ОДНОМ месте и фильтрует лист:
   точное совпадение заголовка, `state=open`, не PR. Create и reconcile
   читают `expected_title` отсюда — заголовок issue и фраза дедупа одна и
   та же строка по построению (у search-дизайна были два независимых
   выражения, которые могли разъехаться).
4. **IF existing open issue**:
   - **да** → **GitHub comment on existing** (новое срабатывание дописывается
     комментарием в минимальный открытый issue) → `200 {deduped: true}`;
   - **нет** → **GitHub create issue** (title/labels/assignees прежние,
     failed-units в теле сохранены).
5. **GitHub reconcile list** (после create, свежий лист) → **Reconcile find
   rival**: дубль = открытый issue с ТОЧНЫМ заголовком и МЕНЬШИМ номером.
   Номера GitHub выдает последовательно в момент create, а лист консистентен,
   поэтому гонка двух доставок всегда сходится: создавший старший номер при
   своем reconcile видит младший и закрывает ровно СВОЙ issue. Итерации и
   ретраи не нужны, поисковый индекс не участвует вовсе.
6. **Respond** `202` (создан) / `202 converged` (закрыт как дубль).

**Durable sweep (раз в 6 ч):** плановый проход группирует открытые
ops-soft-alert issues по точному заголовку, выживает минимальный номер,
каждый дубль закрывается с комментарием-ссылкой на выжившего. Сходится из
любого бэклога независимо от консистентности индекса и от доставок, умерших
посреди выполнения. **Первый проход после импорта закроет бэклог шторма
21–23.11 (46 дублей по трем заголовкам)** — разовый всплеск close-уведомлений
@pe4kinsmart-tech, это ожидаемо. PR с заголовком soft-alert и посторонние
issues sweep не трогает (фильтр `pull_request` + метка).

Приемка конкурентности из PR #4481 («Verification outstanding») выполнена
мок-реплеем `N8nSoftAlertWorkflowReplayTest` (12 тестов, 298 утверждений):
все 6 порядков create/reconcile двух доставок сходятся в один issue под
задержанной видимостью поиска (ноль обращений к индексу), отдельный новый
отпечаток открывает свой issue, failed-units попадают в тело issue и
комментарии, неверный секрет → 401 без обращений к GitHub, sweep гасит
бэклог формы 35/7/7. Расхождение search-дизайна воспроизведено и оставлено
как документация (`test_delayed_search_index_reproduces_the_reviewed_fail…`).

Do **not** put bot tokens in exportable node JSON (use n8n credentials / env).

**Live wiring (enablement):** workflow id `softAlertH2148` on `.91` must be
**re-imported** from this template (owner ops step, same lane as the 02-08
enablement; the sender-side grace ships via `deploy.sh`). Env token
`SOFT_ALERT_GITHUB_TOKEN` (Authorization header from n8n env) + secret check
against `$env.SOFT_ALERT_WEBHOOK_SECRET` (both on `N8N_ENV_ALLOWLIST`) stay
unchanged. Prefer rotating the GitHub token to a dedicated fine-grained PAT
(Issues: write).

**H8562 incident context (21–23.11.2026):** 35+ open issues shared one title
`[soft-alert] guards 6fd69840` (`guards/failed-units: telegram-daily.service`).
Root cause chain, verified read-only on `.92`: the guard samples
`systemctl --failed`; the failing unit flaps with the */10 timer beat against
the */15 probe — at :00/:30 ticks the unit is mid-run (`activating`, invisible
to `systemctl --failed`) → false green → `ProbeCabinetHealth` forgot
`LAST_SOFT_FP/AT` → the next failing tick (:15/:45) re-fired the same
fingerprint as a «new» class → the pre-H8562 receiver created an issue
unconditionally. Sender-side flap grace (N consecutive green probes close an
incident, `CABINET_PROBE_SOFT_GREEN_GRACE_TICKS`, default 2) + single-flight
lock land in the app; this receiver-side dedup makes the webhook idempotent
per fingerprint. The unit itself fails on `reserve_exhausted` (empty content
queue) — that trigger is an owner action (refill the queue / normalize the
service), tracked separately from the code fix. `SoftFailureFingerprint`
уже нормализует волатильный класс из issue #1144 (метки времени/SHA, H2335/H3227)
— расширения охвата отпечатка не потребовалось.

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

## 6. Enablement checklist

| # | Step | Status (02-08-2026) |
|---|---|---|
| 1 | Import n8n workflow on `.91`; wire GitHub | **done** — id `softAlertH2148` published/active; GitHub via env `SOFT_ALERT_GITHUB_TOKEN` (Bearer), not UI `githubApi` credential |
| 2 | Caddy route + secret | **done** — host already reverse_proxy `context-ai.ru` → n8n; secret in n8n env + `/root/.n8n-soft-alert-webhook-secret` |
| 3 | Prod `.env` `SOFT_ALERT_WEBHOOK_URL` + `SECRET` | **done** on `.92` |
| 4 | `php artisan config:clear` | **done** |
| 5 | Force soft alert / smoke once | **done** — 401 unauth · 202 auth · Laravel notifier 202 · smoke issues #1068/#1069 closed |
| 6 | Confirm issue opened; agent stub dry-run | **done** — `soft_alert_agent_stub.py --ssh root@193.232.229.92` → dry-run JSON ok |

**Residual (Ivan):** rotate `SOFT_ALERT_GITHUB_TOKEN` to a dedicated fine-grained PAT (Issues: write on Systema only). Tracking: ops issue on enablement residual (assignee `@pe4kinsmart-tech`).

TG soft path remains the parallel signal when `TELEGRAM_BOT_TOKEN` is set.

_Dr. Mārcis Gasūns_
