_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Mail

Mailable-классы для отправки email. Все письма ставятся в очередь (не отправляются синхронно).

## `StudentWelcomeMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/student/welcome.blade.php`

Отправляется новому студенту после первой успешной оплаты.  
Содержит: имя пользователя, временный пароль (при автосоздании аккаунта), ссылку на кабинет.  
Вызывается из `Payment::processSuccessfulPayment()`.

## `CourseWelcomeMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/course/welcome.blade.php`

Благодарность за **первую реальную оплату конкретного курса** (в т.ч. вернувшемуся студенту за 2-й/3-й курс — когда `StudentWelcomeMail` уже не сработает).  
Содержит: приветствие, ссылку на чат курса (`Course::chat_url`, если задан), кнопку «В личный кабинет», строку поддержки.  
Вызывается из `Payment::sendCourseWelcomeEmailIfFirstForCourse()` (внутри `processSuccessfulPayment()`).

## `AnnouncementMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/announcement.blade.php`

Рассылка объявления по email.  
Тема письма — `Announcement::title`.  
Запускается из `SendMessengerAlerts` job при включенном переключателе email в объявлении.

## `PurchaseConfirmationMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/purchase-confirmation.blade.php`

Подтверждение покупки — чек и приветствие в одном (H1286). Уходит на **каждую**
реальную (не conditional) успешную оплату курса: что куплено, тариф, сумма
(при нулевой — строка опущена), когда откроется доступ (общая строка 1 волны
revenue-copy), с чего начать, куда писать. Кассовый чек платёжной системы —
упомянут, не воспроизводится.  
Вызывается из `Payment::sendPurchaseConfirmation()` (внутри `processSuccessfulPayment()`).
Копия: [docs/copy/money-purchase-confirmation-onboarding-seq.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-purchase-confirmation-onboarding-seq.md).

## `OnboardingDay1Mail` / `OnboardingDay5Mail`
**Очередь**: `mailing`  
**Шаблоны**: `resources/views/emails/onboarding/{day1,day5}.blade.php`

Онбординг первой недели после покупки курса (H1286): день 1 «с чего начать»,
день 5 «если ещё не начали» (мягкий чек-ин без вины, грейс-нота «просто
проигнорируйте»). **Отправка сознательно НЕ подключена** (прецедент марафонских
писем) — канал ждёт ESP-гейта (H1147). Рабочая доставка дней 1/5 сегодня —
Telegram/VK через `ScheduledReminder` (`Payment::scheduleOnboardingIfFirstForCourse()`,
только первая оплата конкретного курса). Тест:
`tests/Feature/Mail/PurchaseOnboardingSequenceTest.php`.

## `MarathonWelcomeMail` / `MarathonDay1Mail` / `MarathonDay2Mail` / `MarathonDay3Mail` / `MarathonRecordingMail`
**Очередь**: `mailing`  
**Шаблоны**: `resources/views/emails/marathon/{welcome,day1,day2,day3,recording}.blade.php`

Пять писем марафона «Консультация по онлайн-курсам ОРС» (H1148); текст рулен H1067
([marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md)) — не редактировать при изменениях кода.
`MarathonDay3Mail` несет оба рулевых варианта по треку (`paid`): 3а платный / 3б бесплатный.
**Отправка сознательно НЕ подключена** — ни одного send-сайта вне `app/Mail/`; канал ждет
ESP-гейта (H1147), Telegram-дрип остается основным. Тест: `tests/Feature/Mail/MarathonMailablesTest.php`.

---

## Добавление нового письма

```bash
php artisan make:mail NewMailName --markdown=emails.new-mail
```

Для постановки в очередь добавить `implements ShouldQueue` и указать `public $queue = 'mailing'`.

_Dr. Mārcis Gasūns_
