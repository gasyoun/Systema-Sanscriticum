<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            // Это поле 'content' будет хранить ВСЕ блоки сразу
            // (Hero, Видео, Отзывы, Форму и т.д.) в формате JSON
            if (!Schema::hasColumn('landing_pages', 'content')) {
                $table->json('content')->nullable()->after('slug');
            }
        });
    }

    public function down()
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};