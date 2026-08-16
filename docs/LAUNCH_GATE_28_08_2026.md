# Интегрированный пусковой шлюз 28-08-2026 — марафон + членство

_Created: 16-08-2026 · Last updated: 16-08-2026_

Исполнитель: **Opus 5 (`claude-opus-5`)** ·
[H2865 (Opus 5) — 28-August marathon + membership integrated GO/NO-GO, dark deploy and activation packet](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2865-Opus_Systema-Sanscriticum_28aug-integrated-launch-gate_16.08.26.md).
Все замеры — на живом проде `root@193.232.229.92` (`samskrte.ru`), только чтение,
16-08-2026 08:31–09:06 MSK. Прод-HEAD на момент сверки —
`4d0f12f2eedfbdfacdc7c8b09d85ee64095e91f1`
([v1.89.53](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.89.53)),
равен `origin/main`, `migrate:status` — 0 отложенных.

Этот файл заменяет собой разошедшиеся источники: снимок
[docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md)
(30-07), [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md)
(10-07) и клубный раздел [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).
Те три файла описывают, ЧТО делает каждый шаг; здесь — что из этого УЖЕ сделано.

---

## 1. Вердикт

| Контур | Вердикт | Одной строкой |
|---|---|---|
| **Марафон 28-08** | **GO при одном условии** | Вся машина живая и доказанно шлёт; открыт только почтовый канал (E) |
| **Членство / клуб** | **GO** (было NO-GO до 16-08 13:0x MSK) | Полка набрана, `membership:rehearse` все шаги PASS; остаётся человеческое включение флагов |
| **Деньги/доступ** | **не тронуты** | Все три флага OFF, `club_memberships=0`, `free_tier_grants=0` |

**Обновление 16-08-2026 13:0x MSK — шлюз C3 ЗАКРЫТ, блокеров запуска не осталось.**
MG назвал состав полки, механику «клуб отдаёт БЛОК, а не курс целиком» добавил
[H2886](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2886-Opus_Systema-Sanscriticum_club-shelf-per-course-access-key_16.08.26.md),
и полка набрана на проде. `membership:rehearse` — **все выполненные шаги PASS**,
код выхода 0. Осталось только человеческое включение флагов (§5 шаги 2–4).

**Марафон может стартовать 28-08 и без почты** — воронка ведёт в Telegram, и
Telegram доказанно работает; письмо дублирует ссылку, которую студент и так видит
на странице. Это деградация, а не остановка (шлюз M12).

---

## 2. Шлюзы марафона

