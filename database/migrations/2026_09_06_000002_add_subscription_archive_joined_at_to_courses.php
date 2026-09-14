<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3916: аудит-колонка авто-входа курса в архив подписки «в записи».
 *
 * Правило 6-месячного окна: завершённый поток попадает в архив подписки
 * только через 6 месяцев после последнего занятия. Шедулер
 * `subscription:refresh-archive` ставит `club_included = true` и
 * фиксирует здесь момент входа. Ручные включения (2 курса) колонку
 * не получают до первого прогона шедулера — не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->date('subscription_archive_joined_at')->nullable()->after('club_access_key');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('subscription_archive_joined_at');
        });
    }
};
