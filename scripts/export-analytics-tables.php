<?php

declare(strict_types=1);

/**
 * export-analytics-tables.php — read-only выгрузка таблиц в CSV для аналитики.
 *
 * Зачем: код аналитики (TeacherAnalytics, OrderPaymentConversionService,
 * LessonView-срезы) уже написан, но считается только по РЕАЛЬНЫМ данным прода.
 * Этот скрипт отдаёт нужные таблицы в CSV для custdev (просмотры/отвал уроков,
 * путь регистрация→оплата) и сравнения темпа групп/преподавателей.
 *
 * Свойства:
 *   • Только SELECT (DB::table()->cursor()). НИЧЕГО не меняет — безопасно на проде.
 *   • Креды БД берутся из .env приложения (Laravel bootstrap) — отдельный логин не нужен;
 *     если есть read-only пользователь, просто подставьте его в .env перед запуском.
 *   • Потоковая выгрузка (unbuffered cursor) — не грузит всю таблицу в память.
 *   • Корректный CSV через fputcsv + UTF-8 BOM (открывается в Excel без кракозябр).
 *
 * Запуск на сервере из корня приложения:
 *   php scripts/export-analytics-tables.php
 *
 * Результат: storage/app/analytics-export/<ГГГГ-ММ-ДД>/<таблица>.csv
 * Скачать по FTP из этого каталога.
 *
 * ⚠️ ПЕРСДАННЫЕ: users / leads / payments содержат имена, email, телефоны. CSV с
 *    ними — тоже перс.данные: хранить/передавать соответствующе, после анализа удалить.
 *    Нужны только «темп/воронка» без контактов? — раскомментируйте $COLUMNS ниже,
 *    тогда по этим таблицам выгрузятся лишь перечисленные колонки.
 *
 * Альтернатива без PHP (если удобнее mysql-клиент), tab-separated:
 *   mysql --batch -e "SELECT * FROM lesson_views" DB_NAME > lesson_views.tsv
 */

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Таблицы под две задачи из запроса: custdev-воронка и темп групп/преподавателей.
$TABLES = [
    'lesson_views',         // просмотры/отвал уроков (ядро custdev + темп)
    'lessons',              // знаменатель прогресса (сколько уроков в курсе)
    'courses',              // курс ↔ преподаватель (teacher_id) — срез по преподавателям
    'groups',               // группы (для сравнения темпа между группами)
    'group_user',           // кто в какой группе (pivot, с left_at)
    'schedules',            // расписание занятий (темп прохождения материала во времени)
    'webinar_attendances',  // посещаемость вебинаров (Zoom)
    'payments',             // путь регистрация→оплата (даты, суммы, статус, тариф)
    'users',                // регистрации + имена студентов (привязка к прогрессу)
    'leads',                // верх воронки (лид→оплата, UTM-атрибуция)
];

// Опционально: ограничить колонки для таблиц с перс.данными (снять комментарий).
// Пустой массив / отсутствие ключа = выгружать таблицу целиком.
$COLUMNS = [
    // 'users'    => ['id', 'created_at', 'last_activity_at', 'utm_source'],
    // 'leads'    => ['id', 'status', 'utm_source', 'created_at', 'converted_at'],
    // 'payments' => ['id', 'user_id', 'tariff', 'amount', 'status', 'is_conditional', 'created_at', 'paid_at'],
];

$stamp = date('Y-m-d');
$dir = storage_path("app/analytics-export/{$stamp}");
if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Не удалось создать каталог {$dir}\n");
    exit(1);
}

fwrite(STDERR, "Выгрузка в {$dir}\n");
$grandTotal = 0;

foreach ($TABLES as $table) {
    if (! Schema::hasTable($table)) {
        fwrite(STDERR, "  · {$table}: пропуск — таблицы нет в этой БД\n");
        continue;
    }

    $cols = $COLUMNS[$table] ?? ['*'];
    $path = "{$dir}/{$table}.csv";
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM для Excel

    $headerWritten = false;
    $rows = 0;

    foreach (DB::table($table)->select($cols)->cursor() as $row) {
        $assoc = (array) $row;
        if (! $headerWritten) {
            fputcsv($fh, array_keys($assoc));
            $headerWritten = true;
        }
        // NULL → пустая ячейка; скаляры как есть.
        fputcsv($fh, array_map(static fn ($v) => $v ?? '', array_values($assoc)));
        $rows++;
    }

    fclose($fh);
    $grandTotal += $rows;
    fwrite(STDERR, sprintf("  ✓ %-22s %8d строк → %s.csv\n", $table, $rows, $table));
}

fwrite(STDERR, "Готово: {$grandTotal} строк всего. Каталог: {$dir}\n");