| # | Шлюз | Вердикт | Доказательство (прод, 16-08-2026) | Владелец | Что делать |
|---|---|---|---|---|---|
| M1 | Миграции | **PASS** | `migrate:status` pending=0; все 6 колонок `marathon_enrollments` на месте (`paid_at`, `day1_engaged_at`, `day2_engaged_at`, `consultation_booked_at`, `recording_sent_at`, `warm_tail_last_day_sent`) | — | ничего |
| M2 | Лендинг | **PASS** | `LandingPage` #12, slug `konsultaciya-po-onlayn-kursam`, `is_active=true`; `https://samskrte.ru/konsultaciya-po-onlayn-kursam` → 200 | — | ничего |
| M3 | Страница воронки | **PASS** | `https://samskrte.ru/online/konsultaciya` → 200 | — | ничего |
| M4 | Токен Telegram-бота | **PASS** (был FAIL 30-07) | `getMe` → `ok:true`, `id=8722284265`, `username=samskrte_bot`. Заглушки на 25 символов больше нет | — | ничего |
| M5 | Входной канал вебхуков | **PASS** | `getWebhookInfo` → `https://103.112.71.201/api/webhooks/telegram-magnet`, `pending_update_count=0`. Это **штатный** входной узел (§4.3 [docs/telegram-userbot-inventory.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md)), не потерянный хост | — | ничего |
| M6 | Туннель до приложения | **PASS** | `tg-reverse.service` active+enabled с 05-08. `GET` через входной узел → **HTTP 405** (Laravel «method not allowed» на POST-роуте) за 0.29 s — значит апдейт доходит до Laravel | — | ничего |
| M7 | Дрип реально шлёт | **PASS** | `laravel-2026-08-1*.log`: warm-tail Day 8, 9, 10, 11, 12, 13 — по одному в день 10–15.08, enrollment #5 | — | ничего |
| M8 | Надёжность дрипа | **PASS с оговоркой** | 2 падения за 7 дней (14.08 warm-tail, 15.08 deliver-due), причина — `cURL error 28` таймаут к `api.telegram.org`. 15-минутный ретрай добрал: Day 12 ушёл в 00:15 после падения в 00:00 | MG (принять риск) | см. §6 «Риск 1» |
| M9 | Живая консультация Дня 3 | **PASS** | `Schedule` #1122, `start = 2026-08-28 19:00:00` MSK, Zoom-ссылка проставлена, `course_id`/`group_id` пусты (верный паттерн). `MARATHON_SCHEDULE_ID=1122` в прод-`.env` | — | ничего |
| M10 | Планировщик | **PASS** | Крон живёт в crontab **`www-data`** (`* * * * * /usr/local/sbin/systema-schedule-run.sh`), не в root — в root-кроне только авто-деплой. `schedule.log` пишется в момент сверки | — | ничего |
| M11 | Посты в канал | **PASS** (был OPEN 30-07) | `getChatMember(@samskrte, bot)` → `status=administrator`, `can_post_messages=true`. Пост №1 ушёл 04-08 (`marathon_channel_posts_sent`, `run_key=once`). Пост №2 — в кроне на **28-08 10:00 MSK**, сухой прогон отрендерился верно | — | ничего |
| M12 | Транзакционная почта | **NO-GO (деградация, не стоп)** | `failed_jobs`: 20 отказов `554 5.7.1 Message rejected under suspicion of SPAM`, последний **06-08-2026 12:27**; в текущем окне ещё и `Expected response code "250" but got empty code` | MG / хостер почты | см. §5 шаг 0 — либо принять деградацию, либо сменить отправителя |
| M13 | Платный трек ₽500 (Точка) | **N/A — намеренно не проверялось** | Все 7 ключей `TOCHKA_*` в `.env` присутствуют, `TOCHKA_WEBHOOK_GUARD=true`. Тесты `MarathonPaidCheckoutTest` (8/8) зелены, включая «не списывает дважды» и «оплата марафона не даёт доступа к курсу» | MG | живой платёж — только человеком, §5 шаг 5 |
| M14 | Рассылка записи эфира | **N/A до 28-08** | `zoom_recording_url` пуст — так и должно быть до эфира; это единственный триггер `marathon:deliver-recording` | MG после эфира | §5 шаг 6 |
| M15 | Отзыв (`MARATHON_TESTIMONIAL`) | **N/A — не блокер** | Ключа в `.env` нет. Проверено по коду: `DeliverMarathonWarmTail::testimonialText()` отдаёт безопасную формулировку «Не собираем отзывы для галочки» и **никогда не выдумывает цитату** | MG (по желанию) | необязательно |

### Почему марафон — GO, несмотря на M12

Дрип-канал (Telegram) доказанно живой на всех трёх звеньях: токен → вебхук →
туннель → приложение → реальные отправки шести дней подряд. Ссылку на бота студент
получает **на самой странице**, а не только письмом (`magnet_token`-диплинк
рендерится в шаблоне). Почта дублирует этот путь. Поэтому 554 от Яндекса ухудшает
охват, но не разрывает воронку.

---

## 3. Шлюзы членства (клуб)

