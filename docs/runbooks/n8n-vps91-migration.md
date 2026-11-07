# RUNBOOK — переезд n8n-стека с vps91 (context-ai.ru) на новый хост

_Created: 07-11-2026 · Last updated: 07-11-2026_

**Аудитория:** ops/агенты. **Когда:** диск vps91 кончился (`86–89%`), провайдер снял бокс, нужен
новый хост под n8n. Это сценарий «машины с нуля», а не обновление образа.
**Источники:** живой осмотр vps91 и duck 07-11-2026 (ниже — снятые факты), [ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS.md), [RUNBOOK_BOTS_WEBHOOK_RELAY.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md) (семейство TG-релеев), H6451 (релей duck), H4283 (редактор — tailnet-only), H7690 (этот пакет).

---

## 0. Что переезжает (снято с живого vps91 07-11-2026)

| Узел | Факт |
|---|---|
| vps91 / `root@193.232.229.91` | hostname `samskrtam50`, Debian 13 (trixie), docker 29.5.3, compose v5.1.4, `/` = 49G/42G занято (**5.4 ГБ свободно**) |
| Контейнеры | `n8n-n8n-1` (`n8nio/n8n:2.38.6@sha256:7406a977…`), `tg-fanout` (тот же образ), `n8n-caddy-1` (`caddy:2.11.4@sha256:af5fdcd…`) |
| `/opt/n8n` | `.env` (39 строк, **`N8N_ENCRYPTION_KEY` обязателен**), `docker-compose.yml` + `override`, `caddy/Caddyfile`, `patches/task-runner-process-js.js`, `entrypoint-with-env-patch.sh`, `tg-webhook-reregister.sh`, `tg-webhook-guard-check.sh`, `tg91.crt` (публичный сертификат релея) |
| `/opt/n8n/storage` | 3.4 ГБ: `database.sqlite` 260 МБ + `-wal` 18 МБ, `storage/` (binaryData) 3.1 ГБ |
| Приватный ключ релея | `tg91.key` живёт **только на duck** (`/etc/nginx/ssl/tg91.key`) |

Стек: Caddy :443 → `n8n:5678` (публикуется только на loopback) + `tg-fanout:8080` для TG-фан-аута; наружу
n8n ходит через **privoxy :8118 → SOCKS5 127.0.0.1:1080** (см. §7).

## 1. Новый хост: база

```bash
# Debian 13+ (trixie/bookworm), root
apt-get update && apt-get install -y ca-certificates curl gnupg rsync sqlite3 cron nginx privoxy
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker cron
docker --version && docker compose version     # эталон .91: 29.5.3 / v5.1.4
```

**Проверить место ДО развёртывания:** `df -h /` — нужно ≥ 20 ГБ (3.4 ГБ состояния + образы ~1.5 ГБ +
запас). Теснота диска и есть причина переезда, повторять её нельзя.

## 2. Восстановление из tar в `/opt/n8n`

Свежий архив лежит на vps91 и на off-боксе:

```bash
# на vps91 (или скачать с duck, если .91 мёртв)
ls -lt /opt/n8n/backups/n8n-migration-*.tar.gz | head -1
# duck: ssh root@147.189.174.159 'ls -lt /root/n8n-backups/ | head -3'
```

```bash
# на НОВОМ хосте
mkdir -p /opt/n8n && cd /opt/n8n
tar -xzf /path/n8n-migration-YYYYMMDD.tar.gz        # ./, .env, db/, caddy/, patches/, скрипты, tg91.crt
cat MANIFEST.txt                                    # created_utc, образы, sha256 снапшота БД
install -m 600 .env .env                            # .env обязан остаться 0600
sqlite3 db/database.sqlite 'PRAGMA quick_check;'    # = ok
install -d -o 1000 -g 1000 storage                  # n8n в контейнере = uid 1000 (node)
cp -p db/database.sqlite storage/database.sqlite
# бинарные данные неприходят в ночном архиве (он < 500 МБ).
# Их источник — недельный full-архив (./binaryData/) ЛИБО старый хост:
#   rsync -a root@<старый>:/opt/n8n/storage/storage/ /opt/n8n/storage/storage/
```

> **Почему `.env` — критичен.** `N8N_ENCRYPTION_KEY` расшифровывает таблицу `credentials_entity`.
> Без ключа workflows поднимутся, но каждый креденшел вернёт `Could not decrypt`; перевыпуск всех
> ключей вручную — часы работы. Проверка: `docker exec n8n-n8n-1 n8n list:workflow` + открыть креденшел в UI.

## 3. Образы: Docker Hub из RU напрямую недоступен

Два пути, оба проверяемы. Первый предпочтителен (digest-пин сохраняется буквально).

