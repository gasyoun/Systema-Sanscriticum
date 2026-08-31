<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3807: «одна карточка на программу» (рулинг MG 31-08-2026).
 *
 * До сих пор запись прошедшего потока продавалась ОТДЕЛЬНОЙ строкой каталога
 * под тем же номером потока, что и живой курс: витрина и поиск показывали одну
 * программу дважды (`astronomiia-dlia-astrologov` 279/418,
 * `ioga-sutry-patandzali` 396/327, `likbez-po-lingvistike` 344/394). Удалять
 * там нечего — у записи свои блоки, тарифы и оплаты (у курса 327 их 129.
 *
 * Связь `recording_of_course_id` называет это явно: «этот курс — ЗАПИСЬ вон
 * того». Курс остаётся жив и покупаем, но перестаёт быть вторым товаром в
 * каталоге; его страница отдаёт `rel=canonical` на живой курс, а живой курс
 * показывает запись вариантом покупки.
 *
 * `nullOnDelete`, а не каскад: удаление живого курса не имеет права утащить за
 * собой оплаченную запись — она в этот момент просто снова становится
 * самостоятельным товаром.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('recording_of_course_id')
                ->nullable()
                ->after('predecessor_course_id')
                ->constrained('courses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recording_of_course_id');
        });
    }
};
