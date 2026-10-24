<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Публичные страницы преподавателей (/prepodavately): карточка в списке +
     * отдельная страница по образцу samskrtam.ru, но в прода-оформлении витрины.
     * Всё выключено по умолчанию: пока админ не заполнит и не включит страницу,
     * сайт преподавателя не показывает ни в списке, ни по прямому слагу.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->boolean('page_enabled')->default(false)->after('bio');
            $table->string('page_slug')->nullable()->unique()->after('page_enabled');
            // Специализация под именем: «преподаватель санскритской грамматики и индийской философии».
            $table->string('page_role')->nullable()->after('page_slug');
            // 1–2 предложения для карточки в списке.
            $table->text('page_excerpt')->nullable()->after('page_role');
            // Контент страницы (RichEditor): «Образование», «Преподавательская
            // деятельность», «Научные интересы», «Языки», «Личные интересы».
            $table->longText('page_html')->nullable()->after('page_excerpt');
            // Пары «подпись — значение» для строки фактов под именем (дата рождения, альма-матер).
            $table->json('page_facts')->nullable()->after('page_html');
            $table->string('youtube_url')->nullable()->after('page_facts');
            // Ручной порядок карточек; при равенстве — по имени.
            $table->unsignedInteger('page_sort')->default(100)->after('youtube_url');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn([
                'page_enabled',
                'page_slug',
                'page_role',
                'page_excerpt',
                'page_html',
                'page_facts',
                'youtube_url',
                'page_sort',
            ]);
        });
    }
};
