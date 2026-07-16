<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1085 (R7, H1068 editorial audit §3.6): финальный CTA (final-cta.blade.php)
 * жёстко писал «Готовы начать изучать санскрит?», что неверно для курсов по
 * философии/хинди/календарю. cta_subject — готовая фраза в винительном
 * падеже («философию», «хинди», «календарь»), которую куратор вписывает
 * вручную под конкретный курс — программная склонение существительных не
 * делается. Пусто = используется дефолт «санскрит» (текущее поведение).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('cta_subject')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('cta_subject');
        });
    }
};
