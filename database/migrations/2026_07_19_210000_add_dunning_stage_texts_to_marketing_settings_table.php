<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лестница эскалации напоминаний должникам (H1289): переопределяемые
 * менеджером тексты/темы стадий 2–4. Стадия 1 живёт в существующих
 * debt_reminder_text/debt_reminder_subject. Все колонки nullable:
 * пусто = дефолт из App\Support\DunningStage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('debt_reminder_stage2_subject')->nullable();
            $table->text('debt_reminder_stage2_text')->nullable();
            $table->string('debt_reminder_stage3_subject')->nullable();
            $table->text('debt_reminder_stage3_text')->nullable();
            $table->string('debt_reminder_stage4_subject')->nullable();
            $table->text('debt_reminder_stage4_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'debt_reminder_stage2_subject',
                'debt_reminder_stage2_text',
                'debt_reminder_stage3_subject',
                'debt_reminder_stage3_text',
                'debt_reminder_stage4_subject',
                'debt_reminder_stage4_text',
            ]);
        });
    }
};
