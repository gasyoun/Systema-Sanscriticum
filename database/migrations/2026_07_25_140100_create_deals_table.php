<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GC-C1 (H1641) — сущность СДЕЛКИ (конкретная потенциальная продажа).
 * Аддитивно: leads/payments/tariffs не меняются ни одной колонкой.
 *
 * Ранг 4 «лестницы полномочий» (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md
 * §2.2): сделка НАБЛЮДАЕТ денежное ядро и никогда его не авторизует. При
 * расхождении сделки и платежа прав платёж, а сделка считается устаревшей.
 *
 * Два отступления от буквы бухгалтерии §5, оба намеренные и вынесены на ревью
 * человеку (см. PR):
 *   1. `lead_id` NULLABLE (в §5 нарисован обязательным). Спека же сама пишет в
 *      §4.1, что payments.lead_id «near-empty by construction» — обязательный
 *      lead_id сделал бы мост от оплаты мёртвым для обычных покупок без лида.
 *   2. Добавлен `source_payment_id` — его в §5 нет; он нужен для
 *      ИДЕМПОТЕНТНОСТИ моста (повторная доставка вебхука не должна плодить
 *      вторую сделку) и для зеркала реверса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->foreignId('stage_id')->constrained('deal_stages')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            // 'won' | 'lost' | свободная причина менеджера.
            $table->string('closed_reason')->nullable();
            // Платёж, закрывший сделку. Ключ идемпотентности моста; НЕ денежная
            // связь — сделка ничего в payments не пишет. UNIQUE: проверка
            // «есть ли уже сделка по этому платежу» и вставка не атомарны, и
            // вне вебхука (Filament/artisan) строку платежа никто не лочит —
            // индекс закрывает гонку на уровне БД. NULL допускается многократно,
            // что и нужно после реверса (снимаем привязку).
            $table->foreignId('source_payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index('stage_id');
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
