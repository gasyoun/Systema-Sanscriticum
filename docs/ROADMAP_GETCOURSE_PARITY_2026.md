# Roadmap: паритет с getcourse.ru — Q3 2026

_Created: 09-07-2026 · Last updated: 09-07-2026_

Автор-исполнитель роадмапа: Opus 4.8 (`claude-opus-4-8`), сессия H438. Эталон сравнения — [getcourse.ru](https://getcourse.ru) (всё-в-одном платформа для онлайн-школ). getcourse **не используется** в проде samskrte.ru — берётся только как reference-набор фич. Цель: закрыть каждый реально нужный gap, чтобы платформа никогда не понадобилась.

## 0. Метод

Gap-анализ — четыре read-only разведки по коду (09-07-2026), каждая сверяла заявленную у getcourse способность с фактическим кодом Systema. Итог ниже опирается на `file:line`-доказательства, не на догадки. Денежно-курсовое ядро (доступ по оплате, тарифы/блоки, дебиторка, признание выручки, ЗП, support-inbox, партнёрка) **уже покрывает ~70% getcourse** и здесь не переоценивается — роадмап только про 4 незакрытых домена.

## 1. Матрица паритета

| Семейство getcourse | Состояние Systema | Дырка |
|---|---|---|
| **Вебинар-аналитика** (кто был/не был, минуты, no-show) | Zoom-вебхуки `join/leave`, participant-отчёты, per-session/group/student отчёты, авто-алерты неявившимся | ✅ ≈90% — нет авто-создания встреч + нет провайдер-абстракции |
| **CRM-воронка** (сделки, стадии, канбан, задачи менеджеру) | `Lead` (5 статусов, авто-конверт на оплате), когпит «Моя работа сегодня», недожатые заказы | 🟡 нет настраиваемых стадий+канбана, нет атрибуции по менеджерам |
| **Маркетинг-автоматизация** (автоворонки, рассылки, сегменты, триггеры) | `Announcement` (по группам), библиотека `MessageTemplate`, drip вынесен в внешний n8n (хардкод-шаги) | 🟠 нет сегментов, кампаний с трекингом, in-app воронок, единого роутера каналов |
| **Тесты/квизы** (авто-проверка, гейтинг, авто-сертификаты) | Движка нет; ДЗ = статус+комменты без баллов; гейтинг только по оплате; сертификаты вручную | 🔴 greenfield |

## 2. Три ставки квартала

1. **Вебинар-аналитику довести до 100% и застраховать уход с Zoom** — самый дешёвый и самый близкий к завершению домен; плюс страховка на случай ухода Zoom из РФ.
2. **Маркетинг-автоматизацию вернуть в приложение** — сегменты + кампании + единый роутер каналов, чтобы продажи/удержание не зависели от внешнего n8n.
3. **Ввести движок квизов** — единственный полностью отсутствующий у getcourse функционал; открывает авто-сертификаты и педагогический прогресс.

Реалистично: за квартал закрываются вебинар-домен целиком, ядро маркетинга (сегменты+кампании+роутер) и ядро квизов; тяжёлые хвосты (визуальный конструктор воронок, гейтинг-на-прохождение) уезжают в Q4 — помечены `Later`.

---

## 3. Тикеты

Оценка каждого: **E** усилие (S/M/L) · **V** ценность · **R** реюз · **Risk**. Все за фича-флагом (`config/features.php`), watcher-safe сборка, аддитивные миграции — денежное ядро не трогаем.

### Домен B — Вебинар-аналитика + страховка ухода с Zoom (готовность высокая → делаем первым)

**GC-B1 · Авто-создание Zoom-встреч через API** — `zoom_join_url`/`zoom_start_url` сейчас заполняются вручную; [`ZoomService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Zoom/ZoomService.php) уже держит Server-to-Server OAuth и читает participant-отчёты, но метода `createMeeting` нет. Добавить `createMeeting()` (`POST /users/me/meetings`) и писать ссылки при создании `Schedule`. **E:S · V:M · R:высокий (OAuth готов) · Risk:низкий · flag:`zoom_auto_create`**

**GC-B2 · Консолидированный дашборд посещаемости** — данные уже есть ([`ClassAttendanceService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ClassAttendanceService.php): per-session/group/student; [`WebinarAttendance`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/WebinarAttendance.php)). Не хватает одной витрины «вся статистика»: rate посещаемости по студенту/группе/курсу во времени, список хронических неявок, тренд, экспорт. Прямой ответ на запрос «знать всё о тех, кто ходит и не ходит». Реюз сервисов целиком — только новая Filament-страница + агрегаты. **E:M · V:высокий · R:высокий · Risk:низкий · flag:`attendance_dashboard`**

