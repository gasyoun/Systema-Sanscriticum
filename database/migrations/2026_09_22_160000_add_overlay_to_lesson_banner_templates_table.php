<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Плашки занятий — прозрачный верхний слой шаблона (overlay.png).
 *
 * Нужен макетам, где изменяемый элемент лежит В СЕРЕДИНЕ стопки слоёв: у
 * «Кочергиной 53» огромный водяной номер стоит над плашками, но под фото
 * преподавателя. Рендер: фон → поля "layer":"under" → overlay → остальные поля.
 * У плоских макетов колонки пусты, и поведение прежнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_banner_templates', function (Blueprint $table) {
            $table->string('overlay_disk')->nullable()->after('background_path');
            $table->string('overlay_path')->nullable()->after('overlay_disk');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_banner_templates', function (Blueprint $table) {
            $table->dropColumn(['overlay_disk', 'overlay_path']);
        });
    }
};
