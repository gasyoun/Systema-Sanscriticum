<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тот же гвард, что users_email_valid (MG 04-10-2026, «да» на предложения
 * распространить), для leads.email: лид-форма и n8n-конвейер тоже писали в
 * поле мусор (обрезанный домен «ahyg13@gma»). Паттерн — пара к
 * User::EMAIL_PATTERN / users_email_valid; NULL — честное «email нет»
 * (колонка nullable, контакт живёт в leads.contact).
 */
return new class extends Migration
{
    private const REGEXP = '^[^@[:space:]]+@[^@[:space:]]+\\\\.[^@[:space:]]+$';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Мусор → NULL (email у лида опционален; контакт остаётся в contact).
        DB::table('leads')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereRaw(sprintf("email not regexp '%s'", self::REGEXP))
            ->update(['email' => null]);

        DB::statement(sprintf(
            'ALTER TABLE leads ADD CONSTRAINT leads_email_valid CHECK (email IS NULL OR email = %s OR email REGEXP %s)',
            "''",
            "'".self::REGEXP."'"
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_email_valid');
    }
};
