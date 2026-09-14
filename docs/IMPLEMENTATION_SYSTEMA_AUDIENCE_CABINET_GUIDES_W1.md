# Реализация, волна 1: студенческий гид, страница в /dvaram, Playwright, PDF

_Created: 21-08-2026 · Last updated: 21-08-2026_

Пошаговая сборка волны 1 для [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md). Компоненты — в [ARCHITECTURE_SYSTEMA_AUDIENCE_CABINET_GUIDES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_AUDIENCE_CABINET_GUIDES.md), приёмка — в [VERIFICATION_SYSTEMA_AUDIENCE_CABINET_GUIDES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_AUDIENCE_CABINET_GUIDES.md).

Исполнитель: [H3212 (Grok 4.6) — Wave 1: illustrated student cabinet guide in /dvaram with Playwright screenshots](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3212-Grok_Systema-Sanscriticum_student-cabinet-guide-illustrated_21.08.26.md).

Система под watcher: авторство в worktree, посадка + коммит без окна. Главное дерево не трогать.

## Шаг 0. Изоляция

```
git -C <клон Systema-Sanscriticum> fetch origin
git -C <клон> worktree add -b h3212-student-guide ../Systema-Sanscriticum-h3212-<pid> origin/main
```

Не фиксированный `../Systema-Sanscriticum-h3212`. `vendor/` копировать робокопией, не junction.

Зависимость: нет.

## Шаг 1. MarkdownGuide

Файл: `app/Support/MarkdownGuide.php`

- `html(string $relativeSource): ?string`
- путь только `base_path($relativeSource)`, никакого input
- `Str::markdown` + `preg_replace` для `src="screenshots/` → raw GitHub, как в TeacherGuide (`SCREENSHOT_BASE`)
- пустой/отсутствующий файл → `null`

Тест: `tests/Feature/MarkdownGuideTest.php` — рендер фикстурного md, подстановка src, null на missing.

Зависимость: шаг 0.

## Шаг 2. Скелет гида

Файл: `docs/STUDENT_CABINET_GUIDE_RU.md`

Шапка с датами, оглавление четырёх частей из архитектуры §4, семь пустых `###` в части I, в части II — по вкладке кабинета (смотреть `resources/views/student/dashboard.blade.php` и [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md), не выдумывать вкладки).

Русский, без `RoleGate`, без имён классов, без «вебхук». Кнопки — дословно.

Зависимость: шаг 0.

## Шаг 3. Текст части I и II

Тот же файл. Порядок — от частого:

1. Войти
2. Открыть урок
3. Сдать ДЗ — не копировать [STUDENT_HOMEWORK_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md), ссылка на `https://samskrte.ru/faq/dz`
4. Словарь
5. Оплатить долг
6. Почему закрыт
7. Позвать куратора

Часть II: курсы, оплаты, долги, прана (ссылка `/help/prana-balance`), колода, лила, профиль. Часть III: shared-PC / remember-me (H1949). Часть IV: FAQ из onboarding.

Каждый шаг части I — место под кадр: `![](screenshots/student-guide/<slug>-1440.png)` и строка «на телефоне — тот же кадр `-390`».

Зависимость: шаг 2.

## Шаг 4. Страница в кабинете

Файлы:

- `app/Http/Controllers/StudentCabinetGuideController.php` (или метод на `StudentController`, если контроллер уже толстый — **новый** класс)
- `resources/views/student/guide.blade.php` — layout кабинета, `prose`, `{!! $html !!}` только из MarkdownGuide
- `routes/web.php` внутри auth-группы `/dvaram`: `GET /dvaram/help` имя `student.help`
- ссылка в шапке/меню кабинета с подписью **«Как пользоваться»**

Гостю — редирект на логин (как у `/dvaram`). Не 200 публично.

Зависимость: шаги 1 и 3.

## Шаг 5. Playwright

1. `npm i -D playwright` в этом worktree, lockfile обновить.
2. `scripts/capture-guide-screenshots.mjs` — читает `docs/generated/student_guide_shots.json`, логинится фикстурой, снимает 1440 и 390, пишет PNG.
3. Манифест: один объект на шаг части I плюс ключевые вкладки части II. `path` — реальные маршруты (`/dvaram`, `/c/{slug}`, lesson, dictionary tab).
4. Фикстура студента: существующая фабрика `User` + оплаченный курс, без прод-БД. Если для локального `artisan serve` нужна sqlite — как Dusk, см. [DUSK_LOCAL_WINDOWS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DUSK_LOCAL_WINDOWS.md); не заводить второй Dusk.

Нет Chromium: шаг 5 заканчивается манифестом без PNG, журнал PR, покрытие кадров в тесте помечается `markTestSkipped` только если файлов нет **и** env `GUIDE_SHOTS_OPTIONAL=1`. По умолчанию отсутствие PNG — красный тест, чтобы не забыть снять на машине с Chrome.

Зависимость: шаг 3 (нужны slug).

## Шаг 6. Вставка кадров и прогон съёмки

Прогнать скрипт против локального сервера. Вставить недостающие `![]()` если манифест вырос. Не коммитить кадры с живым email.

Зависимость: шаг 5.

## Шаг 7. PDF

Файл: `docs/build-student-guide.php` — копия [build-teacher-guide.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/build-teacher-guide.php) со сменой SOURCE/OUTPUT. Не править учительский сборщик.

`chroot` на `docs/`, относительные картинки, DejaVu Sans. Прогнать, размер > 0, в PDF есть image-объекты если кадры есть.

Зависимость: шаг 3; картинки — шаг 6.

## Шаг 8. Тесты покрытия

Файл: `tests/Feature/StudentCabinetGuideCoverageTest.php`

- файл гида существует и не пуст
- каждый `###` части I имеет `screenshots/student-guide/<slug>-1440.png` и `-390.png` (если не `GUIDE_SHOTS_OPTIONAL`)
- `/dvaram/help` = 200 студенту, не 200 гостю
- в тексте гида нет `RoleGate`, `BlockAccessMaterializer`, `PaymentObserver`
- страница содержит заголовок из md

Зависимость: шаги 4 и 6.

## Шаг 9. Freshness

Файл: `scripts/student_guide_freshness.py` — копия teacher-скрипта с WATCHED из архитектуры §6. Вставить в существующий workflow как warn-шаг, код 0 всегда.

Зависимость: шаг 3.

## Шаг 10. Гигиена

1. Шапка [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md): «ученику — /dvaram/help; этот файл — командная карта».
2. Метадок `docs/STUDENT_CABINET_GUIDE_RU.meta.md`.
3. `CHANGELOG.md` `[Unreleased]` → Added, затем `/cut-release`.
4. `.ai_state.md` Next Steps: указатель на план и на H3213.
5. Не удалять onboarding-student.

Зависимость: шаги 4–9.

## Шаг 11. PR, мерж, деплой

Документация + страница + скрипт. Денежный код не в diff. После мержа — известный деплой Systema. Смоук: залогиненный GET `/dvaram/help` содержит «Как пользоваться» или заголовок первого сценария. Гость не видит текст гида.

Зависимость: шаг 10.

_Dr. Mārcis Gasūns_
