<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            // Заполняется только для уроков с show_on_main — выводится бейджем на карточке.
            // Заполнение опционально: пусто — бейдж не показывается.
            $table->unsignedInteger('duration_seconds')->nullable()->after('lesson_date');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('duration_seconds');
        });
    }
};
