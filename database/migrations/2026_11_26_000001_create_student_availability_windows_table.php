<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Окна доступности ученика (RRULE + TZID, одна строка = одно окно). */
    public function up(): void
    {
        Schema::create('student_availability_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16)->default('student')->comment('student|curator');
            $table->string('kind', 16)->default('allow')->comment('allow|deny (deny — только куратор)');
            $table->string('rrule', 191)->comment('Ограниченный подвид: FREQ=WEEKLY;BYDAY=MO,TU,...');
            $table->string('start_time', 5)->comment('HH:mm');
            $table->string('end_time', 5)->comment('HH:mm');
            $table->string('tzid', 64)->default('Europe/Moscow');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_availability_windows');
    }
};
