_Created: 07-05-2026 · Last updated: 05-09-2026_

# app/Console/Commands

Artisan-команды. Запускаются через `php artisan <имя>` или по расписанию в `Console/Kernel.php`.

## Команды

### `CleanCourseArchives`
```bash
php artisan archives:clean
```
Удаляет ZIP-архивы материалов старше 24 часов из `storage/app/tmp/course-archives/`.  
Запускается по расписанию ежечасно. Выводит объем освобожденного места.

### `ImportAcademyData`
```bash
php artisan academy:import
```
Интерактивный мастер импорта данных из Excel/CSV.  
Пошагово: преподаватели → курсы → студенты → платежи.  
Каждый шаг показывает превью и просит подтверждение перед записью в БД.

### `ImportArticlesFromHtml`
```bash
php artisan articles:import-html {path}
```
Импорт статей блога из HTML-файлов (миграция со старого сайта).  
Парсит заголовок, тело, мета-теги через Symfony DomCrawler.

### `MigrateMediaToCurator`
```bash
php artisan media:migrate-to-curator
```
Переносит загруженные файлы из старого формата хранилища в Filament Curator.  
Создает записи в таблице `media`.

### `MigrateBuilderMedia`
```bash
php artisan media:migrate-builder
```
Перемещает медиафайлы из папки `lecture-builder` в основное хранилище платформы.

### `SyncLessonMaterials`
```bash
php artisan lessons:sync-materials
```
Синхронизирует вложения уроков из внешнего источника.  
Аналог API-эндпоинта `/api/sync-lessons`, но для ручного/cron-запуска.

### `DebugPaymentSkips`
```bash
php artisan payments:debug-skips
```
Диагностическая команда. Находит платежи, для которых не была выдана подписка (статус paid, но нет группы).  
Используется для ручной диагностики, не запускается по расписанию.

---

## Расписание

Расписание задач настраивается в `app/Console/Kernel.php`:

```
CloseStaleSessionsJob   → каждые 5 минут
CleanCourseArchives     → каждый час
```

_Dr. Mārcis Gasūns_
