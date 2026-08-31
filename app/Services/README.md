# app/Services

Бизнес-логика, вынесенная из контроллеров и моделей. Регистрируются синглтонами в `AppServiceProvider` (если требуют конфига) или создаются через `new` прямо в контроллере.

## Сервисы верхнего уровня

### `ArticleViewTracker`
Дедупликация просмотров статей.
- Фильтрует ботов по паттернам user-agent.
- Строит стабильный `visitor_hash` из IP + UA + дневная соль.
- Кешируется в Redis: повторный просмотр одной статьи тем же посетителем за день не считается.

### `ActivityTracker`  (`Activity/ActivityTracker.php`)
Оркестратор активности студента. Вызывается из `UserLoginListener` и `UserLogoutListener`.
- `handleLogin()` — открывает `UserSession`, обновляет `login_count`, пишет событие `login`.
- `handleLogout()` — закрывает сессию, накапливает время, пишет событие `logout`.
- Все операции обернуты в `try/catch` — сбой трекинга не ломает основной флоу.

### `CertificateService`
Генерация PDF-сертификатов через dompdf.
- Встраивает фон как Base64 (чтобы обойти ограничения путей dompdf).
- Генерирует QR-код со ссылкой верификации.
- Вызывается из `StudentController` и `GenerateCertificatesArchive` job.

### `CourseMaterialsArchiver`
ZIP-архив материалов курса для студента.
- Фильтрует файлы по оплаченным тарифам студента (если тариф блочный — только нужные блоки).
- Добавляет `video_links.txt` со ссылками на видео, сгруппированными по блокам.
- Сохраняет в `storage/app/tmp/course-archives/`; старые архивы чистит команда `CleanCourseArchives`.

### `PaymentImportService`
Импорт платежей из Excel.
- Автодетект строки заголовка.
- Поиск студента по имени, email или телефону.
- Режим preview (без записи в БД) для проверки файла перед импортом.

### `VideoLinkNormalizer`
Нормализация ссылок YouTube и Rutube в канонический формат.
- Распознает все варианты URL: `/watch?v=`, `/embed/`, `youtu.be/`, голые ID.
- Для Rutube обрабатывает приватные видео с токенами.

---

## Подсистема лекций (`Lecture/`)

Все классы подключаются к Python-микросервису `lecture-builder/` через HTTP.

```
LectureDraft (модель)
    └─ PreprocessLectureDraftJob
            └─ LectureBuilderClient::preprocess()   ← lecture-builder/server.py /preprocess
    └─ BuildLectureHtmlJob
            └─ LectureBuilderClient::render()        ← lecture-builder/server.py /render
    └─ LectureAiClient::*()                          ← lecture-builder/server.py /ai/*
    └─ LecturePatcher::applyPatch()                  ← PHP-порт патч-логики
    └─ LecturePublisher::publish()                   ← перемещает в public/
```

| Класс | Что делает |
|---|---|
| `LectureBuilderClient` | HTTP-клиент: `/preprocess` (транскрипт/PDF → JSON + слайды), `/render` (JSON → HTML), `/health`. |
| `LectureAiClient` | HTTP-клиент для AI-эндпоинтов: структурирование, исправление, расстановка слайдов, таймкоды. |
| `LecturePatcher` | PHP-порт Python-логики патчинга. Применяет точечные правки к JSON лекции (замена текста/заголовка в конкретном блоке). |
| `LecturePublisher` | Перемещает HTML и слайды из `storage/` в `public/lectures/`, переписывает пути к ассетам, создает `Lesson`, переводит черновик в статус `published`. |
| `LectureStorage` | Абстракция файловой структуры: `storage/app/lectures/{id}/{raw,slides,data.json,backups,output}`. |

---

## Подсистема расписания (`Schedule/`)

| Класс | Что делает |
|---|---|
| `ScheduleGenerator` | Создает события календаря из конфигурации. Уважает фильтры дней недели, пропуски, дополнительные даты, плейсхолдеры шаблона (`{N}`, `{DATE}`, `{BLOCK}`, `{BN}`). |
| `GeneratorConfig` | DTO с параметрами генерации: группа, курс, диапазон дат, длительность, количество занятий, шаблон. |
| `TemplateRenderer` | Рендерит шаблон события, заменяя плейсхолдеры. Разделитель `|` в шаблоне разбивает на `title`, `description`, `tag`. |
| `ScheduleMover` | Перенос одного занятия (`reschedule`) и отмена с каскадом +1 неделя для this+subsequent той же группы (`cancelAndShiftWeek`). Staff-инструкция: [docs/CURATOR_ADMIN_GUIDE_RU.md, раздел «Расписание: перенос занятия и смена дня/времени»](../../docs/CURATOR_ADMIN_GUIDE_RU.md#расписание-перенос-занятия-и-смена-днявремени). |

---

## Соглашения

- Сервисы не должны обращаться к HTTP-запросу (`request()`). Все данные передаются параметрами.
- Логирование через `Log::error()`, а не через `throw` — сбои вспомогательных сервисов (трекинг, уведомления) не должны ломать основной флоу.
- `LectureBuilderClient` и `LectureAiClient` регистрируются синглтонами в `AppServiceProvider::register()`.
