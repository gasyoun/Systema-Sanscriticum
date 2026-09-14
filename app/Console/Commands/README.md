_Created: 07-05-2026 · Last updated: 10-09-2026_

# app/Console/Commands

Artisan-команды. Запускаются через `php artisan <имя>` или по расписанию в `Console/Kernel.php`.
Сигнатуры в этом файле сверены с `$signature` команд 10-09-2026 (H4515): ранее здесь
были семь несуществующих сигнатур — источник «задокументировано, значит живо».

## Команды

### `CleanCourseArchives`
```bash
php artisan archives:cleanup {--hours=24}
```
Удаляет ZIP-архивы материалов старше N часов (по умолчанию 24) из `storage/app/tmp/course-archives/`.  
По расписанию: ежедневно в 03:00 (`archives-cleanup`). Выводит объём освобождённого места.

### `ImportAcademyData`
```bash
php artisan import:academy
```
Интерактивный мастер импорта данных из Excel/CSV.  
Пошагово: преподаватели → курсы → студенты → платежи.  
Каждый шаг показывает превью и просит подтверждение перед записью в БД. Ручная команда, не по расписанию.

### `ImportCourseBlocksFromCsv`
```bash
php artisan blocks:import-csv
```
Импорт блоков курса из CSV (кампания импорта мая 2026). Ручная команда кампании, не по расписанию.

### `BackfillCourseEnrollments`
```bash
php artisan courses:backfill-enrollments {--dry-run}
```
Бэкфилл записей на курс из истории платежей; `--dry-run` — только показать. Ручная команда, не по расписанию.

### `SyncCoursesFromAdmin`
```bash
php artisan courses:sync-from-admin
```
Синхронизация курсов из админ-CSV (кампания импорта). Ручная команда, не по расписанию.

### `BuildCanonicalCsv`
```bash
php artisan import:build-canonical
```
Сборка канонического CSV курсов для импорта (dry-run обзор кампании). Ручная команда, не по расписанию.

### `ImportArticlesFromHtml`
```bash
php artisan articles:import {path}
```
Импорт статей блога из HTML-файлов (миграция со старого сайта).  
Парсит заголовок, тело, мета-теги через Symfony DomCrawler. Ручная команда, не по расписанию.

### `NormalizeUserEmails`
```bash
php artisan users:normalize-emails {--apply}
```
Нормализация email-идентификаторов пользователей. Без `--apply` — только показать; коллизии не мержит автоматически.  
Операторский инструмент: менять идентификатор входа по расписанию нельзя — сознательно вне планировщика.

### `PurgeBotSubscribers`
```bash
php artisan newsletter:purge-bots
```
Вычистка ботов из подписчиков рассылки. `--force` удаляет пользователей — поэтому сознательно не по расписанию.

### `SyncLessonMaterials`
```bash
php artisan materials:sync
```
Синхронизирует вложения уроков из внешнего источника.  
Аналог API-эндпоинта `/api/sync-lessons`, но для ручного/cron-запуска.

### `DebugPaymentSkips`
```bash
php artisan debug:payment-skips {--file=payments.csv}
```
Диагностическая команда. Находит платежи, для которых не была выдана подписка (статус paid, но нет группы).  
Для ручной диагностики, не по расписанию.

### `PostTeacherPayouts`
```bash
php artisan salary:post-payouts {--apply}
```
Ручной идемпотентный бэкфилл выплат преподавателям (без `--apply` — только показать).  
Сервис `TeacherPayoutPoster` живёт в пяти Filament-вызовах; CLI-обёртка — для ручных догонов. Не по расписанию.

---

## Расписание

Источник правды — `Console/Kernel.php::schedule()` (~57 задач). Смотреть фактический список:

```bash
php artisan schedule:list
```

Примеры (сверены 10-09-2026):

```
CloseStaleSessionsJob (close-stale-sessions) → каждые 5 минут
CleanCourseArchives (archives-cleanup)       → ежедневно в 03:00
```

_Dr. Mārcis Gasūns_
