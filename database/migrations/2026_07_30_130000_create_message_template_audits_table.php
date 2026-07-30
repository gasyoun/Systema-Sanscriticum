<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит правок библиотеки шаблонов (H1932) — зеркало lead_audits: с открытием
 * редактирования кураторам (manager) каждая правка шаблона фиксируется
 * append-only строкой «кто, когда, что поменял».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_audits', function (Blueprint $table) {
            $table->id();

            // ID шаблона БЕЗ внешнего ключа: запись аудита должна пережить
            // удаление самого шаблона (это и есть смысл аудита удалений).
            $table->unsignedBigInteger('message_template_id')->nullable()->index();

            // Кто совершил действие. Снимок имени храним отдельно — чтобы
            // лог не «поплыл» после переименования или удаления сотрудника.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();

            // created | updated | deleted
            $table->string('action', 16)->index();

            // created/deleted → снимок значимых полей; updated → {field: [old, new]}.
            $table->json('changes')->nullable();

            // Append-only: только момент записи, без updated_at.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_audits');
    }
};
