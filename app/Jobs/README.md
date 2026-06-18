# app/Jobs

Фоновые задачи для очереди. Запускаются через `dispatch()`. Мониторинг — `/horizon`.

## Задачи

### `GenerateCertificatesArchive`
Генерирует PDF-сертификаты для всех участников группы и упаковывает в ZIP.  
После завершения отправляет администратору ссылку на скачивание.  
Использует `CertificateService`. Запускается из Filament-действия на ресурсе `GroupResource`.

### `SendMessengerAlerts`
Массовая рассылка объявления через Telegram и VK.  
Конвертирует HTML в текст для мессенджеров (удаляет теги, заменяет ссылки).  
Итерирует по целевым пользователям; отправка через `User::sendTelegramMessage()` / `User::sendVkMessage()`.

### `SendPaymentToSheetJob`
Отправляет данные платежа в n8n-вебхук для синхронизации с Google Sheets.  
5 попыток с экспоненциальным откатом. Финальный сбой логируется.  
URL вебхука берётся из `config('services.n8n.payment_webhook')`.

### `BuildLectureHtmlJob`
Вызывает `LectureBuilderClient::render()` для сборки HTML лекции из JSON.  
Обновляет статус `LectureDraft` → `built`, сохраняет путь к выходному файлу.  
При ошибке пишет в поле `error_log` черновика.

### `PreprocessLectureDraftJob`
Загружает транскрипт/PDF в `lecture-builder` (`/preprocess`).  
Получает слайды (JPG) и структурированный JSON. Обновляет статус → `editing`.  
Сохраняет пути в полях `slides_path`, `data_path` черновика.

### `CloseStaleSessionsJob`
Закрывает зависшие сессии (`UserSession`) без хартбита более 15 минут.  
Накапливает посчитанное время в `users.total_time_spent`.  
Запускается по расписанию каждые 5 минут через `Console/Kernel.php`.

### `TrackLessonViewJob`
Upsert-запись в `lesson_views`: счётчик открытий, время, статус прохождения.  
Инкрементирует счётчики уровня пользователя и сессии.  
Пишет событие `lesson_open` в `ActivityEvent`.  
Использует очередь `tracking` (отдельная от основной, чтобы не задерживать важные задачи).

## Очереди

| Очередь | Задачи | Приоритет |
|---|---|---|
| `default` | `GenerateCertificatesArchive`, `SendMessengerAlerts`, `SendPaymentToSheetJob`, `BuildLectureHtmlJob`, `PreprocessLectureDraftJob` | Средний |
| `tracking` | `TrackLessonViewJob` | Низкий |
| `mailing` | `StudentWelcomeMail`, `CourseWelcomeMail`, `AnnouncementMail` | Средний |

Настройки воркеров для каждой очереди — в `config/horizon.php`.
