<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // H162: тумблер + lead-time для groups:notify-forming-shortfall, тот же
    // паттерн, что debt_reminder_lead_days.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_settings', 'recruitment_notify_enabled')) {
                $table->boolean('recruitment_notify_enabled')->default(true)->after('debt_reminder_text');
            }
            if (! Schema::hasColumn('marketing_settings', 'recruitment_notify_lead_days')) {
                $table->unsignedTinyInteger('recruitment_notify_lead_days')->default(2)->after('recruitment_notify_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (['recruitment_notify_enabled', 'recruitment_notify_lead_days'] as $col) {
                if (Schema::hasColumn('marketing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
