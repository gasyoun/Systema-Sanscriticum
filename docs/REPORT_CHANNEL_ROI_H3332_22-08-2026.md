# Channel ROI baseline (H3332) — витрина юнит-слоя, срез 22-08-2026

_Created: 22-08-2026 · Last updated: 22-08-2026_

Исполнитель: OxAlpha (`x-preview-f-free`). Команда: `php artisan report:channel-roi`
([ReportChannelRoi.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ReportChannelRoi.php)),
read-only. Решение о кэпах скидок/рассрочки — `@DECIDE` MG **после** накопления данных этого слоя
([план §7](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONETIZATION_PLAN_2026H2.md)).

## Срез 2026-08-23 00:08 MSK · all time

| channel (source/campaign) | leads | users | payers | revenue, ₽ | rev/lead, ₽ | first lead |
|---|---|---|---|---|---|---|
| tquhyvfq / rhyntpzd | 1 | 0 | 0 | 0 | 0 | 2026-05-01 |
| xziwheqh / kgompxsk | 1 | 0 | 0 | 0 | 0 | 2026-05-09 |
| olfjqkdo / nfjrnewx | 1 | 0 | 0 | 0 | 0 | 2026-05-09 |
| newsletter_popup / floating_subscribe | 11 | 0 | 0 | 0 | 0 | 2026-08-01 |

Caveat команды: **3/14 (21,4 %)** лидов связаны с пользователями — исторические разрывы атрибуции до magic-link (H324).

## Чтение базлайна

1. UTM-разметка до сегодняшнего дня фактически не использовалась для платных каналов — мусорные
   campaign-строки (случайные подстроки), единственный осмысленный канал — попап рассылки.
2. Выручка по каналам = 0: это **базлайн до первого платного трафика**; VK-тест (H3333) станет
   первой реальной точкой.
3. Связка лид→юзер→платёж работает end-to-end (e2e-доказательство в
   [VK_PAID_TEST_INSTRUMENTATION_H3333_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VK_PAID_TEST_INSTRUMENTATION_H3333_2026.md)).

## Регламент

- После запуска VK-теста: `report:channel-roi --source=vk` еженедельно; стоп-правила —
  [H3333 §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VK_PAID_TEST_INSTRUMENTATION_H3333_2026.md).
- Калибровка кэпов (стек скидок ≤ X %, доля рассрочки): минимум одна когорта платящих с известным CAC.

_Dr. Mārcis Gasūns_
