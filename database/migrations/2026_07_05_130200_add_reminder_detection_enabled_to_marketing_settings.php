<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Отдельный от support_ai_include_telegram флаг: управляет только LLM-шагом
    // классификации «это просьба напомнить?». Дефолт ВКЛ — детектор лишь предлагает,
    // ничего не отправляет сам; approval-очередь уже человеческий гейт (см. H187).
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('reminder_detection_enabled')->default(true)->after('debt_reminder_text');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('reminder_detection_enabled');
        });
    }
};
