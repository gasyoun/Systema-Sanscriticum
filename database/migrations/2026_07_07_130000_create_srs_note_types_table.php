<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — типы карточек. Note type задаёт набор полей и язык
 * колоды («санскритская базовая», позже «хинди»). Wave 1 сеет один тип `sa`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_note_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique(); // стабильный машинный ключ, напр. sanskrit_basic
            $table->string('name');
            $table->enum('language', ['sa', 'hi'])->default('sa');
            // Имена полей карточки в порядке отображения, напр. ["devanagari","iast","cyrillic","translation"].
            $table->json('fields');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_note_types');
    }
};
