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
