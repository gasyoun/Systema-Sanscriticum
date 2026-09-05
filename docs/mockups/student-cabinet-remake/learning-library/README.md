# Мокап «Библиотека» (Learning library) — H957, направление C, мокап №4

_Created: 15-07-2026 · Last updated: 05-09-2026_

Четвёртый мокап ремейка кабинета по рулингу M.G. 15-07-2026: «мокап №4 — направление C».
Направление C из [STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md);
предыдущие: [course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace) (B v1),
[course-workspace-v2/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2) (B v2),
[today-first-coach/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach) (A).
Собрано Fable 5 (`claude-fable-5`) 15-07-2026.

## Проверяемая гипотеза

Если кабинет — личная библиотека владений с жёсткими полками (Идут сейчас / Мои записи /
Истёкшие-с-продлением / Завершённые) и членством как постоянной карточкой уровня полки, то
покупатель записей (сегмент «нет времени», M6) чувствует владение и возвращается сам, а
коммерческое расширение владения (продолжение цикла, членство) читается как естественный
рост библиотеки, не как продажа.

## Дизайн-система

Переиспользует визуальную систему B v2 (styles.css + app.js) — направления сравниваются по
архитектуре. Добавлен слой полок: `.shelf-track` (горизонтальные скроллеры со scroll-snap),
`.owned-card` с лентой срока (`.ribbon`), рельса прогресса `.rail` (Khan-паттерн).

## Страницы (3)

| Файл | Слой | Что доказывает |
|---|---|---|
| [index.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/learning-library/index.html) | Библиотека (полки) | 5 полок владения; истёкшее — структурная полка с продлением (R4); членство — нативная карточка уровня полки (R8/R24); живое — одна полка из пяти (осознанная жертва против R6, показана честно) |
| [item.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/learning-library/item.html) | Предмет | Рельса прогресса вместо списка уроков (прогресс = навигация, Khan); оффер расширения владения после прогресса (R2/R17) со ссылкой на членство |
| [schedule.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/learning-library/schedule.html) | Расписание | Сервисная страница при библиотеке; «запись ложится на полку в течение суток» (M6); слабое место направления названо в подвале |

## Ограничения

- Статический прототип; платежи/помощь/профиль в навигации инертны (эти слои доказаны в B v2;
  в направлении C они не меняются структурно).
- В проде полка «Мои записи» требует отдельного каталога записей (сегодня записи — уроки в
  группах): реальное изменение модели, отмечено в направлении C как data/engineering-цена.
- Контент реалистичный, но иллюстративный; цена членства — по рулингу R24.

## Путь к продукту

Каталог owned-recordings (модельное изменение) → полки как запросы по владению → рельса
прогресса поверх существующего lesson-прогресса → членская карточка (флаг до запуска).
Всё — после выбора победителя (`@DECIDE`) и milestone-инструментации.

## Верификация

Проверено 15-07-2026 (Fable 5, `claude-fable-5`) в headless Chrome через puppeteer-core:
консоль чиста на всех 3 страницах, горизонтального переполнения страницы на 390px нет
(горизонтальный скролл полок — их собственный `overflow-x: auto`, намеренный), переключатель
темы работает. Скриншоты light/dark × desktop/mobile — в [screenshots/](screenshots/);
в mobile-скриншотах полной высоты нижняя панель на время снимка переведена в
`position: absolute` (артефакт full-page склейки fixed-элементов).

_Dr. Mārcis Gasūns_
