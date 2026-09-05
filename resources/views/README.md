_Created: 07-05-2026 · Last updated: 05-09-2026_

# resources/views

Blade-шаблоны. Организованы по контексту (кабинет, магазин, блог, лендинги).

## Макеты (`layouts/`)

| Файл | Используется в |
|---|---|
| `student.blade.php` | Все страницы личного кабинета (`/cabinet/*`) |
| `shop.blade.php` | Магазин и оформление заказа |
| `articles.blade.php` | Блог |
| `promo.blade.php` | Лендинги курсов |

## Личный кабинет (`student/`)

| Файл | Маршрут | Описание |
|---|---|---|
| `dashboard.blade.php` | `/cabinet` | Список доступных курсов студента |
| `lesson.blade.php` | `/cabinet/lesson/{id}` | Плеер урока с хартбитом, заметками, прогрессом |
| `course.blade.php` | `/cabinet/course/{slug}` | Список уроков курса с прогрессом |
| `calendar.blade.php` | `/calendar` | Расписание событий студента |
| `certificate_pdf.blade.php` | (PDF) | Шаблон PDF-сертификата для dompdf |
| `payments.blade.php` | `/cabinet/payments` | История платежей (Livewire) |
| `dictionary.blade.php` | `/cabinet/dictionary` | Словарь (Livewire) |
| `open_lessons.blade.php` | `/cabinet/open` | Бесплатные/открытые уроки |

## Магазин (`shop/`)

| Файл | Маршрут | Описание |
|---|---|---|
| `index.blade.php` | `/shop` | Каталог курсов (Livewire: CourseCatalog) |
| `show.blade.php` | `/shop/{slug}` | Страница курса: описание, тарифы, программа |
| `checkout.blade.php` | `/checkout/{tariff}` | Оформление: финальная цена, промокод, форма |

## Лендинги (`promo/`)

Главный шаблон: `promo/show.blade.php` — рендерит массив JSON-блоков из `LandingPage::content`.

### Блоки конструктора (`promo/blocks/`)

Каждый файл — отдельный тип блока. Блок получает `$block` с полями из JSON.

| Файл | Назначение |
|---|---|
| `hero_block.blade.php` | Главный экран с заголовком, подзаголовком, CTA |
| `form_block.blade.php` | Форма заявки (лид) |
| `price_block.blade.php` | Тарифы и цены |
| `program_block.blade.php` | Программа курса (список тем) |
| `faq_block.blade.php` | Вопросы и ответы (аккордеон) |
| `teacher_block.blade.php` | Блок преподавателя |
| `video_block.blade.php` | Встроенное видео |
| `testimonials_block.blade.php` | Отзывы студентов |
| `schedule_block.blade.php` | Расписание занятий |
| `features_block.blade.php` | Преимущества курса |
| `guarantee_block.blade.php` | Гарантия возврата |
| `gallery_block.blade.php` | Галерея изображений |
| `text_block.blade.php` | Произвольный текстовый блок |
| ...и другие | ~20 типов блоков всего |

**Добавление нового типа блока**: создать `promo/blocks/{type}_block.blade.php`, зарегистрировать тип в `LandingPage::renderBlock()`.

## Блог (`articles/`)

| Файл | Маршрут | Описание |
|---|---|---|
| `index.blade.php` | `/s` | Список статей с фильтрами по категории |
| `show.blade.php` | `/s/{slug}` | Страница статьи |

## Auth (`auth/`)

Страницы входа, регистрации. Стандартные Laravel auth views.

## Emails (`emails/`)

| Файл | Описание |
|---|---|
| `emails/student/welcome.blade.php` | Welcome-письмо новому студенту |
| `emails/announcement.blade.php` | Шаблон письма-объявления |

## Checkout

| Файл | Описание |
|---|---|
| `checkout/` | Шаблоны успешной/неуспешной оплаты |

_Dr. Mārcis Gasūns_
