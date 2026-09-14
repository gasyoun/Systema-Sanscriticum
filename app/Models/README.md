_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Models

Eloquent-модели. Каждая модель = одна таблица БД + бизнес-логика, не вынесенная в сервис.

## Домен: Учеба

| Файл | Таблица | Роль |
|---|---|---|
| `User.php` | `users` | Студент или администратор. Флаги `is_admin`, `is_lecture_editor`. Методы `sendTelegramMessage()`, `sendVkMessage()`. Реализует `FilamentUser` для доступа в панель. |
| `Course.php` | `courses` | Курс. Связи: уроки, тарифы, блоки, преподаватель, категории, группы. Флаги `is_active`, `is_visible`. |
| `Lesson.php` | `lessons` | Урок внутри курса. Поля видео: `video_link` (YouTube), `rutube_link`. Хранит вложения массивом (`attachments`), транскрипт, флеш-карточки. |
| `Group.php` | `groups` | Учебная группа — механизм доступа. Студент видит урок, только если состоит в группе, привязанной к уроку. |
| `CourseBlock.php` | `course_blocks` | Временной раздел курса (неделя, модуль). Поля `starts_at`/`ends_at`. Метод `isCurrent()` — определяет активный блок по датам или ручному флагу. |
| `Tariff.php` | `tariffs` | Тариф (вариант покупки): на весь курс или на конкретные блоки. Метод `calculateFinalPriceForUser()` — скидки лояльности + вычет уже оплаченного (апгрейд). |
| `Certificate.php` | `certificates` | Сертификат об окончании. Уникальный номер генерируется автоматически в `boot()`. |
| `LessonView.php` | `lesson_views` | Одна запись на пару (студент, урок). Счетчик открытий, суммарное время, статус прохождения, последний хартбит. |

## Домен: Оплата

| Файл | Таблица | Роль |
|---|---|---|
| `Payment.php` | `payments` | Платеж. Статусы: `pending` → `paid` → `success`. Метод `grantAccess()` добавляет студента в нужные группы. Метод `processSuccessfulPayment()` вызывается из `PaymentObserver`. |
| `PromoCode.php` | `promo_codes` | Промокод. Типы: `percent` или `fixed`. Метод `calculateDiscountedPrice()`. Поля: `max_uses`, `used_count`, `expires_at`. |

## Домен: Контент

| Файл | Таблица | Роль |
|---|---|---|
| `LandingPage.php` | `landing_pages` | Лендинг курса. Поле `content` — JSON массив блоков конструктора. Slug используется в catch-all роуте `/{slug}`. |
| `Article.php` | `articles` | Статья блога. Автослаг из заголовка. Счетчик просмотров, время чтения, SEO-поля. |
| `ArticleCategory.php` | `article_categories` | Категория статей. |
| `ArticleView.php` | `article_views` | Иммутабельный лог просмотра статьи: `visitor_hash`, IP, referrer, user-agent. Нет `updated_at`. |
| `Announcement.php` | `announcements` | Объявление для студентов. Таргетинг по группам/курсам. Переключатели отправки: email, Telegram, VK. |
| `Dictionary.php` | `dictionaries` | Словарь (например, санскритских терминов). |
| `DictionaryWord.php` | `dictionary_words` | Слово в словаре: деванагари, IAST, кириллица, перевод. |

## Домен: Аналитика / Активность

| Файл | Таблица | Роль |
|---|---|---|
| `ActivityEvent.php` | `activity_events` | Иммутабельный лог событий: `login`, `logout`, `lesson_open`, `lesson_complete`, `note_saved`. JSON-поле `data`. Нет `updated_at`. |
| `UserSession.php` | `user_sessions` | Сессия студента. Хартбит каждые N секунд. При закрытии накапливает `duration` в профиль пользователя. |

## Домен: Преподаватели

| Файл | Таблица | Роль |
|---|---|---|
| `Teacher.php` | `teachers` | Преподаватель. Метод `calculateEarnings()` поддерживает 4 модели оплаты: `percent`, `fix_per_student`, `fix_per_block`, `fix_total`. |
| `TeacherPayout.php` | `teacher_payouts` | Выплата преподавателю. |

## Домен: Маркетинг / CRM

| Файл | Таблица | Роль |
|---|---|---|
| `Lead.php` | `leads` | Заявка с лендинга. UTM-поля, реферал, IP, user-agent. |
| `MarketingSetting.php` | `marketing_settings` | Глобальные настройки: пороги скидок лояльности, пиксели (Яндекс Метрика, VK Pixel). Синглтон — одна запись в таблице. |
| `Schedule.php` | `schedules` | Событие в календаре (вебинар, занятие). Привязка к группе/курсу. Метод `getLink()` — берет прямую ссылку или извлекает из описания. |
| `ChatMessage.php` | `chat_messages` | Сообщение чата поддержки. Роли: `user` / `assistant`. |
| `Category.php` | `categories` | Категория курса (иконка, цвет, сортировка). |
| `LectureDraft.php` | `lecture_drafts` | Черновик лекции. Машина состояний: `draft` → `preprocessing` → `editing` → `built` → `published`. |

## Важные соглашения

- **Автогенерация слагов** — в `boot()` через `static::creating()`. Не вызывай slug-генерацию вручную.
- **Иммутабельные логи** (`ActivityEvent`, `ArticleView`) — только вставка, никогда не обновляй.
- **Доступ через группы** — никогда не давай доступ к урокам напрямую, только через `Group`.
- **Один маркетинговый синглтон** — `MarketingSetting::first()`, запись всегда одна.

_Dr. Mārcis Gasūns_
