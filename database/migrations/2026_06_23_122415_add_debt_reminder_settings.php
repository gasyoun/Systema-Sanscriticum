<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Настройки авто-рассылки напоминаний должникам (debts:remind).
    // debt_reminders_enabled по умолчанию FALSE — массовая исходящая рассылка
    // включается осознанно после проверки шаблона.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_settings', 'debt_reminders_enabled')) {
                $table->boolean('debt_reminders_enabled')->default(false)->after('class_reminder_lead_minutes');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_lead_days')) {
                $table->unsignedSmallInteger('debt_reminder_lead_days')->default(7)->after('debt_reminders_enabled');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_cadence_days')) {
                $table->unsignedSmallInteger('debt_reminder_cadence_days')->default(7)->after('debt_reminder_lead_days');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_to_telegram')) {
                $table->boolean('debt_reminder_to_telegram')->default(true)->after('debt_reminder_cadence_days');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_to_vk')) {
                $table->boolean('debt_reminder_to_vk')->default(true)->after('debt_reminder_to_telegram');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_to_email')) {
                $table->boolean('debt_reminder_to_email')->default(true)->after('debt_reminder_to_vk');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_subject')) {
                $table->string('debt_reminder_subject')->nullable()->after('debt_reminder_to_email');
            }
            if (! Schema::hasColumn('marketing_settings', 'debt_reminder_text')) {
                $table->text('debt_reminder_text')->nullable()->after('debt_reminder_subject');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach ([
                'debt_reminders_enabled', 'debt_reminder_lead_days', 'debt_reminder_cadence_days',
                'debt_reminder_to_telegram', 'debt_reminder_to_vk', 'debt_reminder_to_email',
                'debt_reminder_subject', 'debt_reminder_text',
            ] as $col) {
                if (Schema::hasColumn('marketing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
