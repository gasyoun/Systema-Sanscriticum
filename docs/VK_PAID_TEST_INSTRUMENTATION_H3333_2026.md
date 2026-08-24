# VK paid test — instrumentation pack (H3333)

_Created: 22-08-2026 · Last updated: 22-08-2026_

Исполнитель: OxAlpha (`x-preview-f-free`). Решение MG 22-08-2026:
[MONETIZATION_PLAN_2026H2 §8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md) —
малый платный VK-тест **параллельно марафону**, бюджет ≈ ₽20–40 тыс. суммарно.
Хендофф: [H3333-OxAlpha_Systema-Sanscriticum_vk-paid-test-instrumentation-pack_22.08.26.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3333-OxAlpha_Systema-Sanscriticum_vk-paid-test-instrumentation-pack_22.08.26.md).
Сам бюджет и запуск кампаний — человек; здесь только инструменты и правила остановки.

## 1. E2e-верификация атрибуции (выполнена 22-08-2026)

Реальный HTTP-прогон через прод (`POST /subscribe`, CSRF + анти-бот time-trap пройдены):

```
email=h3333e2e@gmail.com & utm_source=vk & utm_medium=cpc
  → Lead id=366: utm_source=vk · utm_medium=cpc · utm_campaign=h3333_e2e_probe   ✅
  → тестовые строки удалены (lead+user), пост-проверка: 0
```

Канал захвата: `NewsletterSubscribeController::store()` пишет все пять UTM-полей + `click_id`
(`Lead::$fillable`: utm_source/medium/campaign/content/term). Флаг `newsletter_subscribe=1`.
Анти-бот (honeypot `website`, time-trap `ff_ts`, одноразовые домены) работает — живой клик из
VK его проходит автоматически, скриптовый — нет (проверено).

## 2. UTM-схема (канонические значения)

| Поле | Значение | Комментарий |
|---|---|---|
| `utm_source` | `vk` | всегда |
| `utm_medium` | `cpc` | платный клик |
| `utm_campaign` | `marathon_aug26` / `club_sep26` | один оффер на кампанию |
| `utm_content` | `post_<id>` / `clip_<id>` | идентификатор креатива |
| `utm_term` | сегмент (`zero2534` / `retention`) | для сплита аудиторий |
| `click_id` | `{vk_click_id}` макет VK | пробрасывается в `leads.click_id` |

Ссылки вести на лендинг соответствующего оффера (марафон: `/konsultaciya-po-onlayn-kursam`;
клуб: `/klub`). Все поля формы лендинга уже прокидывают UTM в `Lead`.

## 3. Харнесс (read-only SQL/отчёт)

Поканальный ROI строит команда юнит-слоя [`marathon:warmtail-ab-report`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MarathonWarmtailAbReport.php)
— нет: канальный отчёт — это H3332 (`report:channel-roi`, см. [его отчёт](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/REPORT_CHANNEL_ROI_H3332_22-08-2026.md)).
До его запуска ручная проверка:

```sql
SELECT l.utm_source, l.utm_campaign,
       count(*) leads,
       count(DISTINCT u.id) users,
       count(DISTINCT p.user_id) payers,
       COALESCE(SUM(p.amount),0) revenue
FROM leads l
LEFT JOIN users u ON u.lead_id = l.id
LEFT JOIN payments p ON p.user_id = u.id
  AND p.status IN ('paid','success') AND p.is_conditional = 0
  AND p.tariff NOT IN ('Расход','salary_payout','deposit','trial','marathon_paid')
GROUP BY l.utm_source, l.utm_campaign ORDER BY revenue DESC;
```

## 4. Стоп-правила (объявлены ДО запуска)

1. Суммарный расход кампании > **₽40 000** → пауза, эскалация MG.
2. CAC канала > маржи юнита (после первого отчёта `report:channel-roi`) → пауза, эскалация.
3. ≥ 100 кликов без единого лида → креатив/лендинг не работают, стоп без ожидания бюджета.
4. Любое расхождение атрибуции (клики без строк `Lead` с utm) → стоп до разбора.

## 5. Чеклист запуска (человек)

1. Кабинет VK Ads: создать кампанию по §2 (один оффер = одна кампания).
2. Бюджет дневной ≤ ₽3 000 (лимит окна теста).
3. После первых ~50 кликов: прогнать харнесс §3 — лиды с `utm_source=vk` появились?
4. Еженедельно: харнесс + сверка расхода кабинета с `AdPostSpend`/Direct-бюджетом.

_Dr. Mārcis Gasūns_