```bash
# A. save/load со СТАРОГО хоста (digest гарантированно тот же)
#    на старом:
docker save n8nio/n8n:2.38.6 caddy:2.11.4 | gzip -1 > /tmp/n8n-images.tgz
scp /tmp/n8n-images.tgz root@<новый>:/tmp/
#    на новом:
gunzip -c /tmp/n8n-images.tgz | docker load && docker images | grep -E 'n8nio/n8n|caddy'

# B. pull через прокси (если старый хост уже мёртв)
export HTTPS_PROXY=http://<прокси-хост>:8118 HTTP_PROXY=$HTTPS_PROXY
docker pull n8nio/n8n:2.38.6@sha256:7406a977895d0158ab0f5b2a3dc66de636711f36c06f0e2e2b1c391fc91f8ec3
docker pull caddy:2.11.4@sha256:af5fdcd76f2db5e4e974ee92f96ee8c0fc3edb55bd4ba5032547cbf3f65e486d
```

**Гоча:** в базовом `docker-compose.yml` caddy = `caddy:latest`; пин на 2.11.4 живёт ТОЛЬКО в
`docker-compose.override.yml`. Потерять override = вернуть `latest` и потерять mem_limit/healthcheck.
После восстановления: `cd /opt/n8n && docker compose config | grep -E 'image:|mem_limit'`.

Запуск — только когда готовы DNS и релей (§4–§6), иначе Caddy уйдёт в ACME-ошибки:

```bash
cd /opt/n8n && docker compose up -d && docker compose ps   # ждём 3 × healthy
```

## 4. DNS `context-ai.ru` → новый IP

1. За сутки снизить TTL A-записи до 60 с.
2. Переключить A-запись и дождаться `dig +short context-ai.ru`.
3. Caddy сам переобретёт Let's Encrypt-сертификат после переключения DNS (данные — в volume
   `caddy_data`, при чистом хосте выпуск с нуля): `docker logs -f n8n-caddy-1` → `certificate obtained`.
4. Если старый хост ещё жив — **остановить его контейнеры** до переключения, иначе два Caddy будут
   драться за ACME (`docker compose stop`), а не удалять.

Публичный :443 — только вебхуки. Редактор закрыт (H4283, tailnet-only) — не «починить» это, открыв UI наружу.

## 5. duck-релей `tg91-relay` (Telegram → vps91)

Причина релея (H6451): edge-провайдер режет TG-диапазоны внутрь `193.232.229.0/24`, а duck
(`147.189.174.159`, US) принимает TG без фильтра. Релей — nginx `:8443` → vps91 caddy (SNI `context-ai.ru`).

```bash
ssh root@147.189.174.159
sed -n '1,40p' /etc/nginx/sites-available/tg91-relay     # proxy_pass https://193.232.229.91;
# заменить IP на новый (сохранив rewrite /tg91/(.*) -> /$1, proxy_ssl_name context-ai.ru, Host)
nginx -t && systemctl reload nginx
```

**Если новый хост — тоже RU-DC за тем же блоком:** релей остаётся обязательным, меняется только
proxy_pass. Если хост вне `193.232.229.0/24` — релей можно снять, но лишь после проверки прямого URL
из §6: провайдерский блок подтверждается пробой, а не рассуждением.

## 6. Перерегистрация TG-вебхуков + проверка

Лежит в архиве как `tg-webhook-reregister.sh`; на новом хосте в `/opt/n8n/`, `chmod 700`.

```bash
/opt/n8n/tg-webhook-reregister.sh    # xpost (channel_post) + tukan, certificate=@/opt/n8n/tg91.crt
# проверка (URL должен указывать на duck, pending = 0)
T=$(grep '^TUKAN_TG_BOT_TOKEN=' /opt/n8n/.env | cut -d= -f2-)
curl -sS -x http://127.0.0.1:8118 "https://api.telegram.org/bot$T/getWebhookInfo" \
  | python3 -m json.tool | grep -E '"url"|pending_update_count|last_error_message'
# ожидаем: url = https://147.189.174.159:8443/tg91/webhook/…, pending_update_count = 0, ошибки нет
```

> **ГОЧА (дорогая).** Любой `PUT`/активация через n8n API перерегистрирует вебхук на `context-ai.ru`,
> а провайдер режет TG-inbound → бот молча умирает (`pending_update_count` растёт). Поэтому
> `tg-webhook-reregister.sh` запускается **после любых манипуляций с workflow**, а сторож
> `tg-webhook-guard-check.sh` лечит это в пределах 10 минут. Не «оптимизировать» этот шаг.

## 7. privoxy :8118 на новом хосте (без него OpenRouter = 403)

n8n ходит в интернет не напрямую: контейнеры видят `host.docker.internal:8118` (в compose
`extra_hosts: host.docker.internal:host-gateway`), privoxy отправляет избирательный трафик в SOCKS5.

