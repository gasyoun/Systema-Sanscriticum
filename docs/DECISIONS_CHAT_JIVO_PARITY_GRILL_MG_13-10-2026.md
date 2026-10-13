_Created: 13-10-2026 · Last updated: 13-10-2026_

# Решения MG по чату поддержки — интерактив-грилл 13-10-2026 (Jivo-паритет)

Повод: живой срез [docs/OPS_SUPPORT_JIVO_PARITY_LIVE_SNAPSHOT_12-10-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPS_SUPPORT_JIVO_PARITY_LIVE_SNAPSHOT_12-10-2026.md) + сессия /grillme; визы собраны двумя раундами AskUserQuestion 13-10-2026, исполнены той же пассой (batch-mint H6600–H6604, [PR Uprava #4938](https://github.com/gasyoun/Uprava/pull/4938) merged). Статусы исполнения — только в [реестре Uprava](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md), этот док — запись рулингов.

| Карточка | Вердикт MG | Хендофф | Гейт |
|---|---|---|---|
| ИИ-первый-эшелон «Отдела заботы» (черновик <60 с, автопосылка только шаблонные access) | **Да, минтить** | [H6600](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6600-OxAlpha_Systema-Sanscriticum_ai-first-echelon-zabota_13.10.26.md) | слово MG на автопосылку |
| Проактивные приглашения куратором на горячих страницах (лендинг мини-курса, /shop, оплата; рабочие часы) | **Да, включать** | [H6601](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6601-OxAlpha_Systema-Sanscriticum_proactive-invites-hotpages_13.10.26.md) | — |
| Гео-город: чем заменить мёртвый cloudflare-драйвер | **DB-IP Lite без регистрации** (не MaxMind: принципиальна безрегистрационность; агент дорабатывает URL-источник + CC BY 4.0 атрибуцию в политику с переизданием PDF) | [H6602](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6602-OxAlpha_Systema-Sanscriticum_geo-dblip-city-provider_13.10.26.md) | — |
| Email-канал zabota@samskrte.ru (№82, H1200) | **Делать сейчас** | [H6603](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6603-OxAlpha_Systema-Sanscriticum_inbound-email-channel-82_13.10.26.md) | внешний: ящик reg.ru — руки MG |
| SLA-дрен TG-бэклога (64 без ответа/134 unresolved>24ч) и VK (7 тредов) | **Да, TG+VK волной** | [H6604](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6604-OxAlpha_Systema-Sanscriticum_support-sla-drain-tg-vk_13.10.26.md) | — |

Человек-шаг (единственный, разблокирует финальный смок H6603): создать ящик `zabota@samskrte.ru` в панели reg.ru («Почта» тарифа домена samskrte.ru), пароль — в чат агенту или в `.env` на `.91` как `ZABOTA_IMAP_PASSWORD`. Остальное (n8n-проводник на .91, секрет `INBOUND_EMAIL_WEBHOOK_SECRET`, флаг `SUPPORT_INBOUND_EMAIL`, смок-письмо) — агентская сторона по чек-листу [DEPLOY_QUEUE №82](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).

Срез-источник чисел: parity-report 30 д от 12-10 (HOLD, 7 остатков); close срезного хендоффа H6577 ждёт verifier-пары не-OxAlpha-семьи (H4430/H6275) — волна H6537.

_к.ф.н. М.Ю. Гасунс_
