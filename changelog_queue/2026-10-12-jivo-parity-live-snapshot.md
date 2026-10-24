# Живой срез Jivo-паритета чата поддержки на проде — 12-10-2026

_Created: 12-10-2026 · Last updated: 12-10-2026_

Ответ на вопрос MG «чат с новыми учениками работает полноценно?» зафиксирован durable-отчётом с живыми пробами прода (без PII): виджет и конвейер живы (13/13 флагов ON, presence-эндпоинт доказан end-to-end пробой 200+запись), но вердикт `support:parity-report` — HOLD. Числа за 30 д: Telegram 518 разговоров/827 входящих/1-й ответ ≈51 мин (64 без ответа, 134 unresolved >24 ч), веб-виджет 11 разговоров/9 входящих/1-й ответ ≈8,6 ч, VK 7 без ответа. Root cause №1: geo-город мёртв — в проде `SUPPORT_GEO_DRIVER=cloudflare` при рулинге H3445 = MaxMind, а домен не за CF-прокси (nginx, origin-IP) — CF-заголовки не приходят физически. Остатки: email-канал H1200 (№82, человек-шаги reg.ru+n8n), H2381 workflow-хвост, telegram-business stale sync. Решения (ИИ-первый-эшелон, proactive-приглашения, geo-драйвер, email-канал, SLA-дрен, VK) унесены в грилл MG. Отчёт: [docs/OPS_SUPPORT_JIVO_PARITY_LIVE_SNAPSHOT_12-10-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPS_SUPPORT_JIVO_PARITY_LIVE_SNAPSHOT_12-10-2026.md).

_к.ф.н. М.Ю. Гасунс_
