# Таблица «элемент T1-анонса → поле страницы курса» (H6580)

_Created: 13-10-2026 · Last updated: 13-10-2026_

Фиксированное соответствие, по которому страница курса `samskrte.ru/k/<slug>` наполняется контентом из запускового анонса (тип T1 по [typology playbook](https://github.com/gasyoun/claude-config/blob/main/references/anons-types-playbook.md); чеклист поста — [anons-post-template-checklist](https://github.com/gasyoun/claude-config/blob/main/references/anons-post-template-checklist.md)). Данные — контент-JSON [resources/data/course-page-content.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/course-page-content.json) (ключ = slug курса), читает [CoursePageContent](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CoursePageContent.php) — паттерн H6570 (CourseHeroSchedule, PR #3235).

## Таблица соответствия

| Элемент поста (T1-скелет, пункт чеклиста) | Поле контент-JSON | Поле страницы / рендер |
|---|---|---|
| Заголовок 🗣 CAPS (п. 1) | — не переносится | `course.title` — канон БД; заголовок поста равен названию курса |
| Идейный хук «Зачем изучать…» (п. 5, тело) | `description_html` | блок «О курсе» — fallback при пустом `course.description` (`data-testid="course-anons-description"`); БД — канон, пост — зеркало |
| Буллеты «Что вас ждет» (п. 5) | `program_bullets[]` | секция «Программа курса» — fallback при отсутствии блоков с содержимым (`data-testid="course-anons-program"`) |
| Портрет преподавателя (п. 7) | `teacher_bio` | резерв: у курса педагог-блок рендерится из БД; JSON — когда портрет есть только в посте |
| Видеоанонс 3 платформы (п. 3) | `video_links{VK Видео, Rutube, YouTube}` = URL | строка «Видеоанонс: …» над описанием (`data-testid="course-anons-video"`) |
| Доступность «Подойдет всем…» (п. 6) | `prerequisites` | резерв под блок пререквизитов; факт «подойдет всем» уже несёт страница |
| T5-тизеры (серия постов по курсу) | `faqs[{q,a}]` | «Частые вопросы» — источник между `course->faqs` (БД) и flagship-оверлеем (H2761) |

**Не переносится никогда** (п. 2 чеклиста — шапка-логистика): цена, кворум «нужно N намерений», расписание/дата старта, длительность, CTA-маршруты записи. Канон этих чисел — страница (тарифы, ждун-блок, hero-schedule H6570); пост зеркалит снапшот.

Отсутствующий в посте элемент = `null`/`[]` в JSON и остаётся пустым — факты программы не выдумываются (устав скилла anons); поле заполняется той же механикой, когда элемент появляется в опубликованном посте.

## Пилот filosofiya-sankhi (13-10-2026)

- **Источник текста** — канонический текст MG от 12-10-2026 («Зачем изучать санкхью», догмат о влиянии санкхьи), передан для переделки под эталон 540 в сессии H6576 и каноничен по рулингу MG; на стене [t.me/s/samskrte](https://t.me/s/samskrte) пост на момент лендинга не опубликован (стена кончается № 647) — текст взят дословно из присланного MG оригинала, логистические строки поста (16×2, блок 6 000 ₽, пн 18:00 МСК, кворум 8) в страницу не переносились.
- **Заполнено:** `description_html` — 8 абзацев текста MG (идейный хук).
- **Пустые поля (до = после):** `program_bullets` — в посте нет буллетов «Что вас ждет»; `video_links` — в посте строка «Видеоанонс: VK Видео · Rutube · YouTube» без URL (плейсхолдеры до публикации, H6576); `prerequisites` — строка доступности в посте отсутствует; `faqs` — T5-тизеры по санкхье ещё не выходили; `teacher_bio` — портрет в посте отсутствует (педагог-блок страницы рендерит Максима Леонова из БД).

## Правка и наполнение

1. Новый курс: ключ = slug в [course-page-content.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/course-page-content.json), поля по таблице; `description_html` санитизируется `RichHtml` при чтении (тот же контур, что `course.description_html`, H2896 #8).
2. Источник — только опубликованный (или каноничный от MG) текст поста; числа не переносить.
3. Хардкод-ассерты [CoursePageContentTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CoursePageContentTest.php) — публичные обещания страницы; правка JSON без правки теста должна ломать тест сознательно.

_к.ф.н. М.Ю. Гасунс_
