_Created: 02-07-2026 · Last updated: 05-09-2026_

# Аудит проекта Systema-Sanscriticum

Дата: 2026-06-12
Ветка: `develop`
Охват: маршруты, middleware, вебхуки (Tochka/Telegram/VK/Max/n8n), контроль доступа к урокам/файлам, платежная логика, новый Internal API + Telegram-юзербот (незакоммичено).

Легенда серьезности: 🔴 высокая · 🟠 средняя · 🟡 низкая · 🔵 заметка/качество.

---

## 🔴 1. Path traversal (LFI) в выдаче ассетов лекций

**Файл:** `app/Http/Controllers/Editor/LectureDraftController.php:68-83`, `app/Services/Lecture/LectureStorage.php:64-67`

Регулярка пропускает `..`:

```php
if (! preg_match('#^(slides|src)/[A-Za-z0-9_./-]+$#', $path)) {
    abort(403, 'Запрещенный путь');
}
$abs = $this->storage->absolutePath($draft, $path); // конкатенация без нормализации
return response()->file($abs);
```

Класс символов `[A-Za-z0-9_./-]` включает `.` и `/`, поэтому путь вида
`slides/../../../../../../.env` проходит проверку. `LectureStorage::absolutePath()`
просто склеивает корень диска с `relativePath` (никакой нормализации), а
`response()->file()` отдает уже разрешенный PHP путь с `..`. Итог — чтение
произвольных файлов сервера (`.env`, ключи, исходники).

Гейт: доступ только у аутентифицированного `is_admin`/`is_lecture_editor`, поэтому
это эскалация для редактора лекций, а не анонимный RCE. Тем не менее — реальная утечка секретов.

**Фикс:** запретить `..` явно и/или проверять, что реальный путь остается внутри рабочей папки:

```php
if (! preg_match('#^(slides|src)/[A-Za-z0-9_./-]+$#', $path) || str_contains($path, '..')) {
    abort(403, 'Запрещенный путь');
}
$abs = realpath($this->storage->absolutePath($draft, $path));
$root = realpath($this->storage->absoluteWorkingDir($draft));
abort_if($abs === false || $root === false || ! str_starts_with($abs, $root), 404);
```

---

## 🟠 2. HTTP-запрос в Tochka внутри открытой DB-транзакции

**Файл:** `app/Http/Controllers/PaymentController.php:37-220`

Весь `createPayment` обернут в `DB::transaction`, и **внутри** транзакции делается
сетевой вызов `$tochka->createPaymentWithReceipt(...)`. При этом ранее в коде берутся
`lockForUpdate()` на промокод и списывается прана. Медленный/зависший эквайринг держит
row-lock на `promo_codes`/`pranas` всё время HTTP-запроса (timeout у клиента — секунды),
что под нагрузкой ведет к блокировкам и таймаутам.

Важно: в `DepositController` и `TrialController` это уже исправлено правильно — там
запись в БД в транзакции, а HTTP-вызов **после** commit (см. комментарий
`DepositController.php:40-41`). `PaymentController` отстал от этого паттерна.

**Фикс:** вынести вызов Точки за пределы `DB::transaction`, как в депозите/пробном.

---

## 🟠 3. Гостевой чекаут создает платежи на чужой существующий email

**Файл:** `app/Http/Controllers/PaymentController.php:42-63`

В отличие от `DepositController`/`TrialController` (где для существующего email гостю
отказывают — защита от takeover), в `createPayment` гость, указавший **существующий**
email, молча привязывается к этому аккаунту: `$user = $existingUser` и далее на него
создается `Payment`. Логина (и takeover) нет, но любой может насоздавать pending-платежей
на чужой аккаунт и засорять карточку студента; в связке с фискализацией чек уйдет на чужой email.

**Фикс:** привести к поведению депозита — для гостя с существующим email бросать
`ValidationException` («войдите в кабинет»), а не молча подставлять чужого пользователя.

---

## 🟠 4. Internal API отдает PII всех студентов без троттлинга

**Файл:** `routes/api.php:62-64`, `app/Http/Controllers/Internal/StudentTelegramNotesController.php`

Эндпоинт `GET /api/internal/students/telegram-notes` возвращает `telegram_id` + `phone`
**всех** студентов одним списком. Защита — общий секрет в заголовке (`hash_equals`, fail-closed —
это хорошо), но:

- нет rate-limit (в отличие от публичных форм с `throttle:5,1`); при утечке секрета — мгновенный слив всей базы контактов;
- секрет статический и бессрочный, ротация не автоматизирована.

**Фикс:** добавить `->middleware('throttle:...')`, рассмотреть пагинацию/`updated_since`,
задокументировать ротацию `INTERNAL_API_SECRET` (как уже сделано для Max-секрета в CLAUDE.md).

---

## 🟠 5. Логирование полного payload вебхуков (утечка PII/секретов в логи)

**Файлы:** `app/Http/Controllers/WebhookController.php:43`, `app/Http/Controllers/Api/VkBotController.php:20`

```php
Log::info('--- ДАННЫЕ ВЕБХУКА ---', $payload);   // Tochka: ФИО, суммы, назначение платежа
Log::info('VK WEBHOOK CATCHED:', $data);          // VK: тексты сообщений студентов
```

