# Реализация: автооткрытие приёма ДЗ, волна 1

_Created: 27-07-2026 · Last updated: 27-07-2026_

Пошаговая последовательность волны 1 плана
[PLAN_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA_2026H2.md).
Схема и границы — в
[ARCHITECTURE_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md).
Шаги идут строго по порядку; каждый называет свои файлы и от чего зависит.

Работа ведётся в отдельном worktree (`git worktree add -b <ветка> ../Systema-Sanscriticum-h1764-<pid> origin/main`),
запись файлов — по протоколу [/watcher-safe-commit](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md),
потому что в этом репозитории внешний процесс откатывает незакоммиченные изменения.
Отдельный `composer install` в worktree обязателен — **никаких junction на чужой `vendor/`**
(ловушка описана в [CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md),
[#713](https://github.com/gasyoun/Systema-Sanscriticum/issues/713)).

## Шаг 1 — конфиг

**Файл:** `config/homework.php` (дополнить, не переписывать).

Добавить блок `auto_open` с ключами из таблицы «Область действия» архитектуры. Все значения
через `env()`, ни одного числа в коде. `course_slugs` по умолчанию **пустой** — фича спит,
пока курс не назван явно; это делает выкатку безопасной по умолчанию.

Зависит от: ничего.

## Шаг 2 — миграция

**Файл:** `database/migrations/2026_07_27_120000_add_homework_auto_open_to_lessons.php`.

Пять колонок на `lessons` по таблице архитектуры, каждая под `Schema::hasColumn`-охраной —
ровно как в
[`2026_06_03_120000_add_homework_fields_to_lessons.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_03_120000_add_homework_fields_to_lessons.php).
Индекс на `(homework_opens_at)` — по нему ходит ежечасная выборка.

`down()` снимает все пять колонок.

Зависит от: шага 1 (нет), но идёт второй, чтобы модель уже имела куда писать.

## Шаг 3 — модель `Lesson`

**Файл:** [`app/Models/Lesson.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php).

1. В `$fillable` — `textbook_lesson`. Остальные четыре колонки **не** в `$fillable`: их
   ставит только автомат, массовое присваивание из формы им противопоказано.
2. В `$casts` — `textbook_lesson` → `integer`, четыре временных поля → `datetime`.
3. В существующий `static::saving()` (он уже есть, там живёт защита `block_number`) добавить:
   если `hasVideo()` истинно и `recording_attached_at` пуст — поставить `now()` и пересчитать
   `homework_opens_at` через `HomeworkAutoOpener::opensAtFor()`. Отметка ставится **один раз**;
   перезаливка видео её не двигает, иначе ДЗ уезжало бы вперёд от замены битой записи.
4. Новый метод — единственная точка правды открытости:

```php
public function homeworkOpenFor(?User $user): bool
```

`homework_enabled` **и** (`homework_closed_at` пуст **или** для `$user` есть грант). В волне 1
грантов ещё нет — ветка возвращает `false` по отсутствию таблицы, поэтому метод пишется сразу
в финальной форме, а проверка гранта включается в волне 2.

5. Скоуп `scopeAutoOpenCandidates()` — курс в охвате, `textbook_lesson` в списке,
   `homework_enabled = false`, `homework_auto_opened_at` пуст, `homework_opens_at <= now()`.

Зависит от: шагов 1–2.

## Шаг 4 — `HomeworkAutoOpener`

**Файл:** `app/Services/HomeworkAutoOpener.php` (новый).

```php
public static function opensAtFor(CarbonInterface $recordingAt): CarbonInterface
public function due(): Collection          // уроки, которые пора открыть
public function open(Lesson $lesson): bool // открыть один урок
```

`opensAtFor()` — чистая функция, статическая, без обращений к БД: прибавить `delay_hours`,
затем перевести на ближайшее `align_hour`:00 в `Europe/Moscow` в момент или после. Именно её
покрывает таблица краевых случаев из архитектуры.

`open()` в транзакции: заполнить `homework_prompt`, если он пуст (шаг 5), поставить
`homework_enabled = true` и `homework_auto_opened_at = now()`, сохранить. Пуш в мессенджеры —
**после** коммита транзакции, как в
[`HomeworkService::recordReview`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkService.php):
синхронный HTTP внутри транзакции держать нельзя.

Идемпотентность обеспечивается `homework_auto_opened_at`: повторный прогон команды в тот же
час не откроет урок дважды и не пришлёт второй пуш.

Зависит от: шага 3.

## Шаг 5 — `KocherginaExerciseSource`

**Файл:** `app/Services/KocherginaExerciseSource.php` (новый).

```php
public function forLesson(int $textbookLesson): ?string
```

Читает mdx по пути из конфига, находит заголовок `Занятие <римская цифра>`, берёт блок от
строки `Упражнения` до следующего заголовка занятия, возвращает текст. Кэш на время процесса —
файл 15 756 строк, читать его пять раз за прогон незачем.

Возвращает `null`, если файл недоступен, занятие не найдено или блок пуст. `null` — не ошибка:
открытие продолжается с отсылочной формулировкой.

**Ограничение D14.** Перед подстановкой текста учебника вызывающая сторона (`open()`)
проверяет `is_free`/`is_preview`. Если урок публичный — текст НЕ подставляется, пишется
«Выполните все упражнения к Занятию N учебника Кочергиной», в лог уходит `warning` с
`lesson_id`. Урок при этом открывается нормально.

Текст учебника не коммитится: в фикстурах тестов — синтетический mdx, сочинённый для теста.

Зависит от: шага 1 (путь из конфига).

## Шаг 6 — уведомление

**Файл:** `app/Services/HomeworkNotifier.php` (дополнить).

Новый метод `opened(Lesson $lesson): void`. Получатели — активные студенты группы урока
(`group_id` пуст ⇒ все студенты курса). Каналы — из `homework.auto_open.notify_channels`,
по умолчанию Telegram и VK, **без письма** (D9).

Текст: «Открылось домашнее задание к уроку «<название>»» + ссылка
`route('student.lesson', [$course->slug, $lesson->id])`. Форма и обработка ошибок копируются
с `pushReviewToMessengers`: `try/catch` вокруг отправки, падение канала пишется в лог и не
роняет открытие.

Зависит от: шага 4.

## Шаг 7 — команда

**Файл:** `app/Console/Commands/AutoOpenHomeworkCommand.php` (новый), сигнатура
`homework:auto-open {--dry-run} {--backfill-last}`.

Без флагов: пройти `due()`, открыть каждый, вывести таблицу «урок / занятие / когда открыт».
`--dry-run` — та же таблица, ни одной записи в БД и ни одного пуша.
`--backfill-last` (D11) — взять **один** урок: самый свежий прошедший урок каждого курса в
охвате, у которого есть запись и не включено ДЗ; открыть его без уведомления. История
дальше одного урока не трогается.

**Файл:** [`app/Console/Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php) —
`$schedule->command('homework:auto-open')->hourly()`, рядом с `content:publish-due`, с
`->withoutOverlapping()` и `->onOneServer()` по образцу соседних строк.

Ежечасный, а не ежедневный: момент открытия вычисляется точно, ежечасный проход просто
доносит его с задержкой не больше часа и переживает перезапуск сервера.

Зависит от: шагов 4–6.

## Шаг 8 — поверхности

1. [`app/Filament/Resources/LessonResource.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/LessonResource.php) —
   в секцию «Домашнее задание» добавить `TextInput::make('textbook_lesson')` (числовое, 1–40,
   подсказка «Занятие учебника Кочергиной») и **только для чтения** показ `homework_opens_at`.
2. [`app/Http/Controllers/HomeworkController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/HomeworkController.php) —
   заменить `abort_unless((bool) $lesson->homework_enabled, 404, …)` на
   `abort_unless($lesson->homeworkOpenFor($user), 404, …)`. Одна строка, но это и есть
   серверный гейт: без неё закрытие волны 2 обходится прямым POST.
3. [`app/Http/Controllers/StudentController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentController.php)
   (строка ~847) — ветка `if ($lesson->homework_enabled)` переводится на тот же метод.

Зависит от: шага 3.

## Шаг 9 — тесты

**Файл:** `tests/Feature/Homework/AutoOpenHomeworkTest.php` (новый).

Полный список того, что должно быть покрыто, — в
[VERIFICATION_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md).
Время морозится `Carbon::setTestNow()`, таймзона в тестах — `Europe/Moscow`, как в проде.

Зависит от: шагов 1–8.

## Шаг 10 — регистрация

1. `CHANGELOG.md` — пункт в `[Unreleased] / Added`, затем
   [/cut-release](https://github.com/gasyoun/claude-config/blob/main/commands/cut-release.md).
2. [`CLAUDE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) — раздел
   про новый контур рядом с «Group Reviewers»: где живёт правило, почему `homework_auto_opened_at`
   решает вопрос владения, и что `homeworkOpenFor()` — единственная точка правды.
3. `.ai_state.md` — перенести пункты в «Завершено», следующие шаги указать на волну 2.

Зависит от: шага 9.

## Журнал решений

Пустой на старте. Исполняющий агент дописывает сюда каждую развилку, разрешённую по D13:
дата, развилка, применённый дефолт, одна строка обоснования.

_Dr. Mārcis Gasūns_
