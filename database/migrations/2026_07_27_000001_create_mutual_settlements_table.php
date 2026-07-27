<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Акт взаимозачёта «преподаватель-ученик» (H1730, шаг B3).
 *
 * Фиксированный СНИМОК двух цифр за период: сколько человек заплатил школе как
 * ученик и сколько ему начислено как преподавателю. Строка хранит суммы, а не
 * пересчитывает их, — поздняя оплата задним числом не должна двигать уже
 * подписанную цифру.
 *
 * Таблица только создаётся; ни одна существующая строка не переписывается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutual_settlements', function (Blueprint $table) {
            $table->id();

            // Один и тот же человек в двух ипостасях. Инвариант
            // users.teacher_id === teacher_id проверяет MutualSettlementService.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            // На какой блок/поток зафиксированы условия (решение 9 плана).
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('block_number')->nullable();

            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            $table->decimal('tuition_amount', 12, 2)->default(0);
            $table->decimal('salary_amount', 12, 2)->default(0);
            $table->decimal('offset_amount', 12, 2)->default(0);
            $table->string('net_direction', 32)->default('zero');
            $table->decimal('net_amount', 12, 2)->default(0);

            $table->string('status', 32)->default('draft');
            $table->timestamp('fixed_at')->nullable();
            $table->foreignId('fixed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            // Выплата, в которой зачёт израсходован. Непустой payout_id = акт
            // уже зачтён и в выборку доступных не попадает (однократность на
            // уровне данных, тот же приём, что у прямых платежей).
            $table->foreignId('payout_id')->nullable()->constrained('teacher_payouts')->nullOnDelete();

            // Расшифровка обеих сумм НА МОМЕНТ ФИКСАЦИИ — сверка воспроизводима
            // через год, даже если платежи с тех пор поменялись.
            $table->json('breakdown')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('payout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutual_settlements');
    }
};
