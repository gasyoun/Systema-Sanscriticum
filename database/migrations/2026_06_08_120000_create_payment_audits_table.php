<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_audits', function (Blueprint $table) {
            $table->id();

            // ID платежа БЕЗ внешнего ключа: запись аудита должна пережить
            // удаление самого платежа (это и есть смысл аудита удалений).
            $table->unsignedBigInteger('payment_id')->nullable()->index();

            // Кто совершил действие. Снимок имени храним отдельно — чтобы
            // лог не «поплыл» после переименования или удаления админа.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();

            // created | updated | deleted
            $table->string('action', 16)->index();

            // Снимок суммы на момент действия — для быстрого показа и фильтра.
            $table->decimal('amount', 12, 2)->nullable();

            // created/deleted → снимок значимых полей; updated → {field: [old, new]}.
            $table->json('changes')->nullable();

            // Append-only: только момент записи, без updated_at.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_audits');
    }
};