Два файла переносятся руками (в ночной tar они не входят — там только `/opt/n8n`):

```bash
# 1) SOCKS5-аплинк (NL exit) — /etc/systemd/system/socks-nl.service
[Service]
ExecStart=/usr/bin/ssh -N -D 127.0.0.1:1080 -i /root/.ssh/id_tunnel \
  -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
  -o ExitOnForwardFailure=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
  -o TCPKeepAlive=yes tunnel@103.112.71.201
Restart=always
# ключ id_tunnel и известный host-key переносить отдельно (scp), права 600

# 2) /etc/privoxy/config — существенное (остальное дефолт)
listen-address 127.0.0.1:8118
listen-address 172.18.0.1:8118      # docker bridge (n8n)
listen-address 172.17.0.1:8118      # docker bridge (по умолчанию)
forward           /              .
forward-socks5t   .openrouter.ai:443/   127.0.0.1:1080 .
# полный список forward-socks5t (telegram/t.me/youtube/googlevideo/instagram/facebook):
#   ssh root@193.232.229.91 'grep -vE "^(#|$)" /etc/privoxy/config'
systemctl daemon-reload && systemctl enable --now socks-nl privoxy
ss -lntp | grep -E ':1080|:8118'
```

Проверка (без неё OpenRouter отвечает `403 security policy` — прямой доступ из RU/DC заблокирован):

```bash
curl -sS -x http://127.0.0.1:8118 -o /dev/null -w '%{http_code}\n' --max-time 20 https://openrouter.ai/api/v1/models
# 200 — прокси работает; 403 — privoxy мимо аплинка
```

## 8. Смоук: платная проба на 1 токен + зелёный execution

```bash
# 1) стек жив
cd /opt/n8n && docker compose ps            # n8n-n8n-1, tg-fanout, n8n-caddy-1 = healthy
curl -s -o /dev/null -w '%{http_code}\n' https://context-ai.ru/healthz   # внутри контейнера /healthz=200

# 2) ключ расшифровывается + воркфлоу на месте
docker exec n8n-n8n-1 n8n list:workflow | wc -l          # эталон 07-11-2026: 102 записи в БД

# 3) платная проба через прокси: ровно 1 токен, дешёвая модель
T=$(grep -E '^OPENROUTER_API_KEY=' /opt/n8n/.env | cut -d= -f2-)
curl -sS -x http://127.0.0.1:8118 --max-time 60 https://openrouter.ai/api/v1/chat/completions \
  -H "Authorization: Bearer $T" -H 'Content-Type: application/json' \
  -d '{"model":"<дешёвая модель>","max_tokens":1,"messages":[{"role":"user","content":"ok"}]}' \
  | head -c 300
# ожидаем usage с completion_tokens=1 (НЕ 403 и не таймаут)

# 4) зелёный execution на новом хосте: n8n UI недоступен (H4283) → через API внутри хоста
docker exec n8n-n8n-1 n8n execute --id <безопасный_workflow_id>   # exit 0 = зелёный
```

Проверка ботов — по [RUNBOOK_BOTS_WEBHOOK_RELAY.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md): `pending_update_count=0`, без `last_error_message`.

## 9. Бэкап-контракт (что уже живёт на vps91 и чем это доказано)

**На .91 две полосы, и это осознанно.** Их легко спутать, поэтому сначала различие:

| Полоса | Расписание | Куда | Что внутри | Зачем |
|---|---|---|---|---|
| H3182 `n8n-backup.service` → `/usr/local/sbin/n8n-backup-run.sh` (managed, ставится `scripts/server_guards_apply.sh --profile n8n`) | systemd-timer 03:17 UTC | `/var/backups/n8n/n8n-YYYYMMDDTHHMMSSZ.tar.gz` (14 шт., ~45 МБ) + restic → `sftp:backup92:/srv/restic-n8n/n8n` | снимок БД (`integrity_check` + сверка `workflow_entity`), `credentials.json`/`workflows.json` как блобы, compose, caddy | ежедневная целостность данных |
| **H7690 DR-пак** `/opt/n8n/backup-nightly.sh` (не managed — не перетирается apply) | cron 02:15 UTC (+ `--full` Вс 03:30) | `/opt/n8n/backups/n8n-migration-YYYYMMDD.tar.gz` (14 шт., ~43 МБ) + **duck US** `/root/n8n-backups/` | compose+override, **`.env` (N8N_ENCRYPTION_KEY)**, `caddy/`, `patches/`, entrypoint, tg-скрипты, `tg91.crt`, снимок БД, `MANIFEST.txt` | переезд: без `.env` креденшелы не расшифруются |

