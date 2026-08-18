<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2869 step-9 repair: единицы каталога VisualDCS материализуются в БД при
 * ИМПОРТЕ, а не декодируются из 11–15-МБ JSON на пути запроса. На проде
 * запросный json_decode опубликованного релиза (7 689 + 31 753 единиц)
 * исчерпывал memory_limit=128M php-fpm и валил все поверхности в 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visualdcs_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visualdcs_release_id')
                ->constrained('visualdcs_releases')
                ->cascadeOnDelete();
            $table->string('surface', 16);
            $table->string('unit_id');
            $table->string('tier', 16);
            $table->string('title', 512);
            $table->string('title_lower', 512);
            $table->unsignedInteger('sort_order');
            // Поверхностные поля списка (rank/tokens/domGender/src/…) — мелкие.
            $table->json('summary');
            // Тяжёлая часть show-страницы: cells / txt+links. Только одна
            // строка декодируется на запрос.
            $table->longText('detail');
            $table->timestamps();

            $table->unique(['visualdcs_release_id', 'unit_id'], 'vdcs_units_release_unit_unique');
            $table->index(['visualdcs_release_id', 'surface', 'sort_order'], 'vdcs_units_release_surface_sort');
            $table->index(['visualdcs_release_id', 'surface', 'tier'], 'vdcs_units_release_surface_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visualdcs_units');
    }
};
