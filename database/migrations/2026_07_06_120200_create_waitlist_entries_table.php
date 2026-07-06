<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Строка «таблицы набора»: заявка на попадание в будущий набор. Живёт
        // между интересом (Lead) и оплатой (Payment). Возвратные студенты
        // связываются с существующим User по telegram_username — их история
        // групп и платёжная надёжность НЕ дублируются, а выводятся из связей.
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            // Маркетинговая воронка, из которой пришла заявка (если есть).
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            // Возвратный студент, сматченный по @username (источник истории/надёжности).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Целевой набор (Июль 2026 …). Nullable — иногда «на 2027» ещё без набора.
            $table->foreignId('intake_id')->nullable()->constrained('intakes')->nullOnDelete();

            $table->string('name');
            // @username в тукане (Telegram), нормализованный (без @). Ключ матчинга.
            $table->string('telegram_username')->nullable();
            // Телефон/иной контакт, когда @username нет.
            $table->string('contact')->nullable();

            // novice = Новичок, continuing = Продолжающий.
            $table->string('level')->nullable();
            // Свободный текст пожеланий по времени: «Суббота 9:00».
            $table->string('preferred_schedule')->nullable();
            // Заметка о часовом поясе: «+6 МСК», «Токио +9».
            $table->string('timezone_note')->nullable();

            // waiting → invited → recording_sent → enrolled → declined → silent.
            $table->string('status')->default('waiting');

            // Свободный текст прежней группы для тех, кто НЕ сматчен с User
            // (первичка без аккаунта). Для сматченных история берётся из связей.
            $table->string('prior_group_note')->nullable();
            $table->text('notes')->nullable();

            // Даты последней рассылки/ответа (колонки «Рассылка»/«Ответ»).
            $table->date('last_outreach_at')->nullable();
            $table->date('last_reply_at')->nullable();

            $table->timestamps();

            $table->index('telegram_username');
            $table->index(['intake_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
