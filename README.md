# Systema Sanscriticum — платформа онлайн-обучения санскриту

Laravel-приложение для школы санскрита: учебный кабинет со словарём и домашними
заданиями, магазин курсов с гибкими тарифами, конструктор лендингов, редактор
лекций, лояльная валюта «прана» и панель администратора.

Это не абстрактная LMS, а специализированная среда: учебный контент завязан на
санскритскую лексику (деванагари / IAST / кириллица), проверку домашних заданий
куратором и выдачу верифицируемых сертификатов.

---

## Оглавление

- [Стек](#стек)
- [Быстрый старт](#быстрый-старт)
- [Архитектура и доменная модель](#архитектура-и-доменная-модель)
- [Санскритская педагогика](#санскритская-педагогика)
- [Модули](#модули)
- [Интеграции и вебхуки](#интеграции-и-вебхуки)
- [Роадмап](#роадмап)

---

## Стек

| Слой | Технологии |
|---|---|
| Backend | Laravel 10, PHP 8.1+ |
| Frontend | Vite 5, Tailwind CSS 4, Axios, Livewire |
| Admin | Filament v3 (две панели: `admin` и `editor`) |
| Очереди | Laravel Horizon + Redis |
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
php artisan migrate --seed   # создаёт тестового админа из ADMIN_EMAIL / ADMIN_PASSWORD

npm run dev                  # фронтенд на :5173
php artisan serve            # бэкенд на :8000
php artisan horizon          # мониторинг очередей на /horizon
```

Через Docker Sail:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

Тесты (SQLite in-memory, реальная БД не нужна):

```bash
php artisan test
php artisan test --filter=TestName
./vendor/bin/pint            # форматирование PHP
```

---

## Архитектура и доменная модель

### Управление доступом через оплату

В системе **нет ручного назначения групп**. Доступ к урокам выдаётся автоматически
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

Блок можно продавать «половинами»: урок несёт `lesson.block_half` (1/2; `null` —
не разделён). Единственный источник правды по генерации и проверке ключей —
`Tariff::accessKey()`, `Lesson::unlockingKeys()`, `Lesson::isUnlockedBy()`.

Ценообразование (`Tariff::calculateFinalPriceForUser()`) учитывает:
- скидки за лояльность (через `MarketingSetting`),
- зачёт депозита,
- **апгрейд-кредит** (`Tariff::upgradeCreditForUser()`): покупка целого блока
  засчитывает уже оплаченные его половины, покупка `full` — все оплаченные
  блоки/половины (containment-модель).

### Ключевые доменные связи

```
User ──< Payment >── Tariff ──> Course
User ──< UserGroup >── Group ──< LessonGroup >── Lesson
Course ──< CourseBlock
Course ──< Lesson ──< HomeworkSubmission >── User (куратор-рецензент)
Teacher ──< TeacherPayout (модели оплаты: percent | per_student | per_block | fixed)
Dictionary ──< DictionaryWord
User ──< PranaTransaction (лояльная валюта)
LandingPage ──> JSON-блоки
```

### Две Filament-панели

- [AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php) — полная
  админка `/admin`, доступ по `is_admin` (18 ресурсов).
- [LectureEditorPanelProvider.php](app/Providers/Filament/LectureEditorPanelProvider.php)
  — редактор лекций `/editor`, доступ по `is_lecture_editor`.

Полный администратор видит обе панели.

---

## Санскритская педагогика

То, что делает платформу именно школой санскрита, а не универсальной LMS.

### Словарь (`/cabinet/dictionary`)

Многословарная лексическая база. Каждое слово (`DictionaryWord`) хранится в
четырёх представлениях, и поиск идёт сразу по всем:

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
жадной загрузкой (`with('dictionary')`), чтобы избежать N+1.

Наполнение словаря — импортом CSV в админке через
[DictionaryWordImporter.php](app/Filament/Imports/DictionaryWordImporter.php)
(колонки: деванагари, IAST, кириллица, перевод, страница).

### Домашние задания с проверкой куратором

`HomeworkSubmission` привязана к уроку и курсу и проходит цикл рецензирования:

```
draft → submitted → (needs_revision → submitted)* → accepted
```

- студент может редактировать/досдавать, пока статус `draft` или `needs_revision`;
- куратор-рецензент (`reviewed_by`) меняет статус и оставляет `HomeworkComment`;
- файлы — `HomeworkFile`, скачивание через защищённый маршрут.

Файлы: [HomeworkSubmission.php](app/Models/HomeworkSubmission.php),
[HomeworkController.php](app/Http/Controllers/HomeworkController.php).

### Сертификаты

По завершении курса генерируется PDF-сертификат (DomPDF, `CertificateService`).
Сертификат **верифицируем публично** — маршрут `certificate.verify` подтверждает
подлинность по ID. Доступны выгрузки в PDF и JPG; массовую генерацию делает job
`GenerateCertificatesArchive` (кладёт архивы в `archives/`).

### Прана — лояльная валюта

Прана начисляется за учебную активность и тратится как скидка при покупке
([config/prana.php](config/prana.php)):

- курс: **10 праны = 1 ₽**, покрыть праной можно **не более 30 %** итоговой цены;
- начисления: завершён урок (+10), курс пройден (+500), просмотр открытого урока
  (+20), ежедневный вход (+5), покупка курса (+50);
- учёт — `PranaService` / `PranaTransaction`, настройки в админке.

> **⚠️ Прана — две несовместимые концепции.** В коде **работает** прана как
> скидочная валюта (см. выше). Это **противоречит** документу
> [PRANA_ROADMAP.md](PRANA_ROADMAP.md) / `prana-gemini.md`, описывающему
> геймификацию (ранги Śiṣya→Paṇḍita, `lifetime_prana`, распад, P2P) и **явно
> запрещающему** конвертацию в деньги. Тот роадмап **не реализован** и расходится
> с продом — нужно продуктовое решение (см. [.ai_state.md](.ai_state.md)).

---

## Модули

### 1. Учебный кабинет (`/cabinet`)

Личный кабинет студента: показывает только курсы, к которым есть доступ (через
группы). Прохождение уроков, заметки, скачивание материалов, словарь, домашние
задания, календарь событий, сертификаты.

Файлы: [StudentController.php](app/Http/Controllers/StudentController.php),
[resources/views/student/](resources/views/student/),
[LessonView.php](app/Models/LessonView.php).

### 2. Магазин курсов (`/shop`, `/checkout`)

Витрина курсов, страница курса с выбором тарифа, оформление заказа. Оплата через
Точку Банк, промокоды (процент или фиксированная сумма).

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

Filament v3, 18 CRUD-ресурсов: пользователи, курсы, уроки, платежи, тарифы,
промокоды, словари, преподаватели, расписание, объявления, статьи и т.д. Плюс
медиа (Filament Curator), экспорт в Excel, мониторинг очередей (`/horizon`),
резервное копирование БД (Spatie Backup).

### 6. Блог (`/s/...`)

Статьи с категориями, подсчётом просмотров (по хэшу посетителя), временем чтения
и SEO-метаданными. Файлы: [ArticleController.php](app/Http/Controllers/ArticleController.php),
[ArticleViewTracker.php](app/Services/ArticleViewTracker.php).

### 7. Трекинг активности

| Уровень | Что делает |
|---|---|
| Middleware `TrackUserActivity` | обновляет `users.last_activity_at` на каждом запросе |
| `ActivityTracker` (сервис) | пишет события в `activity_events` (append-only) |
| `LessonView` (модель) | время на уроке, счётчик открытий, хартбит (AJAX `/api/heartbeat`) |

### 8. Преподаватели и выплаты

`Teacher` поддерживает 4 модели оплаты: процент от платежей, за студента, за блок
курса, фиксированная ставка. История — `TeacherPayout`.

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
ответ из админ-панели Helpdesk/Dialogs.

**Безопасность секретов.** Все webhook-секреты и bot-токены шифруются в БД через
Eloquent `encrypted`-cast (`MarketingSetting::$casts`). Так как у MAX секрет идёт
в URL, он может попасть в логи reverse-proxy / CDN / nginx — при любом инциденте
(доступ к логам, утечка дампа БД) перегенерировать `max_webhook_secret` в админке
и заново выполнить `php artisan max:set-magnet-webhook`.

Прочее: **DomPDF** — генерация сертификатов; **Sanctum** — токены API (эндпоинты
мобильного кабинета пока в роадмапе).

---

## Роадмап

Приоритеты: **P0** — делаем сейчас (снять нагрузку с кураторов и сделать кабинет
понятным студенту), **P1** — следующая волна, **P2** — стратегические задачи.

### ✅ Уже сделано

- [x] **Апгрейд тарифов** — зачёт уплаченного при докупке (`Tariff::upgradeCreditForUser()`).
- [x] **Тесты денежного ядра** — вебхук Точки (подпись + идемпотентность,
  [TochkaWebhookTest.php](tests/Feature/Webhooks/TochkaWebhookTest.php)), тиры
  лояльности ([LoyaltyDiscountTest.php](tests/Feature/LoyaltyDiscountTest.php)),
  сборка цены в чекауте ([CheckoutPriceTest.php](tests/Feature/CheckoutPriceTest.php)),
  подписи bot-вебхуков ([BotWebhookSignatureTest.php](tests/Feature/Webhooks/BotWebhookSignatureTest.php)).
- [x] **Чат поддержки через мессенджеры** — Telegram/VK-бот → `ChatMessage` →
  AI-автоответ → передача куратору → ответ из админки.

### 👩‍🏫 Кураторы и админка — снижаем ручную нагрузку

- [ ] **P0 · Очередь проверки ДЗ** — единый экран на базе
  [HomeworkSubmissionResource.php](app/Filament/Resources/HomeworkSubmissionResource.php):
  фильтр по статусу/курсу/уроку, сортировка по «дольше всех ждёт», bulk-действия
  «принять» / «вернуть на доработку», шаблоны частых комментариев. Сейчас каждую
  работу куратор открывает и обрабатывает поштучно.
- [ ] **P0 · Карточка прогресса студента** — на странице `view` в
  [UserResource.php](app/Filament/Resources/UserResource.php) собрать в одном
  месте: % пройденных уроков, время на уроках ([LessonView](app/Models/LessonView.php)),
  статусы ДЗ, последняя активность (`last_activity_at`), баланс праны. Данные есть,
  но размазаны по разделам.
- [ ] **P1 · Сигналы «застрявших» студентов** — виджет/отчёт: неактивен N дней,
  ДЗ висит на проверке дольше SLA, не открыл новый доступный блок. Источники —
  `LessonView`, `HomeworkSubmission.status`, `activity_events`.
- [ ] **P1 · Массовая рассылка из админки по сегменту** — выбрать группу/курс/сегмент
  («застрявшие») и отправить Telegram/VK через очередь (`SendMessengerAlerts` уже есть),
  с предпросмотром охвата и историей рассылок.

### 🎓 Кабинет студента — делаем понятным

- [ ] **P0 · Онбординг-чеклист в кабинете** — первые шаги прямо на `/dvaram`
  ([student/dashboard.blade.php](resources/views/student/dashboard.blade.php)):
  открыть первый урок, найти словарь, сдать первое ДЗ, привязать Telegram. Текстовая
  основа — [docs/onboarding-student.md](docs/onboarding-student.md).
- [ ] **P0 · Прогресс и «следующий шаг»** — видимый прогресс по курсу и явная
  подсказка, какой урок открыть следующим (сигналы в `LessonView` / completeLesson).
- [ ] **P1 · Понятный статус ДЗ + уведомления** — наглядные статусы
  (черновик / на проверке / на доработку / принято) и пуш в Telegram при смене
  статуса куратором.
- [ ] **P1 · Заметнее словарь, материалы, сертификаты** — поднять обнаруживаемость
  [словаря](app/Livewire/StudentDictionary.php), материалов урока и сертификатов.
- [ ] **P1 · Веб-чат поддержки в кабинете** — модель и бэкенд готовы, не хватает UI
  на `/cabinet`.
- [ ] **P2 · Мобильная адаптация кабинета** — текущие шаблоны верстались под десктоп.

### 🏗️ Платформа — стратегические задачи

- [ ] **P1 · Аналитика преподавателей** — посуточный дашборд «прогресс студентов
  преподавателя + воронка просмотров»; сигналы лежат в `LessonView`.
- [ ] **P2 · Реферальная программа** — основа есть (`PromoCode`, `PranaService`),
  кода рефералок нет; нужна модель вознаграждения (продуктовое решение).
- [ ] **P2 · Социальная авторизация** — **greenfield**: [config/social.php](config/social.php)
  хранит только ссылки соцсетей; нет `laravel/socialite` и модели `SocialAccount`.
- [ ] **P2 · API для мобильного приложения** — Sanctum настроен, нужны эндпоинты кабинета.
- [ ] **P2 · Редактор лекций v2** — доработка AI-функций и совместного редактирования.
- [ ] **P2 · Вебинары / live-сессии** — модель `Schedule` есть (и приём `lessons/from-zoom`),
  интеграция с видеоплатформой не реализована (обсуждается в issue #78).
