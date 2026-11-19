<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5768 — авторитетные доказательства успешного скана инжестера.
 *
 * Прежняя семантика полноты («входящие каждый день + свежий синк сейчас»)
 * выдавала тихий, но полностью просканенный день за неполный и прятала
 * непросканированные источники. Полнота окна теперь доказывается ПО ДНЯМ:
 * строка = аккаунт × календарный день (Europe/Moscow) с хотя бы одним
 * УСПЕШНЫМ live-сканом (telegram-support:sync). Дни без строки — неизвестный
 * охват, а не «полный». Исторические окна до появления таблицы честно
 * неполные: доказательств нет. Пишется только на live-пути (не payload-импорт).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_support_scan_days', function (Blueprint $table) {
            $table->id();
            $table->string('account_name', 64);
            $table->string('day', 10); // Y-m-d Europe/Moscow
            $table->unsignedInteger('successful_runs')->default(0);
            $table->unsignedInteger('peers_polled_max')->default(0);
            $table->timestamp('first_success_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['account_name', 'day'], 'telegram_support_scan_day_unique');
            $table->index(['day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_support_scan_days');
    }
};