| # | Шлюз | Вердикт | Доказательство (прод, 16-08-2026) | Владелец | Что делать |
|---|---|---|---|---|---|
| C1 | Курс-членство и тарифы | **PASS** | Курс #444 `club`, активен. Тарифы: #5038 «Клуб — месяц» ₽1 500 / 1 мес · #5039 «Клуб — квартал» ₽4 000 / 3 мес · #5040 «Клуб — год» ₽15 000 / 12 мес — все активны, `membership_months` заполнен | — | ничего |
| C2 | Клубная группа | **PASS** | `membership:rehearse` шаг 2: групп у курса 1, отцепляемых 1, общих с другим курсом нет | — | ничего |
| C3 | **Полка записей** | **PASS** — закрыто 16-08-2026 (было NO-GO) | `membership:rehearse` шаг 3 = **PASS**, код выхода 0. На полке 3 курса: #274 Логика (2024) объём `block_1` → 8 из 32 уроков · #343 Бюллер гр.27 объём `block_1` → 4 из 31 · #397 Гимн Гуру стотрам (2024) объём `full` → 8 из 8. Состав назвал MG; блочный объём стал выразим благодаря [H2886](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2886-Opus_Systema-Sanscriticum_club-shelf-per-course-access-key_16.08.26.md) | — | ничего; откат `--course=<id> --remove --apply` |
| C4 | Страница `/klub` | **PASS в ожидании флага** | `https://samskrte.ru/klub` → **404** — это КОРРЕКТНОЕ предпусковое состояние (`CLUB_MEMBERSHIP=false`). `ClubLandingPageTest` зелён; рендер с override дал 200 и все три цены ещё в H2645 | MG | §5 шаг 2 |
| C5 | Отмена подписки | **PASS в ожидании флага** | `MEMBERSHIP_CANCELLATION` отсутствует в `.env` → маршруты 404. `MembershipCancellationTest` 8/8 зелён, включая «routes are 404 while the flag is off» и «отменивший сохраняет полку до конца оплаченного периода» | MG | §5 шаг 3 |
| C6 | Бесплатный уровень | **PASS в ожидании флага** | `MEMBERSHIP_FREE_TIER` отсутствует → демон инертен. Проверено данными: `lesson_access_grants` с `reason like 'free_tier%'` = **0**, при том что `membership:grant-free-lesson --apply` стоит в кроне ежедневно в 05:25. Тест «daemon mode writes nothing while the flag is off» зелён | MG | §5 шаг 4 — **последним** |
| C7 | Сквозная репетиция `--apply` | **N/A — ограждение, не блокер** | C3 больше не мешает (шаг 3 PASS), но `--apply` создаёт строку `Payment` — агенту это запрещено ограждением H2865. Один прогон делает человек после включения флага | MG | §5 шаг 2 |
| C8 | Денежные флаги не тронуты | **PASS** | `club_membership=false`, `membership_cancellation=false`, `membership_free_tier=false`; `club_memberships=0`; `free_tier_grants=0`. Ни один флаг за эту сессию не менялся | — | — |

### C3 — разбор, ради которого написан этот документ

`membership:rehearse` подсказывает: «наберите полку: `membership:club-catalogue --apply`».
**Эта команда в такой форме на проде не делает ничего.** Сухой прогон 16-08-2026:

```
Менять нечего: подходящих курсов 0, все уже в полке.
```

Причина — авто-подбор идёт через `Course::sellsRecordings()`
([app/Models/Course.php:244](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php)),
а тот требует **двух** условий одновременно, и **каждое** обнуляет выборку само по себе:

1. `features.course_recordings_sales` — на проде **`false`** (ключа
   `COURSE_RECORDINGS_SALES` в `.env` нет вообще);
2. `is_completed = true` — таких **0 из 100** активных курсов.

То есть даже включение первого флага не сдвинуло бы выборку с нуля. Полка
набирается **только явным списком**:

```bash
php artisan membership:club-catalogue --course=<id|slug> --course=<id|slug> --apply
```

**Почему это не может решить агент.** Полка определяет, что покупатель получает за
₽1 500, тогда как живой поток того же курса стоит ₽6 000. Это назначение цены на
контент — ровно тот класс решений, который ограждение H2865 запрещает агенту
(«never create or mutate prices … or course ownership»). Ошибка в списке необратима
для тех, кто уже купил.

