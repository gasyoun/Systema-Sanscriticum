<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр исключений из учебных TG-чатов за курсовой долг + молчание и взносов
 * за восстановление (H2746).
 *
 * Почему отдельная таблица, а не поле у пары (user, course): исключают из
 * ЧАТА, а не из курса, чатов у студента может быть несколько, и взнос —
 * ₽1 000 за КАЖДЫЙ чат. Одна строка = один эпизод «выгнали из этого чата».
 *
 * Снимок основания долга (сумма, блоки, дни просрочки, контакты) лежит прямо
 * в строке и после записи не пересчитывается: платежи и пороги могут поменяться
 * задним числом, а причина исключения — исторический факт. Это тот же принцип,
 * по которому payments не переписываются (guardrail H2746).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_debt_chat_removals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Курс, чей долг стал основанием. Не FK: курс могут архивировать,
            // а строка реестра должна пережить это (как в debt_reminders).
            $table->unsignedBigInteger('course_id')->index();
            // Группа могла быть удалена позже — обнуляем FK, но chat_id и имя
            // остаются снимком, иначе строка перестаёт быть доказательством.
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('telegram_chat_id');
            $table->string('chat_label')->nullable();

            // ── Снимок основания долга (иммутабелен после записи) ───────────
            $table->decimal('debt_amount', 12, 2)->nullable();
            $table->json('debt_block_numbers')->nullable();
            $table->unsignedInteger('debt_reference_block')->nullable();
            $table->unsignedInteger('days_overdue')->default(0);
            $table->timestamp('debt_basis_at')->nullable();

            // ── Снимок доказательств контакта (иммутабелен) ────────────────
            // Список попыток: [{source, at, channel, ref_id, answered}].
            $table->json('contact_attempts')->nullable();
            $table->unsignedInteger('unanswered_contacts')->default(0);
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('silent_since')->nullable();

            // ── Жизненный цикл ─────────────────────────────────────────────
            $table->string('status')->index();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_method')->nullable();
            $table->text('removal_note')->nullable();

            // ── Взнос за восстановление (отдельно от долга!) ───────────────
            $table->decimal('reinstatement_fee', 12, 2)->default(0);
            $table->string('fee_currency', 3)->default('RUB');
            $table->string('fee_status')->default('pending')->index();
            $table->timestamp('fee_settled_at')->nullable();
            $table->foreignId('fee_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->text('fee_waived_reason')->nullable();

            // ── Погашение долга и возврат в чат ────────────────────────────
            $table->timestamp('debt_settled_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('restoration_note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'telegram_chat_id']);
        });

        Schema::create('course_debt_chat_removal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('removal_id')
                ->constrained('course_debt_chat_removals')
                ->cascadeOnDelete();
            $table->string('event')->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_debt_chat_removal_events');
        Schema::dropIfExists('course_debt_chat_removals');
    }
};
