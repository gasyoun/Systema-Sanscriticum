# RUNBOOK — релей вебхуков ботов (103.112.71.201, ssh -R + setWebhook)

_Created: 05-10-2026 · Last updated: 05-10-2026_

**Аудитория:** ops/агенты. **Симптом-класс:** все 4 бота молчат (@samskrte_bot лидовый,
@samskrtamru_bot кабинетный, @zapisi_ORSbot записи, @samskrte_ops_bot ops),
`pending_update_count` растёт.
**Источники:** [telegram-userbot-inventory §4.2–4.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md)
(разбор по проду 27-07), [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md)
(секреты/fail-policy), [LAUNCH_GATE_28_08 §M5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LAUNCH_GATE_28_08_2026.md).

## Топология (почему узел штатный)

```
Telegram ──HTTPS──▶ 103.112.71.201 :443  (входной узел, вне RU-недоступности)
                      │ localhost:8081
                      ▼ обратный туннель: systemd tg-reverse.service НА ПРОДЕ
                        ssh -R 127.0.0.1:8081:127.0.0.1:443 tun92@103.112.71.201
                      ▼
                    nginx прода :443 → Laravel (samskrte.ru, Host-заголовок)
```

Прямой вебхук на прод НЕ поставить: Telegram физически не достукивается до RU-прода
(ноль обращений `149.154.*`/`91.108.*` в nginx за всю историю — §4.3). Узел
103.112.71.201 — штатный вход (фиксация LAUNCH_GATE M5), не «потерянный хост».

## Диагноз

```bash
# 1. Что говорит Telegram (на любой машине):
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | python3 -m json.tool
# смотрите: last_error_message, last_error_date, pending_update_count
# норма: error пуст, pending_update_count=0, url=https://103.112.71.201/api/webhooks/...

# 2. Туннель жив? (на проде .92):
systemctl status tg-reverse.service
journalctl -u tg-reverse.service --since -30min --no-pager | tail -20

# 3. Порт слушается на узле? (с прода):
ssh -o BatchMode=yes tun92@103.112.71.201 'ss -tlnp | grep 8081'
```

## Восстановление по слоям

| Слой | Признак | Действие |
|---|---|---|
| Туннель | `tg-reverse` inactive/failed или 8081 не слушается на узле | `systemctl restart tg-reverse.service` (прод), повторить п.3 диагноза. Если ssh-ключ `tun92@` отвергнут — смотрим `journalctl`: expired host key / refused publickey → ключи в `/root/.ssh/` прода, правка только через MG |
| Узел | 103.112.71.201 не отвечает по :443 вовсе (curl таймаут) | Узел лежит — это вне прода (панель хостера). Эскалация MG немедленно: боты молчат до его возврата, восстановление прода не поможет |
| Приложение | узел и туннель ОК, но `last_error_message` — HTTP-код от Laravel (5xx/404) | Смотреть nginx/laravel логи прода; webhook-маршрут/секрет — [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md); секрет fail-closed: неверный секрет = 403, это защита, не поломка |

## Перекат сертификата setWebhook

Нужен при смене self-signed сертификата узла (Telegram требует тот же серт, что отдаёт
узел, при нестандартном/самоподписанном цепочке):

```bash
# с машины, где лежит серт узла (прод хранит копию для setWebhook):
curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -F "url=https://103.112.71.201/api/webhooks/telegram-magnet" \
  -F "certificate=@/path/to/cert.pem" \
  -F "allowed_updates=[\"message\",\"callback_query\"]" | python3 -m json.tool
# ожидание: {"ok":true,"result":true,"description":"Webhook was set"}
```

После переката — тестовое сообщение в бота и `getWebhookInfo` → `pending_update_count=0`.

## Healthcheck (штатный)

```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo" | grep -o 'pending_update_count":[0-9]*'
# =0 и last_error_message отсутствует → релей здоров; проверять после любых правок узла
```

_Гасунс_
