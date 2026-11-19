<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Карточки открытых занятий в @samskrte_bot: галочка «анонс в боте» на
 * строке расписания. Выбор ручной: пробное в системе платное, разовые
 * курсы тоже бывают платными — автоотбор раздал бы платный Zoom.
 * Плюс свой баннер и строка-приписка для подписи карточки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->boolean('bot_card_enabled')->default(false)->after('is_overview');
            $table->string('card_image_path', 512)->nullable()->after('bot_card_enabled');
            $table->string('card_note', 500)->nullable()->after('card_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn(['bot_card_enabled', 'card_image_path', 'card_note']);
        });
    }
};
