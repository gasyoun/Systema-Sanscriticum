<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6601 — метрика «проактив → входящее сообщение».
 *
 * chat_messages.is_proactive — кураторские сообщения, отправленные кнопкой
 * «Написать первым» со страницы «Посетители онлайн» (H1197 P3, теперь за гейтом
 * горячих страниц/рабочих часов App\Support\SupportProactiveGate). Существующие
 * сообщения остаются false — метрика честно стартует с нуля, задним числом
 * ничего не домечается.
 *
 * support_daily_rollups.proactive_count / proactive_converted — дневные счётчики
 * веб-агрегатора (WebSupportRollupAggregator): сколько проактивных первых записей
 * пришлось на день и ответил ли посетитель после первой проактивной записи треда
 * (входящее role='user' строго позже неё). Parity-срез
 * (SupportParityReportBuilder) поверх этого показывает конверсию за окно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_proactive')->default(false)->after('source');
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unsignedInteger('proactive_count')->default(0)->after('human_reply_count');
            $table->boolean('proactive_converted')->default(false)->after('proactive_count');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_proactive');
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->dropColumn(['proactive_count', 'proactive_converted']);
        });
    }
};
