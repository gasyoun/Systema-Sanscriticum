# Очередь деплоя — для Ивана

_Создано: 08-07-2026 · Обновлено: 11-07-2026_

Всё, что **влито в `main`, но еще не выкачено на прод**, с точными командами для
сервера. Прод — **root-VPS (Ubuntu, Beget)**; деплой делает человек на сервере (**у агента нет доступа** — это ограничение прав, а не хостинга). Обычный путь — `sudo bash deploy.sh`. _Исправлено 10-07-2026 (H478): прежняя формулировка «по FTP, без SSH» неверна — SSH/root есть._
Это список-передача для Ивана: что и в каком порядке запустить.

**Обозначения**
- 🚀 **Приоритет** — после этого деплоя можно запускать проверку/сверку на реальных данных или следующий этап. Делать в первую очередь.
- ⚙️ флаг — нужна правка `.env` **и** согласование финдира перед включением (влияет на деньги).
- ⏱ планировщик — нужна запись в cron/планировщике сервера, а не разовая команда.

> Systema — одно Laravel-приложение: **одна команда `php artisan migrate` применяет
> все накопленные миграции сразу**. Запускается один раз, затем — шаги `.env` / backfill
> / планировщик ниже.
>
> После любой правки `.env`, если конфиг закэширован, сбросить кэш:
> `php artisan config:clear` (иначе флаги не подхватятся).

---

## Systema-Sanscriticum (samskrte.ru) — финансы

