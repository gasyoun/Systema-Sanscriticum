<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Редактируемое из админки время авто-напоминаний:
    // - payment_reminder_time: час суток (МСК) ежедневной рассылки об оплатах;
    // - class_reminder_lead_minutes: за сколько минут до занятия напоминать.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_settings', 'payment_reminder_time')) {
                $table->string('payment_reminder_time', 5)->default('09:00')->after('class_reminders_enabled');
            }
            if (! Schema::hasColumn('marketing_settings', 'class_reminder_lead_minutes')) {
                $table->unsignedSmallInteger('class_reminder_lead_minutes')->default(60)->after('payment_reminder_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (['payment_reminder_time', 'class_reminder_lead_minutes'] as $col) {
                if (Schema::hasColumn('marketing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
