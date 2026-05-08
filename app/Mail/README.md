# app/Mail

Mailable-классы для отправки email. Все письма ставятся в очередь (не отправляются синхронно).

## `StudentWelcomeMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/student/welcome.blade.php`

Отправляется новому студенту после первой успешной оплаты.  
Содержит: имя пользователя, временный пароль (при автосоздании аккаунта), ссылку на кабинет.  
Вызывается из `Payment::processSuccessfulPayment()`.

## `AnnouncementMail`
**Очередь**: `mailing`  
**Шаблон**: `resources/views/emails/announcement.blade.php`

Рассылка объявления по email.  
Тема письма — `Announcement::title`.  
Запускается из `SendMessengerAlerts` job при включённом переключателе email в объявлении.

---

## Добавление нового письма

```bash
php artisan make:mail NewMailName --markdown=emails.new-mail
```

Для постановки в очередь добавить `implements ShouldQueue` и указать `public $queue = 'mailing'`.
