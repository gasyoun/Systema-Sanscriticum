# Laravel Dusk локально на Windows

_Created: 10-08-2026 · Last updated: 18-08-2026_

Как запустить браузерные тесты ([`tests/Browser/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/Browser)) на рабочей машине под Windows. Заведено в H2532; проверено на этой машине 10-08-2026.

Dusk — **локальный инструмент**, в CI он не заводится: нужен живой HTTP-сервер и настоящий Chrome. Отдельное решение, не этот handoff.

## Что стоит на машине (фактические версии на 10-08-2026)

| Компонент | Версия | Откуда |
|---|---|---|
| Chrome | **150.0.7871.187** | `C:\Program Files\Google\Chrome\Application\chrome.exe` |
| ChromeDriver | **150.0.7871.124** | поставлен `dusk:chrome-driver --detect` |
| `laravel/dusk` | **v8.6.0** | мажор под `illuminate ^13.0` (проект на Laravel 13.24) |
| PHP | 8.3.32 | `C:\php83\php.exe` |

Мажор Dusk берётся по совместимости с Laravel, а не «самый свежий по привычке»: v8 — первый, объявляющий `illuminate/console ^13.0`.

## Разовая настройка

### 1. Пакет и каркас

```
php C:\php83\composer.phar install
php C:\php83\composer.phar require --dev "laravel/dusk:^8.6"
php C:\php83\composer.phar dump-autoload
php artisan dusk:install
```

`dump-autoload` — не косметика: без него `php artisan dusk:install` падает с `Class "Laravel\Dusk\DuskServiceProvider" not found`, потому что пакет уже лежит в `vendor/`, но ещё не попал в `vendor/composer/autoload_psr4.php`.

### 2. ChromeDriver (CA-сертификаты)

У PHP на этой машине не задан ни `curl.cainfo`, ни `openssl.cafile`, поэтому скачивание драйвера падает с `cURL error 60: unable to get local issuer certificate`. Не правим глобальный `php.ini` — передаём бандл на один запуск:

```
php -d curl.cainfo="C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt" ^
    -d openssl.cafile="C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt" ^
    artisan dusk:chrome-driver --detect
```

`--detect` обязателен: при промахе мимо версии установленного Chrome тест падает на старте с `session not created`.

### 3. `.env.dusk.local`

Не в git (`.gitignore`: `.env.*.local`). Проще всего снять с рабочего `.env` и переопределить:

```
APP_ENV=local
APP_URL=http://127.0.0.1:8010
DB_CONNECTION=sqlite
DB_DATABASE=C:/Users/<вы>/AppData/Local/Temp/dusk_systema.sqlite
SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=array
TELEGRAM_SUPPORT_ENABLED=false
PULSE_ENABLED=false
TELESCOPE_ENABLED=false
SERVER_GUARDS_VERIFY=false
CABINET_PROBE_CHECK_GUARDS=false
```

Почему именно так:

- **Отдельная файловая БД.** Dusk гоняет по своей БД, не по БД продукта. `phpunit.xml` тут не годится в принципе: там SQLite `:memory:`, а браузер — отдельный процесс и в память тест-процесса не заглядывает.
- **`SESSION_DRIVER=file`.** С `array` сессия не переживает редирект после логина — вход «не залипает».
- **`APP_URL` совпадает с портом сервера.** Проект поднимается на `:8010`, Dusk по умолчанию ждёт `:8000`; рассинхрон даёт невнятные таймауты вместо ошибки.

Прогнать миграции в эту БД:

```
php artisan migrate --env=dusk.local --force
```

### 4. Ассеты Filament

ЛК преподавателя — это панель Filament, а `public/css/filament` и `public/js/filament` в git не хранятся (build-артефакты, см. `.gitignore`). Без них страница отдаёт 200 и **пустой белый экран**: HTML приходит, Alpine не стартует.

```
php artisan filament:assets
```

### 5. Скомпилированные вьюхи

```
php artisan view:cache
```

Не оптимизация ради оптимизации: без неё первый рендер страницы Filament
компилирует сотни blade-шаблонов в одном запросе и упирается в лимит времени
(см. следующий пункт).

## Запуск

Два окна. Первое — сервер.

**`php artisan serve` тут не годится, и это не придирка** (H2502, 13-08-2026).
Он поднимает встроенный сервер PHP как отдельный процесс, а тот наследует
`max_execution_time = 30` из `C:\php83\php.ini` (у CLI лимит снят, у SAPI
`cli-server` — нет) и передать ему `-d` через `artisan serve` нечем. Первый
рендер страницы панели на этой машине в тридцать секунд не укладывается, и тест
падает на `assertSee`, показывая пустой экран — то есть выглядит как сломанная
вёрстка, а не как таймаут. Поэтому сервер поднимается напрямую, из `public/`:

```
cd public
php -d max_execution_time=0 -d memory_limit=1024M ^
    -S 127.0.0.1:8010 -t . ^
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

`cd public` обязателен: роутер `server.php` ищет `index.php` в **текущем
каталоге**, а не рядом с собой (`artisan serve` делает ровно это — запускает
процесс с рабочим каталогом `public/`). Запуск из корня даёт
`Failed opening required '.../index.php'`.

Второе окно — тесты:

```
php artisan dusk                                             # вся папка tests/Browser
php artisan dusk tests/Browser/SmokeTest.php                 # харнес жив
php artisan dusk tests/Browser/TeacherGuideScreenshotsTest.php  # кадры руководства
php artisan dusk tests/Browser/VisualDcsLearnerEvidenceTest.php # H2869: кадры+аудит VisualDCS
php artisan dusk tests/Browser/VisualDcsReducedMotionTest.php   # H2869: reduced-motion ветка
```

Для пары VisualDCS-тестов нужны `VISUALDCS_VERB/NOMINAL/PASSAGE=true` в
`.env.dusk.local` (только dusk-окружение; при выключенных флагах тесты
скипаются с подсказкой).

`Warning: TTY mode is not supported on Windows platform.` — ожидаемый шум, не ошибка.

## Конфигурация

`php artisan dusk` читает **`phpunit.dusk.xml`**, а не `phpunit.xml` — Dusk ищет его в `base_path()` сам. Поэтому конфигурация Feature-тестов не тронута, и трогать её не нужно.

## Что уже проверено, не переоткрывать

| Симптом | Причина | Что делать |
|---|---|---|
| `Class "Laravel\Dusk\DuskServiceProvider" not found` | автолоадер не пересобран | `composer dump-autoload` |
| `cURL error 60` при `dusk:chrome-driver` | у PHP не задан CA-бандл | `-d curl.cainfo=...` на один запуск |
| Страница пустая, белая, но HTTP 200 | не опубликованы ассеты Filament | `php artisan filament:assets` |
| `session not created` | ChromeDriver ≠ версия Chrome | `dusk:chrome-driver --detect` |
| Логин «не залипает», редирект на форму входа | `SESSION_DRIVER=array` | `file` в `.env.dusk.local` |
| Непонятные таймауты на каждом `visit()` | `APP_URL` ≠ порт сервера | свести к одному порту |
| `no such element: {"css selector":"body body"}` | `$browser->text('body')` — Dusk уже скоупится на `body` | не префиксовать селектор `body` |
| Страница без вёрстки, сверху `WARNING: MadelineProto runs around 10x slower…` | вендорный `echo` в stdout до первого байта HTML — ломает и разметку, и AJAX-JSON Livewire | `php scripts/silence_madeline_windows_polyfill.php` (обычно его дёргает `composer` хуком `post-autoload-dump`; после оборванного `composer install` — руками) |
| Страница без вёрстки, иконки во весь экран | не собраны ассеты Vite (`public/build/`) | `npm ci && npm run build` |
| `Maximum execution time of 30 seconds exceeded` на рендере страницы панели | `max_execution_time` из `php.ini` у SAPI `cli-server` | поднимать сервер напрямую с `-d max_execution_time=0` (см. «Запуск»), плюс `php artisan view:cache` |
| `Failed opening required '<корень>/index.php'` | `php -S` запущен не из `public/` | `cd public` перед запуском |

## Про сам smoke-тест

[`tests/Browser/SmokeTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Browser/SmokeTest.php) логинится преподавателем через `loginAs()` и убеждается, что ЛК открылся.

- **Отдельной панели преподавателя нет** — ЛК это Filament `/admin`; фейсконтроль в `User::canAccessPanel()`, ветка `default` пускает `isTeacher()`.
- Панель редиректит `/admin` на первый доступный ресурс (у преподавателя — `/admin/lessons`), поэтому проверяется **префикс** пути.
- **Якоря текстовые** (подписи навигации «Обучение», «Расписание»), не CSS-селекторы: редизайн ломает `nth-child`, а название раздела переживает.
- **Имя пользователя якорем не годится** — Filament держит его в выпадающем меню аватара, в тексте страницы его нет.
- `DatabaseTruncation`, не `RefreshDatabase`: транзакция последнего живёт в тест-процессе, браузер её не видит.

Скриншотов гида этот тест не делает — это
[`tests/Browser/TeacherGuideScreenshotsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Browser/TeacherGuideScreenshotsTest.php),
описанный ниже.

## Про тест кадров руководства (H2502)

[`tests/Browser/TeacherGuideScreenshotsTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Browser/TeacherGuideScreenshotsTest.php)
пересобирает [`docs/screenshots/teacher-guide/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/screenshots/teacher-guide)
с нуля: по одному PNG на каждый раздел переписи
[`docs/generated/teacher_nav_census.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/generated/teacher_nav_census.json).

- **Список экранов берётся из переписи, а не из кода теста.** Свой список
  разъехался бы с меню при первой правке гейта.
- **Денежные экраны не снимаются никогда** (`TeacherSalaries`,
  `MutualSettlements`, `TeacherPayoutResource`, `PaymentResource`). Фильтр стоит
  трижды: по классу, по URL и по именам готовых файлов.
- **Данные под кадрами — фикстура теста**, не прод и не дамп: имена студентов,
  курс, уроки и словарь заданы явно, чтобы diff показывал изменения интерфейса,
  а не новые случайные имена от фейкера.
- **`APP_NAME` попадает В КАДР** — это подпись в шапке панели. Перед пересъёмкой
  сведите его со значением прода, иначе руководство научит преподавателя чужому
  названию школы.
- **Локаль панели `en` — это правда прода**, а не недоделка стенда:
  `config/app.php` держит `'locale' => 'en'` константой, без env. Поэтому «New
  lesson» и «Showing 1 to 3 of 3 results» на кадрах — ровно то, что видит
  преподаватель.

CI кадры не переснимает (Dusk локальный), поэтому недостачу ловит обычный
Feature-тест `TeacherGuideCoverageTest::every_section_the_teacher_sees_has_a_screenshot`:
появился раздел в меню — сборка краснеет, пока кадр не переснят.

_Dr. Mārcis Gasūns_
