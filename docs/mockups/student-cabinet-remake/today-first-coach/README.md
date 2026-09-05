# Мокап «Сегодня» (Today-first coach) — H956, направление A, мокап №3

_Created: 15-07-2026 · Last updated: 05-09-2026_

Третий мокап ремейка кабинета по рулингу M.G. 15-07-2026: «мокап №3 — направление A».
Направление A из [STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md);
предыдущие мокапы: [course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace) (B v1),
[course-workspace-v2/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2) (B v2).
Собрано Fable 5 (`claude-fable-5`) 15-07-2026.

## Проверяемая гипотеза

Если главная — не дом курсов, а **план на сегодня** (нумерованный, с фиксированным честным
порядком: незаконченный урок → домашние на доработке → сегодняшнее живое → первые шаги →
один следующий шаг после реального прогресса), то возвращающийся студент начинает заниматься
быстрее, чем в любой курсо-центричной архитектуре, а оффер в виде «пункта плана после
прогресса» ощущается как забота, не как продажа.

## Дизайн-система

Сознательно **переиспользует визуальную систему B v2** (styles.css + app.js, издательский
академизм, навигация по работам, R14–R16/R23): направления должны сравниваться по
**архитектуре**, а не по стилю. Добавлен только слой плана (`.plan`, `.debt-banner`).

## Страницы (4)

| Файл | Состояние | Что доказывает |
|---|---|---|
| [index.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/today-first-coach/index.html) | Обычный вечер | План дня из 5 пунктов; continue — всегда пункт 1 (R5); чеклист — пункт-группа (R12); оффер — пункт плана ПОСЛЕ прогресс-события (R2, «чистейшее» прочтение); «Почему такой план?» — ответ на главный риск направления (непрозрачный алгоритм) |
| [index-debt.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/today-first-coach/index-debt.html) | Recovery: платёж отклонён | Баннер проблемы ведёт; офферы подавлены безусловно; доступное продолжает работать (бессрочные записи, живое занятие) — честность вместо блокировки всего |
| [courses.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/today-first-coach/courses.html) | Тонкие курсы | Слабое место направления показано честно: курс — только список уроков, без «дома»; истёкший доступ виден (R4) |
| [lesson.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/today-first-coach/lesson.html) | Урок в плане | «Пункт 1 из 5», футер «дальше по плану» — план следует за студентом; гибридный прогресс (R7) |

## Ограничения

- Статический прототип: расписание/записи/платежи/помощь в навигации ведут на существующие
  страницы мокапа — эти слои уже доказаны в B v2 и не перестраивались здесь (у направления A
  они отличаются только тем, что вход в них — через план).
- Порядок плана в проде — `buildContinueLearningAction()` + ранжирование пунктов; здесь
  захардкожен в разметке.
- Контент реалистичный, но иллюстративный.

## Путь к продукту

Самое дешёвое направление: спайн уже существует (`buildContinueLearningAction()`, ContinueCard
из PR #353); нужны ранжирование пунктов плана + recovery-детектор (отклонённый платёж → баннер
+ подавление офферов) + вынос курсов в тонкие страницы. Всё — после выбора победителя
(`@DECIDE`) и milestone-инструментации.

## Верификация

Проверено 15-07-2026 (Fable 5, `claude-fable-5`) в headless Chrome через puppeteer-core:
консоль чиста на всех 4 страницах, горизонтального переполнения на 390px нет, переключатель
темы работает. Скриншоты light/dark × desktop/mobile — в [screenshots/](screenshots/);
в mobile-скриншотах полной высоты нижняя панель на время снимка переведена в
`position: absolute` (артефакт full-page склейки fixed-элементов).

_Dr. Mārcis Gasūns_
