<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал режима просмотра за пользователя (H1947) — зеркало message_template_audits
 * и lead_audits. Строка заводится на СТАРТЕ режима и закрывается stopped_at на
 * выходе; больше её никто не правит.
 *
 * Таблица создаётся всегда — она аддитивна и при выключенном флаге
 * features.staff_impersonation просто остаётся пустой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_audits', function (Blueprint $table) {
            $table->id();

            // Кто вошёл. Имена храним снимком — лог не должен «поплыть» после
            // переименования или удаления сотрудника/студента.
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('impersonator_name')->nullable();

            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name')->nullable();

            // student | manager
            $table->string('mode', 16)->index();

            $table->timestamp('started_at')->nullable()->index();

            // NULL = режим не закрыт штатно (закрыли браузер, истекла сессия).
            $table->timestamp('stopped_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();

            // Append-only: только момент записи, без updated_at.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audits');
    }
};
