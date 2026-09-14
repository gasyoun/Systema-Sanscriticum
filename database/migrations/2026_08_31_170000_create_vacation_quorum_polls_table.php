<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Механизм снятия занятий при роспуске (H3790 фаза C): soft delete,
        // обратимо через withTrashed; фид/кабинет отфильтруют автоматически.
        Schema::table('schedules', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Опросы кворума на каникульных группах (H3790, фаза C).
        // Один опрос на группу за сезон: бот спрашивает чат «когда возобновляем?»,
        // replies на message_id считает как голоса платного состава.
        Schema::create('vacation_quorum_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('resolved_at')->nullable();
            // pending = ждём дедлайн; quorum_met / dissolve_pending / dissolved / quorum_unmet_declined
            $table->string('outcome')->default('pending');
            // JSON-список telegram_user_id, признанных платными участниками
            $table->json('paid_voters')->nullable();
            $table->unsignedTinyInteger('quorum_required')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_quorum_polls');
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
