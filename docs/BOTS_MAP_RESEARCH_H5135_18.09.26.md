# BOTS_MAP_RESEARCH_H5135 — карта бот-архитектуры estate + бенчмарк edu-платформ + вердикты для адъюдикации

_Created: 18-09-2026 · Last updated: 18-09-2026_

Провенанс: [H5135](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5135-OxAlpha_Systema-Sanscriticum_bots-architecture-research-benchmark_18.09.26.md) (filename-tier OxAlpha; исполнил GLM 5.3 `zai-coding-plan/glm-5.3-flash`, H3688 any-lane). Исследование **не меняет прод** — вердикты адъюдирует MG: [лист голосования](https://gasyoun.github.io/vote/sheets/systema_bots_map_h5135.html).

База (prior art, построено поверх, расхождения помечены): [`docs/telegram-bots-inventory.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-bots-inventory.md) (17-09, H5063) · [`docs/telegram-userbot-inventory.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md) · [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md).

---

## 1. Метод и журнал живых проб (18-09-2026)

Все пробы — usernames и структура только; токены, chat_id студентов и содержимое чатов не читались (фенс H5135). Четыре пробы, один день:

1. **t.me-пробы** (публичные страницы): 8 юзернеймов — @samskrtamru_bot (HTTP 200, display name «ORS»), @samskrte_bot («samskrte»), @zapisi_ORSbot («Почтальон ревнителей санскрита»), @testpodpiska12_bot («testpodpiska»), @rusamskrtam («Куратор курсов Общества ревнителей санскрита»), @grokusaurus_bot (bare), @webinar_17june_bot («webinar_17.06.2026»), канал @samskrte («ОПОВЕЩЕНИЯ САНСКРИТЯН»).
2. **.92 `/var/www/html`**: имена env-переменных (55 TELEGRAM_*/STUDENT_*/VK_*), значения USERNAME-переменных, `landing_bots` (7 строк: id, username, is_active), `supervisorctl status telegram-student-poll`, `php artisan telegram:webhooks` (таблица регистрации вебхуков, секреты замаскированы).
3. **.91 n8n** (`n8nio/n8n:2.27.5`, контейнер `n8n-n8n-1`): `n8n list:workflow` — **87 воркфлоу**, отобраны TG-релевантные.
4. **Внешний бенчмарк**: только публичные источники, официальные репо/docs до блогов (GitHub-first); NO-DATA помечен явно.

## 2. Карта: бот × функция × транспорт × владелец

| # | Сущность | Функции | Транспорт (live) | Владелец/хранение | Проба 18-09-2026 |
|---|----------|---------|------------------|-------------------|------------------|
| 1 | **@samskrtamru_bot** | привязка TG, ИИ-куратор, `/кабинет`/`/вход`, личные уведомления студенту | **аварийный long-poll** `telegram:poll-student`, supervisor **RUNNING** (uptime 0:53, ротация ~1 ч); вебхук **не зарегистрирован** (труба мертва с 06-09) | LMS .92, `STUDENT_TELEGRAM_BOT_*` | t.me 200 «ORS»; `telegram:webhooks`: «Кабинет — не зарегистрирован»; supervisorctl RUNNING |
| 2 | **@testpodpiska12_bot** | служебные алерты LMS (cabinet:probe, дайджесты), чаты кураторов/маркетологов/онбординга; фолбэк-токен студенческого пути | webhook `/api/telegram/webhook` через входной узел | LMS .92, `TELEGRAM_BOT_*`; в .env live-комментарий **«замени на реальный юзернейм твоего бота»** | t.me 200 «testpodpiska»; .env grep; webhooks-таблица |
| 3 | **@samskrte_bot** | лид-магнит, марафон drip Day 1–3, постинг в канал @samskrte | webhook `/api/webhooks/telegram-magnet` (совпадает) | `MarketingSetting.tg_bot_*` **+ дубль в `landing_bots` строка 7** (активна, вебхук смотрит на глобальный магнит вместо своего `/{webhookKey}` — расхождение) | t.me 200; mysql: row 7 `samskrte_bot` active=1; webhooks: «Совпадает: НЕТ» |
| 4 | **@zapisi_ORSbot** | чат бронирования, напоминания, welcome-карточки (H4314–H4318), roster peer, forward в n8n | webhook `/api/webhooks/telegram-zapisi` (совпадает); аварийный `zapisi:poll` выключен (default false) | `MarketingSetting.zapisi_*`, флаг ON | t.me 200 «Почтальон ревнителей санскрита»; webhooks: да |
| 5 | **@rusamskrtam** (userbot, MTProto) | support-sync «Отдела заботы», harvest, roster-groups; UX «Написать в Telegram» | MadelineProto, одна сессия, cron `schedule:run` | LMS .92, `TELEGRAM_SUPPORT_*`, `TELEGRAM_SUPPORT_USERNAME=rusamskrtam`, ENABLED=true | t.me 200 «Куратор курсов ОРС»; .env grep |
| 6 | **@grokusaurus_bot** | Grok «Отдел заботы» (зовы кураторов) | long-poll на **ПК Марциса**, не VPS | `C:\Users\user\.grok\channels\telegram\.env` | t.me 200 (bare) |
| 7 | **@webinar_17june_bot** | лендинг-бот вебинара 17.06 (+ n8n forward) | **мёртв**: `getWebhookInfo` → `404 Not Found` (токен в БД отозван/перевыпущен), при этом t.me-страница жива и строка active=1 | `landing_bots` row 1 | webhooks-таблица: «ошибка запроса 404»; t.me 200. **КАНАРИ — строка «active» опровергнута пробой** |
| 8 | **landing_bots rows 2–6** | legacy-лендинги | нет username; токены в БД есть; вебхук-статус не проверяем без username | `landing_bots` | mysql: 5 строк `<no-username>` active=1 |
| 9 | **Telegram Business lane** (H5065) | ответы студенту **от имени аккаунта** школы | **не развёрнут**: в прод-.env ноль `TELEGRAM_BUSINESS_*` переменных | документирован [`docs/RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17.md), код в репо | .env grep 18-09: vars отсутствуют. **КАНАРИ — строка инвентаря §2.5 «Env: TELEGRAM_BUSINESS_BOT_TOKEN» не соответствует проду** |
| 10 | **n8n TG-флоу (.91)** | welcome-карточки zapisi (`h4314welcome0000000`), «Почтальон — аварии» (Error Trigger → TG MG), Webinar Bot family (Registration ×3, Warming ×2, Warming Sequence, main), Parse_TG ×2, постинг H3746/H3812 @institutsanskrita, monthly-пост, Content Harvester, «БОТ ТУКАН USERs» | n8n 2.27.5, docker | .91 | `n8n list:workflow`: 87 воркфлоу, TG-релевантных ~15 |
| 11 | Story publisher | автопубликация stories | прилегающая дорожка, не Bot API-бот | `TELEGRAM_STORY_PUBLISHER=true` | .env grep |
| 12 | **VK-бот** | тот же ИИ-куратор во ВКонтакте | VK API | `VK_BOT_TOKEN` задан | .env grep; [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md) §2 |

Каналы (не боты): @samskrte «ОПОВЕЩЕНИЯ САНСКРИТЯН» (посты марафона), @institutsanskrita (n8n-постинг расписания/waitlist).

## 3. Канарка — строки карты, опровергнутые/поправленные пробой

1. **`@webinar_17june_bot` «active» — ложь инвентаря.** Инвентарь (§3, 17-09): «7 строк, username-ы у части карточек», row 1 active с n8n forward. Проба: Telegram отвечает `404 Not Found` на getWebhookInfo — токен мёртв, бот-строка не работает, «webinar 17.06» давно прошёл. Строку карты исправлено: **сущность мертва при живом t.me-имени**.
2. **`@samskrte_bot` задвоен.** Инвентарь описывает его только как `MarketingSetting.tg_bot_*`. Проба mysql: он же — `landing_bots` строка 7 (active), зарегистрирован на **глобальный** magnet-вебхук вместо пер-лендингового `/{webhookKey}`. Та же анатомия, что у инцидента 22.07 (потерянный edge при переезде узла).
3. **Business-бота нет в проде.** Инвентарь §2.5 (H5065, 17-09) описывает env-полосу как существующую; проба .env: **ни одной** `TELEGRAM_BUSINESS_*` переменной — lane задокументирован, но ранбук не исполнен.

## 4. Бенчмарк: 10 edu-платформ (публичные источники, 18-09-2026)

| Платформа | TG-боты | Функции | Транспорт | Пруф |
|---|---|---|---|---|
| **Stepik** | 0 официальных; 1 официальный канал; community-many (52 репо — ДЗ студентов курсов Stepik о ботах) | канал = анонсы; уроков в боте нет | без ботов; REST API для третьих лиц | [t.me/stepik_courses](https://t.me/stepik_courses) · [api docs](https://stepik.org/api/docs/) · [GH-поиск](https://github.com/search?q=stepik+telegram+bot&type=repositories) |
| **Skyeng** | 1 бренд-бот `@skyeng_bot` | не верифицируемы (пустое описание) | Bot API (сам факт), пуши — своё приложение | [t.me/skyeng_bot](https://t.me/skyeng_bot) · [app](https://play.google.com/store/apps/details?id=skyeng.words.prod) |
| **Skillbox** | 0 учебных; 1 канал | лид-ворка: форма «Как с вами связаться? — В Telegram»; канал анонсов | канал + живые продажи | [skillbox.ru](https://skillbox.ru/) |
| **Нетология** | 0 ботов; 1 канал | анонсы; поддержка — чат сайта/почта | app push + чат-виджет | [netology.ru](https://netology.ru/) |
| **Учи.ру** | **NO-DATA** — сайт и help.uchi.ru отдают 403 анонимному fetch | — | — | [uchi.ru](https://uchi.ru/) (403) |
| **Lingualeo** | 1 официальный, **deprecated**: «Lingualeo переехал, ссылка в описании» | исторически — тренировка слов в чате; KB поддержки не содержит TG-раздела | ушёл в app-first | [t.me/lingualeo_bot](https://t.me/lingualeo_bot) · [support KB](https://support.lingualeo.com/) |
| **Puzzle English** | 1 официальный `@puzzle_english_bot` | рассылка/интенсивы — **не тренажёр** | Bot API, one-way broadcast | [t.me/puzzle_english_bot](https://t.me/puzzle_english_bot) |
| **Duolingo** | 0 официальных; third-party врапперы | digest слов/стрика через неофициальный API | unofficial API → TG Bot API | [duolingo_remember](https://github.com/yihong0618/duolingo_remember/blob/main/duolingo.py) · [DuolingoFree](https://github.com/r4hx/DuolingoFree/blob/main/backend/tasks.py) |
| **Quizlet** | 0 официальных; third-party (8 репо) | флеш-карточные боты, генераторы сеток **в** Quizlet | unofficial | [GH-поиск](https://github.com/search?q=quizlet+telegram+bot&type=repositories) · [quizletBot](https://github.com/psajd/quizletBot) |
| **Anki-экосистема** | 0 официальных; community-many | бот генерирует карточки в чате → аплоад в аккаунт Anki | community: TG Bot API + AnkiConnect/ankisrs sync | [ankigenbot](https://github.com/damaru2/ankigenbot) (91★) · [anki-connect](https://github.com/FooSoft/anki-connect); в [ankitects/anki](https://github.com/ankitects/anki) и [Anki-Android](https://github.com/ankidroid/Anki-Android) — 0 TG-кода |

**Главный факт бенчмарка:** ни одна из 10 платформ в 2026 не ведёт учебный цикл (уроки/ДЗ/тренажёр) в официальном TG-боте. Официальные TG-поверхности — маркетинговые каналы и лид-ворки. Единственный полноценный TG-native тренажёр (Lingualeo) ушёл в приложение. Anki-мосты — 100% community. Единый caveat: описания t.me волатильны (Lingualeo уже «переехал»), метрики чужих ботов недоступны.

## 5. Gaps: что есть у конкурентов, чего нет у нас (гипотеза 3)

| Функция | Рынок (бенчмарк) | У нас | Вывод-рекомендация |
|---|---|---|---|
| Тренажёр/ДЗ в боте | **нет ни у кого** (официального) | ИИ-куратор в @samskrtamru_bot — фактически **впереди рынка** | тренажёр остаётся в кабинете; бот-тренажёр не строить |
| Spaced repetition | только community Anki-мосты | нет | park: рынок говорит app-first; для санскрита осмысленно, но не ботом-первым |
| Payment-напоминания | в TG — ни у кого | есть: долги/рассрочки через `SendMessengerAlerts` (@samskrtamru_bot, VK) | держать как есть |
| Onboarding-цепочка | лид-ворки (Skillbox-форма, Puzzle English рассылка) | марафон-drip @samskrte_bot покрывает лид-онтр; студенческий onboarding — чат `TELEGRAM_ONBOARDING_CHAT_ID` + welcome бота | кандидат «welcome-digest студенту», низкий приоритет |
| Опросы/NPS | в TG — нет (у нас только VK Survey skeleton в n8n) | нет | park: нет свидетельств ценности |

## 6. Вердикты по сущностям (рекомендации — адъюдирует MG, [лист](https://gasyoun.github.io/vote/sheets/systema_bots_map_h5135.html))

| # | Сущность | Вердикт | Обоснование | Цена перехода |
|---|----------|---------|-------------|---------------|
| V1 | @samskrtamru_bot | **KEEP** | ядро студенческого канала: привязка, ИИ-куратор, самообслуживание кабинета (ON с 02-09), уведомления; ahead-of-market факт бенчмарка | 0 (residual уже стоит: возврат на вебхук, когда труба оживёт) |
| V2 | @samskrte_bot | **KEEP + dedupe** | лид-ворка живая; дубль в `landing_bots` row 7 с чужим вебхуком — тот же класс риска, что инцидент 22.07 | одна SQL-правка (убрать row 7 или переправить на её webhookKey), ~15 мин |
| V3 | @zapisi_ORSbot | **KEEP** | мультифункционален (брони, напоминания, welcome-карточки, roster), свежеукреплён H4314–H4318 | 0 |
| V4 | @testpodpiska12_bot | **SPLIT + RENAME (new)** | тест-имя в проде, .env-комментарий «замени на реальный» live; студент-критичный фолбэк смешан с ops-алертами (гипотезы 2 и 4 подтверждены пробой); рельс «bots get names» | создать @samskrte_ops_bot, ротация `TELEGRAM_BOT_*`, перерегистрация вебхука, кураторам нажать Start; ~1 ч ops, кода нет |
| V5 | @rusamskrtam | **KEEP** | двойная роль (MTProto-раннер + живой саппорт UX) задокументирована и работает; ответ-от-имени-аккаунта со временем уйдёт Business-боту (V8), но это отдельная полоса | 0 |
| V6 | @grokusaurus_bot | **KEEP** | не-LMS инструмент «Отдела заботы» на ПК МГ; отдельная полоса по рулингу 09-09 | 0 |
| V7 | landing_bots legacy (rows 1–6) | **AUDIT + KILL** | row 1: токен мёртв (404) при active=1 — ложь инвентаря; rows 2–6: без username, история «токен в БД есть» непрозрачна; вебинар 17.06 давно прошёл | SQL-ревизия + deactivate мёртвых, ~30 мин; правило вперёд: новый лендинг-бот всегда с именем |
| V8 | Telegram Business lane | **NEW (enable) или park — решение MG** | код и ранбук готовы (H5065), в проде 0 vars; снимает конфликт «бот vs личка» в аналитике; включение — один ранбук | исполнить RUNBOOK ~30 мин + проверка curator-аналитики; park = 0 |
| V9 | n8n webinar-семейство (.91) | **PRUNE дублей** | 6 вариантов Registration/Warming (skeleton/fixed/final) — накопление черновиков рядом с боевыми; 87 воркфлоу без ревизии | один проход по .91: deactivate скелетонов, ~30 мин, обратимо |
| V10 | Gaps: тренажёр/СR/NPS в боте | **PARK** | бенчмарк: рынок ушёл из TG-ботов в приложения; наш ИИ-куратор уже впереди; строить бот-тренажёр = против рынка | 0 (решение «не строить») |

## 7. NO-DATA и неизвестные

- Учи.ру — 403 на анонимный fetch: честный NO-DATA, не «ботов нет».
- Внутренние метрики чужих ботов недоступны; t.me-описания волатильны.
- n8n: состав 87 воркфлоу назван, но активность/куронность каждого (включая «БОТ ТУКАН USERs», «My workflow N») требует ревизии на .91 — в карту включены только TG-релевантные имена.
- rows 2–6 landing_bots: без username статус вебхука не проверить (нет токена наружу — и не надо: фенс).

## 8. Связанное

- Лист адъюдикации: <https://gasyoun.github.io/vote/sheets/systema_bots_map_h5135.html> (10 карт, ~5–7 мин).
- Хендоф: [H5135](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5135-OxAlpha_Systema-Sanscriticum_bots-architecture-research-benchmark_18.09.26.md).

_Гасунс_
