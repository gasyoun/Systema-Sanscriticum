<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `$table->timestamp('sent_at')` без nullable/дефолта на MySQL превращается в
 * `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
 * (правило первого TIMESTAMP-столбца, explicit_defaults_for_timestamp = OFF).
 *
 * Из-за ON UPDATE база сама затирала колонку при ЛЮБОМ обновлении строки: ресинк
 * телеграм-сообщения (updateOrCreate обновляет text/raw_payload) переписывал
 * `sent_at` на момент импорта, причём в UTC — мартовские вопросы в Helpdesk
 * выглядели пришедшими сегодня, со временем на 3 часа назад. Тем же болели ещё
 * девять таблиц: `scheduled_reminders.scheduled_for` уезжал на «сейчас» при
 * отметке об отправке, `magic_link_tokens.expires_at` — продлевался сам.
 *
 * Снимаем ON UPDATE, сохраняя тип, nullability и DEFAULT: авто-подстановка при
 * INSERT безвредна, разрушительна именно перезапись при UPDATE.
 *
 * Данные восстанавливает `telegram:restore-sent-at` (реальная дата уцелела в
 * raw_payload); для остальных таблиц источника для восстановления нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // SQLite (тесты) такого атрибута не знает
        }

        foreach ($this->autoUpdatingColumns() as $column) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s %s %s',
                $column->table_name,
                $column->column_name,
                $column->column_type,
                $column->is_nullable === 'YES' ? 'NULL' : 'NOT NULL',
                $this->defaultClause($column),
            ));
        }
    }

    public function down(): void
    {
        // Осознанно не откатываем: ON UPDATE здесь и был дефектом — вернуть его
        // значит снова начать терять реальные даты событий.
    }

    /**
     * Колонки с авто-обновлением, кроме `updated_at` — там это ровно то,
     * что нужно.
     *
     * @return array<int, object>
     */
    private function autoUpdatingColumns(): array
    {
        return DB::select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
                    IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND EXTRA LIKE ? AND COLUMN_NAME <> ?',
            [DB::getDatabaseName(), '%on update%', 'updated_at'],
        );
    }

    /**
     * NOT NULL-колонку нельзя оставить без явного DEFAULT: MySQL тут же навесит
     * авто-атрибуты обратно.
     */
    private function defaultClause(object $column): string
    {
        $default = $column->column_default;

        if ($default !== null && preg_match('/^current_timestamp(\(\d*\))?$/i', (string) $default)) {
            return 'DEFAULT CURRENT_TIMESTAMP';
        }

        if ($default === null) {
            return $column->is_nullable === 'YES' ? 'DEFAULT NULL' : 'DEFAULT CURRENT_TIMESTAMP';
        }

        return 'DEFAULT '.DB::getPdo()->quote((string) $default);
    }
};