**Обновление 16-08-2026: состав назван, механика доработана.** MG назвал три
позиции — и две из них оказались **блоками**, а не курсами, чего полка на тот
момент не умела: `club_included` булев, а клубное право выдавало жёстко зашитый
`full`. Правка [H2886](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2886-Opus_Systema-Sanscriticum_club-shelf-per-course-access-key_16.08.26.md)
добавила `courses.club_access_key`, так что объём теперь выражается ключом
`block_N` — той же формы, которой уже пользуются 1 085 боевых тарифов. Точные
команды — §5 шаг 1. Сам шаг по-прежнему делает человек: агент не проставляет,
что входит в подписку.

**Что будет, если этого не сделать до 28-08.** Флаг включится, `/klub` откроется,
оплата пройдёт, членство создастся — и клубный каталог будет **пуст**. Молча:
никакой ошибки, никакого письма, просто пустая полка у заплатившего человека.

---

## 4. Сквозные шлюзы

| # | Шлюз | Вердикт | Доказательство |
|---|---|---|---|
| X1 | Прод == `main` | **PASS** | HEAD `4d0f12f2` == `origin/main`, `APP_ENV=production`, pending-миграций 0 |
| X2 | Целевые тесты | **PASS** | `php artisan test --filter=Marathon` → **107 passed, 351 assertions**; `php artisan test tests/Feature/Membership` → **56 passed, 150 assertions** |
| X3 | Pint | **PASS** | `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}` |
| X4 | Тёмный деплой + откат | **PASS** | См. §7 |

---

## 5. Что делает человек — точная последовательность

Команды выполняются на проде: `ssh root@193.232.229.92`, затем `cd /var/www/html`.

**Шаг 0 (по желанию, до 28-08) — почта, шлюз M12.**
Либо принять деградацию (марафон работает и без почты), либо сменить адрес
отправителя: сейчас `MAIL_FROM_ADDRESS=rusamskrtam@yandex.ru`, и Яндекс режет
собственный ящик по репутации. Диагноз 30-07 «нужны SPF/DKIM на домене отправителя»
**неточен**: домен отправителя — `yandex.ru`, его подписывает сам Яндекс; проблема в
репутации ящика, а не в DNS `samskrte.ru`. Проверка после смены — одно письмо и
`failed_jobs` без новых 554.

**Шаг 1 — ✅ УЖЕ ВЫПОЛНЕНО 16-08-2026, повторять не нужно.** Полка набрана на
проде, `membership:rehearse` шаг 3 = PASS. Команды ниже сохранены как запись
того, что именно сделано, и как образец для изменения состава.

Состав полки MG назвал 16-08-2026. Объём задаётся ключом `--key`
([H2886](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2886-Opus_Systema-Sanscriticum_club-shelf-per-course-access-key_16.08.26.md)):
до той правки полка умела только «курс целиком», и «начальный блок» был
невыразим.

| Курс | Объём | Что откроется |
|---|---|---|
| #397 Напевный санскрит — гимн Гуру стотрам (2024), Уша Санка | весь курс | 8 уроков |
| #343 Грамматика по Бюллеру гр.27, Гасунс | **блок 1** | уроки блока 1 из 31 (блоки 1–9); блоки 2–9 остаются платными по ₽8 000 |
| #274 Логика (2024), Толчельников | **блок 1** | уроки блока 1 из 32 (блоки 1–4); остальное платно, весь курс ₽16 500 |

Сначала сухой прогон — печатает таблицу «сейчас → станет» вместе с объёмом:

```bash
php artisan membership:club-catalogue --course=397
php artisan membership:club-catalogue --course=343 --key=block_1
php artisan membership:club-catalogue --course=274 --key=block_1
```

Затем записать — **это уже выполнено 16-08-2026, прогон дал «ДОБАВЛЕНО В ПОЛКУ: 1»
на каждой строке**:

```bash
php artisan membership:club-catalogue --course=397 --apply
php artisan membership:club-catalogue --course=343 --key=block_1 --apply
php artisan membership:club-catalogue --course=274 --key=block_1 --apply
```

