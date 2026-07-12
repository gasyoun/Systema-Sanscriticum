<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Плановое время отправки (H816/S6 "запланированный анонс"). NULL = отправить
            // сразу при создании (прежнее поведение). dispatched_at — идемпотентность:
            // отмечает, что рассылка (или её сознательный пропуск при выключенных
            // галочках) уже обработана, чтобы announcements:dispatch-due не слал повторно.
            $table->timestamp('scheduled_at')->nullable()->after('button_url');
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