Полные тела вебхуков пишутся в лог на уровне INFO. Это персональные данные (а для VK —
переписка) в открытом виде в `storage/logs`, которые часто читаются шире, бэкапятся и
попадают в внешние log-коллекторы. Для Tochka в payload может быть и финансовая информация.

**Фикс:** логировать только идентификаторы/статус, а не весь payload; для отладки — за
флагом и с маскированием.

---

## 🟡 6. Хардкод админ-почты в логике входа

**Файл:** `app/Http/Controllers/AuthController.php:34`

```php
if ($user->email === 'pe4kinsmart@gmail.com' || $user->is_admin) {
```

Личная почта зашита в код как признак админа. Если аккаунт переименуют/удалят или почту
сменят — логика молча поедет; плюс это лишний путь, дублирующий `is_admin`. Уже есть
`is_admin` — достаточно его.

**Фикс:** убрать строковое сравнение, оставить `$user->is_admin`.

---

## 🟡 7. `fail()` помечает failed «последний pending» без сверки с заказом

**Файл:** `app/Http/Controllers/PaymentController.php:234-248`

При возврате на `/payment/fail` берется **любой** последний pending-платеж пользователя и
помечается `failed`. Если у студента параллельно есть два pending (например, открыл две
вкладки/два курса), может «свалиться» не тот заказ. Источник истины — вебхук Точки, так что
это не критично для доступа, но статусы в админке могут разъезжаться.

**Фикс:** передавать идентификатор платежа (например, в query/сессии) и помечать именно его,
либо вовсе довериться вебхуку и не трогать статус на возврате.

---

## 🟡 8. `casts()`-метод не применяется (известная грабля Laravel 10.x в этом проекте)

**Контекст:** памятка проекта `eloquent-casts-method-noop`.

`wants_email_announcements`/`is_admin` приходят как `int 1/0`, а не `bool`. В контроллерах
повсюду используется `$request->boolean(...)` при записи (это ок), но при чтении модельных
полей сравнения вида `if (! $user->is_admin)` работают (truthy), а вот строгие `=== true`
сломались бы. Сейчас явных строгих сравнений в просмотренном коде нет, но риск регрессии есть.

**Фикс:** объявлять касты через свойство `$casts` (а не метод `casts()`), пока проект на
Laravel 10.50; в тестах сверять через `assertDatabaseHas`, не `assertTrue`.

---

## 🔵 9. Заметки по устойчивости (не баги, но стоит учесть)

- **Tochka webhook (`WebhookController`)** — подпись RS256 проверяется корректно (Key с
  фиксированным алгоритмом → нет alg-confusion), идемпотентность через `lockForUpdate`
  реализована верно. Замечаний по безопасности нет, кроме п.5 (логирование).
- **`/api/sync-lessons` и `/api/lessons/from-zoom`** — защищены общим секретом
  (`hash_equals`, fail-closed). Нет отдельного троттлинга сверх глобального `api`. Приемлемо,
  но желателен явный лимит.
- **IDOR-проверки** в `HomeworkController::download` (владелец/препод/админ),
  `StudentController::downloadCertificate` (через `auth()->user()->certificates()`),
  `HomeworkController::ensureLessonAccessible` — реализованы корректно.
- **Telegram-юзербот** (`telegram-userbot/`) — `.gitignore` исключает `.env`, `*.session`,
  `state.json`; README предупреждает про `chmod 600` сессии. Секрет берется из env, в коде
  не хардкодится. Замечаний нет. Перед коммитом проверьте, что файл сессии/`.env` реально
  не попали в индекс.
- **`/u/{user}` шорт-линк** — редиректит на Filament-URL; доступ режется гвардом панели
  `admin`. Сам роут без `auth`, но утечки нет (неадмин получит 403 уже в Filament).
- **Незакоммиченный PDF** в корне репо (`Руководство — создание платежа из админки.pdf`)
  и `tests/Feature/Internal/` — проверьте, что PDF не нужно коммитить (добавить в
  `.gitignore` или удалить).

---

## Сводка приоритетов

| # | Серьезность | Тема | Файл |
|---|------|------|------|
| 1 | 🔴 | Path traversal в ассетах лекций | `Editor/LectureDraftController.php:73` |
| 2 | 🟠 | HTTP к Tochka внутри DB-транзакции | `PaymentController.php:37` |
| 3 | 🟠 | Гостевой чекаут на чужой email | `PaymentController.php:42` |
| 4 | 🟠 | Internal API: PII всех студентов без лимита | `routes/api.php:62` |
| 5 | 🟠 | Логирование полного payload вебхуков | `WebhookController.php:43`, `VkBotController.php:20` |
| 6 | 🟡 | Хардкод админ-почты | `AuthController.php:34` |
| 7 | 🟡 | `fail()` берет произвольный pending | `PaymentController.php:234` |
| 8 | 🟡 | `casts()`-метод не работает | модели |

Рекомендованный порядок исправления: **1 → 3 → 5 → 4 → 2**, остальное — по мере.

_Dr. Mārcis Gasūns_