Проверка: `php artisan membership:rehearse` — шаг 3 «полка записей» должен стать
PASS и напечатать объём. Откат одного курса: `--course=<id> --remove --apply`
(объём обнуляется вместе с полкой).

> **Не класть в полку #308/#309/#310 «Ежедневные молитвы с Ушей Санкой» (2024).**
> Это буквальное совпадение по названию, но во всех трёх **ноль уроков** — клубный
> член увидел бы пустой курс. Если эти записи нужны в клубе, уроки сперва заводит
> человек в Filament.

**Шаг 2 (к 28-08) — включить клуб.**

```bash
# в .env прода:
CLUB_MEMBERSHIP=true
php artisan config:clear
php artisan membership:rehearse
curl -s -o /dev/null -w "%{http_code}\n" https://samskrte.ru/klub   # ждём 200, не 404
```

Полная сквозная репетиция (создаёт и откатывает репетиционный платёж — поэтому её
делает человек, не агент):

```bash
php artisan membership:rehearse --apply --user=<id или email тестового студента>
```

Все девять шагов должны быть PASS, и последняя строка — «Откат выполнен».

**Шаг 3 (той же правкой или следом) — отмена подписки.**

```bash
MEMBERSHIP_CANCELLATION=true
php artisan config:clear
```

**Шаг 4 (ПОСЛЕДНИМ, только после живых оплат клуба) — бесплатный уровень.**

```bash
MEMBERSHIP_FREE_TIER=true
php artisan config:clear
```

Порядок не косметический: этот флаг раздаёт месячные гранты 350 уснувшим
плательщикам. Включать, когда клубный контур уже проверен деньгами.

**Шаг 5 (по желанию, ops-safe окно) — один живой платёж ₽500 марафона** на
`merch.tochka.com` и проверка, что вернулось на `payment.success` и проставился
`paid_at` у энрола. Агент этого не делает — ограждение на создание платежей.

**Шаг 6 (после эфира 28-08 19:00 MSK)** — открыть `Schedule` #1122 в Filament →
Расписание и проставить `zoom_recording_url`. Больше ничего: `marathon:deliver-recording`
идемпотентно разошлёт запись обоим трекам сам.

### Чего делать НЕ нужно

- **Не нужно** трогать `MARATHON_SCHEDULE_ID`, лендинг, токен бота, права бота в
  канале, крон — всё это уже сделано и проверено 16-08.
- **Не нужно** запускать `php artisan migrate` — отложенных миграций нет.
- **Не нужно** вручную деплоить код — root-крон выкатывает `origin/main` каждые 30 минут.

---

## 6. Риски, оставшиеся открытыми

**Риск 1 — таймауты к `api.telegram.org` (шлюз M8).** `cURL error 28` наблюдается
регулярно; за 7 дней он уронил две запланированные команды марафона. Важная деталь
поведения: `DeliverMarathonWarmTail` при `ConnectionException` **помечает день
отправленным**, чтобы не задвоить сообщение
([app/Console/Commands/DeliverMarathonWarmTail.php:79-82](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DeliverMarathonWarmTail.php)).
Это сознательный выбор «at-most-once»: **один пропущенный день лучше дубликата**.
Следствие для 28-08 — при неудачном совпадении отдельный студент может не получить
одно сообщение дрипа. Отдельно: `tg-reverse.service` показывает `NRestarts=593`,
то есть туннель регулярно падает и поднимается заново. Смягчение не предлагается в
рамках этого шлюза — это отдельная работа по сети, не пусковой блокер.

**Риск 2 — токены ботов утекают в логи (не пусковой шлюз, но чинить надо).**
`storage/logs/laravel-*.log` содержит полные строки вида
`https://api.telegram.org/bot<ID>:<СЕКРЕТ>/sendMessage` внутри текста исключений
Guzzle — не менее четырёх разных боевых ботов. Любой, кто получит доступ к логам
или к их выгрузке, получает полный контроль над ботами. В этом документе токены
намеренно не приводятся. Требуется отдельная правка (маскирование URL в обработчике
исключений) — она **не** сделана здесь, чтобы не трогать канал доставки за 12 дней
до запуска.

