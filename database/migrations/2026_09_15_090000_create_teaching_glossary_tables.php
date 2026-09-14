<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Преподавательский глоссарий (агрегат H4832) — поверхность тира Top (5 000 ₽/мес).
 *
 * Данные кладёт только `teaching-glossary:import` из зарегистрированного в kosha
 * агрегата (dataset `stenogrammy-teaching-glossary`: кириллическая форма → SLP1
 * лемма, частота ≥ 20, профиль по 42 курсам). Сам TSV в репозиторий НЕ
 * коммитится — датасет restricted-тира, файл живёт на диске сервера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_glossary_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('cyrillic_form', 191);
            $table->string('lemma_slp1', 191);
            $table->text('ru_gloss')->nullable();
            $table->unsignedInteger('corpus_freq');
            $table->unsignedInteger('n_files');
            $table->unsignedInteger('n_courses');
            $table->boolean('ambiguous_lemmas')->default(false);
            $table->string('gloss_provenance', 32)->nullable();
            $table->timestamps();

            $table->unique(['cyrillic_form', 'lemma_slp1']);
            $table->index('corpus_freq');
        });

        Schema::create('teaching_glossary_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('course', 191)->unique();
            $table->unsignedInteger('n_headwords');
            $table->text('top_terms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_glossary_courses');
        Schema::dropIfExists('teaching_glossary_terms');
    }
};
