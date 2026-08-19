<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `debt_reminders.source` — кто отправил напоминание: авто-лестница
 * (`debts:remind`) или человек кнопкой «Напомнить» (H3156).
 *
 * Зачем колонка, а не просто строка при ручной отправке. Таблица обслуживает
 * ДВУХ потребителей: анти-спам-дедуп в RemindDebtors (пропустить пару, которой
 * уже писали внутри cadence) и доказательство контакта для правила H2746.
 * Без различения источника ручное сообщение куратора молча глушило бы
 * следующее авто-напоминание на неделю — и вместе с ним откладывало эскалацию
 * стадии DunningStage. Это изменение политики общения с должниками, а не
 * побочный эффект починки отчёта.
 *
 * Backfill: все существующие строки писала авто-команда — единственное место
 * в кодовой базе, где раньше вызывался DebtReminder::create. Поэтому дефолт
 * `auto` историчен, а не удобен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_reminders', function (Blueprint $table) {
            if (! Schema::hasColumn('debt_reminders', 'source')) {
                $table->string('source', 16)->default('auto')->after('block_number');
                $table->index(['user_id', 'course_id', 'source']);
            }
        });

        Schema::table('marketing_settings', function (Blueprint $table) {
            // Подавляет ли ручное сообщение куратора следующее авто-напоминание.
            // Дефолт FALSE = сегодняшнее поведение лестницы не меняется: до
            // H3156 ручная отправка вообще не оставляла строки и подавить
            // ничего не могла. Включать — осознанное решение человека.
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_manual_suppresses_auto')) {
                $table->boolean('debt_reminder_manual_suppresses_auto')
                    ->default(false)
                    ->after('debt_reminder_cadence_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debt_reminders', function (Blueprint $table) {
            if (Schema::hasColumn('debt_reminders', 'source')) {
                $table->dropIndex(['user_id', 'course_id', 'source']);
                $table->dropColumn('source');
            }
        });

        Schema::table('marketing_settings', function (Blueprint $table) {
            if (Schema::hasColumn('marketing_settings', 'debt_reminder_manual_suppresses_auto')) {
                $table->dropColumn('debt_reminder_manual_suppresses_auto');
            }
        });
    }
};
