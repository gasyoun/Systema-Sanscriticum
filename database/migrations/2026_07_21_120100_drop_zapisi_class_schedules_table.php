<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручную таблицу напоминаний zapisi_class_schedules заменил авто-механизм из
 * расписания (zapisi:remind-classes читает Schedule → group.telegram_chat_id).
 * down() пересоздаёт таблицу в исходном виде — откат обратим.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('zapisi_class_schedules');
    }

    public function down(): void
    {
        Schema::create('zapisi_class_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id')->index();
            $table->dateTime('starts_at')->index();
            $table->text('message_template');
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
