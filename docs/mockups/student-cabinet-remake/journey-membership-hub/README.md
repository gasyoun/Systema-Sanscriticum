# Мокап «Путь» (Journey & membership hub) — H958, направление D, мокап №5

_Created: 15-07-2026 · Last updated: 05-09-2026_

Пятый (последний по составу направлений) мокап ремейка кабинета по рулингу M.G. 15-07-2026:
«мокап №5 — направление D». Направление D из
[STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md);
предыдущие: [B v1](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace) ·
[B v2](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2) ·
[A](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach) ·
[C](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/learning-library).
Собрано Fable 5 (`claude-fable-5`) 15-07-2026.

## Проверяемая гипотеза

Если кабинет показывает лестницу школы (письмо → грамматика → тексты, мост M3) как видимый
путь со станциями, вехами и честными правилами загорания следующей станции (только после
полного прохождения текущей, без таймеров), то продолжение лестницы — самая глубокая и
дорогая LTV-траектория школы — продаёт себя само, а членство обретает точную роль:
непрерывность между платными станциями.

## Дизайн-система

Переиспользует визуальную систему B v2 (styles.css + app.js). Добавлен слой пути: `.path`
(вертикальная линия с узлами: done/current/lit), `.station` (в т. ч. `lit` — «загоревшаяся»),
`.milestones` (вехи-ориентиры), `.offpath` (внепутевые владения).

## Страницы (3)

| Файл | Состояние | Что доказывает |
|---|---|---|
| [index.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/journey-membership-hub/index.html) | Путь, станция в процессе | Карта уровней (пройдено/текущее/следующее/горизонт); следующая станция НЕ горит, пока текущая не пройдена («станция подождёт вас» — снятие риска давления); вехи как ориентиры контура обучения; членство = непрерывность между станциями; полка «Вне пути — и это нормально» (зигзаг-студент не пристыжен) |
| [index-completed.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/journey-membership-hub/index-completed.html) | Путь после завершения станции | Ключевой флоу направления: станция II «загорается» с лестничным оффером ТОЛЬКО после полного прохождения I (R2), «ранняя запись без дедлайн-таймера»; членство сильнее всего именно в паузе между станциями |
| [station.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/journey-membership-hub/station.html) | Станция = курс | Внутренность станции наследует «дом курса» B; вехи станции (аттестации) как ориентиры, не дедлайны оплаты; место станции на пути |

## Ограничения

- Статический прототип; записи/платежи/помощь в навигации инертны (слои доказаны в B v2 / C).
- В проде путь требует явного графа учебной программы — сегодня его приближает только
  `TrajectoryPaths` на публичной главной; это самая тяжёлая data-model-цена направления,
  зафиксированная в его описании.
- Контент реалистичный, но иллюстративный; цена членства — по рулингу R24.

## Путь к продукту

Граф программы (модельное изменение, самое дорогое из всех направлений) → PathMap поверх
`TrajectoryPaths` → StationCard = существующий курс + вехи → «загорание» = completion-событие
станции. Всё — после выбора победителя (`@DECIDE`) и milestone-инструментации.

## Верификация

Проверено 15-07-2026 (Fable 5, `claude-fable-5`) в headless Chrome через puppeteer-core:
консоль чиста на всех 3 страницах, горизонтального переполнения на 390px нет, переключатель
темы работает. Скриншоты light/dark × desktop/mobile — в [screenshots/](screenshots/);
в mobile-скриншотах полной высоты нижняя панель на время снимка позиционирована абсолютно
от низа страницы (артефакт full-page склейки fixed-элементов).

_Dr. Mārcis Gasūns_
