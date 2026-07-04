# Архитектурная спецификация: продающие страницы курсов (samskrte.ru)

_Created: 04-07-2026 · Last updated: 04-07-2026_

Развитие существующей страницы курса `shop.course.show` в полноценную продающую
страницу (landing) — первый слой из [vitrina.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/vitrina.md).
Это следующий шаг после завершённого каталога в стиле Arzamas
([TZ_arzamas_catalog.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TZ_arzamas_catalog.md)).

## Зафиксированные решения (интервью 04-07-2026)

| Развилка | Выбор |
|---|---|
| Как рендерить | **Расширяем существующую `shop.course.show`** (не generic LandingPage-билдер, не новая подсистема) |
| Где хранится контент | **Структурированные реляции + Filament** (не JSON-блоб) |
| Объём v1 | **Полный набор блоков** (MVP + доверие + техтребования + шаги оплаты) |
| Идентичность страницы | **Одна страница** — тот же URL `/online/kursy/{slug}` продаёт холодному трафику и служит деталями для купивших |

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
| Пример урока (sample) | ➕ новый | `courses.preview_video_url` + `courses.preview_note` |
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
preview_video_url   string    nullable   // ссылка на пример урока (YouTube/VK/mp4)
preview_note        text      nullable   // подпись/что в примере + ДЗ
tech_requirements   text      nullable   // per-course override; пусто → общий текст
meta_title          string    nullable   // SEO <title> (опц., см. под-решения)
meta_description    string    nullable   // SEO meta description
```
Касты в модели: `audience` и `outcomes` → `'array'`.

### Новая колонка на `teachers`
```
photo_path   string nullable   // фото для блока преподавателя (сейчас поля нет)
```

### Что НЕ создаём
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
7. **Пример урока** — `id="sample"`, hidden если `preview_video_url` пуст
8. **Преподаватель(и)** — bio + фото, основной и со-препод
9. **Тарифы** — `id="tariffs"`, *есть* (сохранить якорь, на него ведёт карточка каталога)
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

### Blade
Новые партиалы в `resources/views/shop/partials/` (audience, outcomes, sample, teachers,
testimonials, faq, tech-payment, trust-strip, final-cta) — включаются из `show.blade.php`.
Тёмная тема и токены (`#E85C24`, `#111622`, `#1F2636`) — как в каталоге.

### Filament — [CourseResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/CourseResource.php)
Сейчас RelationManagers пусты — это и есть точка расширения.
- Новая вкладка **«Лендинг»**: `TagsInput` для `audience`/`outcomes`; `TextInput` `preview_video_url`;
  `Textarea` `preview_note`; Tiptap `tech_requirements`; `TextInput` `meta_title`/`meta_description`.
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

## Вне объёма (отдельные слои vitrina, не эта спека)

Подписка на весь каталог · лид-магниты + email-цепочки · унификация реквизитов/ценовых якорей
(ИП Гасунс vs ИП Поликарпова, «от 400 ₽») · миграция платформы. Каждое — отдельный @DECIDE в GTD.

## Открытые под-решения (уточнить перед реализацией)

1. **Отзывы:** библиотека + пивот (рекомендуется, переиспользование) **или** проще —
   `course_reviews` с прямым `course_id`. Спека выше идёт по варианту «библиотека».
2. **Пример урока:** просто ссылка на видео (`preview_video_url`, рекомендуется) **или**
   переиспользовать гейт-плеер `Lesson` как бесплатный preview (сложнее, но единый плеер).
3. **SEO-поля** `meta_title`/`meta_description`: в v1 **или** отложить в v2.

---

_Dr. Mārcis Gasūns_
