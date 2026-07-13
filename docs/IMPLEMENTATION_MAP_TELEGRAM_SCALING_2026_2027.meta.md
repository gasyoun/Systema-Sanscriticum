# IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.meta.md — metadoc about `IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Метадок-компаньон для [`IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md) — PR/файловой карты исполнения зонтичного roadmap масштабирования Telegram-интеграции.

## Предмет

- **Документ:** [`IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)
- **Назначение:** развернуть зонтичный roadmap в узлы уровня «один PR = одна агент-сессия» с точным перечнем файлов, флагов, миграций, тестов, зависимостей, гейтов и исполнителей.
- **Аудитория:** агенты-раннеры (Opus для сверки, Sonnet для кодовых PR), MG и Иван как держатели prereq/прод-доступа.
- **Формат/контракт:** карта, построенная на чтении кода `main` (не по прозе roadmap); DAG зависимостей + таблицы узлов + сводка H###/исполнителей; расхождения кода и roadmap зафиксированы отдельным разделом.

## Провенанс

- Предмет создан: 11-07-2026.
- Метадок написан: 13-07-2026 (H887, Opus 4.8 `claude-opus-4-8`).
- Следующее упрочнение: none (плановое — при закрытии первой волны узлов P0/W1).

## Ранжированный бэклог улучшений

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Синхронизировать статусы узлов с фактическим состоянием H570/H590–H598 (merged/в работе) | Карта — снимок на 11-07; узлы уже могут исполняться, «launchable сейчас» устаревает быстрее всего | parked (нужен проход по реестру Uprava/handoffs после первой волны PR) |
| 2 | Зеркалировать D## `/decision-record` по W1.2/W4.1, когда он будет оформлен | Два узла жёстко ждут формального decision-record до прод-включения авто-ответа | parked (ждёт оформления decision-record человеком) |
| 3 | Довести правку имени команды `support:deflection-report`→`support:topic-ranking` (R2) в roadmap-текст | Расхождение зафиксировано, но исходный roadmap ещё не синхронизирован | parked (косметическая правка roadmap, низкий приоритет) |
| 4 | Добавить фактический критический-путь-трекер к дедлайну 01-09-2026 | Карта называет путь P0.1→P0.3→W1.3→W3.2, но не отслеживает прогресс по нему | parked (нужен по мере старта узлов) |

## Известные ограничения / оговорки

- Карта — **снимок кода `main` на 11-07-2026**; любой мёрж узла или рефактор support-подсистемы делает конкретные якоря-файлы и «launchable сейчас» потенциально устаревшими.
- Гейты `prereq` (Иван, прод-доступ, backfill `telegram_chat_id`) — внешние к репо; их статус карта не может проверить сама.
- Узлы WS2/WS4 и W1.4→S5–S12 очерчены только на PR-уровне; их хэндофы минтятся при старте фазы, а не здесь.

## Назначение / вероятное неверное использование

- **Для чего:** взять один узел, увидеть его файлы/флаги/тесты/гейт и запустить как единичную агент-сессию; свериться, что prereq закрыт до старта.
- **Неверное чтение:** трактовать «launchable сейчас = да» как «гейт снят» без проверки живого prereq; считать раздел реконсиляции (§2) вечным — он верен только на дату снимка; минтить хэндофы для WS2/WS4 отсюда (они умышленно не заминчены).

## План поддержки и вывода из эксплуатации

- **Кто/что держит живым:** обновляется вместе с зонтичным roadmap и по мере мёржа узловых PR; реестр [`Uprava/handoffs`](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md) — источник истины по статусу H###.
- **Как выглядит архив/конец:** когда все узлы P0–WS4 закрыты (или дедлайн 01-09-2026 пройден и цель снята), карта помечается `retired`/`superseded` и заменяется пост-фактум отчётом об исполнении.

## Статус устаревания

`active`

## Связанные документы

- Компаньон-roadmap: [`ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)
- WS1-детализация: [`ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
- Ground truth кода: [`support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) · [`cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md) · [`support-identity.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md)
- Провенанс-хэндоф: [H565](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H565-Opus_Systema-Sanscriticum_telegram_scaling_roadmap_11.07.26.md)
- GTD-провязка: [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)

## История ревизий

| Дата | Событие | Кто |
|---|---|---|
| 13-07-2026 | метадок создан (H887) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
