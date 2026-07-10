# Архитектурная спецификация: продающие страницы курсов (samskrte.ru)

_Created: 04-07-2026 · Last updated: 04-07-2026_

Развитие существующей страницы курса `shop.course.show` в полноценную продающую
страницу (landing) — первый слой из [vitrina.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/vitrina.md).
Это следующий шаг после завершенного каталога в стиле Arzamas
([TZ_arzamas_catalog.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TZ_arzamas_catalog.md)).

## Зафиксированные решения (интервью 04-07-2026)

| Развилка | Выбор |
|---|---|
| Как рендерить | **Расширяем существующую `shop.course.show`** (не generic LandingPage-билдер, не новая подсистема) |
| Где хранится контент | **Структурированные реляции + Filament** (не JSON-блоб) |
| Объем v1 | **Полный набор блоков** (MVP + доверие + техтребования + шаги оплаты) |
| Идентичность страницы | **Одна страница** — тот же URL `/online/kursy/{slug}` продает холодному трафику и служит деталями для купивших |

---

## Главный принцип — reuse-first

Текущая страница ([resources/views/shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php),
[ShopController::show](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/ShopController.php))
**уже содержит половину блоков** из макета vitrina. Спека добавляет только недостающее, остальное переиспользует.

| Блок макета vitrina | Статус | Источник данных |
|---|---|---|
| Hero + двойной CTA | 🔁 расширить | существующий hero; добавить вторую CTA «Смотреть пробный урок» → скролл к блоку примера |
| Микродоверие (20+ лет • записи • русскоязычно) | ➕ новый (статичный) | Blade-партиал, без БД |
| Для кого курс | ➕ новый | `courses.audience` (json array) |
| Чему вы научитесь | ➕ новый | `courses.outcomes` (json array) |
| О курсе | ✅ есть | `courses.description` (Tiptap rich-text) |
| Программа по модулям | ✅ есть | `CourseBlock` + `lessons` (аккордеон уже построен) — **не дублировать таблицей модулей** |
| Пример урока (sample) | 🔁 переиспользовать плеер | `lessons.is_preview` — существующий гейт-плеер `Lesson`, публично открыт **только** для флаг-урока |
| Преподаватель(и) | 🔁 расширить | `Teacher.bio` (уже есть, редко заполнен) + новое `teachers.photo_path`; показывать основного И со-препода |
| Тарифы (#tariffs) | ✅ есть | `tariffs` (полностью готов: full/block/half, скидки, депозит) |
| Отзывы | ➕ новый | таблица `testimonials` + пивот `course_testimonial` |
| FAQ по курсу | ➕ новый | таблица `course_faqs` |
| Техтребования + как проходит оплата | ➕ новый (в основном общий) | общий партиал из `MarketingSetting` + опц. override `courses.tech_requirements` |
| Финальный CTA | ➕ новый (статичный) | Blade-партиал |
| Преимущества (Вечный доступ / Онлайн) | ✅ есть | статичный, оставить |

**Вывод:** 3 новые таблицы + несколько полей на `courses`/`teachers`. Программа, тарифы,
описание, депозит, пробное — переиспользуются как есть.

---

## Модель данных

### Новые таблицы

**1. `course_faqs`** — вопросы-ответы по конкретному курсу
```
id
course_id      foreignId constrained cascadeOnDelete
question       string
answer         text
sort_order     unsignedSmallInteger default 0
is_visible     boolean default true
timestamps
```

**2. `testimonials`** — библиотека отзывов (переиспользуемая между курсами)
```
id
author_name    string
city           string  nullable
avatar_path    string  nullable
body           text
rating         unsignedTinyInteger nullable   (1–5, опц.)
media_url      string  nullable               (аудио/видео-отзыв)
is_visible     boolean default true
timestamps
```

**3. `course_testimonial`** — пивот (какие отзывы показаны на каком курсе, в каком порядке)
```
id
course_id       foreignId constrained cascadeOnDelete
testimonial_id  foreignId constrained cascadeOnDelete
sort_order      unsignedSmallInteger default 0
timestamps
unique(course_id, testimonial_id)
```

> Библиотека + пивот выбраны вместо `course_id` прямо на отзыве, потому что vitrina прямо
> рекомендует «общую библиотеку отзывов и отбор 3–5 релевантных на страницу». Если это
> избыточно — упрощаемая альтернатива: одна таблица `course_reviews` с `course_id` (см.
> «Открытые под-решения»).

### Новые колонки на `courses`
```
audience            json      nullable   // «Для кого» — массив строк
outcomes            json      nullable   // «Чему научитесь» — массив строк
tech_requirements   text      nullable   // per-course override; пусто → общий текст
meta_title          string    nullable   // SEO <title> (override; пусто → фолбэк = title курса)
meta_description    string    nullable   // SEO meta description (override; пусто → усеченное description)
```
Касты в модели: `audience` и `outcomes` → `'array'`.

### Новая колонка на `lessons` (пример урока)
```
is_preview   boolean default false   // бесплатный публичный preview данного урока
```

### Новая колонка на `teachers`
```
photo_path   string nullable   // фото для блока преподавателя (сейчас поля нет)
```

### Что НЕ создаем
- ❌ `course_modules` — программа уже моделируется `CourseBlock` (title, dates, description) + `lessons`.
- ❌ Отдельный движок лендинга — используем `Course` + Blade, не generic `LandingPage`.
- ❌ Поля лендинга в JSON-блобе — по решению всё структурировано.

---

## Порядок секций (одна страница `shop/show.blade.php`)

1. **Hero** + двойной CTA (`Записаться` → `#tariffs`, `Смотреть пробный урок` → `#sample`) — *расширить*
2. **Микродоверие** — *партиал*
3. **Для кого курс** — hidden если `audience` пуст
4. **Чему вы научитесь** — hidden если `outcomes` пуст
5. **О курсе** (`description`) — *есть*
6. **Программа курса** (аккордеон блоков) — *есть*, `id="program"`
7. **Пример урока** — `id="sample"`, гейт-плеер `Lesson` с `is_preview=true`; hidden если у курса нет preview-урока
8. **Преподаватель(и)** — bio + фото, основной и со-препод
9. **Тарифы** — `id="tariffs"`, *есть* (сохранить якорь, на него ведет карточка каталога)
10. **Отзывы** — hidden если пивот пуст
11. **FAQ по курсу** — аккордеон, hidden если `course_faqs` пуст
12. **Техтребования + как проходит оплата** — общий партиал (+ override)
13. **Финальный CTA** — *партиал*
14. **Преимущества** — *есть*, оставить

**Graceful degradation:** каждый новый блок скрывается при пустых данных — как уже сделано
для «Коротко о курсе». Частично заполненный курс не показывает пустых секций, поэтому
раскатка не ломает существующие курсы.

---

## Изменения в коде

### Контроллер
[ShopController::show](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/ShopController.php):
догрузить реляции `teachers` (со-преподаватели), `faqs`, `testimonials` в существующий
`with([...])`, чтобы не появился N+1. Логику доступа/тарифов не трогать.

### Модель Course
Добавить `hasMany(CourseFaq)`, `belongsToMany(Testimonial)` через `course_testimonial`
(с `orderByPivot('sort_order')`), касты массивов. Аксессор `techRequirements()` →
per-course override или общий дефолт из `MarketingSetting`.

### Пример урока — доступ и безопасность (переиспользование гейт-плеера)
Блок «Пример урока» открывает существующий плеер `Lesson`, а не отдельное видео-поле.
- **Флаг:** `lessons.is_preview`. В Filament — ограничить **одним** preview-уроком на курс (валидация).
- **Гейт доступа — единственная точка правды.** Научить [`Lesson::isUnlockedBy()` / `unlockingKeys()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php)
  возвращать «открыт» для preview-урока **для всех** (в т.ч. гость), не трогая ключи `full`/`block_N`/`block_N_hH`.
- **Роут:** отдавать плеер preview-урока по публичному пути без auth-middleware.
- ⚠️ **Безопасность (обязательный тест):** публично доступен **только** урок с `is_preview=true`
  этого курса — не соседние уроки, не другой курс. Прямой запрос не-preview урока гостем → 403/redirect.
  Feature-тест на оба случая (preview открыт гостю; не-preview закрыт) — часть DoD.

### Blade
Новые партиалы в `resources/views/shop/partials/` (audience, outcomes, sample, teachers,
testimonials, faq, tech-payment, trust-strip, final-cta) — включаются из `show.blade.php`.
Темная тема и токены (`#E85C24`, `#111622`, `#1F2636`) — как в каталоге.

### Filament — [CourseResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/CourseResource.php)
Сейчас RelationManagers пусты — это и есть точка расширения.
- Новая вкладка **«Лендинг»**: `TagsInput` для `audience`/`outcomes`; Tiptap `tech_requirements`;
  `TextInput` `meta_title`/`meta_description`.
- **Preview-урок:** `Toggle` `is_preview` в редакторе уроков (`LectureEditor`/`LessonResource`),
  валидация «не более одного preview на курс».
- **FaqsRelationManager** (question/answer/sort_order/is_visible, reorderable) — паттерн как у репитера `tariffs`.
- **TestimonialsRelationManager** (attach из библиотеки + `sort_order`) + отдельный `TestimonialResource` для самой библиотеки.
- **TeacherResource**: `FileUpload` → `photo_path`.

### Миграции (по конвенциям репозитория)
`foreignId()->constrained()->cascadeOnDelete()`, `sort_order` = `unsignedSmallInteger` default 0,
`is_visible` boolean default true, `timestamps()`, json-касты. Отдельные файлы на таблицу и на
`ALTER` колонок `courses`/`teachers`. (BOM-ловушка не применима — PHP.)

---

## Раскатка и данные

- Новые поля/таблицы пустые → блоки скрыты, пока редактор не заполнит. Дата-миграция не нужна.
- Один флагманский курс (напр. «Грамматика по Кочергиной» или «Бхагавад-гита») заполняется
  полностью как эталон и для визуальной отладки через скилл `blade-styling` (Playwright).
- Тесты: Feature-тест, что страница рендерит новые блоки при заполненных данных и скрывает при пустых.

---

## Аналитика (флаг, вне v1-кода)

vitrina перечисляет события `view_course_page`, `sample_lesson_play`, `teacher_bio_click`,
`faq_expand`, `begin_checkout`. Разметку повесить отдельной фазой (data-атрибуты в партиалах
готовим сразу, GA4/Metrica-провод — позже).

## Вне объема (отдельные слои vitrina, не эта спека)

Подписка на весь каталог · лид-магниты + email-цепочки · унификация реквизитов/ценовых якорей
(ИП Гасунс vs ИП Поликарпова, «от 400 ₽») · миграция платформы. Каждое — отдельный @DECIDE в GTD.

## Под-решения — зафиксированы (интервью 04-07-2026)

1. **Отзывы:** ✅ библиотека `testimonials` + пивот `course_testimonial` (переиспользование между курсами).
2. **Пример урока:** ✅ переиспользуем гейт-плеер `Lesson` (`lessons.is_preview`), публично только флаг-урок —
   см. «Пример урока — доступ и безопасность». (Отдельного видео-поля на курсе нет.)
3. **SEO-поля:** ✅ v1 — `meta_title`/`meta_description` сразу, с авто-фолбэком (title курса / усеченное description).

---

_Dr. Mārcis Gasūns_
