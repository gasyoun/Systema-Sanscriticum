# H2761 — Flagship syllabus, free step, teacher, FAQ

_Created: 15-08-2026 · Last updated: 15-08-2026_

Three Gasuns flagships on `shop.course.show` (`/k/{slug}`): thematic «Чему научитесь», a non-stale first step, teacher block, course FAQ. Calendar «Ближайшие занятия» stays.

## Schema note — fields already exist

No new Course column. Three existing routes:

| Slot | Store | Grep |
|---|---|---|
| Thematic syllabus | `courses.outcomes` json array | [app/Models/Course.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php) `$casts['outcomes']` |
| Fit / audience | `courses.audience` json array | same file, `$casts['audience']` |
| Course FAQ | `course_faqs` | [app/Models/CourseFaq.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/CourseFaq.php) |
| Teacher | `teachers.bio`, `teachers.photo_path` | [app/Models/Teacher.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Teacher.php) |

Empty Filament rows hide the Blade slots. Overlay: [config/flagship_landing.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/flagship_landing.php) via [app/Support/FlagshipLanding.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/FlagshipLanding.php). A filled admin field wins.

Photo: the teacher slot already exists. Prod `photo_path` is empty (initial «Г»). Do not invent a photo; upload in Filament when ops has the file.

No GTD `needs_repository_confirmation` — the Course fields were already there.

## Three Course-schema routes (prod)

| Program | URL | Match |
|---|---|---|
| Кочергина | [https://samskrte.ru/k/grammatika-po-kocerginoi-gr61](https://samskrte.ru/k/grammatika-po-kocerginoi-gr61) | slug contains `kocerginoi` |
| Старт чтения | [https://samskrte.ru/k/start-chteniya](https://samskrte.ru/k/start-chteniya) | slug `start-chteniya` |
| Бюлер | [https://samskrte.ru/k/grammatika-po-biulleru-gr27](https://samskrte.ru/k/grammatika-po-biulleru-gr27) | slug contains `biulleru` |

JSON-LD `Course.teaches` is filled from outcomes (DB or overlay).

## Section checklist

| Section | Selector | Kochergina | Start чтения | Бюлер |
|---|---|---|---|---|
| Чему научитесь | `#chemu-nauchites` | 8 themes | 6 themes | 6 themes |
| Первый шаг | `#flagship-free-step` | evergreen → `/online/materialy` | evergreen → `/reading/kosha-demo` | осень 2027 + `#tariffs` |
| Преподаватель | `#teachers` | existing name + published bio if `bio` empty | same | same |
| FAQ | `#course-faq` | 6 course questions | 6 | 6 |
| Ближайшие занятия | schedule heading | keep calendar | keep if sessions exist | keep calendar |

Büehler free step must not claim a 2026 intake. Copy: «Нового старта в 2026 году нет». Teacher prose is condensed from [https://samskrtam.ru/marcis-gasuns](https://samskrtam.ru/marcis-gasuns) and the live course descriptions — not a new biography.

## Prove

- Tests: `FlagshipLandingTest`, `FlagshipLandingPageTest`
- After deploy: curl the three URLs and grep `#chemu-nauchites`, `#flagship-free-step`, `#teachers`, `#course-faq`

_Dr. Mārcis Gasūns_
