<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3761 — связка «экранное имя в Zoom → пользователь платформы».
 *
 * Zoom отдаёт почту лишь у 4 % участников, поэтому `webinar_attendances.user_id`
 * пуст у 96 % строк, а плашка покрытия показывает ноль. Связка живёт отдельной
 * таблицей, а не проставляется в сами строки посещаемости: бэкфил волны 3
 * работает «только вставками» и не имеет права переписывать уже собранное, да и
 * решение «этот ник — этот студент» человек должен иметь возможность
 * пересмотреть, не трогая исходные данные Zoom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_participant_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Как имя выглядело в Zoom — чтобы человек в админке узнал строку.
            $table->string('zoom_name');
            // Нормализованный ключ (ZoomNameMatcher::key): нижний регистр,
            // латиница в кириллице, токены отсортированы — «Иванова Анна» и
            // «Anna Ivanova» дают одну строку.
            $table->string('zoom_name_key', 190);
            // auto_name — предложено сопоставлением; manual — подтверждено человеком.
            $table->string('source', 16)->default('auto_name');
            // strong — совпали имя и фамилия; weak — одно слово.
            $table->string('confidence', 16)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            // Одно имя внутри курса указывает ровно на одного человека.
            $table->unique(['course_id', 'zoom_name_key']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_participant_links');
    }
};
