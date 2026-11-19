---
metadoc: COURSE_PAGE_ANONS_MAPPING_2026-10-13.md
Created: 13-10-2026
Last updated: 13-10-2026
genre: mapping table
owner: GLM (ZCode/zai-individual-coding-plan/GLM-5.3)
---

_Created: 13-10-2026 · Last updated: 13-10-2026_

Таблица фиксированного соответствия «элемент T1-анонса → поле страницы курса» (H6580): контент-JSON по slug питает блоки «О курсе» (описание/видеоанонс), «Программа курса» (буллеты) и «Частые вопросы» (T5-тизеры) как fallback-источник под каноном БД; числа (цена, кворум, расписание) постом не переносятся.

Потребители: [resources/data/course-page-content.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/course-page-content.json) (данные, ключ = slug), [app/Support/CoursePageContent.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CoursePageContent.php) (чтение + RichHtml-санитизация), [resources/views/shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php) и [resources/views/shop/partials/faq.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/faq.blade.php) (рендер), [tests/Feature/CoursePageContentTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CoursePageContentTest.php) (хардкод-контракт). Родственный паттерн — CourseHeroSchedule (H6570, hero-schedule из контент-JSON). Наполнение — только из опубликованного/каноничного текста поста (скилл anons, чеклист 540); отсутствующий элемент остаётся пустым.

История ревизий: 13-10-2026 H6580 — создание (пилот filosofiya-sankhi: description из текста MG 12-10-2026; буллеты/видео-URL/доступность/FAQ пустые — элементов нет в посте).

_к.ф.н. М.Ю. Гасунс_
