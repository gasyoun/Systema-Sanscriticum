_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Filament

Две Filament v3 панели с разными ролями и наборами ресурсов.

## Панели

### Admin Panel (`/admin`)
**Провайдер**: `app/Providers/Filament/AdminPanelProvider.php`  
**Доступ**: `User::is_admin === true` (проверяется в `canAccessPanel()`)  
Полный административный интерфейс: управление всеми сущностями платформы.

### Editor Panel (`/editor`)
**Провайдер**: `app/Providers/Filament/LectureEditorPanelProvider.php`  
**Доступ**: `User::is_lecture_editor === true`  
Ограниченная панель только для работы с черновиками лекций.

---

## Resources (18 ресурсов)

Все ресурсы находятся в `app/Filament/Resources/`.

| Ресурс | Модель | Ключевые особенности |
|---|---|---|
| `UserResource` | `User` | Поля: email, телефон, флаги admin/editor, Telegram/VK ID, метрики активности. |
| `CourseResource` | `Course` | Связи: преподаватель, тарифы, группы, категории. Флаги `is_active`, `is_visible`. |
| `LessonResource` | `Lesson` | Видео (YouTube + Rutube), вложения (Curator), транскрипт, флеш-карточки. |
| `TariffResource` | `Tariff` | Тип (full/block), цена, скидочная цена, привязка к блокам. |
| `GroupResource` | `Group` | Управление составом групп + действие генерации архива сертификатов. |
| `PaymentResource` | `Payment` | Только чтение + ручное изменение статуса. Показывает сумму, курс, студента, транзакцию. |
| `PromoCodeResource` | `PromoCode` | CRUD промокодов: тип, значение, лимит использований, дата истечения. |
| `TeacherResource` | `Teacher` | Профиль, реквизиты, модель оплаты (`percent`/`fix_per_student`/`fix_per_block`/`fix_total`). |
| `CategoryResource` | `Category` | Категория курса: иконка, цвет, порядок сортировки. |
| `LandingPageResource` | `LandingPage` | Редактор лендинга: герой, фичи, CTA, JSON-блоки конструктора. |
| `LeadResource` | `Lead` | Просмотр заявок, фильтры, UTM-данные, кнопка экспорта CSV. |
| `ArticleResource` | `Article` | Редактор статей: rich text, расписание публикации, SEO-поля, пиксели аналитики. |
| `ArticleCategoryResource` | `ArticleCategory` | Категории блога с автослагом. |
| `AnnouncementResource` | `Announcement` | Объявления с таргетингом и переключателями каналов (email/Telegram/VK). |
| `CertificateResource` | `Certificate` | В основном только просмотр. Скачивание PDF. |
| `DictionaryResource` | `Dictionary` | Словарные коллекции. |
| `DictionaryWordResource` | `DictionaryWord` | Словарные слова: деванагари, IAST, кириллица. |
| `ScheduleResource` | `Schedule` | События с временным диапазоном, цветом, привязкой к группе/курсу. |
| `MarketingSettingResource` | `MarketingSetting` | Одна запись. Настройки программы лояльности и пикселей. |

---

## Widgets

`app/Filament/Widgets/`

| Файл | Описание |
|---|---|
| `StudentStatsOverview` | Три ключевых KPI на главной: кол-во студентов, выручка, средний LTV. |
| `SalesFunnelChart` | Воронка: лиды → регистрации → покупки. |
| `RetentionChart` | График удержания студентов по времени. |
| `CourseEarningsChart` | Выручка по курсам за период. |

---

## Pages

`app/Filament/Pages/`

| Файл | Описание |
|---|---|
| `Helpdesk` | Кастомная страница чата поддержки. |

---

## Медиа

Медиафайлы управляются через **Filament Curator** (`awcodes/filament-curator`).  
Галерея доступна из любого ресурса через стандартное поле Curator.  
Все загрузки хранятся в `storage/app/public/media/`.

---

## Добавление нового ресурса

```bash
php artisan make:filament-resource ModelName --generate
```

Зарегистрировать панель, к которой должен принадлежать ресурс, через метод `panel()` в провайдере.  
Если ресурс нужен только в редакторе — разместить в `app/Filament/Editor/Resources/`.

_Dr. Mārcis Gasūns_
