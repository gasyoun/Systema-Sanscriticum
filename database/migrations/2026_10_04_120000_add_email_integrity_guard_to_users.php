<?php

use App\Models\SuppressedEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Защита users.email от мусора (MG 04-10-2026: «чтобы подобный мусор туда
 * впредь никогда не попадал») — импорт 22-04 принёс в email имена, телефон
 * и телеграм-хэндл («alexander pavlov», «89384362599», «@nadiyoga_practice»).
 *
 * 1. Существующий мусор → дом-плейсхолдеры import-{id}@no-email.com (конвенция
 *    «без email», UserResource фильтрует по %no-email.com) + suppressed_emails
 *    (кампании их пропускают). Логин по плейсхолдеру+паролю сохраняется.
 * 2. CHECK users_email_valid на уровне БД — ловит и те пути, что минуют
 *    Eloquent (сырые вставки импорта). Паттерн — пара к User::EMAIL_PATTERN;
 *    Eloquent-путь дополнительно стережёт мутатор (громкий отказ).
 */
return new class extends Migration
{
    /** MariaDB-трансляция User::EMAIL_PATTERN (пробелы как [:space:]). */
    private const REGEXP = '^[^@[:space:]]+@[^@[:space:]]+\\\\.[^@[:space:]]+$';

    public function up(): void
    {
        // Гвард — только MySQL/MariaDB (прод): REGEXP-синтаксис непереносим,
        // на sqlite (тесты) enforcing-слой не ставится, мутатор покрывает
        // Eloquent-путь и там.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $junk = DB::table('users')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw(sprintf("email not regexp '%s'", self::REGEXP))
            ->orderBy('id')
            ->get(['id']);

        foreach ($junk as $u) {
            $placeholder = 'import-'.$u->id.'@no-email.com';

            DB::table('users')->where('id', $u->id)->update(['email' => $placeholder]);
            SuppressedEmail::suppress($placeholder, 'import_junk_email_2026-04');
        }

        DB::statement(sprintf(
            'ALTER TABLE users ADD CONSTRAINT users_email_valid CHECK (email IS NULL OR email = %s OR email REGEXP %s)',
            "''",
            "'".self::REGEXP."'"
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_valid');
    }
};
