# RUNBOOK — исходящий Telegram-релей через .92 (hermes; НЕ вебхук-релей)

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Аудитория:** ops/агенты, авторы лейн и скриптов. **Когда:** скрипт должен доставить сообщение MG, а исходящие вызовы Bot API с Mac флапают; диагностика «бот молчит, но это исходящая сторона».
**Источники:** `/home/hermes/bin/lane_lib.sh` (`tg_send`) и `/home/hermes/bin/hermes_notify.sh` на .92 (пробы 09-10-2026), [RUNBOOK_BOTS_WEBHOOK_RELAY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md) (смежный, но ДРУГОЙ релей), живая проба getMe 09-10-2026.

## Отличие от вебхук-релея (103.112.71.201)

| | [RUNBOOK_BOTS_WEBHOOK_RELAY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md) | Этот ранбук |
|---|---|---|
| Направление | ВХОДЯЩИЕ update'ы к 4 ботам (@samskrte_bot, @samskrtamru_bot, @zapisi_ORSbot, @samskrte_ops_bot) | ИСХОДЯЩИЕ вызовы Bot API от лейн/скриптов |
| Узел | 103.112.71.201 + `ssh -R` туннель (tg-reverse.service) | сам .92 (curl с него стабильно) |
| Симптом | боты молчат, pending_update_count растёт | lane-уведомления не доходят до MG |

## Топология

```
лейн/скрипт на .92 ──▶ curl https://api.telegram.org (с .92 стабильно) ──▶ Telegram Bot API
        Mac ─────────▶ api.telegram.org — ФЛАПАЕТ (маршрутный класс MTProto-блока) → напрямую НЕ использовать
```

Канон для любого лейна: `/home/hermes/bin/hermes_notify.sh page|send "текст"` → `lane_lib.sh:tg_send` → `POST /bot$TELEGRAM_BOT_TOKEN/sendMessage` в `chat_id=$TELEGRAM_HOME_CHANNEL` (чат MG). Токен читается из `/home/hermes/.hermes/.env` и наружу не копируется.

- `page` — P1-класс: немедленно в awake-окне, иначе в очередь брифа; `send` — всегда немедленно.
- Evidence: `/home/hermes/brief/tg_delivery.log` — `ok` + `message_id` либо error-описание Telegram (фикс 05-09-2026 после «are you sure it reached me?»: прежняя версия логировала «sent OK» безусловно).
- Дед-ман свитч: утренний brief приходит КАЖДЫЙ день — тишина означает, что мертво что-то на стороне лейн; это фича, не сбой.

## Живая проба (рид-онли)

```bash
ssh -o BatchMode=yes root@193.232.229.92
T=$(grep -E '^TELEGRAM_BOT_TOKEN=' /home/hermes/.hermes/.env | cut -d= -f2-)
curl -s -m 10 "https://api.telegram.org/bot$T/getMe"
# 09-10-2026: {"ok":true,…} @gasuns_hermes_bot → исходящий маршрут жив
tail -3 /home/hermes/brief/tg_delivery.log   # последние доставки и их ok/message_id
```

## Разовая отправка вручную

`*** GATE ***` — доставляет сообщение в чат MG. Только через .92, канон-путь:

```bash
ssh root@193.232.229.92 "/home/hermes/bin/hermes_notify.sh send 'текст сообщения'"
```

## Диагноз «сообщение не дошло»

| След в `tg_delivery.log` | Класс | Действие |
|---|---|---|
| `ok=true` + message_id | доставлено | смотреть у MG чат, не бота |
| `ok=false … 429 Too Many Requests` | flood | ретрай с паузой (lane_lib сам не ретраит), не спамить |
| `ok=false … 403 bot was blocked` | бот заблокирован получателем | проверка у MG |
| `notify-skipped-no-env` | `.hermes/.env` не прочитался | права/путь на .92; скрипт звать от владельца env |
| строк нет вовсе | скрипт не дошёл до отправки | журнал самого лейна |

## Гочи

- Бот НЕ может начать личный диалог первым: сообщение Ивану — только пересылка MG из чата MG.
- С Mac `api.telegram.org` не дёргать: флап даёт ложные «бот мёртв». Диагностику ботов делать с .92; для входящих — [RUNBOOK_BOTS_WEBHOOK_RELAY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md).
- Секреты: токен только из env на .92; в логах/брифах его быть не должно.

_Dr. Mārcis Gasūns_
