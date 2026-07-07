<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H266 (M1, #190) — продажа записей завершённых курсов.
 *
 * Оба поля АДДИТИВНЫЕ и nullable-безопасные (default false): существующие
 * курсы/тарифы не меняют поведения, пока их явно не пометят.
 *
 *  - courses.is_completed — курс/поток завершён, записи опубликованы. Лендинг
 *    завершённого курса переключается с «Записаться» на покупку записи (только
 *    когда фича-флаг course_recordings_sales ВКЛ; см. Course::sellsRecordings()).
 *  - tariffs.is_recording — этот тариф продаёт ЗАПИСЬ (evergreen), а не участие в
 *    живом потоке. НЕ новая система доступа: accessKey() остаётся 'full'/'block_N',
 *    поэтому запись открывает те же уроки через тот же PaymentObserver::grantAccess().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_completed')->default(false)->after('is_active');
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->boolean('is_recording')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('is_completed');
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('is_recording');
        });
    }
};
