# Мокап «Курс как дом» v2 — H822, итерация направления B

_Created: 14-07-2026 · Last updated: 05-09-2026_

Вторая итерация направления B (Course workspace) по рулингу M.G. 14-07-2026: «мокап №2 остаётся
направлением B». Первая итерация: [course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace);
доказательная база — [STUDENT_CABINET_REMAKE_RESEARCH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md);
направления и рулинги — [STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md).
Собрано Fable 5 (`claude-fable-5`) 14-07-2026.

## Рулинги итерации (M.G., 14-07-2026, интервью в сессии H822-resume)

- **R21.** Мокап №2 = итерация направления B, не новое направление.
- **R22.** Основной объём — добить недостающие слои страниц: библиотека записей со слотом
  членства (R8), календарь, прогресс+сертификат, сообщения.
- **R23.** В №1 менять всё три: визуальный тон/плотность, структуру страниц, тексты и названия.
- **R24.** Членство — полноценная карточка с ценой (2000 ₽/мес · 20 000 ₽/год), составом и
  честным «скоро»; место — постоянный слот в библиотеке записей.
- **R25.** Лёгкий JS: рабочие табы, переключатель темы, сворачиваемые блоки.

## Что изменилось против v1

| Ось | v1 | v2 |
|---|---|---|
| Страниц | 4 | 8 (+ библиотека, календарь, прогресс, сообщения) |
| Визуальный тон | нейтральный «чистый» | издательский академизм: засечки в заголовках, small-caps кикеры с линейками, тёплая бумага, золотой акцент |
| Главная | стопка карточек | композитная лента «Сегодня» (продолжить + живое в одном блоке), курсы строками, чеклист свёрнут в одну строку |
| Навигация | «Мои курсы / Расписание / Платежи / Помощь / Инструменты» | по работам: «Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь»; инструменты — тихая строка ссылок |
| Интерактив | нет (статика) | app.js: табы курса с хэш-адресацией, тема с localStorage, foldline-блоки с памятью состояния |
| Членство | не показано | полноценная карточка «Самскрте+» в библиотеке (R24) |

## Страницы

| Файл | Слой | Что доказывает |
|---|---|---|
| [index.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/index.html) | Сегодня (главная) | R5 в одной ленте; R12 чеклист одной строкой; R4 истёкший курс виден строкой с продлением; R10 инструменты вторичны |
| [course.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/course.html) | Дом курса | R9 workspace-табы (JS + hash); единственный оффер в момент прогресса (R2/R17); «почему закрыто?» на замках |
| [lesson.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/lesson.html) | Урок | R7 гибридный прогресс; C-L1 конспект над домашним на мобайле; автосохранение заметок (F8) |
| [library.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/library.html) | Библиотека записей | R8/R24 слот членства с ценой и честным «скоро»; R4 истёкшая покупка видна; M6 «в своём темпе» |
| [calendar.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/calendar.html) | Календарь | M8 три часовых пояса; iCal; «догонять не нужно» (M6); прошедшее → запись в библиотеке |
| [progress.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/progress.html) | Прогресс + сертификат | R7 честный счёт; без серий/шкал срочности (guardrail M5); сертификат с проверкой подлинности |
| [access.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/access.html) | Оплата и доступ | R4 «требует внимания» первым; «Почему закрыто?» (D §4A); подавление промо при проблеме доступа (R2) |
| [messages.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/messages.html) | Помощь | R19 пикер тем с автоконтекстом; web-FAQ (F15); тред куратора без «магических фраз» |

## Ограничения

- Статический прототип с лёгким JS: оплата, отправка сообщений, плеер — макеты.
- Контент реалистичный, но иллюстративный; имён/дат реальных студентов нет.
- Цена членства показана по прямому рулингу R24 (M.G. выбрал «полноценную карточку с ценой»
  при явно предложенной альтернативе «без цены»); остальные цены по-прежнему скрыты.
- Тёмная тема — `prefers-color-scheme` + ручной переключатель (localStorage).

## Путь к продукту

Тот же Blade-скелет, что в v1 (workspace-роут с саб-табами, lapse-детектор, heartbeat-прогресс),
плюс: библиотека — новый роут `/library` поверх существующих записей; календарь — рендер
существующего расписания групп; членство — витринная карточка до запуска подписки (флаг).
Всё — после выбора победителя (`@DECIDE`) и milestone-инструментации (ledger §6).

## Верификация

Проверено 14-07-2026 (Fable 5, `claude-fable-5`) в headless Chrome через puppeteer-core:
консоль на всех 8 страницах (error/warning/pageerror), горизонтальное переполнение на 390px,
работа табов/темы. Скриншоты light/dark × desktop/mobile — в [screenshots/](screenshots/);
в mobile-скриншотах полной высоты фиксированная нижняя панель на время снимка переведена в
`position: absolute` (full-page склейка рисует fixed-элементы посреди страницы) — в живой
странице она fixed.

_Dr. Mārcis Gasūns_
