<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // H3084 — очередь «этот платёж-«Расход» на самом деле выплата преподавателю».
    //
    // Предложение, а не факт: подтверждение меняет только статус строки ЗДЕСЬ.
    // Ни `teacher_payouts`, ни `payments` эта таблица не трогает — перенос в
    // выплатной реестр остаётся отдельным действием человека.
    //
    // `payment_id` уникален: один платёж — не более одного предложения, иначе
    // подтверждённые дубли посчитались бы в «выплачено» дважды.
    public function up(): void
    {
        Schema::create('teacher_payout_attribution_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('course_family')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on')->nullable();
            $table->float('confidence')->default(0);
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending|confirmed|rejected
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('payment_id');
            $table->index(['teacher_id', 'status']);
            $table->index('course_family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payout_attribution_suggestions');
    }
};
