# Systema Sanscriticum — карта проекта

> Навигатор для человека и агента. Не заменяет `AGENTS.md`, `CLAUDE.md`, спецификации и код: помогает открыть только нужное для текущей задачи.

## 1. Что это за система

Laravel-платформа школы санскрита [samskrte.ru](https://samskrte.ru): учебный кабинет, курсы и оплата, домашние задания, словарь, SRS, лендинги, лекции, поддержка, финансы преподавателей и административные панели.

- **Backend:** Laravel 13, PHP 8.3, MySQL в проде, SQLite in-memory в тестах.
- **Frontend:** Vite 8, Tailwind CSS 4, Livewire, Blade.
- **Администрирование:** Filament v3, панели `/admin` и `/editor`.
- **Асинхронность:** Horizon + Redis; real-time поддержка через Reverb.
- **Продуктовое описание и дорожная карта:** [README.md](README.md).
- **Текущий handoff, блокеры и следующие шаги:** `.ai_state.md` — читать только актуальную часть, не загружать целиком без необходимости.

## 2. Порядок чтения перед задачей

1. Прочитай [AGENTS.md](AGENTS.md): приоритеты, высокорискованные области и правила проверки.
2. По таблице ниже выбери домен и открой его короткую карту.
3. В [CLAUDE.md](CLAUDE.md) прочитай только соответствующий раздел, а не весь файл.
4. Открой связанную спецификацию и указанные файлы кода.
5. Перед завершением выполни целевые проверки домена, затем необходимые общие проверки.

Не читай `README.md`, `CLAUDE.md`, `.ai_state.md` и каталог `docs/` целиком для каждой задачи.

## 3. Маршрутизация по задачам

| Что меняется | Сначала прочитать | Основной код | Минимальные проверки |
|---|---|---|---|
| Оплата, тарифы, доступ к урокам, реферальный кредит | [Money / access](docs/domains/money-access.md) → `CLAUDE.md` § Money contour | `app/Models/Payment.php`, `Tariff.php`, `Lesson.php` | `CheckoutPriceTest`, `TochkaWebhookTest` + профильные тесты изменения |
| Курс-запись и его уроки | [Money / access](docs/domains/money-access.md) | `app/Console/Commands/MirrorRecordingLessons.php` | `MirrorRecordingLessonsTest` |
| Зарплата, выплаты, взаиморасчёты преподавателей | `AGENTS.md` § Teacher surfaces → [money-access manual](docs/money-access-core-manual.md) | `app/Services/TeacherSalaryService.php`, `MutualSettlementService.php` | тесты `TeacherSalary*` + тесты затронутого расчёта |
| Поддержка, Helpdesk, Telegram support analytics | [Support](docs/domains/support.md) → [subsystem map](docs/support-subsystem-map.md) | `app/Services/Support/`, `app/Services/TelegramSupport/` | `TelegramSupportAnalyticsTest` + профильные тесты |
| SRS, колоды, повторения | [SRS](docs/domains/srs.md) | `app/Services/Srs/`, `SrsController.php` | `tests/Unit/Srs/`, `tests/Feature/Srs/` |
| Кабинет студента, прохождение уроков, ДЗ | `README.md` § Учебный кабинет и § Домашние задания | `StudentController.php`, `HomeworkController.php`, `resources/views/student/` | профильные `tests/Feature/Student/`, `tests/Feature/Homework/` |
| Магазин и страницы курсов | `README.md` § Магазин курсов; при цене/доступе также Money / access | `ShopController.php`, `CheckoutController.php`, `Tariff.php` | `tests/Feature/Shop/`, `CheckoutPriceTest` |
| Лендинги и марафон | `README.md` § Конструктор лендингов и § Диагностический марафон | `LandingPage`, `MarathonController.php`, `resources/views/promo/`, `resources/views/marathon/` | профильные feature-тесты; при изменении UI собрать Vite |
| Редактор лекций | `README.md` § Система лекций | `app/Services/Lecture/`, `app/Filament/Editor/`, `lecture-builder/` | профильные тесты редактора |
| Вебхуки и секреты | `CLAUDE.md` § External integrations → [webhook-security](docs/webhook-security.md) | `routes/api.php`, соответствующий контроллер | профильные webhook-тесты, проверка подписи и fail-policy |
| Деплой и ресурсы сервера | `CLAUDE.md` § Deploy / soft-alert → [deploy](docs/deploy.md) | `deploy.sh`, `ops/` | проверки из deploy-чеклиста; никакого ручного `git pull` на проде |
| Новая проверка или неясный запуск тестов | [tests/README.md](tests/README.md) | `tests/` | целевой тест, затем необходимый общий прогон |

Если задача затрагивает несколько доменов, читай карту каждого затронутого домена. Изменение цены и доступа — всегда money/access, даже если визуально это лишь страница магазина.

## 4. Структура репозитория

| Путь | Назначение |
|---|---|
| `app/Models/` | Eloquent-модели и часть доменных инвариантов |
| `app/Http/Controllers/` | HTTP-сценарии веба и вебхуков |
| `app/Services/` | прикладные и интеграционные сервисы |
| `app/Filament/` | ресурсы и страницы панелей `/admin` и `/editor` |
| `app/Livewire/` | интерактивные компоненты кабинета |
| `app/Console/Commands/` | artisan-команды и эксплуатационные процедуры |
| `app/Jobs/` | отложенные задачи |
| `database/migrations/` | источник истины по схеме БД |
| `resources/views/` | Blade-шаблоны |
| `resources/js/`, `resources/css/` | frontend-исходники |
| `routes/` | HTTP-, API-, console- и broadcast-маршруты |
| `tests/` | PHPUnit unit/feature/browser-тесты |
| `docs/` | спецификации, руководства, решения и исторические материалы; вход — [docs/README.md](docs/README.md) |

## 5. Неприкосновенные инварианты

- Доступ к оплаченным урокам выдаётся через оплату и группы; ручного назначения групп как штатного сценария нет.
- `tariff.is_active` управляет **покупкой**, а не уже предоставленным доступом.
- Ключи доступа `full`, `block_N`, `block_N_hH` вычисляются только через `Tariff::accessKey()`, `Lesson::unlockingKeys()`, `Lesson::isUnlockedBy()`.
- Курс-запись получает собственные уроки; нельзя вместо этого выдавать доступ к урокам другого курса.
- Право проверки ДЗ назначается через `group_reviewer`, не через `course_teacher`.
- Финансовые пороги не хардкодятся; используются существующие конфигурации.
- Тяжёлая работа выносится из HTTP-запроса в jobs.
- Секреты, реальные персональные данные и живые финансовые сведения не добавляются в документацию и публичные артефакты.
- На проде используется только документированный `deploy.sh`; tracked `app/` и `config/` на VPS вручную не редактируются.

Подробности и исключения — в соответствующих доменных документах; код и актуальные миграции важнее пересказа.

## 6. Команды

Выполнять из корня репозитория. Если `php` недоступен на Windows, использовать `C:\php83\php.exe` по `AGENTS.md`.

```bash
# Установка и локальный запуск
composer install
npm install
php artisan serve
npm run dev

# Целевые проверки
php artisan test --filter=CheckoutPriceTest
php artisan test --filter=TochkaWebhookTest
php artisan test --filter=TelegramSupportAnalyticsTest
php artisan test --filter=TeacherSalary

# Форматирование и полный прогон
C:\php83\php.exe artisan pint --dirty
php artisan test --parallel

# Frontend, если нет public/build/manifest.json или менялись assets
npm install
npm run build
```

Не заявлять об успешной проверке, если команда не запускалась или завершилась ошибкой.

## 7. Definition of done

- [ ] Прочитаны правила всех затронутых высокорискованных доменов.
- [ ] Сохранены инварианты доступа, денег, приватности и очередей.
- [ ] Запущены профильные тесты; их результат указан честно.
- [ ] Перед PR выполнен необходимый полный прогон и Pint.
- [ ] Для web/Blade-проверок при необходимости собраны Vite-assets.
- [ ] Обновлены только документы, чьи утверждения изменились.
- [ ] При логическом milestone или новом блокере обновлён актуальный handoff по правилам репозитория.
- [ ] В итоге перечислены изменения, проверки и оставшиеся риски.
