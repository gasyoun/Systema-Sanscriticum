<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отзывы на странице входа (бегущие колонки вокруг формы):
 * - reviewed_at — дата отзыва, редактируется в админке и печатается на карточке;
 *   пусто — дата не показывается (старые отзывы без даты не получают выдуманную);
 * - show_on_login — отдельный переключатель «на странице входа», по умолчанию true,
 *   чтобы уже видимые отзывы остались на входе, как сейчас.
 * Аддитивно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->date('reviewed_at')->nullable()->after('media_url');
            $table->boolean('show_on_login')->default(true)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn(['reviewed_at', 'show_on_login']);
        });
    }
};
