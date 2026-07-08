<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('recruitment_notify_enabled')->default(true);
            $table->unsignedTinyInteger('recruitment_notify_lead_days')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['recruitment_notify_enabled', 'recruitment_notify_lead_days']);
        });
    }
};
