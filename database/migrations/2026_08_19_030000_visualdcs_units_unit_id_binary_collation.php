<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H3116 хвост: `unit_id` обязан сравниваться ПОБАЙТОВО.
 *
 * На проде (MySQL, utf8mb4_unicode_ci) уникальный индекс складывал IAST-корни,
 * различающиеся только диакритикой: `abhikramay` и `abhikrāmay` — разные
 * глагольные корни опубликованного релиза, но accent-insensitive коллация
 * считает их одним ключом, и материализация 7 689 корней падала на duplicate
 * key. SQLite (тесты) сравнивает байты — потому локально это не воспроизвелось.
 *
 * `title_lower` остаётся на коллации таблицы сознательно: там LIKE-поиск, и
 * диакритико-нечувствительное совпадение для поиска — удобство, не дефект.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite и так побайтовый (BINARY) — менять нечего.
        }

        DB::statement(
            'ALTER TABLE visualdcs_units MODIFY unit_id VARCHAR(255) '
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'
        );
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE visualdcs_units MODIFY unit_id VARCHAR(255) '
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
        );
    }
};
