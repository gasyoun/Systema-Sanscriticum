<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал событий обещаний оплаты: истечение (было тихим), нудж студенту,
 * прощение долга. Раньше promises:expire молча флипал active→expired — теперь
 * каждое событие фиксируется (retention audit gap #4). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promise_events', function (Blueprint $table) {
            $table->id();

            // Без FK: событие переживает удаление самого обещания.
            $table->unsignedBigInteger('payment_promise_id')->nullable()->index();

            // Снимок студента/курса/суммы — чтобы дайджест и история читались
            // даже после изменений/удаления обещания.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();

            // expired | nudged | waived
            $table->string('event', 16)->index();

            // Кто инициировал (для nudged/waived; null = система/демон).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();

            $table->string('note')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promise_events');
    }
};
