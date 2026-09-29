<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Плашки занятий — по одной строке на занятие расписания.
 *
 * render_status — судьба отрисовки: rendered | no_template | no_number.
 * delivery_status — судьба доставки на Google Диск (пишет n8n через API):
 * null (ещё не забирали) | delivered | no_folder | error.
 *
 * render_hash = версия шаблона + номер + дата. Пока хеш не менялся, плашку не
 * перерисовываем; поменялся (перенос даты, новый номер, новая версия шаблона)
 * — перерисовываем и сбрасываем доставку, чтобы n8n заменил файл на Диске.
 *
 * drive_filename — имя файла на Диске: UTC-дата старта «ГГГГ-ММ-ДД.jpg», ровно
 * так его ищет ZOOM 1.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->unique('lesson_banners_schedule_unique')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('lesson_banner_templates')->nullOnDelete();
            $table->string('render_status', 32);
            $table->string('render_hash', 64)->nullable();
            $table->unsignedInteger('lesson_number')->nullable();
            $table->string('image_disk')->nullable();
            $table->string('image_path')->nullable();
            $table->string('drive_filename')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->string('delivery_status', 32)->nullable();
            $table->text('delivery_error')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['render_status', 'delivery_status'], 'lesson_banners_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_banners');
    }
};
