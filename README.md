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
User ──< PranaTransaction (balance-скидка + lifetime-ранг)
User ──< SocialAccount (вход через провайдера)
User ──< User (referred_by — реферальная связь)
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

### Словарь (вкладка в кабинете `/dvaram`)

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

### Прана — лояльность и геймификация (два счётчика)

Прана начисляется за учебную активность ([config/prana.php](config/prana.php)) и
хранится в **двух независимых счётчиках** на пользователе:

| Счётчик | Назначение | Поведение |
|---|---|---|
| `prana_balance` | скидочный кошелёк | тратится при покупке, может убывать |
| `lifetime_prana` | накопительный ранг | растёт от начислений, **тратами не убывает** |

- **Скидка:** **10 праны = 1 ₽**, праной можно покрыть **не более 30 %** итоговой
  цены курса;
- **Начисления** (растят оба счётчика): завершён урок (+10), курс пройден (+500),
  просмотр открытого урока (+20), ежедневный вход (+5), покупка курса (+50),
  приглашённый друг оплатил (+100);
- **Ранги** по `lifetime_prana`: Śiṣya → Adhyāyin → Snātaka → Ācārya → Paṇḍita
  (`config('prana.ranks')`, `PranaSettings::rankFor()`, `User::pranaRank()`);
- **P2P-перевод** («подарить прану», `POST /dvaram/prana/transfer`,
  `PranaService::transfer()`) — только из `prana_balance`, с дневными лимитами;
  ранг ни у кого не растёт;
- **Распад** (`prana:decay`) сжигает % `balance` у неактивных N+ дней — `lifetime`
  не трогает, по умолчанию **выключен**, повешен в расписание на еженедельный
  ночной прогон (включается через `PRANA_DECAY_ENABLED`);
- учёт — `PranaService` / `PranaTransaction`, настройки в админке.

> **ℹ️ Историческое противоречие снято.** Раньше «скидочная прана» в проде
> конфликтовала с геймификацией из [PRANA_ROADMAP.md](PRANA_ROADMAP.md) (ранги,
> `lifetime_prana`, распад, P2P). Две концепции были несовместимы **только при
> одном счётчике**. С разделением на `prana_balance` (деньги-скидка) и
> `lifetime_prana` (статус-ранг) они сосуществуют: ранг — это статус, а не валюта.

---

## Модули

### 1. Учебный кабинет (`/dvaram`)

Личный кабинет студента (старый путь `/cabinet` отдаёт 301 на `/dvaram`):
показывает только курсы, к которым есть доступ (через группы). Прохождение уроков,
заметки, скачивание материалов, словарь, домашние задания, календарь событий,
сертификаты, онбординг-чеклист, подсказка «следующий урок», вкладка праны и
веб-чат поддержки.

Файлы: [StudentController.php](app/Http/Controllers/StudentController.php),
[resources/views/student/](resources/views/student/),
[LessonView.php](app/Models/LessonView.php).

### 2. Магазин курсов (`/online`, `/checkout`)

Каталог курсов (`/online`, фильтры горизонтальными чипами в стиле Arzamas, теги
категорий, типографские обложки-фолбэки), страница курса (`/online/kursy/{slug}`)
с выбором тарифа, оформление заказа (`/checkout/{tariff}`). Оплата через Точку
Банк, промокоды (процент или фиксированная сумма).

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
курса, фиксированная ставка. История — `TeacherPayout`. Дашборд «Аналитика
студентов» (`TeacherAnalytics`): посуточная воронка открытий из `lesson_views`,
funnel «начали / прошли» и таблица прогресса; скоуп по `Course.teacher_id`
(админ видит всех).

### 9. Реферальная программа

Ленивый `users.referral_code` + `referred_by`; `CaptureReferral` middleware ловит
`?ref=` в сессию, `ReferralService` идемпотентно привязывает реферера и начисляет
ему **100 праны при первой оплате** приглашённого (через `PaymentObserver`).
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

Прочее: **DomPDF** — генерация сертификатов; **Sanctum** — токены мобильного
кабинета (эндпоинты `/api/v1`, см. модуль 11); **Socialite** — вход через Google
(VK / Yandex — follow-up).

---

## Роадмап

Приоритеты: **P0** — снять нагрузку с кураторов и сделать кабинет понятным
студенту, **P1** — следующая волна, **P2** — стратегические задачи. **P0, P1 и P2
полностью закрыты** (включая редактор лекций v2 и вебинары Zoom). **P3 тоже
закрыт**: мобильная адаптация кабинета, реферальная награда денежным кредитом и
углубление геймификации праны (end-to-end). Следующая волна — hardening/техдолг,
кросс-репо `lecture-ui` и polish/UX (см. «Следующая волна» ниже).

### ✅ Уже сделано

