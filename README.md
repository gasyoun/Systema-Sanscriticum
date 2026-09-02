# Systema Sanscriticum — платформа онлайн-обучения санскриту

_Created: 13-02-2026 · Last updated: 02-09-2026_

Laravel-приложение для школы санскрита: учебный кабинет со словарем, домашними
заданиями и интервальными повторениями (SRS), магазин курсов с гибкими тарифами,
воронки привлечения (диагностический марафон, бесплатные игры-упражнения),
конструктор лендингов, редактор лекций, лояльная валюта «прана» и панель
администратора.

Это не абстрактная LMS, а специализированная среда: учебный контент завязан на
санскритскую лексику (деванагари / IAST / кириллица), проверку домашних заданий
куратором и выдачу верифицируемых сертификатов. Ниже — сначала то, что платформа
дает студенту, куратору и преподавателю; техническая часть (стек, установка,
доменная модель) идет следом.

---

## Оглавление

- [Санскритская педагогика](#санскритская-педагогика)
- [Воронки привлечения](#воронки-привлечения)
- [Сценарии использования](#сценарии-использования)
- [Стек](#стек)
- [Быстрый старт](#быстрый-старт)
- [Архитектура и доменная модель](#архитектура-и-доменная-модель)
- [Модули](#модули)
- [Интеграции и вебхуки](#интеграции-и-вебхуки)
- [Роадмап](#роадмап)
- [Прод: uptime / мониторинг](#прод-uptime--мониторинг)
- [Как этот репозиторий связан с остальными](#-как-этот-репозиторий-связан-с-остальными)

---

## Санскритская педагогика

То, что делает платформу именно школой санскрита, а не универсальной LMS.

### Словарь (вкладка в кабинете `/dvaram`)

Многословарная лексическая база. Каждое слово (`DictionaryWord`) хранится в
четырех представлениях, и поиск идет сразу по всем:

| Поле | Назначение |
|---|---|
| `devanagari` | санскритский шрифт (देवनागरी) |
| `iast` | научная латинская транслитерация (IAST) |
| `cyrillic` | кириллическое чтение |
| `translation` | перевод / значение |
| `page` | ссылка на страницу/источник |

Несколько словарей (`Dictionary`, флаг `is_active`) можно фильтровать по
выпадающему списку. Реализация — Livewire-компонент
[StudentDictionary.php](app/Livewire/StudentDictionary.php) с пагинацией и
жадной загрузкой (`with('dictionary')`), чтобы избежать N+1. Поиск на MySQL
идет через составной `FULLTEXT`-индекс (`MATCH … AGAINST`, boolean-prefix) по
всем четырем полям, а не через `LIKE '%…%'` со сканом таблицы; для запросов
короче трех символов и на SQLite (тесты) действует подстрочный LIKE-фолбэк.

Наполнение словаря — импортом CSV в админке через
[DictionaryWordImporter.php](app/Filament/Imports/DictionaryWordImporter.php)
(колонки: деванагари, IAST, кириллица, перевод, страница).

### Транслитерация (`/sanskritorium`)

Публичный конвертер IAST → деванагари + SLP1 на vendored
`resources/js/vendor/sanskrit-util.js`. Тот же движок, что у флаг-гейта
`/transliterate` (H1463); второй транскодер не заводится. Путь композиции:
`to_slp1` → `slp1_to_devanagari` (`iast_to_devanagari` сломан и не вызывается).

### Интервальные повторения — «Anki для санскрита» (`/koloda`, `/dvaram/koloda`)

Нативный SRS-тренажер (Saraswati trainer suite) с планировщиком FSRS: студент
повторяет карточки (лексика санскрита и хинди) с интервалами, которые движок
подбирает по истории ответов, с дневным лимитом новых карточек на колоду. Есть
страница статистики (`/dvaram/koloda/stats`).

| URL | Кто |
|---|---|
| [`/koloda`](https://samskrte.ru/koloda) | публичный каталог колод (проба без логина) |
| `/koloda/{slug}` | публичная колода |
| `/dvaram/koloda` | кабинет (прогресс сохраняется) |

Старые `/srs` и `/dvaram/srs` отдают **301** на `koloda`.

Фича за флагом `SRS_ENABLED` ([config/srs.php](config/srs.php)) — по умолчанию
**включена**; `SRS_ENABLED=false` гасит маршруты и пункт меню. Файлы:
[SrsController.php](app/Http/Controllers/SrsController.php).

### Домашние задания с проверкой куратором

`HomeworkSubmission` привязана к уроку и курсу и проходит цикл рецензирования:

```
draft → submitted → (needs_revision → submitted)* → accepted
```

- студент может редактировать/досдавать, пока статус `draft` или `needs_revision`;
- куратор-рецензент (`reviewed_by`) меняет статус и оставляет `HomeworkComment`;
- файлы — `HomeworkFile`, скачивание через защищенный маршрут.

Файлы: [HomeworkSubmission.php](app/Models/HomeworkSubmission.php),
[HomeworkController.php](app/Http/Controllers/HomeworkController.php).

### Сертификаты

По завершении курса генерируется PDF-сертификат (DomPDF, `CertificateService`).
Сертификат **верифицируем публично** — маршрут `certificate.verify` подтверждает
подлинность по ID. Доступны выгрузки в PDF и JPG; массовую генерацию делает job
`GenerateCertificatesArchive` (кладет архивы в `archives/`).

### Прана — лояльность и геймификация (два счетчика)

Прана начисляется за учебную активность ([config/prana.php](config/prana.php)) и
хранится в **двух независимых счетчиках** на пользователе:

| Счетчик | Назначение | Поведение |
|---|---|---|
| `prana_balance` | скидочный кошелек | тратится при покупке, может убывать |
| `lifetime_prana` | накопительный ранг | растет от начислений, **тратами не убывает** |

- **Скидка:** **10 праны = 1 ₽**, праной можно покрыть **не более 30 %** итоговой
  цены курса;
- **Начисления** (растят оба счетчика): завершен урок (+10), курс пройден (+500),
  просмотр открытого урока (+20), ежедневный вход (+5), покупка курса (+50),
  приглашенный друг оплатил (+100);
- **Ранги** по `lifetime_prana`: Śiṣya → Adhyāyin → Snātaka → Ācārya → Paṇḍita
  (`config('prana.ranks')`, `PranaSettings::rankFor()`, `User::pranaRank()`);
- **P2P-перевод** («подарить прану», `POST /dvaram/prana/transfer`,
  `PranaService::transfer()`) — только из `prana_balance`, с дневными лимитами;
  ранг ни у кого не растет;
- **Распад** (`prana:decay`) сжигает % `balance` у неактивных N+ дней — `lifetime`
  не трогает, по умолчанию **выключен**, повешен в расписание на еженедельный
  ночной прогон (включается через `PRANA_DECAY_ENABLED`);
- учет — `PranaService` / `PranaTransaction`, настройки в админке.

> **ℹ️ Историческое противоречие снято.** Раньше «скидочная прана» в проде
> конфликтовала с геймификацией из [PRANA_ROADMAP.md](PRANA_ROADMAP.md) (ранги,
> `lifetime_prana`, распад, P2P). Две концепции были несовместимы **только при
> одном счетчике**. С разделением на `prana_balance` (деньги-скидка) и
> `lifetime_prana` (статус-ранг) они сосуществуют: ранг — это статус, а не валюта.

---

## Воронки привлечения

Верх воронки — как незнакомый посетитель превращается в лида, а лид в оплату.

### Диагностический марафон (`/online/konsultaciya`)

3-дневный марафон «Консультация по онлайн-курсам ОРС»: лендинг с захватом лида,
входной квиз-диагностика уровня (`marathon.level-quiz`), выдача контента по дням
через Telegram (`marathon:deliver-due`, доставка записей и «теплого хвоста» после
марафона). Есть бесплатный трек и платный tripwire-трек «с проверкой»
(`MARATHON_PAID_TRACK_PRICE`, дефолт 500 ₽). Длина прогрева после марафона —
`MARATHON_WARM_TAIL_DAYS` (медиана времени до покупки из custdev).

Визуальное направление лендинга переключается независимо от контента (H1975):
`show.blade.php` — тонкая оболочка, отдающая рендер одному из
`resources/views/marathon/skins/{a,b,c,d}/content.blade.php` через
`App\Support\MarathonVisual` (`MARATHON_LANDING_VISUAL_VARIANT`, дефолт `b`, QA-оверрайд
`?skin=`). На 31-07-2026 готовы B (light island, дефолт), A (dark-native, H1976),
C (warm paper, H1977); D (stepped, H1978) — в очереди.

Файлы: [MarathonController.php](app/Http/Controllers/MarathonController.php),
[config/marathon.php](config/marathon.php),
[DeliverDueMarathonContent.php](app/Console/Commands/DeliverDueMarathonContent.php).

### Бесплатные игры-упражнения (`/lila/`)

Статические браузерные тренажёры по санскриту под
[`public/lila/`](public/lila/) — index-каталог + семейства движков
(без сборки, offline/iframe-safe). Живой каталог на проде:
[samskrte.ru/lila/](https://samskrte.ru/lila/).

| Семейство | Движок | Примеры |
|---|---|---|
| **Сортировки** | `sort/` | роды; существительное↔местоимение; спряжение по лицу/числу |
| **Пары** | `match/` | корень↔форма; RU↔SA предложения |
| **Пропуски** | `cloze/` | глагол в контексте; указательные; вопросительный аккузатив |
| **Таблицы** | `table/` (H1710) | парадигма спряжения; номинатив м.р. на -i |
| **Лигатуры / корни** | match + data.js | top-10/50/200 лигатур; top-25/50/100 корней по DCS |

Мягкий воронковый гейт: аноним проходит **одну** игру бесплатно, дальше — ненавязчивое
предложение «зарегистрируйте бесплатный кабинет, чтобы продолжить»; **залогиненные
студенты не гейтятся** и видят все тренажёры. Состояние — в `localStorage` (nudge, не DRM),
авторизация читается пробой `/api/games/auth`. Гейт:
[public/lila/gate.js](public/lila/gate.js). Порт с LearningApps:
[`/learningapps-port`](https://github.com/gasyoun/claude-config/blob/main/commands/learningapps-port.md),
хелпер [`scripts/decode_learningapps.py`](scripts/decode_learningapps.py).
Карта для куратора/студента:
[`docs/student-manual.md`](docs/student-manual.md) §12.

---

## Сценарии использования

Сквозные потоки по ролям — что система делает «от и до». Каждый сценарий ссылается
на реальные модули/модели, которые его реализуют.

### Студент

1. **Покупка курса → автодоступ.** Студент выбирает тариф в магазине
   ([`/online`](app/Http/Controllers/ShopController.php)), `Tariff::calculateFinalPriceForUser()`
   считает итоговую цену (лояльность/персональная скидка, зачет депозита,
   апгрейд-кредит, оплата праной). Оплата уходит в Точку Банк → вебхук
   `/api/webhooks/tochka` → `PaymentObserver::grantAccess()` добавляет студента в
   нужную `Group`. **Ручного назначения групп нет** — доступ открывается фактом оплаты.
2. **Покупка блока «половинами».** Дорогой блок можно взять за два захода:
   `block_N_h1`, затем `block_N_h2`. При докупке целого блока уже оплаченная
   половина зачитывается (`Tariff::upgradeCreditForUser()`), а две половины в сумме
   = полная цена блока.
3. **Учеба в кабинете.** На [`/dvaram`](app/Http/Controllers/StudentController.php) —
   онбординг-чеклист «С чего начать», карточки курсов с подсказкой «следующий урок»,
   плеер урока с heartbeat-трекингом времени, словарь (деванагари/IAST/кириллица),
   сдача ДЗ куратору, прана-таб (ранг, streak, лидерборд, бейджи, магазин праны).
4. **Сертификат по завершении.** PDF-сертификат с публично проверяемым ID
   (`CertificateService`, маршрут `certificate.verify`).
5. **Приглашение друга.** Реферальная ссылка `?ref=…` → при первой оплате
   приглашенного реферер получает денежный кредит (`users.referral_credit`),
   который авто-зачитывается в его следующую покупку.

### Куратор

1. **Очередь проверки ДЗ.** В админке — фильтр по уроку/курсу, сортировка «дольше
   всех ждет», bulk «Принять» / «Вернуть на доработку», шаблоны комментариев
   (`HomeworkService::recordReview`). Вердикт уходит студенту пушем в Telegram/VK.
2. **Карточка прогресса студента.** В `UserResource` — % пройденных уроков по курсам,
   сводка ДЗ, активность, прана — в одном месте.
3. **Сигналы «застрявших».** Виджет `StuckStudentsWidget`: неактивен 14+ дней или ДЗ
   на доработке 7+ дней без пересдачи → точечная сегментная рассылка в TG/VK.
4. **Веб-чат поддержки.** Студент пишет из кабинета (вкладка «Поддержка», Livewire
   `StudentChat`) → ИИ-автоответ (тот же «мозг», что и мессенджер-бот) → «позови
   куратора» переводит диалог на человека.

### Преподаватель

1. **Вебинар на Zoom.** Из `ScheduleResource` экшен «Создать Zoom-встречу»
   (Server-to-Server OAuth) → `join_url` зеркалится в `Schedule.link`, кнопка
   «Подключиться» в кабинете студента работает без правок. Запись (`recording.completed`)
   и посещаемость (`participant_joined/left`) прилетают вебхуком `/api/webhooks/zoom`.
2. **Аналитика студентов.** Страница «Аналитика»: воронка открытий уроков, funnel
   «начали/прошли», посещаемость вебинаров — со скоупом по `Course.teacher_id`.
3. **Выплаты.** `TeacherPayout` с моделями оплаты `percent | per_student | per_block | fixed`,
   закрытие периода и леджер выплат.

### Администратор / маркетолог

1. **Лендинг под запуск.** `LandingPage` хранит JSON-блоки; catch-all `/{slug}`
   рендерит страницу из Blade-блоков. На каждый лендинг — свой lead-магнит-бот
   (Telegram/VK/MAX) со своим вебхук-секретом.
2. **Лид-магнит и прогрев.** Заявка с лендинга → доставка магнита через
   `/api/webhooks/{telegram|vk|max}-magnet` → шаги прогрева в n8n (`/api/webhooks/lead-step`).
3. **Тарифы и ценообразование.** Блоки/половины блоков, тарифы `full | block_N | block_N_hH`,
   лояльные тиры (`MarketingSetting`), персональные скидки, депозиты-брони,
   промокоды — единый расчет в `Tariff`.
4. **Мобильное приложение.** Sanctum-токены, эндпоинты `/api/v1` (курсы с прогрессом,
   уроки с флагами `locked`/`is_completed`) — фундамент под нативный клиент.

---

## Стек

| Слой | Технологии |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| Frontend | Vite 8 (Node.js 20.19+ или 22.12+), Tailwind CSS 4, Axios, Livewire |
| Admin | Filament v3 (две панели: `admin` и `editor`) |
| Очереди | Laravel Horizon + Redis |
| Real-time | Laravel Reverb (WebSocket, живой чат поддержки, H536) |
| БД | MySQL (прод), SQLite in-memory (тесты) |
| Деплой | Laravel Sail (Docker) |
| Часовой пояс | `Europe/Moscow` (зашит в [config/app.php](config/app.php)) |

---

## Быстрый старт

```bash
cp .env.example .env
composer install
npm install

php artisan key:generate
php artisan migrate --seed   # создает тестового админа из ADMIN_EMAIL / ADMIN_PASSWORD

npm run dev                  # фронтенд на :5173
php artisan serve            # бэкенд на :8000
php artisan horizon          # мониторинг очередей на /horizon
```

`composer.json` эмулирует Unix-расширения `pcntl`/`posix` при разрешении lock-файла
на Windows и поэтому оставляет Composer `platform-check=false`. Это не отключение
прод-проверки: CI выполняет `composer check-platform-reqs` и проверяет
зафиксированные зависимости через `composer audit --locked`, а `deploy.sh` после
production-install выполняет `composer check-platform-reqs --no-dev` на реальном
сервере.

Через Docker Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

Тесты (SQLite in-memory, реальная БД не нужна):

```bash
php artisan test --parallel   # параллельный прогон (brianium/paratest) — так же гоняет CI
php artisan test --filter=TestName
./vendor/bin/pint            # форматирование PHP
```

---

## Архитектура и доменная модель

### Управление доступом через оплату

В системе **нет ручного назначения групп**. Доступ к урокам выдается автоматически
по факту успешной оплаты:

```
Оплата (Точка Банк)
   → вебхук /api/webhooks/tochka
   → Payment переходит в один из Payment::PAID_STATUSES
   → PaymentObserver::grantAccess()
   → пользователь добавляется в нужную Group
   → уроки фильтруются по группам пользователя на этапе запроса
```

Ключевые файлы: [PaymentObserver.php](app/Observers/PaymentObserver.php),
[Payment.php](app/Models/Payment.php).

### Блочная структура курсов и ключи доступа

Курс делится на `CourseBlock` — секции с временным окном (`starts_at` / `ends_at`).
Тариф (`Tariff`) может ограничивать доступ конкретными блоками. Доступ кодируется
строковыми ключами, хранящимися в `payments.tariff`:

| Ключ | Что открывает |
|---|---|
| `full` | весь курс |
| `block_N` | блок N целиком |
| `block_N_hH` | половина блока N (H ∈ {1,2}) |

Блок можно продавать «половинами»: урок несет `lesson.block_half` (1/2; `null` —
не разделен). Единственный источник правды по генерации и проверке ключей —
`Tariff::accessKey()`, `Lesson::unlockingKeys()`, `Lesson::isUnlockedBy()`.

Ценообразование (`Tariff::calculateFinalPriceForUser()`) учитывает:
- скидки за лояльность (через `MarketingSetting`),
- зачет депозита,
- **апгрейд-кредит** (`Tariff::upgradeCreditForUser()`): покупка целого блока
  засчитывает уже оплаченные его половины, покупка `full` — все оплаченные
  блоки/половины (containment-модель).

### Курс-запись: свои уроки, а не доступ к чужому курсу (H3823)

Продажа «курса в записи» — это отдельный курс со своими блоками и тарифами, и доступ
считается **по курсу**: у курса-записи без единого урока купившие не получают ничего.
Курс 327 «Йога-сутры Патанджали (1 поток, 2025) в записи» продержался так до 01-09-2026 —
129 оплат, пять активных тарифов, ноль уроков; все шестнадцать записей лежали на уроках
живого курса 396.

Штатное лечение — **завести курсу-записи собственные уроки-контейнеры** со ссылками на те
же YouTube/RuTube записи, командой
[`catalog:mirror-recording-lessons {source} {target}`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MirrorRecordingLessons.php):

```sh
php artisan catalog:mirror-recording-lessons 396 327            # сухой прогон, ничего не пишет
php artisan catalog:mirror-recording-lessons 396 327 --apply    # запись
```

Команда переносит ровно то, что делает урок записью (заголовок, `block_number`,
`block_half`, порядок, дату, признак публикации, `youtube_url`/`rutube_url`/`video_url`),
пишет **только** в `lessons` и идемпотентна по слоту `(block_number, block_half,
sort_order)`. Дословный перенос `block_number` — и есть механизм доступа: из него
`Lesson::unlockingKeys()` выводит `block_N`, поэтому купленный `block_2` открывает ровно
второй блок записи, без отдельной таблицы соответствий и без правки money/access-контура.

Чего делать **нельзя**: выдавать купившим курс-запись доступ к урокам живого курса (это
уже money/access-контур → [`/money-pr-land`](https://github.com/gasyoun/claude-config/blob/main/commands/money-pr-land.md))
и трогать тарифы или видимость курса-записи — инцидент 31-08-2026 состоял ровно в этом
(см. «`tariff.is_active` gates BUYING» в [CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)).
Пин: [`MirrorRecordingLessonsTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/MirrorRecordingLessonsTest.php).

### Ключевые доменные связи

```
User ──< Payment >── Tariff ──> Course
User ──< UserGroup >── Group ──< LessonGroup >── Lesson
Course ──< CourseBlock
Course ──< Lesson ──< HomeworkSubmission >── User (куратор-рецензент)
Teacher ──< TeacherPayout (модели оплаты: percent | per_student | per_block | fixed)
Dictionary ──< DictionaryWord
User ──< PranaTransaction (balance-скидка + lifetime-ранг)
User ──< SocialAccount (вход через провайдера)
User ──< User (referred_by — реферальная связь)
LandingPage ──> JSON-блоки
```

### Две Filament-панели

- [AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php) — полная
  админка `/admin`, доступ по `is_admin` (ресурсы автообнаружением из
  `app/Filament/Resources`, сейчас 37).
- [LectureEditorPanelProvider.php](app/Providers/Filament/LectureEditorPanelProvider.php)
  — редактор лекций `/editor`, доступ по `is_lecture_editor`.

Полный администратор видит обе панели.

---

## Модули

### 1. Учебный кабинет (`/dvaram`)

Личный кабинет студента (старый путь `/cabinet` отдает 301 на `/dvaram`):
показывает только курсы, к которым есть доступ (через группы). Прохождение уроков,
заметки, скачивание материалов, словарь, домашние задания, календарь событий,
сертификаты, онбординг-чеклист, подсказка «следующий урок», вкладка праны и
веб-чат поддержки.

Файлы: [StudentController.php](app/Http/Controllers/StudentController.php),
[resources/views/student/](resources/views/student/),
[LessonView.php](app/Models/LessonView.php).

### 2. Магазин курсов (`/online`, `/checkout`)

Каталог курсов (`/online`, фильтры горизонтальными чипами в стиле Arzamas, теги
категорий, типографские обложки-фолбэки), путь из трёх направлений (`/online/put`),
страница курса (`/k/{slug}`, legacy
`/online/kursy/{slug}` → 301) с выбором тарифа, оформление заказа
(`/checkout/{tariff}`). Оплата через Точку Банк, промокоды (процент или
фиксированная сумма). Кабинет: `/c/{slug}`, урок `/c/{slug}/u/{id}`.

Файлы: [ShopController.php](app/Http/Controllers/ShopController.php),
[CheckoutController.php](app/Http/Controllers/CheckoutController.php),
[Tariff.php](app/Models/Tariff.php), [PromoCode.php](app/Models/PromoCode.php).

### 3. Конструктор лендингов (`/{slug}`)

Лендинги из JSON-блоков (~20 типов: hero, форма заявки, цены, программа, FAQ,
отзывы и др.). Последний маршрут в [routes/web.php](routes/web.php) перехватывает
любой slug и ищет `LandingPage`.

Добавление типа блока: создать Blade в [resources/views/promo/blocks/](resources/views/promo/blocks/),
зарегистрировать в `LandingPage::renderBlock()`.

### 4. Система лекций (`/editor`)

Отдельная Filament-панель для авторов. Цикл: черновик (`LectureDraft`) → сборка
HTML (микросервис `lecture-builder`) → публикация. Есть AI-функции
(`LectureAiClient`).

Файлы: [app/Services/Lecture/](app/Services/Lecture/),
[app/Filament/Editor/](app/Filament/Editor/).

### 5. Панель администратора (`/admin`)

Filament v3, 37 автообнаруживаемых CRUD-ресурсов: пользователи, курсы, уроки, платежи, тарифы,
промокоды, словари, преподаватели, расписание, объявления, статьи и т.д. Плюс
медиа (Filament Curator), экспорт в Excel, мониторинг очередей (`/horizon`),
резервное копирование БД (Spatie Backup).

### 6. Блог (`/s/...`)

Статьи с категориями, подсчетом просмотров (по хэшу посетителя), временем чтения
и SEO-метаданными. Файлы: [ArticleController.php](app/Http/Controllers/ArticleController.php),
[ArticleViewTracker.php](app/Services/ArticleViewTracker.php).

### 7. Трекинг активности

| Уровень | Что делает |
|---|---|
| Middleware `TrackUserActivity` | обновляет `users.last_activity_at` на каждом запросе |
| `ActivityTracker` (сервис) | пишет события в `activity_events` (append-only) |
| `LessonView` (модель) | время на уроке, счетчик открытий, хартбит (AJAX `/api/heartbeat`) |

### 8. Преподаватели и выплаты

`Teacher` поддерживает 4 модели оплаты: процент от платежей, за студента, за блок
курса, фиксированная ставка. История — `TeacherPayout`. Дашборд «Аналитика
студентов» (`TeacherAnalytics`): посуточная воронка открытий из `lesson_views`,
funnel «начали / прошли» и таблица прогресса; скоуп по `Course.teacher_id`
(админ видит всех).

### 9. Реферальная программа

Ленивый `users.referral_code` + `referred_by`; `CaptureReferral` middleware ловит
`?ref=` в сессию, `ReferralService` идемпотентно привязывает реферера и начисляет
ему **100 праны при первой оплате** приглашенного (через `PaymentObserver`).
Карточка-приглашение — во вкладке праны кабинета.

### 10. Социальная авторизация (scaffold)

`social_accounts` + `SocialAccount`, `SocialAuthService` (find-or-create,
отвязан от Socialite ради тестируемости), маршруты `/auth/{provider}/redirect|callback`
(whitelist-guard → 404). Кнопки на `/login` видны только при заданном `client_id`.
Google работает из коробки; VK / Yandex требуют community-драйверов
`socialiteproviders/*` (follow-up).

### 11. Мобильный API (`/api/v1`, Sanctum)

Personal access tokens на `User` (`HasApiTokens`): `POST /api/v1/auth/login`
(throttle 10/мин) → токен, `auth/me`, `auth/logout`, `courses` (с прогрессом),
`courses/{slug}/lessons` (флаги `locked` / `is_completed`). Та же логика доступа,
что и в вебе: группы + оплаченные тарифы + grant.

### 12. Telegram support analytics (`/admin/telegram-support/telegram-support-analytics`)

Отдельный аналитический слой для **support-аккаунта Telegram**: импортирует
сообщения через MadelineProto, нормализует их в `telegram_support_messages`,
собирает дневные разговоры (`chat_id + date`), считает first-time contacts,
unanswered, human/AI replies и топики по keyword-правилам.

Команда:

```bash
php artisan telegram-support:sync
php artisan telegram-support:sync --payload=storage/app/support-sample.json
```

В проде команда повешена в расписание (`telegram-support-sync`) и является no-op,
пока `TELEGRAM_SUPPORT_ENABLED=false`. Для live-импорта нужны:
`TELEGRAM_SUPPORT_API_ID`, `TELEGRAM_SUPPORT_API_HASH`,
`TELEGRAM_SUPPORT_SESSION`, опционально лимиты `TELEGRAM_SUPPORT_HISTORY_LIMIT`,
`TELEGRAM_SUPPORT_DIALOG_LIMIT`, `TELEGRAM_SUPPORT_PROFILE_BACKFILL_LIMIT`.

Админка дает:
- страницу аналитики `/admin/telegram-support/telegram-support-analytics`;
- CRUD keyword-топиков и responder-mapping;
- ручную привязку Telegram support contact → `User`;
- авто-привязку контактов по `telegram_id`, username и имени, когда это однозначно.

Файлы: [TelegramSupportSyncService.php](app/Services/TelegramSupport/TelegramSupportSyncService.php),
[SupportDailyRollupAggregator.php](app/Services/TelegramSupport/SupportDailyRollupAggregator.php),
[TelegramSupportAnalytics.php](app/Filament/Pages/TelegramSupportAnalytics.php),
[TelegramSupportContactResource.php](app/Filament/Resources/TelegramSupportContactResource.php).

---

## Интеграции и вебхуки

| Назначение | Эндпоинт | Секрет |
|---|---|---|
| **Точка Банк** — оплаты | `POST /api/webhooks/tochka` | подпись в теле |
| Telegram — уведомления пользователям | `POST /api/telegram/webhook` | — |
| VK — уведомления пользователям | `POST /api/vk-webhook` | — |
| Lead-магнит Telegram | `POST /api/webhooks/telegram-magnet` | header `X-Telegram-Bot-Api-Secret-Token` |
| Lead-магнит Telegram (на лендинг) | `POST /api/webhooks/telegram-magnet/{webhookKey}` | header-секрет, бот резолвится по ключу |
| Lead-магнит VK | `POST /api/webhooks/vk-magnet` | секрет в теле |
| Lead-магнит MAX | `POST /api/webhooks/max-magnet/{secret}` | секрет в **path** (Max не поддерживает header/body) |
| Lead-step (цепочки писем) | `POST /api/webhooks/lead-step` | — |

**Чат поддержки** реализован поверх мессенджеров: Telegram/VK-бот → лог
`ChatMessage` → AI-автоответ с историей → передача куратору («позови куратора») →
ответ из админ-панели Helpdesk/Dialogs. Веб-чат в кабинете (Livewire `StudentChat`)
получает real-time доставку через **Laravel Reverb** (WebSocket-транспорт + broadcast-событие,
H536): включается `BROADCAST_DRIVER=reverb` после развертывания Reverb-сервера на хосте
(по умолчанию транспорт `log`/`null`).

**Telegram support analytics** — не пользовательский бот и не вебхук, а импорт
истории отдельного support-аккаунта через MadelineProto. Включается переменными
`TELEGRAM_SUPPORT_*`; первый login/session выполняется вручную в терминале, дальше
`telegram-support:sync` работает как cron-safe инкрементальный импорт.

**Безопасность секретов.** Все webhook-секреты и bot-токены шифруются в БД через
Eloquent `encrypted`-cast (`MarketingSetting::$casts`). Так как у MAX секрет идет
в URL, он может попасть в логи reverse-proxy / CDN / nginx — при любом инциденте
(доступ к логам, утечка дампа БД) перегенерировать `max_webhook_secret` в админке
и заново выполнить `php artisan max:set-magnet-webhook`.

Прочее: **DomPDF** — генерация сертификатов; **Sanctum** — токены мобильного
кабинета (эндпоинты `/api/v1`, см. модуль 11); **Socialite** — вход через Google
(VK / Yandex — follow-up).

---

## Роадмап

Роадмап ведется волнами: **Now** — активная разработка, **Next** — ближайшая
очередь после стабилизации текущей волны, **Later** — крупные улучшения без
жесткой даты. Старые P0/P1/P2/P3-задачи закрыты и оставлены ниже только как
история доставленного. Годовой горизонт (Q3 2026 → Q2 2027, волны 1–4 с
критериями выхода) — в
[`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md).

Стратегический вид бизнеса целиком (обе линии — курсы и книги) —
одностраничный холст Остервальдера в
[`docs/BUSINESS_MODEL_CANVAS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BUSINESS_MODEL_CANVAS.md).

Крупные активные направления вынесены в отдельные дорожные карты (папка
[`docs/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs)):
[масштабирование Telegram](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)
(+ [карта реализации](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)),
[SRS / Memrise-клон](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md),
[паритет с GetCourse](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md),
[AI-контент](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md),
[автоматизация поддержки](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md).

### Now

- **Масштабирование Telegram — активная программа.** Support-аналитика (импорт истории
  support-аккаунта через MadelineProto → нормализованные сообщения, дневные conversations,
  first-time / unanswered / human-vs-AI метрики, keyword-топики, страница
  `/admin/telegram-support/telegram-support-analytics`) выросла в отдельную многопоточную дорожную карту
  (userbot / MTProto-раннер, live-доставка, автопривязка контактов, лимиты против flood).
  Детали и PR-уровневая карта реализации — в
  [`docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md).
- **Salary / finance.** Активно развивается контур выплат преподавателям:
  калькулятор зарплат, поздние оплаты прошлых блоков, фильтры по курсу/блоку,
  процент-фолбэк, PayPal-конвертация, письма-отчеты преподавателям и зеркалирование
  выплат в финансовую таблицу/ledger. Это денежный контур, поэтому любые изменения
  требуют точечных тестов и осторожного review.
- **Hardening / техдолг.** Продолжить покрытие денежно- и доступно-критичных
  путей: `Tariff::upgradeCreditForUser()`, loyalty/deposit в
  `calculateFinalPriceForUser()`, ключи `block_N_hH`, webhook-подписи и
  fail-policy для Tochka/Telegram/VK/MAX/Zoom. В Laravel 12 допустимы и свойство
  `protected $casts`, и защищённый метод `casts(): array`; сохранять локальную
  конвенцию модели.
- **UX / polish.** Проход по `/dvaram`, helpdesk, Telegram support analytics,
  salary/finance страницам и лендингам: адаптив, ясные пустые состояния,
  формулировки, таблицы и действия без изменения денежной семантики.

### Next

- **Telegram support production loop.** После первой живой сессии: проверить
  cron-safe импорт, dashboard на реальных чатах, responder breakdown, профильные
  backfill-лимиты, сценарий мягкого сброса только support analytics/sync state.
- **Finance reliability.** Закрепить salary/finance регрессии тестами:
  late-payment picker, закрытые периоды, авансы, PayPal rate date, зеркальные
  `salary_payout`-транзакции, экспорт/письма.
- **Attendance / Zoom reliability.** Связать новые attendance-изменения с текущим
  UX: трекинг-редирект «подключиться к занятию», Zoom participant webhooks,
  `zoom:sync-attendance --days=2` как страховка и показ посещаемости в админке.
- **Docs and runbooks.** Удерживать README как публичную карту, `.ai_state.md` как
  рабочий журнал, а `AGENTS.md` как инструкции для следующих агентов.

### Later

- **AI-diff для редактора лекций.** Визуальный pre-apply diff перед применением
  AI-правок, поверх уже существующего отката к бэкапу.
- **Mobile/API follow-up.** Нативный клиент или PWA-слой поверх уже готового
  Sanctum API, если появится продуктовый запрос.
- **Глубокая аналитика обучения.** Сводные cohort/retention отчеты по кабинетам,
  вебинарам, домашним заданиям и платежным событиям.

### ✅ Уже сделано

**Привлечение и вовлечение (июль 2026)**

- [x] **Диагностический марафон** — 3-дневная воронка «Консультация по онлайн-курсам
  ОРС» end-to-end: лендинг + захват, входной level-quiz, доставка контента по дням
  и «теплого хвоста» в Telegram, бесплатный + платный tripwire-трек
  ([MarathonController.php](app/Http/Controllers/MarathonController.php),
  [config/marathon.php](config/marathon.php)).
- [x] **Бесплатные игры-упражнения** — статические тренажёры sort / match / cloze / **table**
  (+ лигатуры и корни по частотности) под `public/lila/` с мягким гейтом
  «одна игра бесплатно → зарегистрируйся»; студенты в кабинете — без гейта
  ([public/lila/gate.js](public/lila/gate.js); H1710 batch 26-07-2026).
- [x] **SRS-движок (Saraswati trainer)** — нативные интервальные повторения «Anki для
  санскрита» с планировщиком FSRS, страница повторений и статистики, за флагом
  `SRS_ENABLED` (по умолчанию ON, пилот август 2026;
  [SrsController.php](app/Http/Controllers/SrsController.php), [config/srs.php](config/srs.php)).
- [x] **Живой чат поддержки на Reverb** — WebSocket-транспорт + broadcast-событие
  (H536) для real-time доставки в веб-чате кабинета; включается `BROADCAST_DRIVER=reverb`.
- [x] **Сводная посещаемость** — консолидированный дашборд посещаемости вебинаров (H553).

**Денежное ядро и доступ**

- [x] **Апгрейд тарифов** — зачет уплаченного при докупке (`Tariff::upgradeCreditForUser()`).
- [x] **Тесты денежного ядра** — вебхук Точки (подпись + идемпотентность,
  [TochkaWebhookTest.php](tests/Feature/Webhooks/TochkaWebhookTest.php)), тиры
  лояльности ([LoyaltyDiscountTest.php](tests/Feature/LoyaltyDiscountTest.php)),
  сборка цены в чекауте ([CheckoutPriceTest.php](tests/Feature/CheckoutPriceTest.php)),
  подписи bot-вебхуков ([BotWebhookSignatureTest.php](tests/Feature/Webhooks/BotWebhookSignatureTest.php)).
- [x] **Консолидация «оплачено»** — единый `Payment::scopePaid()` / `PAID_STATUSES`
  вместо инлайн-литералов `['paid','success']`.
- [x] **Безопасность** — закрыт VK-IDOR (одноразовый `vk_auth_token` вместо сырого
  user id), throttle `/login`, проверка подписи легаси бот-вебхуков.

**P0 — кураторы и админка**

- [x] **Очередь проверки ДЗ** — фильтр по статусу/курсу/уроку, сортировка
  «дольше всех ждет», bulk «принять» / «вернуть на доработку», шаблоны комментариев
  ([HomeworkSubmissionResource.php](app/Filament/Resources/HomeworkSubmissionResource.php)).
- [x] **Карточка прогресса студента** — секция «Прогресс обучения» в
  [UserResource.php](app/Filament/Resources/UserResource.php): % пройденных уроков,
  сводка ДЗ, активность, прана — в одном месте.

**P0 — кабинет студента**

- [x] **Онбординг-чеклист** на `/dvaram` (`OnboardingChecklist`, прячется при 100 %).
- [x] **Прогресс и «следующий урок»** — подсказка следующего доступного урока в карточке курса.
- [x] **Понятный статус ДЗ + пуш** — явные статусы + уведомление в Telegram/VK о вердикте.

**P1**

- [x] **Сигналы «застрявших» студентов** — `StuckStudentsReport` + админ-виджет
  (неактивен 14+ дн / ДЗ на доработке 7+ дн).
- [x] **Сегментная рассылка** — bulk «написать в TG/VK» по сегменту с превью охвата
  (`SendMessengerAlerts`).
- [x] **Веб-чат поддержки в кабинете** — Livewire `StudentChat` поверх того же
  «мозга», что и мессенджер-бот; «позови куратора» → ответ из Helpdesk.
- [x] **Аналитика преподавателей** — `TeacherAnalytics` (воронка просмотров +
  прогресс студентов).
- [x] **Чат поддержки через мессенджеры** — Telegram/VK-бот → `ChatMessage` →
  AI-автоответ → передача куратору → ответ из админки.

**P2 (доставлено)**

- [x] **Мобильный API** — Sanctum personal access tokens, эндпоинты `/api/v1` (модуль 11).
- [x] **Реферальная программа** — `?ref` → `ReferralService` → 100 праны рефереру при
  первой оплате приглашенного (модуль 9).
- [x] **Социальная авторизация (scaffold)** — `laravel/socialite`, `SocialAccount`,
  Google из коробки (модуль 10).
- [x] **Прана — два счетчика** — скидочный `prana_balance` + накопительный
  `lifetime_prana` с рангами Śiṣya→Paṇḍita; противоречие с PRANA_ROADMAP снято.
- [x] **Прана — P2P и распад** — подарок праны (`transfer`, дневные лимиты) +
  `prana:decay` (по умолчанию выкл., в расписании на еженедельный ночной прогон).
- [x] **Редактор лекций v2** — async-пайплайн (длинные шаги препроцесс/сборка/ИИ в
  очереди `lectures` + поллинг-виджет), структурное редактирование (move/delete
  блоков, split/merge абзацев), advisory-лок + откат к бэкапу. Движок-микросервис
  `lecture-builder` без изменений.
- [x] **Вебинары Zoom (issue #78) — end-to-end** — автосоздание встречи из
  расписания (Server-to-Server OAuth), автоимпорт записи по вебхуку
  `recording.completed` → урок, посещаемость по `participant_joined/left` →
  `webinar_attendances` → секция в `TeacherAnalytics`. Включается кредами Zoom-приложения.

**P3 / предыдущая волна**

- [x] **Мобильная адаптация кабинета** — аудит показал, что кабинет уже
  адаптивен (нет горизонтального оверфлоу на 375px на дашборде, плеере урока,
  странице курса, календаре). Точечная полировка под телефон доставлена (#199):
  бейдж праны в мобильной шапке, свернутое уведомление с «Подробнее», fade-подсказка
  на таб-баре. Решение: responsive Blade (не PWA/нативное); мобильный API — фундамент на будущее.
- [x] **Реферальная награда → денежный кредит** (#201) — вместо 100 праны реферер
  получает реальный денежный кредит (`users.referral_credit`, дефолт 500 ₽),
  который авто-зачитывается в его следующую покупку; возврат при срыве оплаты,
  идемпотентность через `referral_rewards`.
- [x] **Партнерская (агентская) программа** (H292) — внешний **партнер** рекомендует курсы и
  получает фиксированную **денежную выплату** (`PARTNER_REWARD_AMOUNT`, дефолт 1000 ₽) за каждого
  приведенного клиента; отдельно от студенческой рефералки (`Partner`/`PartnerConversion`, ledger
  выплат), чистая ссылка `/mitram/<КОД>`, учет в **Продажи → Партнеры**, бот-API, всё за флагом
  `partner.enabled` (ВЫКЛ). Спека: [docs/partner-program.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/partner-program.md).
- [x] **Прана — углубление геймификации (закрыто end-to-end)** — таблица лидеров
  (#202), бейджи-достижения (#204), streak-награды на вехах (#206, earn-sink),
  магазин праны (#207, admin-defined spend-sink: каталог перков в Filament →
  студент тратит прану → заявка, админ исполняет). Полный стек: ранги · streak ·
  бейджи · лидерборд · P2P · реферальный кредит · магазин.
- [x] **VK / Yandex socialite-драйверы** — community-пакеты
  `socialiteproviders/vkontakte`+`/yandex` + listener `SocialiteWasCalled` в
  `EventServiceProvider`; драйверы резолвятся и строят OAuth-URL (тест). Остается
  завести client_id/secret VK/Yandex в `.env`.
- [x] **Редактор лекций v2 — Phase B завершен + template-фикс** (#209, #210) —
  `template.html.j2` рендерит абзацы редактора без ключа `t` (#209), и добавлен
  **add-block** (`LecturePatcher` op `insert_block` + кнопка «＋» в `lecture-editor.js`,
  #210). Phase B полностью: move / delete / split / merge / add-block. _Dialog-разметка
  не нужна — пайплайн собирает `speech`-блоки (makejson2)._

### ✅ Решенные развилки

- **Видеоплатформа вебинаров** = Zoom (доставлено, см. выше).
- **Модель вознаграждения рефералки** = денежный кредит (доставлено, #201).
- **Прана в проде** = **полная геймификация** (решение 2026-06-26). Включаем
  earn+spend слой целиком — лидерборд · бейджи · streak · магазин · P2P · ранги —
  рядом со скидочным кошельком (two-counter, концепции сосуществуют). **Decay
  остается выключенным** (`PRANA_DECAY_ENABLED=false`); сгорание не включаем.

---

## Прод: uptime / мониторинг

| Для кого | Документ |
|---|---|
| **Ученики / преподаватели** | [samskrte.ru/uptime](https://samskrte.ru/uptime) · [зеркало GitHub](https://gasyoun.github.io/Systema-Sanscriticum/uptime/) · тэг [@rusamskrtam](https://t.me/rusamskrtam) · [шпаргалка преподу](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_SITE_DOWN_CHEATSHEET_RU.md) · [pin TG](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/teacher-site-down-telegram-pin.md) |
| **Кураторы («Отдел заботы»)** | [MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_GROK_ZABOTA_BOT_RU.md) — реплай / `@grokusaurus_bot` / `@rusamskrtam` |
| **Иван / Марцис (ops)** | [docs/UPTIME_BETTERSTACK_MONITORING_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md) §2 |
| **Агенты** (inventory, env, smoke) | [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) |

OS-предохранители (OOM/cron): [docs/server-resource-guards.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).

---

## 🕸️ Как этот репозиторий связан с остальными

Systema — Tier-0 репозиторий и **не только приложение**: часть его кода и данных живёт в общем
контуре из ~85 репозиториев. Полная карта рёбер — в приватном хабе:
[Uprava/PROJECT_INTERLINKS.md](https://github.com/gasyoun/Uprava/blob/main/PROJECT_INTERLINKS.md)
(проза) и [interlinks_edges.tsv](https://github.com/gasyoun/Uprava/blob/main/interlinks_edges.tsv)
(канонический стор рёбер). Ниже — то, что касается Systema.

| Направление | Что течёт | Куда / откуда | Статус |
|---|---|---|---|
| Systema **вендорит** | движок классификатора обращений + `rules/v1` / `taxonomy/v1` / golden-векторы, приколоченные к `PINNED_SHA` | [message-intent-classifier](https://github.com/gasyoun/message-intent-classifier) → [`tools/message-intent-classifier`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tools/message-intent-classifier) | live |
| Systema **потребляет** (косвенно) | замороженные **маскированные** снапшоты диалогов `corpora/eval/…` и `corpora/train/…` — точность вендоренных правил меряется по ним | производитель — [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ); в Systema копии **нет** и не будет | queued |
| Systema **отдаёт** | драйвер Telegram-харвеста Track B ([`app/Services/TelegramHarvest/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Services/TelegramHarvest)) | `telegram-sanskrit-corpus` (приватный стор) | live |
| Systema **отдаёт** | выгрузки прод-БД (платежи / посещаемость / рефералы) → custdev-CSV | [Uprava](https://github.com/gasyoun/Uprava) | queued |
| Systema **потребляет** | замороженные пакеты чтений когорты `cohort_start_chteniya` | [kosha](https://github.com/gasyoun/kosha) → [`resources/data/cohort_start_chteniya`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/data/cohort_start_chteniya) | live |
| Systema **делит движки** | классификатор обращений · канон выплат «на руки» · движок квизов кабинета | [github-spine/SHARED_CODE.md](https://github.com/gasyoun/github-spine/blob/main/SHARED_CODE.md) строки 30–32 | live |

**Границы по правам.** Сырые диалоги поддержки не коммитятся никуда и никогда; в репозитории
попадают только **PII-маскированные** производные, и ни одна строка диалога, фамилия ученика или
сумма выплаты не цитируется ни в рядах хаба, ни здесь. Политика маскировки —
[docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md)
§ PII-политика. Перед любой публикацией —
[`/publish-safety-check`](https://github.com/gasyoun/claude-config/blob/main/commands/publish-safety-check.md).
Указания сотруднику (бухгалтеру, куратору, преподавателю) живут **в кабинете** `/admin`, а не в
публичном репозитории и не в issue.

**Куда писать находку.**

| Что нашли | Куда |
|---|---|
| Продуктовая / денежная / внутрикабинетная ловушка | локальный [FINDINGS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/FINDINGS.md) (ruling F1) |
| Санскритские данные, кодировки, транслитерация | [SanskritLexicography/FINDINGS.md](https://github.com/gasyoun/SanskritLexicography/blob/master/FINDINGS.md) |
| Инфраструктура: хуки, worktree, CI, серверы | [Uprava/FINDINGS.md](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) |
| Переиспользуемый хелпер или движок | [github-spine/SHARED_CODE.md](https://github.com/gasyoun/github-spine/blob/main/SHARED_CODE.md) |

**Куда посмотреть перед тем, как что-то строить.** Что уже существует в контуре —
[SanskritLexicography/FEATURES_INDEX.md](https://github.com/gasyoun/SanskritLexicography/blob/master/FEATURES_INDEX.md);
что делать дальше и кто решает —
[Uprava/GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md);
план связывания этого репозитория —
[docs/PLAN_SYSTEMA_SANSCRITICUM_INTERCONNECTION_2026-08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SANSCRITICUM_INTERCONNECTION_2026-08.md).

---

_Dr. Mārcis Gasūns_
