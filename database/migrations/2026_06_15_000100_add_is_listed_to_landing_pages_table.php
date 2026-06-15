<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Флаг «листить в каталогах». true (дефолт) — страница видна на главной,
     * в блоке «Наши курсы» и в sitemap. false — доступна только по прямой ссылке
     * (например, страница записи вебинара из бота). Прямой URL (PromoController)
     * не зависит от этого флага — он отдаёт страницу по is_active.
     */
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->boolean('is_listed')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn('is_listed');
        });
    }
};
