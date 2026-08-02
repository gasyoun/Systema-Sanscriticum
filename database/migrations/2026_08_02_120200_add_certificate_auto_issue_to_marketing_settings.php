<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Стартуем выключенной: включается руками после dry-run на проде.
            $table->boolean('certificate_auto_issue_enabled')->default(false);
            // Окно ретроспективы: вехи, чей блок закончился раньше N дней назад,
            // автозапуск не трогает (старые потоки не получают внезапных писем).
            $table->unsignedSmallInteger('certificate_auto_issue_lookback_days')->default(14);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'certificate_auto_issue_enabled',
                'certificate_auto_issue_lookback_days',
            ]);
        });
    }
};
