# meta — RUNBOOK_TG_OUTBOUND_RELAY_VPS92_09-10-2026.md

_Created: 09-10-2026 · Last updated: 09-10-2026_

- **Что это:** ранбук исходящего Telegram-релея через .92 (hermes-бот, `hermes_notify.sh`/`tg_send`, delivery-evidence) из волны H6312; до него был документ только про входящий вебхук-релей 103.112.71.201 — [RUNBOOK_BOTS_WEBHOOK_RELAY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_BOTS_WEBHOOK_RELAY.md) (новый ранбук от него явно отличён таблицей).
- **Заказ:** [H6312](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6312-OxAlpha_Systema-Sanscriticum_playbooks-five-new-surfaces_09.10.26.md) (Uprava), исполнен GLM (GLM-5.3-Flash), 09-10-2026.
- **Источники фактов:** живые пробы .92 09-10 (getMe → `ok:true` @gasuns_hermes_bot; `/home/hermes/bin/lane_lib.sh:tg_send` — evidence-строки в `brief/tg_delivery.log`; `.hermes/.env`: `TELEGRAM_BOT_TOKEN`/`TELEGRAM_HOME_CHANNEL`), фикс доставки 05-09-2026 («are you sure it reached me?»).
- **Не является:** документом про входящие вебхуки (RUNBOOK_BOTS_WEBHOOK_RELAY) и про userbot-сессию ([RUNBOOK_USERBOT_SESSION_RECOVERY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_USERBOT_SESSION_RECOVERY.md)).

_Dr. Mārcis Gasūns_
