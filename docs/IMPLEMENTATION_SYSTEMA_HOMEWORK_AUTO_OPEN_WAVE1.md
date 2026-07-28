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

## Покрытие источника — что подставится по каждому занятию

Промер оцифровки на контракте `KocherginaExerciseSource::forLesson()`, снят 28-07-2026
(H1789, пересчитан после H1790). Отвечает на вопрос эксплуатации: у какого занятия
условие ДЗ будет с текстом упражнений, а у какого — с отсылочной формулировкой.

Источник: `Kochergina_unicode.mdx`, 15 756 строк, 945 952 байт. Текст занятия возвращается для **35 из 40** занятий; для 5 (11, 20, 27, 34, 40) блока «Упражнения» в источнике нет, и открытие идёт с отсылочной формулировкой — это штатное поведение A8, а не дефект. Размер блока: медиана 2 411, минимум 1 270, максимум 4 167 символов; выбросов больше 4× медианы нет, то есть граница «до следующего заголовка занятия» нигде не съела посторонний раздел.

**Для волны 1 (занятия 1–5) текст есть у всех пяти**, размеры блоков 1 270…2 420
символов, 4–5 заданий на занятие. Пять занятий без блока — 11, 20, 27, 34, 40 — в пилот
не входят и попадут в охват только в следующих волнах; поведение для них штатное (A8),
а не деградация.

| Занятие | Заголовок, стр. | `Упражнения`, стр. | Блок, симв. | Непустых строк | Упражнений | Примечание |
|---|---|---|---|---|---|---|
| 1 (I) | 229 | 388 | 1270 | 53 | 4 (I–IV) |  |
| 2 (II) | 462 | 532 | 1798 | 42 | 5 (I–V) |  |
| 3 (III) | 612 | 683 | 2278 | 89 | 5 (I–V) |  |
| 4 (IV) | 790 | 859 | 2420 | 87 | 4 (I–IV) |  |
| 5 (V) | 965 | 1097 | 1894 | 72 | 5 (I–V) |  |
| 6 (VI) | 1185 | 1284 | 1393 | 30 | 4 (I–IV) |  |
| 7 (VII) | 1330 | 1395 | 1353 | 35 | 4 (I–IV) |  |
| 8 (VIII) | 1440 | 1540 | 2122 | 60 | 5 (I–V) |  |
| 9 (IX) | 1622 | 1787 | 1741 | 63 | 4 (I–IV) |  |
| 10 (X) | 1869 | 2040 | 3099 | 123 | 5 (I–V) |  |
| 11 (XI) | 2179 | — | — | — | — | 2-й заголовок в хрестоматии, стр. 11008; **нет блока «Упражнения» → отсылочная формулировка** |
| 12 (XII) | 2285 | 2454 | 4167 | 97 | 5 (I–V) |  |
| 13 (XIII) | 2583 | 2796 | 3511 | 90 | 5 (I–V) |  |
| 14 (XIV) | 2909 | 3076 | 3906 | 94 | 5 (I–V) |  |
| 15 (XV) | 3193 | 3325 | 3987 | 111 | 5 (I–V) |  |
| 16 (XVI) | 3464 | 3710 | 3178 | 107 | 4 (I–IV) |  |
| 17 (XVII) | 3870 | 4130 | 2523 | 87 | 6 (I–VI) |  |
| 18 (XVIII) | 4267 | 4361 | 3110 | 108 | 7 (I–VII) |  |
| 19 (XIX) | 4529 | 4704 | 2191 | 73 | 5 (I–V) |  |
| 20 (XX) | 4812 | — | — | — | — | 2-й заголовок в хрестоматии, стр. 11072; **нет блока «Упражнения» → отсылочная формулировка** |
| 21 (XXI) | 4916 | 5056 | 2411 | 85 | 5 (I–V) |  |
| 22 (XXII) | 5218 | 5364 | 2141 | 84 | 5 (I–V) |  |
| 23 (XXIII) | 5529 | 5651 | 1824 | 74 | 5 (I–V) |  |
| 24 (XXIV) | 5783 | 5990 | 2476 | 98 | 5 (I–V) |  |
| 25 (XXV) | 6131 | 6269 | 2298 | 84 | 5 (I–V) |  |
| 26 (XXVI) | 6397 | 6544 | 2715 | 98 | 5 (I–V) |  |
| 27 (XXVII) | 6690 | — | — | — | — | 2-й заголовок в хрестоматии, стр. 11126; **нет блока «Упражнения» → отсылочная формулировка** |
| 28 (XXVIII) | 6788 | 7091 | 2174 | 96 | 5 (I–V) |  |
| 29 (XXIX) | 7245 | 7454 | 1990 | 102 | 5 (I–V) |  |
| 30 (XXX) | 7600 | 7785 | 2831 | 96 | 5 (I–V) |  |
| 31 (XXXI) | 7979 | 8243 | 2070 | 81 | 5 (I–V) |  |
| 32 (XXXII) | 8401 | 8510 | 2821 | 79 | 5 (I–V) |  |
| 33 (XXXIII) | 8652 | 8806 | 2064 | 60 | 5 (I–V) |  |
| 34 (XXXIV) | 8928 | — | — | — | — | 2-й заголовок в хрестоматии, стр. 11198; **нет блока «Упражнения» → отсылочная формулировка** |
| 35 (XXXV) | 9011 | 9155 | 2536 | 64 | 5 (I–V) |  |
| 36 (XXXVI) | 9280 | 9444 | 2428 | 83 | 5 (I–V) |  |
| 37 (XXXVII) | 9605 | 9787 | 2638 | 78 | 5 (I–V) |  |
| 38 (XXXVIII) | 9940 | 10210 | 2837 | 75 | 5 (I–V) |  |
| 39 (XXXIX) | 10357 | 10489 | 2186 | 62 | 5 (I–V) |  |
| 40 (XL) | 10600 | — | — | — | — | **нет блока «Упражнения» → отсылочная формулировка** |

Канонический экземпляр этой таблицы — в репозитории источника,
[EXERCISE_BLOCK_COVERAGE_KOCHERGINA_2026.md](https://github.com/gasyoun/SanskritGrammar/blob/main/KocherginaUchebnik_1998/EXERCISE_BLOCK_COVERAGE_KOCHERGINA_2026.md); там же разобраны особенности
оцифровки, на которые здесь опираться нельзя: дублирующиеся заголовки занятий
XI/XX/XXVII/XXXIV в хрестоматии (потребитель берёт **первое** вхождение — не менять на
последнее) и шесть разных форм маркера задания. Номера строк относятся к состоянию
`Kochergina_unicode.mdx` на 28-07-2026: после `npm run convert` в SanskritGrammar их
надо пересчитать в обоих экземплярах.

Правкой H1790 занятию 30 вернули точку у маркера упражнения II в самом `.docx`, поэтому
разрывов нумерации не осталось ни в одном из 35 занятий с блоком. На поведение
`HomeworkAutoOpener` это не влияет — он берёт блок целиком и заданий не разбирает.

## Журнал решений

Пустой на старте. Исполняющий агент дописывает сюда каждую развилку, разрешённую по D13:
дата, развилка, применённый дефолт, одна строка обоснования.

_Dr. Mārcis Gasūns_
