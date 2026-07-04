<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля продающей страницы курса. Все nullable — пустое поле = соответствующий
 * блок скрыт, поэтому существующие курсы не ломаются.
 *   audience/outcomes  — массивы строк («Для кого» / «Чему научитесь»);
 *   tech_requirements  — per-course override общего текста техтребований;
 *   meta_title/meta_description — SEO-override с авто-фолбэком в контроллере.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->json('audience')->nullable()->after('description');
            $table->json('outcomes')->nullable()->after('audience');
            $table->text('tech_requirements')->nullable()->after('outcomes');
            $table->string('meta_title')->nullable()->after('tech_requirements');
            $table->string('meta_description')->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['audience', 'outcomes', 'tech_requirements', 'meta_title', 'meta_description']);
        });
    }
};
