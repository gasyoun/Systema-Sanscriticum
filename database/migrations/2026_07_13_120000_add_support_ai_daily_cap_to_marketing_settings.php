<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Дневной предел LLM-черновиков FAQ-суггестера v2 (S5): категории D/E/F
    // (цена/доступ/материалы) формулируются внешним LLM (OpenRouter), поэтому у
    // них есть стоимость. NULL → берётся дефолт config('features.support_ai_daily_cap');
    // 0 → без предела. Дополняет тумблер support_answer_suggester_enabled.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->unsignedInteger('support_ai_daily_cap')->nullable()->after('support_answer_suggester_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('support_ai_daily_cap');
        });
    }
};
