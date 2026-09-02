# Кабинетный бот (ИИ-куратор)

Документация по студенческому боту личного кабинета: привязка аккаунта, ИИ-куратор
на DeepSeek, передача диалога живому куратору, форматирование, источники знаний,
уведомления и эксплуатация.

> Аудитория: разработчики и администраторы Академии. Версия актуальна на 21-08-2026 (gap: [docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md)).

---

## 1. Назначение

Кабинетный бот решает три задачи:

1. **Привязка** Telegram/VK-аккаунта студента к его профилю на сайте (чтобы слать
   личные уведомления и узнавать студента в чате).
2. **ИИ-куратор** — автоматически отвечает на вопросы об обучении, курсах, оплате,
   доступе и расписании, опираясь на живую базу знаний.
3. **Передача живому куратору** — по запросу студента («позови куратора») или по
   триггер-словам диалог уходит человеку, который отвечает из админки; студент
   видит, какой куратор с ним общается.

---

## 2. Боты и каналы

| Канал | Токен (env) | Назначение |
|---|---|---|
| **Бот кабинета (Telegram)** | `STUDENT_TELEGRAM_BOT_TOKEN` / `STUDENT_TELEGRAM_BOT_USERNAME` | Привязка, ИИ-куратор, личные уведомления студенту. **Фолбэк на основной бот, если не задан.** |
| **Основной бот (Telegram)** | `TELEGRAM_BOT_TOKEN` / `TELEGRAM_BOT_USERNAME` | Служебные чаты (кураторы/маркетологи/онбординг) и **алерты куратору** о вызове. |
| **VK-бот** | `VK_BOT_TOKEN` | Тот же ИИ-куратор для ВКонтакте. |

Логика выбора Telegram-токена везде одинаковая:

```php
$token = config('services.telegram.student_bot_token')   // STUDENT_TELEGRAM_BOT_TOKEN
    ?: config('services.telegram.bot_token');            // фолбэк на основной
```

Конфиг — `config/services.php`, блоки `telegram`, `vk`, `openrouter`.

---

## 3. Точки входа (webhooks и роуты)

| Метод/Путь | Контроллер | Назначение |
|---|---|---|
| `POST /api/telegram/webhook` | `TelegramWebhookController@handle` | Входящие от бота кабинета (Telegram) |
| `POST /api/vk-webhook` | `Api\VkBotController@handle` | Входящие от VK-бота |
| `GET /telegram/connect` (auth) | `TelegramController@connect` | Генерирует deep-link и редиректит студента в бота (имя роута `telegram.connect`) |

