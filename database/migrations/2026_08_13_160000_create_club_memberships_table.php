<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2644. Оплаченный период клубного членства.
 *
 * Почему отдельная таблица, а не производная от payments: продление РУЧНОЕ,
 * поэтому период имеет собственный конец, грейс и отдельно снимаемое право —
 * ничего из этого в payments не выразить. billing_subscriptions трогать нельзя:
 * там авто-списания (H2026), которых на 01-09 не существует.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Платёж, оплативший период. NULL — ручная выдача/репетиция.
            // unique: один платёж = ровно один период (идемпотентность синка).
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('term_months');
            $table->timestamp('starts_at');
            // Конец ОПЛАЧЕННОГО периода — эту дату видит студент.
            $table->timestamp('ends_at');
            // ends_at + грейс. Материализовано: демон выбирает строки одним
            // индексируемым сравнением, а не арифметикой по колонке грейса.
            $table->timestamp('grace_until');
            $table->unsignedSmallInteger('grace_days')->default(0);
            // «Не продлевать» — намерение студента, НЕ отзыв доступа.
            $table->timestamp('renewal_cancelled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            // payment | manual | rehearsal
            $table->string('source')->default('payment');
            $table->timestamps();

            $table->index(['user_id', 'grace_until']);
            $table->index(['revoked_at', 'grace_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_memberships');
    }
};
