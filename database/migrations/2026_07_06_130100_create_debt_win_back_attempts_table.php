<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал отправленных шаблонов реактивации (H221) — чтобы не бить одного и
     * того же студента повторно (дедуп в окне 24 ч).
     */
    public function up(): void
    {
        Schema::create('debt_win_back_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Шаблон мог быть удалён позже — оставляем строку журнала, обнуляя FK.
            $table->foreignId('template_id')->nullable()
                ->constrained('message_templates')->nullOnDelete();
            // Кто отправил (куратор/менеджер). Nullable — на случай авто-отправки.
            $table->foreignId('sent_by')->nullable()
                ->constrained('users')->nullOnDelete();
            // Каналы, в которые ушло: "tg", "vk", "email" через запятую.
            $table->string('channel')->nullable();
            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_win_back_attempts');
    }
};
