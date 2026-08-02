<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Money-adjacent detector: default OFF until ops enables on staging/prod (H2156).
    // Unlike reminder_detection_enabled (default ON), this must never silently arm itself.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('promise_suggestion_detection_enabled')->default(false)->after('reminder_detection_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('promise_suggestion_detection_enabled');
        });
    }
};
