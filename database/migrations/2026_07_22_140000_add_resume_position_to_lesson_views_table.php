<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-video resume (H1450, W2). Три аддитивные колонки — существующие поля
 * lesson_views (open_count/total_time_on_page/...) не трогаются.
 *
 * last_position_seconds — где студент остановился (для «продолжить с HH:MM»);
 * max_position_seconds — самая дальняя точка (монотонный прогресс-сигнал,
 * никогда не уменьшается даже если студент отмотал назад);
 * video_duration_seconds — знаменатель для %-прогресса, nullable (не все хосты
 * отдают длительность через postMessage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_views', function (Blueprint $table): void {
            $table->unsignedInteger('last_position_seconds')->nullable()->after('total_time_on_page');
            $table->unsignedInteger('max_position_seconds')->nullable()->after('last_position_seconds');
            $table->unsignedInteger('video_duration_seconds')->nullable()->after('max_position_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_views', function (Blueprint $table): void {
            $table->dropColumn(['last_position_seconds', 'max_position_seconds', 'video_duration_seconds']);
        });
    }
};