**Денежное ядро и доступ**

- [x] **Апгрейд тарифов** — зачёт уплаченного при докупке (`Tariff::upgradeCreditForUser()`).
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
  «дольше всех ждёт», bulk «принять» / «вернуть на доработку», шаблоны комментариев
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
  первой оплате приглашённого (модуль 9).
- [x] **Социальная авторизация (scaffold)** — `laravel/socialite`, `SocialAccount`,
  Google из коробки (модуль 10).
- [x] **Прана — два счётчика** — скидочный `prana_balance` + накопительный
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

### ✅ P3 / следующая волна — закрыто

Весь агент-доступный бэклог доставлен. Остаётся только **включение на проде**
(не код): креды Zoom (OAuth + Event Subscription) и VK/Yandex (social-auth),
секреты бот-вебхуков, миграции, Horizon-воркер на очереди `lectures`, флаги
`PRANA_DECAY_ENABLED` / `REFERRAL_CREDIT_AMOUNT`, наполнение магазина праны
админом. Подробности — в `Uprava/GTD_NEXT_ACTIONS.md` (раздел `@DO`).

- [x] **P3 · Мобильная адаптация кабинета** — аудит показал, что кабинет уже
  адаптивен (нет горизонтального оверфлоу на 375px на дашборде, плеере урока,
  странице курса, календаре). Точечная полировка под телефон доставлена (#199):
  бейдж праны в мобильной шапке, свёрнутое уведомление с «Подробнее», fade-подсказка
  на таб-баре. Решение: responsive Blade (не PWA/нативное); мобильный API — фундамент на будущее.
- [x] **Реферальная награда → денежный кредит** (#201) — вместо 100 праны реферер
  получает реальный денежный кредит (`users.referral_credit`, дефолт 500 ₽),
  который авто-зачитывается в его следующую покупку; возврат при срыве оплаты,
  идемпотентность через `referral_rewards`.
- [x] **Прана — углубление геймификации (закрыто end-to-end)** — таблица лидеров
  (#202), бейджи-достижения (#204), streak-награды на вехах (#206, earn-sink),
  магазин праны (#207, admin-defined spend-sink: каталог перков в Filament →
  студент тратит прану → заявка, админ исполняет). Полный стек: ранги · streak ·
  бейджи · лидерборд · P2P · реферальный кредит · магазин.
- [x] **VK / Yandex socialite-драйверы** — community-пакеты
  `socialiteproviders/vkontakte`+`/yandex` + listener `SocialiteWasCalled` в
  `EventServiceProvider`; драйверы резолвятся и строят OAuth-URL (тест). Остаётся
  завести client_id/secret VK/Yandex в `.env`.
- [x] **Редактор лекций v2 — Phase B завершён + template-фикс** (#209, #210) —
  `template.html.j2` рендерит абзацы редактора без ключа `t` (#209), и добавлен
  **add-block** (`LecturePatcher` op `insert_block` + кнопка «＋» в `lecture-editor.js`,
  #210). Phase B полностью: move / delete / split / merge / add-block. _Dialog-разметка
  не нужна — пайплайн собирает `speech`-блоки (makejson2)._

### ✅ Решённые развилки

- **Видеоплатформа вебинаров** = Zoom (доставлено, см. выше).
- **Модель вознаграждения рефералки** = денежный кредит (доставлено, #201).
- **Прана в проде** = **полная геймификация** (решение 2026-06-26). Включаем
  earn+spend слой целиком — лидерборд · бейджи · streak · магазин · P2P · ранги —
  рядом со скидочным кошельком (two-counter, концепции сосуществуют). **Decay
  остаётся выключенным** (`PRANA_DECAY_ENABLED=false`); сгорание не включаем.

### ⏭️ Следующая волна

Агент-доступный бэклог исчерпан — новая волна это три направления (решение 2026-06-26):

- **Hardening / техдолг** (приоритет) — системно убрать cast-ловушку `User`
  (datetime/int приходят строками; перевести на свойство `$casts`), добить
  тест-покрытие денежно-/доступно-критичных путей (upgrade-credit, лояльность,
  половины блоков `block_N_hH`), security-проход по всем webhook-секретам/подписям,
  свип мёртвых конфигов (напр. `prana.rewards.referral` после перехода рефералки
  на денежный кредит).
- **Cross-repo lecture-ui (Python)** — разметка dialog-блоков в `template.html.j2`
  (`data-block-index`) + опц. визуальный pre-apply AI-diff (diff-payload от
  Python-сервиса). Сверяться с `SHARED_CODE.md` / `Uprava/PROJECT_INTERLINKS.md`.
- **Polish / UX** — проход по кабинету, админ-панелям и лендингам
  (вёрстка/адаптив/копирайт) через skill `blade-styling`; вне денежной семантики.