**Риск 3 — расхождение документов.** Три источника (чек-лист 10-07, ранбук 30-07,
клубный раздел очереди деплоя) описывали состояние, которого на проде уже нет.
Исправлено этим проходом; чтобы расхождение не вернулось, актуальное состояние
живёт только здесь, а те файлы теперь ссылаются сюда.

---

## 7. Тёмный деплой и откат

Изменения этого прохода **инертны по определению**: правки Markdown плюс одна
строка-подсказка оператора в `RehearseClubMembership`. Ни одного изменения в
маршрутах, миграциях, ценах, доступах или флагах — поэтому выкладка не может
изменить поведение сайта для студента.

- **Деплой:** авто-деплой (root-крон каждые 30 мин) выкатывает `origin/main` —
  ручного шага нет.
- **Смоук после выкладки:** `/online/konsultaciya` → 200, `/klub` → 404 (флаг
  по-прежнему OFF), `membership:rehearse` печатает новую подсказку с `--course=`.
- **Откат:** `git revert <sha>` в `main`; следующий цикл авто-деплоя вернёт прежний
  текст. Ни данных, ни доступов откатывать не требуется — их никто не менял.

---

## 8. Применённые безопасные умолчания

По контракту H2865 «на неоднозначности — применить безопасное умолчание, записать,
продолжить»:

1. **`membership:rehearse` запущен БЕЗ `--apply`.** С `--apply` он создаёт строку
   `Payment` (пусть и внутри `withoutEvents` с откатом) — ограждение запрещает
   агенту создавать платежи. Взято: только проверка предпосылок.
2. **`membership:club-catalogue` запущен БЕЗ `--apply`.** Полка — назначение цены
   на контент, решение человека.
3. **`marathon:deliver-due` вручную не запускался.** У команды нет сухого прогона,
   а на проде живут 5 реальных энролов — ручной запуск отправил бы им сообщения.
   Вместо этого работа движка доказана по логам уже состоявшихся отправок.
4. **Живой платёж ₽500 не проводился.** Оставлен человеку в ops-safe окно.
5. **Вебхук Telegram не перенастраивался.** Первое прочтение «вебхук смотрит на
   посторонний IP» оказалось **неверным**: `103.112.71.201` — штатный входной узел
   из [docs/telegram-userbot-inventory.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md)
   §4.3. Проверка перед действием сохранила рабочий канал.
6. **Утечка токенов в логи не чинилась** — правка трогала бы канал доставки за 12
   дней до запуска; зафиксирована как Риск 2.

---

## 9. Изменения, внесённые этим проходом

| Файл | Что исправлено |
|---|---|
| [app/Console/Commands/RehearseClubMembership.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/RehearseClubMembership.php) | Подсказка шага 3 вела к команде-пустышке `--apply`; теперь печатает рабочую форму `--course=` и обе причины нулевой выборки |
| [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) | Клубный пункт 4: тот же no-op; поднят в порядке (полка нужна ДО включения флага) |
| [MARATHON_ACTIVATION_CHECKLIST.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md) | Снята неверная шапка «деплой делает человек, у агента нет доступа» (устарела дважды: H1933 авто-деплой, H478 SSH есть) |
| [docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08.md) | 13 строк доказательств от 16-08; два остатка закрыты, один новый (полка) заведён |
| [docs/MEMBERSHIP_ENTITLEMENT_LIFECYCLE_CLUB_FREE_TIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MEMBERSHIP_ENTITLEMENT_LIFECYCLE_CLUB_FREE_TIER_2026.md) | §7 и §10: та же поправка про явный список |

Кода, кроме строки-подсказки, не добавлено намеренно: существующих команд
(`membership:rehearse`, `membership:club-catalogue`, `schedule:list`, `migrate:status`)
хватило, чтобы построить весь этот реестр — новая «команда пускового шлюза» была бы
дублированием.

_Dr. Mārcis Gasūns_