Весь план A→C→B→D + реверс возврата влит; остались только шаги на проде.

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 1 | **Все миграции** | `php artisan migrate` | миграция | 🚀 базовый шаг для №2–№9 — без него ничего ниже не заработает |
| 2 | **Признание выручки по начислению** ([PR #370](https://github.com/gasyoun/Systema-Sanscriticum/pull/370)) | после migrate: `php artisan revenue:backfill-schedule` → сверить вкладку «ОПиУ (начисление)» на 1 реальном годовом курсе | backfill | 🚀 реальный ОПиУ по начислению + сверка отложенной выручки |
| 3 | **Реверс остатка при возврате** ([PR #376](https://github.com/gasyoun/Systema-Sanscriticum/pull/376)) | когда финдир готов: `REVENUE_REVERSE_UNRECOGNIZED_ON_REFUND=true` в `.env` → `php artisan revenue:backfill-schedule` → сверить на 1 реальном возврате | ⚙️ флаг + backfill | обработка возвратов в выручке; **флаг по умолчанию OFF — до включения ничего не меняется** |
| 4 | **Контроль дебиторки** ([PR #365](https://github.com/gasyoun/Systema-Sanscriticum/pull/365)) | проверить пороги `RECEIVABLES_*` в `.env` (3 значения, финдир) → убедиться, что `receivables:check` идет ежедневно в планировщике → сверить на 1 реальной когорте | ⚙️ флаг + ⏱ | 🚀 настройка порогов на реальной дебиторке |
| 5 | **Фонды прибыли + KPI** ([PR #373](https://github.com/gasyoun/Systema-Sanscriticum/pull/373)) | по желанию: настроить доли/окно в `config/profit_funds.php` через `.env` → убедиться, что `finance:kpi-digest` идет по понедельникам 09:00 в планировщике | ⏱ | недельный KPI-дайджест финдиру |

## Systema-Sanscriticum — SEO

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 6 | **SEO P2 Wave-1 — индексация** ([PR #374](https://github.com/gasyoun/Systema-Sanscriticum/pull/374)) | выкатить текущий `main` → включить `index_enabled` по списку «curated core» → отправить URL-слов в Яндекс.Вебмастер | флаг + внешнее | 🚀 замер индексации Wave-1 (нужны живые страницы + обход) |

## Systema-Sanscriticum — прочее (словарь, операционка)

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 7 | **Корпусная Sa→Ru глосса на `/slovar`** ([PR #372](https://github.com/gasyoun/Systema-Sanscriticum/pull/372)) | по желанию: `SLOVAR_ENRICHMENT=true` в `.env` → `php artisan config:clear` | флаг (дисплей, без денег) | обогащение страниц `/slovar/{slug}` глоссой; **по умолчанию OFF — до включения `/slovar` как раньше** |
| 8 | **Набор в группы — уведомления о недоборе** ([PR #386](https://github.com/gasyoun/Systema-Sanscriticum/pull/386)) | входит в общий `php artisan migrate` (№1); джоба `groups:notify-forming-shortfall` идет ежедневно через `schedule:run` | ⏱ | авто-уведомления о недоборе в формирующиеся группы |
| 9 | **Чек-ины по целям делегирования** ([PR #380](https://github.com/gasyoun/Systema-Sanscriticum/pull/380)) | входит в общий `php artisan migrate` (№1); джоба `goals:record-checkins` — пн 09:15 через `schedule:run` | ⏱ | ритм чек-инов по целям делегированных лидов |
| 11 | **«Оплачено до» + дедлайн след. платежа на дашборде и в напоминаниях** ([PR #393](https://github.com/gasyoun/Systema-Sanscriticum/pull/393)) | миграций/флагов нет — входит в обычный `./deploy.sh`; после деплоя сверить карточку «Оплачено до: блок №N» + «Следующий платеж до: DD.MM.YYYY, 00:00 (МСК)» на кабинете студента с реальной оплатой; если `debt_reminder_text` в Marketing Settings кастомный (не пустой) — вручную добавить в него `{paid_until}`/`{deadline}`, иначе новые плейсхолдеры в письмо/Telegram не попадут (свой шаблон не подхватывает изменение DEFAULT_TEXT автоматически) | код (без миграции/флага) | позитивная сводка «оплачено» вместо только-долга + дедлайн следующего платежа на дашборде и в авто-напоминаниях `debts:remind` |
| 12 | **3-дневный диагностический марафон — Phase 1: лендинг + захват** ([PR #407](https://github.com/gasyoun/Systema-Sanscriticum/pull/407), MERGED) | входит в общий `php artisan migrate` (№1); **дополнительно вручную**: создать через Filament-админку `LandingPage` со слагом из `MARATHON_LANDING_SLUG` (дефолт `konsultaciya-po-onlayn-kursam`) — без этой строки `/online/konsultaciya` рендерится (не 500), но без брендинга лендинга; страница уже рабочая и без нее. Первый запуск когорты — 28-08-2026, деплоить можно заранее | миграция + ручной шаг (контент) | вход воронки «Консультация по онлайн-курсам ОРС» (H440 Phase 1) |
| 13 | **Марафон — Phase 2: дрип-движок Day 1/2 через Telegram** ([PR #410](https://github.com/gasyoun/Systema-Sanscriticum/pull/410), MERGED; [PR #411](https://github.com/gasyoun/Systema-Sanscriticum/pull/411) Pint-стиль, auto-merge, ждет ревью MG) | входит в общий `php artisan migrate` — миграций нет в этом слайсе; планировщик `marathon:deliver-due` (каждые 15 мин) уже прописан в `Kernel::schedule()`, сработает сам после деплоя, если серверный cron вызывает `schedule:run` (см. примечание к №4/5/8/9 выше); **дополнительно вручную**: в `MarketingSetting` (Filament) должны быть заполнены `tg_bot_username`/`tg_bot_token` для бота `@samskrte` — без них `magnet_token`-диплинк в письме/на странице ведет на несуществующего бота | код + планировщик, миграций нет | Day 1/Day 2 контент по личному дню энрола; **Phases 3b/5/6 (интерактивный тап-чойс UI, живая консультация, теплый хвост) — отдельные будущие PR, еще не влиты** |
| 14 | **Марафон — Phase 4: платный трек «с проверкой» ₽500 checkout** ([PR #415](https://github.com/gasyoun/Systema-Sanscriticum/pull/415), MERGED) | входит в общий `php artisan migrate` (аддитивная `marathon_enrollments.paid_at`); Точка (Tochka) должна быть настроена (`TOCHKA_API_TOKEN`/`TOCHKA_CUSTOMER_CODE` и т.д. в `.env`) — тот же гейтвей, что уже используют курсы/депозит/пробное; без него кнопка «Оплатить» отдаст «Сервис оплаты временно недоступен» | миграция + код, использует существующий Tochka-гейтвей | ₽500-чекаут для трека «с проверкой»; **промокод ₽1000 на первый курс и зачет ₽500 во флагман сознательно НЕ построены в этом слайсе** — отдельные будущие handoff'ы (см. H471 «Why this scope») |
| 15 | **Марафон — Phase 3b: тап-выбор Day 1/2 + сбор вопроса к консультации** ([PR #421](https://github.com/gasyoun/Systema-Sanscriticum/pull/421), MERGED) | входит в общий `php artisan migrate` (аддитивные `marathon_enrollments.day1_engaged_at`/`day2_engaged_at`); миграций/флагов больше нет — код | миграция + код | Day 1/2 — реальные тап-выбор страницы (не самоответный текст); `day2_question` теперь реально собирается и заполняет ранее пустовавшее поле |
| 16 | **Марафон — Phase 5: живая консультация Дня 3 + запись** (H487, PR TBD) | входит в общий `php artisan migrate` (аддитивная `marathon_enrollments.recording_sent_at`); планировщик `marathon:deliver-recording` уже прописан в `Kernel::schedule()` (каждые 15 мин); **обязательный ручной шаг — иначе Day 3 молча не отправится**: MG создает `Schedule` через существующую Filament-админку (`Расписание`, без привязки к курсу/группе) с датой + Zoom-ссылкой, затем `MARATHON_SCHEDULE_ID=<id>` в `.env` → `php artisan config:clear`; после эфира MG проставляет `zoom_recording_url` на той же записи `Schedule` — рассылка записи включится сама | миграция + код + Zoom создается вручную (см. `ZoomService`, автосоздание встреч отключено) | Day 3 — платный трек получает Zoom-ссылку, бесплатный — обещание записи; `marathon:deliver-recording` рассылает запись обоим трекам после эфира; страница «Вопросы марафона (День 3)» в админке (только чтение) показывает `day2_question` по треку |

| 17 | **Марафон — Phase 6: теплый хвост 13 дней (Дни 4-16)** (H440, PR TBD) | входит в общий `php artisan migrate` (аддитивная `marathon_enrollments.warm_tail_last_day_sent`); планировщик `marathon:deliver-warm-tail` уже прописан в `Kernel::schedule()` (каждые 15 мин), сработает сам после деплоя (тот же серверный cron, что №13/№16); флагов/`.env`-шагов нет — контент полностью в `config/marathon.php` | миграция + код, миграций/флагов нет | evergreen-серия неоплатившим энролам на Дни 4-16 (own-pace/рассрочка/преподаватель/один отзыв, без срочности); авто-останавливается по оплате (`paid_at`) — H440 весь 6-фазный план закрыт этим PR |

| 18 | **Живой веб-чат поддержки — Reverb-транспорт** (H536 Phases 1–3: [PR #432](https://github.com/gasyoun/Systema-Sanscriticum/pull/432)/[#461](https://github.com/gasyoun/Systema-Sanscriticum/pull/461)/[#463](https://github.com/gasyoun/Systema-Sanscriticum/pull/463), MERGED) | входит в общий `php artisan migrate` (аддитивные: `chat_messages.user_id`→nullable, `support_conversations.guest_token`/`guest_name`). **Чтобы включить live-push нужен ОТДЕЛЬНЫЙ шаг (Reverb-сервер):** (1) запустить постоянный WS-процесс `php artisan reverb:start` под supervisor (на боксе supervisor уже есть — рядом с Horizon); (2) reverse-proxy nginx на его порт (дефолт 8080) для `wss://` на samskrte.ru; (3) в `.env`: `BROADCAST_DRIVER=reverb` + `REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET`/`REVERB_HOST`/`REVERB_PORT` + `VITE_REVERB_APP_KEY`/`VITE_REVERB_HOST`/`VITE_REVERB_PORT`/`VITE_REVERB_SCHEME=https`; (4) `npm run build` (Vite подхватит `VITE_REVERB_*`) + `php artisan config:clear` | миграция + постоянный WS-процесс + reverse-proxy + `.env` + Vite-rebuild | 🚀 единственная внешняя зависимость до go-live виджета; **до этого шага виджет работает POST-only (без живого push) — ничего не ломается**, `BROADCAST_DRIVER` остаётся `null`. Визуальный пузырь + live-inbox (Phases 4–5) — ещё не влиты (H612) |

**Про планировщик (№4, №5, №8, №9):** команды `receivables:check` (ежедневно),
`finance:kpi-digest` (пн 09:00), `groups:notify-forming-shortfall` (ежедневно) и
`goals:record-checkins` (пн 09:15) уже прописаны в расписании приложения, но срабатывают
только если серверный cron раз в минуту вызывает `php artisan schedule:run`. Проверить,
что эта запись cron есть, — тогда все четыре запустятся сами после деплоя.

---

## Telegram — вопросы и действия оператору (масштабирование, Phase 0)

Контекст: ты (Иван) запустил userbot на сервере — импорт чата «Отдел заботы» работает
(MTProto-сессия + очередь). План масштабирования — [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md).
Прежде чем масштабировать, нужно закрыть одну развязку — вопрос T1 ниже.

| № | Вопрос / действие | Что нужно от Ивана | Зачем |
|---|---|---|---|
| T1 | **Единый раннер MTProto-синка.** | Ответь: (1) твой cron/supervisor запускает `php artisan telegram-support:sync` (команду Laravel) или **отдельный** самостоятельный MadelineProto-скрипт? (2) Стоит ли `TELEGRAM_SUPPORT_ENABLED=true` в `.env` — при нём планировщик Laravel сам запускает `telegram-support:sync` **каждую минуту** (см. `app/Console/Kernel.php`). **Решение MG:** канонический раннер — **твой cron запускает именно Laravel-команду `telegram-support:sync`**; отдельный самостоятельный скрипт держать не нужно. | Если ОДНОВРЕМЕННО работают твой отдельный скрипт **и** планировщик Laravel на одной сессии → Telegram выдаёт `AUTH_RESTART` (разлогин, нужен повторный вход с кодом). Развязка разблокирует масштабирование. |
| T2 | **Харвестер санскрит-групп и саппорт-синк — одна сессия.** | НИКОГДА не запускать `telegram-harvest:sync` одновременно с `telegram-support:sync` — они делят одну MTProto-сессию. | Защита живой сессии от `AUTH_RESTART`. |
| T3 | **Секрет вебхука — код на `main`, нужен только твой шаг.** [`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php)/[`VerifyVkBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyVkBotWebhook.php) уже fail-closed и задеплоены (H590, узел P0.2). 1) Сгенерировать секреты (`openssl rand -hex 32`, по одному на TG/VK) и задать в `.env`: `TELEGRAM_BOT_WEBHOOK_SECRET=...` + `VK_CALLBACK_SECRET=...` → `php artisan config:clear`. 2) **Telegram**: `curl "https://api.telegram.org/bot<STUDENT_TELEGRAM_BOT_TOKEN>/setWebhook?url=https://<домен>/api/telegram/webhook&secret_token=<TELEGRAM_BOT_WEBHOOK_SECRET>"`. 3) **VK**: тот же секрет — в настройках группы → «Работа с API» → Callback API → «Секретный ключ». Между шагом 1 и шагами 2–3 вебхуки на проде отвечают 403 (TG/VK ретраят доставку, окно в пару минут безболезненно) — не затягивать. Подробный чек-лист (тот же фикс, независимо описан 02-07-2026): [`docs/deploy-checklist-audit-fixes.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy-checklist-audit-fixes.md). | Защита кабинетного вебхука перед ростом трафика (P0.2 узел [Telegram-scaling implementation map](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)). |

> Ответы на T1 можно просто написать MG — он передаст в план. Пункт уходит из списка,
> когда развязка закрыта.

---

## ORS-FAQ (воронка курса) — другой репозиторий

| № | Что | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 10 | **Инструментация LTV-бота** | запустить бота (сейчас не запущен) — чтобы триггер лесенки и лог показов давали реальные события | запуск бота | 🚀 реальный причинный сигнал LTV (код готов на синтетике, не хватает только живого бота) |

---

_Публичная копия для Ивана (Systema/ORS-FAQ — публичные репозитории). Внутренний
источник — приватный `Uprava/GTD_NEXT_ACTIONS.md`. Файл пересобирается автоматически
каждый день в 08:00. Пункт уходит из списка, когда MG подтверждает, что деплой сделан._

_Dr. Mārcis Gasūns_
