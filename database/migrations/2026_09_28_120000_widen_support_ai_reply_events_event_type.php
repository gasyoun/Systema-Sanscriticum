<?php

declare(strict_types=1);

use App\Models\SupportAiReplyEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 28-09-2026: `dm_shadow_would_send_facts` (26 символов, H3999) не влез в
 * `support_ai_reply_events.event_type` varchar(24). Прод-MySQL в strict-mode
 * отказывает (SQLSTATE 22001), а sqlite-тесты длину не проверяют — поэтому
 * каждая переписка, дошедшая до теневой ветки по фактам, роняла синк аккаунта
 * `support` (soft-alert «синк протух»). Тот же класс, что H4200
 * (`ip_expense_audits.action`).
 *
 * 64 — с запасом под будущие события. Длину синхронизирует
 * {@see SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH}; тест сверяет
 * её со всеми EVENT_*-константами.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Без ->index(): существующие индексы переживают change().
        Schema::table('support_ai_reply_events', function (Blueprint $table): void {
            $table->string('event_type', 64)->change();
        });
    }

    public function down(): void
    {
        // Строки длиннее 24 при откате обрезались бы (MySQL strict — ошибка).
        // Откат безопасен только без них; иначе не трогаем колонку.
        $tooLong = DB::table('support_ai_reply_events')
            ->whereRaw('LENGTH(event_type) > 24')
            ->exists();

        if ($tooLong) {
            return;
        }

        Schema::table('support_ai_reply_events', function (Blueprint $table): void {
            $table->string('event_type', 24)->change();
        });
    }
};
