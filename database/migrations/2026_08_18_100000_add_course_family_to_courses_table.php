<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3083 — «семья потоков» курса.
 *
 * Поток уже смоделирован строкой `courses` (332, 375 и 424 — три строки одного
 * курса «Кашмирский шиваизм»). Не хватало только ярлыка, который ставит их в
 * одну таблицу сравнения.
 *
 * Почему колонка, а не таблица: у семьи нет ни статуса, ни дат, ни владельца —
 * это ярлык группировки. Колонка видна в БД глазами, правится админом из
 * карточки курса и откатывается одним dropColumn.
 *
 * Почему не `predecessor_course_id`: существующее поле означает «продолжение с
 * урока N» (цепочку внутри программы). Поток 2 не продолжает поток 1, он его
 * повторяет; смешение двух смыслов сломало бы баннер продолжения в кабинете.
 *
 * NULL = курс ни в какой семье не состоит. Миграция аддитивна: ни один
 * существующий запрос колонку не читает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('course_family', 190)->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['course_family']);
            $table->dropColumn('course_family');
        });
    }
};
