<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Админ-тумблер FAQ-суггестера (H247). ВЫКЛ по умолчанию — фактологический
    // авто-черновик ответа копится в очереди Helpdesk только при явном включении.
    // Дополняет deploy-рубильник config('features.support_answer_suggester').
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('support_answer_suggester_enabled')->default(false)->after('reminder_detection_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('support_answer_suggester_enabled');
        });
    }
};
