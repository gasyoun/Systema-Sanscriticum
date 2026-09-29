<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5066: заявки интереса на курс — публичная форма /interest/{course} под
 * флагом features.course_interest_form. Три интента: join (в следующий набор),
 * recording (купить запись), revive (возобновить занятия, если соберётся
 * группа). course_id nullable: ещё не заведённые курсы-анонсы (напр.
 * «Космография» Леонченко) живут как course_title-only строки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_interest_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('course_title')->default(''); // свободное название анонса, когда курса ещё нет
            $table->string('intent'); // join | recording | revive
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('telegram')->nullable();
            $table->text('comment')->nullable();
            $table->string('status')->default('new'); // new | done (разобрано куратором)
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'intent']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_interest_requests');
    }
};