Третья копия — рабочая машина MG: `scripts/pull_n8n_backup.ps1` + задача Планировщика `n8n-backup-pull`
забирает новейший архив из `/var/backups/n8n` ежедневно в 04:40 (`D:\Backups\n8n`). Это копия **вне**
обоих ДЦ хоть и не с .91.

Почему полосы не слиты: `n8n-backup-run.sh` — managed-файл, правка живёт до следующего
`server_guards_apply.sh`; а DR-пак не должен зависеть от успеха соседней полосы. Цена честная:
два снимка БД в сутки и ~1 ГБ диска на ретенцию обеих полос.

`/opt/n8n/backup-nightly.sh` (0700, root) — версия в репозитории
[scripts/n8n_backup_nightly.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/n8n_backup_nightly.sh),
на хосте лежит байт-в-байт та же копия (sha256 сверен 07-11-2026); править репо-копию, на хост
выкатывать `scp`. `crontab -l`:

```
15 2 * * *     /opt/n8n/backup-nightly.sh           >> /var/log/n8n-backup.cron.log 2>&1
30 3 * * 0     /opt/n8n/backup-nightly.sh --full    >> /var/log/n8n-backup.cron.log 2>&1
```

- **Ночной:** `sqlite3 .backup` живой БД (прод **не** останавливается) → `tar czf` из compose+override,
  `.env`, `caddy/`, `patches/`, entrypoint + tg-скриптов, `tg91.crt`, `MANIFEST.txt`, `db/database.sqlite`.
  `storage/binaryData` и `backups/` внутрь не попадают. Санити в скрипте: `quick_check=ok`, наличие
  `./.env`/`./db/database.sqlite`/`./docker-compose.yml`, отказ при < 2 ГБ свободных, WARN при tar > 500 МБ.
- **Недельный `--full` (Вс 03:30):** то же + `storage/binaryData` (3.1 ГБ) — симлинком, без второй копии
  на диске; после проверенного пуша архив удаляется локально (диск занят на 89%).
- **Ретенция:** 14 локально и 14 на off-боксе; только что записанный не удаляется никогда.
- **Off-бокс:** `duck:/root/n8n-backups/` ключом `/root/.ssh/id_n8n_backup_duck` (ed25519, на duck заперт
  `from="193.232.229.91"`). Проверка — **не логи, а проба**: размер совпал и mtime не старше 15 минут.
  Fallback `.92` — **тот же SPb-DC**: копия, не гео-разнесённый DR (скрипт пишет это в лог).
- **Восстановление проверено 07-11-2026:** tar распакован в temp, `.env` содержит `N8N_ENCRYPTION_KEY`,
  `quick_check = ok`, `select count(*) from workflow_entity` = 102.

**Ежедневная проба (с любой машины):**

```bash
ssh root@147.189.174.159 'ls -lt /root/n8n-backups/ | head -3'
# верхняя строка должна быть моложе 24 ч; пусто/старое = ночной cron не отработал
ssh root@193.232.229.91 'tail -5 /var/log/n8n-backup.log'
```

## 10. Чего НЕ делать

| Не делать | Почему |
|---|---|
| `docker compose up -d` без `docker-compose.override.yml` | caddy вернётся в `latest`, снимутся mem_limit/healthcheck |
| Открывать редактор n8n наружу | H4283: редактор только через tailnet; публичный :443 — вебхуки |
| Перевыпускать `tg91.key` на duck без обновления вебхука | `setWebhook(certificate=…)` обязан нести ТОТ ЖЕ сертификат, что предъявляет nginx duck |
| Любой `PUT` в n8n API без `tg-webhook-reregister.sh` | вебхук уедет на `context-ai.ru`, TG-inbound отрезан провайдером |
| Держать оба Caddy (старый и новый) включёнными | драка за ACME и за DNS-переключение |
| Забыть `extra_hosts: host.docker.internal:host-gateway` | n8n потеряет privoxy :8118 → OpenRouter 403 |
| Класть `backups/` или `binaryData` в ночной tar | диск .91 на 89%; ночной архив обязан быть < 500 МБ |

## 11. Остаточные риски (не закрыты этим пакетом)

1. `tg91.key` (приватный ключ релея) есть **только на duck**. Потеря duck = перевыпуск self-signed
   сертификата + `setWebhook` + правка nginx. Стоит завести off-бокс копию ключа.
2. Fallback `.92` — тот же SPb-DC; настоящий DR — только duck-копия (US).
3. Полный архив еженедельный: потеря > 7 дней медиа допустима, потеря БД — нет (ночной ежедневный).
4. Диск .91 89% — до переезда ночной архив остаётся единственной страховкой.
5. Две полосы делят один диск (§9): под 92% первым снимать `--full`, но не ночные.

_Гасунс_
