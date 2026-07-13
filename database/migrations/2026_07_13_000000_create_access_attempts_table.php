<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Единая лента «проблем со входом» (H849) — до сих пор НИЧЕГО про неудачные
 * попытки входа/восстановления не логировалось (единственный след — строки
 * Laravel в password_reset_tokens). Эта таблица собирает и неудачные логины
 * (событие Illuminate\Auth\Events\Failed / Lockout), и запросы ссылки
 * восстановления (email не найден / троттл / отправлено) в один поток, чтобы
 * админ видел застрявших студентов и мог разблокировать их одним кликом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_attempts', function (Blueprint $table) {
            $table->id();

            // Кого касается попытка. user_id может быть null (ввели незнакомый
            // email); email хранится всегда, если он был введён.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable()->index();

            // Тип проблемы (см. константы AccessAttempt::KIND_*).
            $table->string('kind')->index();
            // Человекочитаемая деталь (например, текст исключения или ветка).
            $table->string('reason')->nullable();

            // IP нужен И для аудита, И чтобы точечно снять IP-троттл при разблокировке.
            $table->string('ip', 45)->nullable()->index();
            $table->text('user_agent')->nullable();

            // Разблокировка: кто и когда «разобрал» эту запись, каким действием.
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handled_action')->nullable();

            $table->timestamps();

            // Лента почти всегда листается по свежести + фильтру «неразобранные».
            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_attempts');
    }
};