> ⚠️ **Безопасность:** `/api/telegram/webhook` защищён middleware `verify.tg.bot`
> ([`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php)),
> fail-closed по `TELEGRAM_BOT_WEBHOOK_SECRET`: пустой секрет → 403 всем. Поэтому
> при регистрации вебхука **обязательно** передавать тот же `secret_token` — иначе
> получится живой вебхук, отбивающий каждый апдейт (снаружи неотличимо от тишины).
> _Правка 27-07-2026: раньше здесь значилось «проверки секрета нет» — это устарело
> и провоцировало регистрацию без секрета._

**Установка вебхука бота кабинета** — общей командой, вместе с остальными ботами:

```bash
php artisan telegram:webhooks          # показать, куда смотрит каждый бот
php artisan telegram:webhooks --set    # перерегистрировать всех на входной узел
```

Команда сама берёт токен (`STUDENT_TELEGRAM_BOT_TOKEN` с фолбэком на
`TELEGRAM_BOT_TOKEN`), секрет и нужные `allowed_updates` (`message` +
`callback_query` — контроллер разбирает оба). Адрес строится от
`TELEGRAM_WEBHOOK_BASE_URL`; пусто → `APP_URL`.

Раньше вебхук ставился руками через `curl`, и это стоило дорого: когда входной
узел переехал, кабинетного бота перерегистрировали, а @zapisi_ORSbot забыли — он
пять дней не получал сообщений, и заметить это было нечем
([инвентарь §4.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md)).

---

## 4. Привязка аккаунта (binding)

1. В кабинете студент жмет «Подключить Telegram» → `GET /telegram/connect`
   (`TelegramController@connect`):
   - генерируется `telegram_auth_token = Str::random(32)`, пишется в `users`;
   - формируется deep-link `https://t.me/{student_bot_username}?start={token}`;
   - редирект в Telegram.
2. Студент жмет Start → бот получает `/start <token>` →
   `TelegramWebhookController@handle`:
   - ищет `User::where('telegram_auth_token', $token)`;
   - при совпадении: `telegram_id = chat_id`, `telegram_auth_token = null`,
     приветствие;
   - токен одноразовый (обнуляется).
3. `/start` без токена → инструкция «привяжите аккаунт в кабинете».

**Самообслуживание кабинета (02-09-2026)** — [`CabinetProvisionBotCommand`](../app/Services/Bot/CabinetProvisionBotCommand.php):

- `/кабинет <email>` в **личке** → если кабинета нет, создаёт его одним шагом
  (Free-tier плейлисты, `signup_source=telegram`), привязывает `telegram_id` и
  шлёт одноразовую magic-ссылку входа. Щиты: ≤1 создание на `telegram_id`,
  существующий email не привязывается, флаг `features.telegram_cabinet_provision`
  (в проде ON с 02-09-2026). Детали и сценарии —
  [RUNBOOK_TELEGRAM_CABINET_LOGIN_2026-08-28.md](RUNBOOK_TELEGRAM_CABINET_LOGIN_2026-08-28.md).
- `/кабинет` в **группе** → только короткий указатель в личку; аккаунтов и
  ссылок в группах нет (magic-link увидели бы все участники).
- `/вход`, `/login`, `/start` — одноразовая ссылка входа для уже созданного
  кабинета ([CabinetLoginBotCommand](../app/Services/Bot/CabinetLoginBotCommand.php)).

**VK** (`VkBotController`): привязка по `ref` из ссылки `vk.me` (id студента) →
`vk_id = from_id`, либо студент уже привязан по `vk_id`.

Поля `users`: `telegram_id` (unique), `telegram_auth_token`, `vk_id`.

---

## 5. ИИ-куратор

### 5.1. Поток обработки вопроса

`TelegramWebhookController::processStudentQuestion()` (и аналог в `VkBotController`):

1. Сохраняет входящее как `ChatMessage` (`role = 'user'`, `is_read = false`).
2. Если активен **режим человека** (`Cache::has("chat_human_{chatId}")`) — в ИИ не
   идет, шлет алерт куратору и выходит (см. §6).
3. Проверяет **триггер-слова** вызова куратора (см. §6).
4. Иначе шлет «⏳ Изучаю манускрипты…» и вызывает `CuratorAi::reply()`.
5. Ответ сохраняется как `ChatMessage` (`role = 'bot'`) и отправляется студенту.

### 5.2. `App\Services\Bot\CuratorAi`

Единый «мозг» для TG и VK. Параметры:

- Провайдер: **OpenRouter**, endpoint `https://openrouter.ai/api/v1/chat/completions`.
- Модель: `OPENROUTER_MODEL` (по умолчанию `deepseek/deepseek-v4-flash`).
- `temperature = 0.3`, `max_tokens = 2000`, `timeout = 45s`.
- Контекст: системный промпт + **последние 15** сообщений диалога
  (`HISTORY_LIMIT`). Все не-`user` сообщения (`bot`/`curator`) подаются модели как
  `assistant` — т.е. реплики живого куратора модель воспринимает как свои прошлые.
- Возвращает `?string`: `null` при отсутствии ключа/ошибке/отказе (тогда студенту
  шлется мягкое «Мои чакры перегружены 🧘…, напишите „позови куратора“»).

Ключ — `OPENROUTER_API_KEY`. Без него ИИ молча не отвечает (ошибка логируется).

### 5.3. Системный промпт — `App\Services\Bot\BotKnowledgeBase`

`systemPrompt()` склеивает три части:

```
persona() (правила)  ─┬─►  единый системный промпт
FAQ (faq.md)         ─┤
Каталог курсов (БД)  ─┘
```

- **`persona()`** — правила поведения (в коде). Ключевое:
  - единственные источники истины — FAQ и Каталог; **запрет выдумывать** факты,
    реквизиты (юрлицо/ИНН/счет/БИК/карту), контакты, цены, даты, имена
    преподавателей;
  - оплата только двумя способами (см. §8);
  - ссылки на курс — только из поля «Страница курса и оплата» каталога;
  - **мягкая продажа (гибрид)** — после ответа по сути бот может добавить РОВНО ОДИН
    мягкий следующий шаг из win/loss-данных (рассрочка / свой темп в записи /
    бесплатное пробное занятие). **Запрещено** давить: искусственная срочность
    («осталось N мест») и социальное доказательство («все берут») давали
    отрицательный лифт. Жесткую продажу, торг и индивидуальные условия (персональная
    скидка/рассрочка/перенос) бот не ведет сам — эскалирует на живого куратора
    («позови куратора», см. §6);
  - форматирование под Telegram-HTML (`<b>`/`<i>`), эмодзи-маркеры.
- **FAQ** — `resources/knowledge/faq.md` (см. §7).
- **Каталог** — `CourseCatalogProvider` (см. §9).

`$userQuestion` в `systemPrompt()` по-прежнему зарезервирован: BM25 из H2448 подключён только к Helpdesk-черновикам (`FAQ_RAG_SUGGESTER` default OFF), не к этому промпту. Разрыв к учебному году (двое кураторов, подсказки в Telegram, счётчик 🍎, GTX 1050 ≠ 16 ГБ из [#1633](https://github.com/gasyoun/Systema-Sanscriticum/issues/1633)): [docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md).

---

## 6. Передача живому куратору (human handoff)

### 6.1. Включение режима человека

Триггер-слова (TG и VK): `куратор`, `человек`, `помощь`, `админ`, `менеджер`,
`оператор`. При совпадении:

- ставится флаг в кэш: `chat_human_{telegram_id}` или `chat_human_vk_{vk_id}`,
  **TTL 7200 с (2 часа)**;
- студенту: «🙏 Понял вас. Передал ваш вопрос живому куратору…»;
- кураторам уходит алерт (см. ниже).

Пока флаг активен — входящие студента **не идут в ИИ**, только алертят куратора.

### 6.2. Алерты кураторам

Алерт шлет **основной бот** (`TELEGRAM_BOT_TOKEN`) на `ADMIN_TELEGRAM_ID`.

- `ADMIN_TELEGRAM_ID` поддерживает **несколько ID через запятую** — алерт уходит
  всем: `ADMIN_TELEGRAM_ID=123,456`.
- Каждый куратор из списка должен **нажать Start у основного бота**, иначе именно
  ему доставка не пройдет (Telegram блокирует сообщения от бота незапустившим).
- Алерт содержит ссылку `/(/admin/dialogs?user_id=ID)` на диалог в админке.

### 6.3. Ответ куратора и бэйдж

Куратор отвечает из админки (§10). При ответе:

- сохраняется `ChatMessage` с `role = 'curator'` и `answered_by = id` куратора
  (кто ответил; `created_at` = когда);
- студенту сообщение подписывается **псевдонимом** куратора:
  `👨‍🏫 <b>{псевдоним}</b>: …` (TG) / `👨‍🏫 {псевдоним}: …` (VK);
- псевдоним берется из `users.curator_display_name` (`User::curatorDisplayName()`),
  фолбэк на настоящее ФИО. Настраивается в карточке пользователя (поле «Псевдоним
  куратора»).

### 6.4. Возврат к ИИ

Кнопка «Завершить чат (Вернуть ИИ)» (`Helpdesk::returnToBot()`):

- чистит `chat_human_*` для TG и VK;
- студенту: «🤖 Куратор завершил диалог. Я снова с вами!»;
- пишет системное `ChatMessage` (`role = 'bot'`).

Если куратор не нажал «Вернуть ИИ» — режим сам спадет через 2 часа (TTL).

---

## 7. База знаний (FAQ)

Файл `resources/knowledge/faq.md` — статические факты (оплата, доступ, расписание,
организация, материалы для новичков).

- Кэш: `bot.kb.faq`, **TTL 600 с (10 мин)**.
- Потолок размера: `60000` символов (обрезается с пометкой).
- **Цены/даты/наличие курсов здесь НЕ дублируются** — они приходят из Каталога.
- Правка = редактирование файла + деплой; чтобы применить сразу —
  `php artisan cache:clear`.

---

## 8. Политика оплаты (в FAQ и промпте)

Ровно **два способа**:

1. **РФ** — на сайте, на странице курса (ссылку бот дает из Каталога). Доступ
   открывается автоматически, чек присылать не нужно. Запасной вариант —
   персональная СБП-ссылка от куратора по оплатам (реквизиты в чате не публикуются).
2. **Заграница** — PayPal `gasyoun@gmail.com`, в сообщении указать название курса;
   после перевода — чек/скриншот в чат.

Промпт **жестко запрещает** сочинять банковские реквизиты (юрлицо/ИНН/счет/БИК/
карту) — на запрос реквизитов бот направляет на сайт и к куратору.

---

## 9. Каталог курсов — `App\Services\Bot\CourseCatalogProvider`

Живой раздел промпта «Каталог курсов» из БД (заменяет устаревавший хардкод).

- Кэш: `bot.kb.catalog`, **TTL 600 с**. Сброс — `flush()` или `cache:clear`.
- Берет активные и видимые курсы (`is_active = true AND is_visible = true`) с
  преподавателем, блоками и активными тарифами.
- По каждому курсу выводит: формат, **число блоков**, цены, **ссылку на оплату**.
- **Число блоков считается по блочным тарифам** (`tariffs.type='block'`, distinct
  `block_number`) — так же, как показывает витрина «Выберите вариант участия».
  (CourseBlock-записи используются только для дат блоков.)
- **Ссылка на оплату** — абсолютный URL страницы курса:
  `config('app.url') + route('shop.course.show', slug)`. Берется из app.url (не из
  хоста webhook-запроса, т.к. каталог кэшируется). Бот **не выдумывает** URL — дает
  только этот.
- Даты блоков — из `CourseBlock.starts_at/ends_at` (если заданы).

---

## 10. Админка: диалоги и карточка студента

Страница **`App\Filament\Pages\Helpdesk`** — `/admin/dialogs` («Чат с куратором»).
Доступ всем, кроме преподавателей (`canAccess`).

- Список диалогов слева (непрочитанные сверху), окно чата справа.
- **Ответ студенту** (`sendMessageToStudent`) — ботом кабинета (фолбэк на основной);
  ставит `answered_by`, подписывает псевдонимом (§6.3).
- **Возврат к ИИ** (`returnToBot`).
- **Модалка «инфо о студенте»** — по клику на имя в шапке чата: основные данные и
  контакты, последние оплаты (+итог), обещания и рассрочки (метка по
  `installment_group_id`), индивидуальные скидки по курсам, ссылка на полную
  карточку. Partial `resources/views/.../partials/student-info-modal.blade.php`.

Вторая точка ответа — `UserResource\Pages\Dialogs` (вкладка в карточке студента),
логика та же.

Модель диалога — `App\Models\ChatMessage`: `user_id` (студент), `role`
(`user`/`bot`/`curator`), `answered_by` (кто ответил), `text`, `is_read`,
`created_at`.

---

## 11. Форматирование сообщений — `App\Services\Bot\TelegramFormatter`

Модель часто отдает Markdown, а Telegram шлется с `parse_mode=HTML` → решетки и
звездочки висели сырыми. Поэтому ответ нормализуется детерминированно:

- `TelegramFormatter::toHtml($text)` — Markdown/смесь → валидный Telegram-HTML:
  `**bold**`/`__`→`<b>`, `*`/`_`→`<i>`, `### Heading`→`<b>`, маркеры списка→`•`,
  `` `code` ``→`<code>`, `[t](url)`→`<a>`; теги модели сохраняются, голые `< > &`
  экранируются. Применяется ко **всем** исходящим в `sendMessage`.
- `TelegramFormatter::toPlain($text)` — для VK (разметку не понимает): срезает и
  Markdown, и теги, оставляя текст и эмодзи.
- В Telegram есть фолбэк: при битом HTML (Telegram 400) сообщение **досылается
  обычным текстом**, чтобы не пропасть.

---

## 12. Исходящие уведомления студенту

Личные уведомления (оплаты, доступы, депозиты, долги, анонсы) идут **ботом
кабинета** через единую точку:

- `User::sendTelegramMessage($text, $imagePath = null)` — `app/Models/User.php`
  (поддержка фото, фолбэк токена, лимиты длины).
- `App\Jobs\SendTelegramMessageJob` — обертка-очередь (`tries=3`).
- `App\Jobs\SendMessengerAlerts` — мультиканал (TG + VK), чистит HTML под каждый
  канал. Используется напоминаниями о долгах, анонсами и т.п.

VK — `User::sendVkMessage()`.

---

## 13. Тумблеры в админке

`MarketingSetting` (раздел «Глобальные настройки»):

- `student_telegram_bot_enabled` — показывать в кабинете блок привязки Telegram.
- `student_vk_bot_enabled` — то же для VK.

Поля управляют **отображением блоков привязки в кабинете студента**
(`resources/views/student/dashboard.blade.php`), а не обработкой вебхуков.

> Все секреты/токены ботов в `MarketingSetting` шифруются (`encrypted` cast).

---

## 14. Переменные окружения

```dotenv
# Бот кабинета (Telegram)
STUDENT_TELEGRAM_BOT_TOKEN=...
STUDENT_TELEGRAM_BOT_USERNAME=...

# Основной бот (алерты кураторам, фолбэк)
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_USERNAME=...

# Кураторы для алертов «студент зовет куратора» (можно несколько через запятую)
ADMIN_TELEGRAM_ID=123456789,987654321

# VK
VK_BOT_TOKEN=...

# ИИ (OpenRouter / DeepSeek)
OPENROUTER_API_KEY=...
OPENROUTER_MODEL=deepseek/deepseek-v4-flash   # необязательно

# Базовый URL (для ссылок на оплату в каталоге)
APP_URL=https://samskrte.ru
```

---

## 15. Эксплуатация и деплой

- **Правка FAQ** (`faq.md`) → `php artisan cache:clear` (или подождать ~10 мин:
  кэш `bot.kb.faq`).
- **Правка цен/курсов/блоков** → каталог обновится сам за ~10 мин (`bot.kb.catalog`),
  для немедленного — `cache:clear`.
- **Правка `persona()`** (код) → деплой + reload php-fpm (промпт не кэшируется).
- **Смена `ADMIN_TELEGRAM_ID` / любых env** → `php artisan config:cache`
  (на проде конфиг закэширован — без этого не подхватится).
- **Установка вебхука** бота кабинета — вручную через `setWebhook` (см. §3).
- **Перепривязка студентов** при смене бота/токена и диагностика — см.
  внутренние заметки.

---

## 16. Ключевые файлы

| Файл | Роль |
|---|---|
| `app/Http/Controllers/TelegramWebhookController.php` | Вебхук бота кабинета, привязка, поток вопроса, алерты, `sendMessage` |
| `app/Http/Controllers/Api/VkBotController.php` | Вебхук VK, тот же поток |
| `app/Http/Controllers/TelegramController.php` | `connect()` — генерация deep-link |
| `app/Services/Bot/CuratorAi.php` | Вызов OpenRouter/DeepSeek, история |
| `app/Services/Bot/BotKnowledgeBase.php` | `persona()` + FAQ + каталог → системный промпт |
| `app/Services/Bot/CourseCatalogProvider.php` | Живой каталог курсов (цены, блоки, ссылки) |
| `app/Services/Bot/TelegramFormatter.php` | Markdown→Telegram-HTML / plain |
| `resources/knowledge/faq.md` | Статическая база знаний |
| `app/Filament/Pages/Helpdesk.php` (+ blade) | Чат кураторов, модалка инфо студента |
| `app/Models/ChatMessage.php` | Сообщения диалога (`role`, `answered_by`) |
| `app/Models/User.php` | `sendTelegramMessage`, `curatorDisplayName`, привязка |

---

## 17. Частые проблемы

| Симптом | Причина / решение |
|---|---|
| Бот не отвечает (ИИ молчит) | Нет `OPENROUTER_API_KEY` или ошибка провайдера — смотри лог `CuratorAi`. |
| Алерт куратору не приходит | Куратор не нажал Start у **основного** бота; или `ADMIN_TELEGRAM_ID` не задан / не сделан `config:cache`. |
| У студента сырые `###`/`**` | Должно лечиться `TelegramFormatter`; проверь, что ответ идет через `sendMessage`. |
| Бот «выдумывает» реквизиты/ссылки | Проверь актуальность `persona()` и FAQ; ссылки — только из каталога. |
| Неверное число блоков у курса | Каталог считает по блочным тарифам; проверь `tariffs` курса. |
| Изменения FAQ не видны | Кэш `bot.kb.faq` 10 мин — `php artisan cache:clear`. |
