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

## 4. n8n workflow (H8562 v3: consistent-list dedup + full-fingerprint proof + paginated reads + scheduled sweep)

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
   снимать метку с живых issues нельзя, иначе дедуп слепнет). Узел несет
   **Always Output Data** (n8n node setting): пустой REST-массив все равно
   отдает один пустой item, поэтому на genuinely пустом борде ветка create
   не умирает — **первый issue нового отпечатка создается** (находка review №1:
   без флага n8n не дает выхода у HTTP-узла на пустом массиве, и первая
   доставка терялась молча). Чтение **пагинировано** (находка review №2):
   поддержанная n8n-пагинация Options → Pagination, режим
   **Update a Parameter in Each Request**, Query `page` = `{{ $pageCount + 1 }}`,
   **Pagination Complete When: Response Is Empty** (GitHub отдает bare
   top-level array — пустая страница означает конец), Limit 20 запросов,
   Interval 300 мс — те же страницы, на которые указывает `Link rel=next`,
   поэтому канонический issue за пределами первой сотни виден lookup'у и sweep'у.
3. **Match exact title** (Code) — строит `expected_title`
   `[soft-alert] {scope} {fingerprint[:8]}` в ОДНОМ месте и фильтрует лист:
   точное совпадение заголовка, `state=open`, не PR, **и ПОЛНЫЙ отпечаток из
   тела issue** (находка review №3: два различных sha256 могут делить первые
   восемь hex — заголовок остается 8-hex только для отображения и
   легаси-совместимости). Полный отпечаток доказывается так: структурный
   маркер `<!-- soft-alert-fingerprint:… -->`, который create пишет в тело
   каждого нового issue (невидим в отрендеренном markdown), а для pre-v3
   issues — легаси-строка `**Fingerprint:** \`…\`` как фолбэк. Тело без
   доказуемого отпечатка — «неоднозначный легаси» — никогда не матчится и
   никогда не закрывается. Create и reconcile читают `expected_title`
   отсюда — заголовок issue и фраза дедупа одна и та же строка по построению.
4. **IF existing open issue**:
   - **да** → **GitHub comment on existing** (новое срабатывание дописывается
     комментарием в минимальный открытый issue с ДОКАЗАННЫМ тем же полным
     отпечатком) → `200 {deduped: true}`;
   - **нет** → **GitHub create issue** (title/labels/assignees прежние,
     failed-units в теле сохранены, маркер полного отпечатка в теле).
5. **GitHub reconcile list** (после create, свежий пагинированный лист) →
   **Reconcile find rival**: дубль = открытый issue с ТОЧНЫМ заголовком, ТЕМ ЖЕ
   полным отпечатком и МЕНЬШИМ номером. Номера GitHub выдает последовательно
   в момент create, а лист консистентен, поэтому гонка двух доставок всегда
   сходится: создавший старший номер при своем reconcile видит младшего и
   закрывает ровно СВОЙ issue. Итерации и ретраи не нужны, поисковый индекс
   не участвует вовсе; коллизионный 8-hex сосед никогда не считается соперником.
6. **Respond** `202` (создан) / `202 converged` (закрыт как дубль).

**Durable sweep (раз в 6 ч):** плановый проход группирует открытые
ops-soft-alert issues по паре (точный заголовок + ДОКАЗАННЫЙ полный
отпечаток), выживает минимальный номер, каждый дубль закрывается с
комментарием-ссылкой на выжившего. Сходится из любого бэклога независимо от
консистентности индекса и от доставок, умерших посреди выполнения.
**Неоднозначные легаси (тело без доказуемого отпечатка) sweep не трогает
никогда** — они остаются открытыми для человеко-дедупа; PR с заголовком
soft-alert и посторонние issues тоже не тронуты (фильтр `pull_request` +
метка).

### Rollout contract v3 (owner steps — агент сам ничего из этого не делает)

| # | Шаг | Кто/проверка |
|---|---|---|
| 1 | **До импорта**: открыть raw-тело пары issues шторма (`[soft-alert] guards 6fd69840`, авг + 21–23.11) и убедиться, что в теле есть строка `**Fingerprint:**` с полным hex. Есть → шторм-дубли будут закрыты первым sweep. Нет → такие issues «неоднозначные», sweep их НЕ закроет (и не должен) — закрыть руками после сходимости. | человек, GitHub UI |
| 2 | **Импорт**: re-import шаблона поверх workflow `softAlertH2148` на `.91` (та же лейн, что enablement 02-08), publish. Секреты/токены в экспорте отсутствуют: остаются env `SOFT_ALERT_GITHUB_TOKEN` + `$env.SOFT_ALERT_WEBHOOK_SECRET` (оба на `N8N_ENV_ALLOWLIST`). | ops |
| 3 | **После импорта**: в трех list-узлах визуально подтвердить Settings → **Always Output Data** включен и Options → **Pagination**: Update a Parameter in Each Request, Query `page` = `{{ $pageCount + 1 }}`, Complete When = Response Is Empty, Limit 20, Interval 300; в create-узле тело содержит `<!-- soft-alert-fingerprint:… -->`. Если UI не показывает Pagination (старая сборка n8n) — обновить n8n ДО включения workflow: без этого пагинация тихо не работает, а канонический issue за пределами первой сотни снова невидим. | ops, n8n UI |
| 4 | **Первый sweep** (до 6 ч после publish): закрывает только доказуемые дубли — шторм-бэклог гаснет одним проходом (разовый всплеск close-уведомлений @pe4kinsmart-tech, ожидаем). Неоднозначные легаси остаются открытыми — это контракт, а не сбой. | наблюдение |
| 5 | **Smoke**: неверный секрет → 401 без обращений к GitHub; повторная доставка активного отпечатка → комментарий в выжившего `200 deduped`; новый отпечаток → свой issue `202`. | ops |
| 6 | **Инварианты любого ручного аудита**: нет `/search/issues`; дедуп/закрытие требуют равный ПОЛНЫЙ отпечаток; выживает минимальный номер; PR никогда не трогаются; неоднозначные не закрываются; маркер в теле новых issues не удалять. | любой |

Приемка из PR #4481 закрыта mocked-runner реплеем + контрактом + исполнением
НАСТОЯЩЕГО JS: `N8nSoftAlertWorkflowReplayTest` 10 (все 6 порядков
create/reconcile двух конкурентных доставок сходятся в один issue под
задержанной видимостью поиска при нуле обращений к индексу; пустой борд →
первый issue создается; канонический issue за пределами первой сотни виден
lookup'у и sweep'у; коллизионный 8-hex отпечаток никогда не склеивается и не
закрывается; неоднозначные легаси не матчатся и не закрываются; PR
игнорируется; sweep гасит бэклог 35/7/7 с выживанием минимальных номеров),
`N8nSoftAlertWorkflowContractTest` 9 (флаги транспорта, схема пагинации,
маркер + легаси-фолбэк, связи узлов, секреты на env),
`N8nSoftAlertWorkflowJsTest` 1 — исполняет реальный JavaScript Code-узлов
экспорта через node против фикстур всех трех находок (пропускается без node).
Расхождение search-дизайна воспроизведено и оставлено как документация.

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