**GC-B3 · Провайдер-абстракция вебинаров (страховка ухода Zoom)** — сейчас Zoom вшит везде (колонки БД `zoom_*`, [`ZoomWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/ZoomWebhookController.php), сервис). Ввести интерфейс `WebinarProvider` (`createMeeting` · `fetchParticipants` · `normalizeWebhook`), `ZoomService` реализует его; обобщить схему (алиас-колонки `meeting_*`). Затем драйвер-скелет self-hosted. **@DECIDE куда переезжать:** рекомендую **BigBlueButton** (заточен под образование, есть attendance-API и записи, moodle-grade) против Jitsi (легче, но нет встроенной посещаемости/отчётов) и LiveKit (SFU-кирпич, всё писать самим). Тикет = только шов + BBB-скелет; полное развёртывание BBB — отдельная инфра-задача Q4. **E:L · V:M (страховка) · R:средний · Risk:средний · flag:`webinar_provider_abstraction`**

### Домен A — Маркетинг-автоматизация (крупный gap → ядро в этом квартале)

**GC-A1 · Движок сегментов (аудиторий)** — сегментации как маркетинг-примитива нет ([разведка]: только членство в группе + хардкод-запросы отчётов + UTM-колонки). Ввести модель `Segment` (сохранённые именованные data-driven фильтры: членство, поведение — `last_activity`/завершённые уроки/владение тарифом, посещаемость, статус лида, дебитор, UTM). Встроенные сегменты обернуть из существующих `ReactivationReport`/`DebtorsReport`/`StuckStudentsReport`. Filament `SegmentResource`. Фундамент для GC-A3/A4. **E:M · V:высокий · R:высокий (реюз отчётов) · Risk:низкий · flag:`marketing_segments`**

**GC-A2 · Единый роутер каналов с предпочтением** — сейчас нет единого «отправь пользователю в его канал»: [`DebtorReminderDispatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorReminderDispatcher.php)/`WinBackSender` веерно шлют во ВСЕ каналы по булевым флагам. Ввести `MessageRouter` с per-user предпочтением + порядком фолбэка (TG→VK→email→MAX). Провести существующие рассыльщики через него. Добавить MAX как полноценный broadcast-канал (сейчас только для лид-магнита). **E:M · V:высокий · R:высокий · Risk:средний (трогает денежные напоминания — осторожный review) · flag:`unified_channel_router`**

**GC-A3 · Кампании-рассылки с трекингом** — апгрейд [`Announcement`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Announcement.php) до модели `Campaign`: выбор сегмента (GC-A1) + шаблон ([`MessageTemplate`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MessageTemplate.php)) + каналы (GC-A2) + расписание + throttle; трекинг открытий/кликов (реюз паттерна signed-tracked-link из [`ScheduleJoinClick`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/ScheduleJoinClick.php)); per-campaign отписка; A/B — опционально. **E:L · V:высокий · R:средний · Risk:средний · flag:`marketing_campaigns`**

**GC-A4 · In-app drip / триггерные цепочки** `Later` — сейчас drip вынесен в внешний n8n с хардкод-шагами (`LeadStepMailer::STEPS`). Data-driven `AutomationFlow` (событие-триггер → шаги с задержками → сообщение/добавление в сегмент). **@DECIDE:** строить нативный движок vs углубить n8n. Тяжёлый — вероятно Q4. **E:L · V:высокий · R:низкий · Risk:высокий · flag:`marketing_automation_flows`**

### Домен C — CRM-воронка (средний gap)

**GC-C1 · Настраиваемые стадии сделки + канбан** — [`Lead`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lead.php) сейчас держит хардкод-энум из 5 статусов, рисуется плоской таблицей. **@DECIDE:** расширить `Lead` конфигурируемыми стадиями (рекомендую — меньше переписывания, авто-конверт на оплате уже есть) vs завести отдельную сущность `Deal`. Тикет: стадии в данные + Filament-канбан с drag-drop. **E:M · V:M · R:высокий (реюз Lead/LeadResource) · Risk:низкий · flag:`crm_pipeline_board`**

**GC-C2 · Атрибуция продаж по менеджерам** — `Lead.assigned_to` есть, но ни один отчёт не атрибутирует конверсию/выручку менеджеру ([`OrderPaymentConversionService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reports/OrderPaymentConversionService.php) ломает только по курсу/каналу). Добавить разбивку по `assigned_to`. Filament-страница. **E:S · V:M · R:высокий · Risk:низкий · flag:`manager_sales_report`**

**GC-C3 · Объект-задача менеджеру** `Next` — промоутить поля `next_contact_at`/`assigned_to` в реальную модель `FollowUpTask` (due/done/тип), кормит [`WorkQueueReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/WorkQueueReport.php); включить флаг `crm_reminders`. **E:M · V:M · R:средний · Risk:низкий · flag:`crm_reminders`**

### Домен D — Тесты/квизы (greenfield)

**GC-D1 · Ядро движка квизов** — модели `Quiz` · `Question` (типы: одиночный/множественный выбор, текст, число, ввод деванагари/транслитерации) · `QuestionOption` · `QuizAttempt` · `QuizAnswer`; авто-оценка объективных типов; привязка квиза к `Lesson`/`CourseBlock`. **@DECIDE:** нужна ли деванагари/транслит-aware проверка ответа (усложняет авто-грейдинг). **E:L · V:высокий · R:низкий · Risk:средний · flag:`quizzes`**

**GC-D2 · Оценки за ДЗ** — добавить score/rubric в [`HomeworkSubmission`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/HomeworkSubmission.php) (аддитивно; сейчас только статус+комменты, баллов нет). **E:S · V:M · R:высокий · Risk:низкий · flag:`homework_scoring`**

**GC-D3 · Гейтинг «разблокировка по прохождению»** `Later` — расширить доступ к уроку опциональным требованием сдать квиз/принять ДЗ. **Критично:** только КАК ДОПОЛНИТЕЛЬНЫЙ слой поверх [`Lesson::isUnlockedBy`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php) — денежный доступ по тарифу-ключу не трогать (нагруженное ядро). Выкл по умолчанию. **@DECIDE:** нужен ли педагогический гейтинг вообще, или квизы = только самопроверка. **E:M · V:M · R:средний · Risk:высокий (money-core рядом) · flag:`progress_gating`**

**GC-D4 · Авто-сертификаты + прогресс-дашборд студента** `Next` — авто-выпуск сертификата по критериям (% уроков + сдача квиза); студенческая витрина прогресса. Реюз [`CertificateService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CertificateService.php) + `UserResource::learningProgress`. **E:M · V:M · R:высокий · Risk:низкий · flag:`auto_certificates`**

---

## 4. Рекомендуемая последовательность Q3 2026 (Now / Next / Later)

**Now** (быстрые победы + фундаменты): GC-B1 → GC-B2 → GC-A1 → GC-A2 → GC-C2 → GC-D2
**Next** (на фундаментах): GC-A3 → GC-C1 → GC-D1 → GC-D4 → GC-C3
**Later** (тяжёлые хвосты, вероятно Q4): GC-A4 → GC-B3 → GC-D3

Логика: сперва добить почти-готовый вебинар-домен (B1/B2 — минимум усилий, максимум ценности), параллельно заложить маркетинг-фундамент (A1 сегменты → A2 роутер), на который сядут кампании A3 и автоворонки A4. Квизы: сначала дешёвое D2 (оценки ДЗ), затем ядро D1, затем сертификаты D4; гейтинг D3 — последним, из-за близости к денежному ядру.

## 5. Открытые развилки (@DECIDE) — с рекомендациями

| # | Тикет | Развилка | Рекомендация |
|---|---|---|---|
| 1 | GC-B3 | Куда уходить с Zoom | **BigBlueButton** (education-grade, attendance-API, записи) |
| 2 | GC-C1 | Стадии на `Lead` vs новый `Deal` | **Расширить `Lead`** (авто-конверт уже есть, меньше переписывания) |
| 3 | GC-A4 | Нативный drip-движок vs углубить n8n | Отложить до конца квартала; по умолчанию — нативный (уход от внешней зависимости соответствует «уйти с getcourse») |
| 4 | GC-D1/D3 | Квизы = самопроверка или педагогический гейтинг | Начать с самопроверки (D1); гейтинг D3 — только после явного «да» |
| 5 | GC-D1 | Деванагари/транслит-aware проверка ответов | По умолчанию строгое сравнение + нормализация; fuzzy — позже |

## 6. Стартовые строки (launchable-now тикеты)

Каждый крупный тикет получит собственный `H###`-хэндофф при старте. Первые три ungated и watcher-safe:

- GC-B1 (авто-создание Zoom-встреч) — минт хэндоффа, executor Sonnet
- GC-B2 (дашборд посещаемости) — минт хэндоффа, executor Sonnet
- GC-A1 (движок сегментов) — минт хэндоффа, executor Sonnet

_Dr. Mārcis Gasūns_
