# RUNBOOK_N8N_RECORDING_STALL — metadoc

_Created: 26-08-2026 · Last updated: 26-08-2026_

**Purpose:** канонический ранбук для класса «занятие прошло, запись не доехала до Telegram/кабинета» — что триажить на `.91` (n8n) и `.92` (Laravel), какие классы падений бывают (egress-flap на Sheets, OpenRouter credits/TOS, стёртый бинарник, протухший `download_token`, passcode), и где граница «агент чинит сам» vs «только человек» (resume с AI Agent1 / корзина Zoom).

**Provenance:** вырос из инцидента 18–20.08.2026 ([INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026.md)); расширен 23-08 (early-death Sheets-класс, `--retry-failed`) и 26-08 (H3557: dedup в БД, scoped чистка, Meta+Bearer DOWNLOAD, теневая копия Drive, маркер старения ≥20ч).

**Ranked backlog:**
1. Авто-догон stuck-записей авторизованным скачиванием внутри ретрай-плеча (сейчас человек перезапускает replay после восстановления из корзины).
2. Алерт при уходе записи в корзину хост-аккаунта (сейчас узнаём постфактум по 404 мета-ноды).

**History:** 26-08-2026 §8 переписан под персистентный дедуп + t.me-ссылки + получателей H3557.

_Dr. Mārcis Gasūns_
