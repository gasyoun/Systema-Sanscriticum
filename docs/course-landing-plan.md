# План реализации: продающие страницы курсов

_Created: 04-07-2026 · Last updated: 04-07-2026_

Исполняемый план к спеке [course-landing-spec.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/course-landing-spec.md).
Ветка: `feat/course-landing` от `origin/main`, доставка PR-only.
⚠️ Репозиторий под watcher'ом — все правки через watcher-safe (scratchpad → land+commit одной командой).

Фазы упорядочены по зависимостям. Фаза 2 (безопасность preview) изолирована и тестируется **до** UI.

---

## Фаза 0 — Ветка
- `git checkout -b feat/course-landing origin/main`.
- Прогнать `php artisan migrate` на копии — чисто ли перед стартом.

## Фаза 1 — Слой данных (миграции + модели)

Миграции (по одной на концерн, конвенции репо: `foreignId()->constrained()->cascadeOnDelete()`,
`sort_order` = `unsignedSmallInteger` default 0, `is_visible` bool default true, `timestamps()`):

1. `create_course_faqs_table` — `course_id`, `question` string, `answer` text, `sort_order`, `is_visible`.
2. `create_testimonials_table` — `author_name`, `city?`, `avatar_path?`, `body` text, `rating?` (tinyint), `media_url?`, `is_visible`.
3. `create_course_testimonial_table` — `course_id`, `testimonial_id`, `sort_order`, `unique(course_id,testimonial_id)`.
4. `add_landing_fields_to_courses` — `audience` json?, `outcomes` json?, `tech_requirements` text?, `meta_title` string?, `meta_description` string?.
5. `add_is_preview_to_lessons` — `is_preview` boolean default false + index.
6. `add_photo_path_to_teachers` — `photo_path` string?.

Модели:
- `CourseFaq` (fillable; `belongsTo(Course)`), `Testimonial` (fillable; `belongsToMany(Course)`).
- [`Course`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php): `hasMany(CourseFaq)`,
  `belongsToMany(Testimonial)->orderByPivot('sort_order')`, касты `audience`/`outcomes` → `array`,
  аксессор `techRequirements()` (override ?? `MarketingSetting`).
- [`Lesson`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php): каст `is_preview` bool; `scopePreview()`.
- [`Teacher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Teacher.php): `photo_path` в fillable.

**DoD:** `migrate:fresh` чисто; unit-тест реляций и round-trip array-кастов.

## Фаза 2 — Доступ к preview + безопасность (highest-risk, делаем первой после данных)

- [`Lesson::isUnlockedBy()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php):
  вернуть `true`, если `is_preview` — **до** проверки ключей `full`/`block_N`/`block_N_hH` (их не трогаем).
- Публичный роут: `GET /online/kursy/{course:slug}/preview` → резолвит preview-урок курса; 404 если нет;
  рендерит существующий плеер без auth-middleware.
- Жёсткая проверка принадлежности: урок обязан быть этого курса И `is_preview` — иначе 403.
- Filament-валидация: не более одного `is_preview` на курс.

**DoD — обязательные Feature-тесты (безопасность):**
- гость открывает preview-урок курса → 200;
- гость на не-preview уроке → 403/redirect;
- preview-роут при отсутствии preview-урока → 404;
- preview курса A не открывает уроки курса B.

## Фаза 3 — Контроллер + Blade-партиалы

- [`ShopController::show`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/ShopController.php):
  в существующий `with([...])` добавить `faqs`, `testimonials`, `teachers` (со-преподы); передать `previewLesson`.
- `<head>` лейаута: `title` = `meta_title ?: title.' — samskrte.ru'`;
  `meta description` = `meta_description ?: Str::limit(strip_tags(description),160)`.
- Партиалы в `resources/views/shop/partials/`: `trust-strip`, `audience`, `outcomes`, `sample`,
  `teachers`, `testimonials`, `faq`, `tech-payment`, `final-cta` — тёмная тема/токены как в каталоге.
- [`show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php):
  вставить в порядке секций спеки; каждый блок под `@if` (скрыт при пустых данных).
- Hero: вторая CTA `Смотреть пробный урок` → `#sample` (только если есть preview-урок).

**DoD:** страница рендерит все блоки для заполненного курса; пустые скрыты; якорь `#tariffs` цел.

## Фаза 4 — Filament-админка

- [`CourseResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/CourseResource.php):
  вкладка **«Лендинг»** — `TagsInput` `audience`/`outcomes`, Tiptap `tech_requirements`, `TextInput` `meta_title`/`meta_description`.
- `FaqsRelationManager` (question/answer/sort_order/is_visible, reorderable) — паттерн репитера `tariffs`.
- `TestimonialResource` (CRUD библиотеки) + `TestimonialsRelationManager` на Course (attach + pivot `sort_order`).
- Редактор уроков: `Toggle` `is_preview` + валидация «≤1 на курс».
- `TeacherResource`: `FileUpload` → `photo_path`.

**DoD:** редактор заполняет все поля; со-препод уже поддержан существующим репитером.

## Фаза 5 — Сид + визуальная проверка + тесты

- Заполнить один флагманский курс («Грамматика по Кочергиной») как эталон.
- Скилл `blade-styling` (Playwright) — десктоп + мобайл.
- Feature-тесты: show/hide блоков; фолбэк meta; безопасность preview (из Фазы 2).
- `./vendor/bin/pint`.

**DoD:** `php artisan test` зелёный; визуально ок на двух ширинах.

## Фаза 6 — PR

- PR `feat/course-landing` → `main`; в теле — ссылка на спеку, чек-лист фаз, скрин эталонного курса.
- **Не** auto-merge — крупная фича на ревью.

---

## Риски и границы
- **Единственный реальный риск — публичность preview.** Изолирован в Фазе 2, тестируется до UI.
- **Не трогать** логику тарифов/ключей доступа (`full`/`block_N`/`block_N_hH`), депозит, пробное (paid trial ≠ free preview).
- Два преподавателя: рендерить `teacher` + `teachers` (unique), фото по `photo_path`.
- Раскатка безопасна: пустые поля → блоки скрыты, существующие курсы не ломаются.

## Порядок и параллелизм
Фаза 1 → 2 (последовательно, критический путь). После Фазы 1 фазы 3 и 4 можно вести параллельно;
Фаза 5 сводит всё, Фаза 6 закрывает.

## Стартовая строка сессии реализации
```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\course-landing-plan.md and execute it.
```
Модель: Opus 4.8 (`claude-opus-4-8`). `cd` в `Systema-Sanscriticum`. Ветка `feat/course-landing` от `origin/main`, watcher-safe правки, PR-only.

---

_Dr. Mārcis Gasūns_
