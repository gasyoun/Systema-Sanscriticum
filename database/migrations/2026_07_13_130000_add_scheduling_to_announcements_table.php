<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Планировщик анонсов (H816 PR 2): раньше анонс рассылался СИНХРОННО при
    // создании (CreateAnnouncement::afterCreate) — отсюда 16-часовой аврал перед
    // запуском. scheduled_at (NULL = «отправить сейчас») позволяет подготовить
    // анонс заранее; dispatched_at — маркер идемпотентности, чтобы команда
    // announcements:dispatch-due не разослала один анонс дважды.
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('is_published');
            $table->timestamp('dispatched_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'dispatched_at']);
        });
    }
};
