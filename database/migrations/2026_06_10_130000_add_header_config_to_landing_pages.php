<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Per-landing настройка шапки: скрыть глобальное меню (Главная/Все курсы/Блог)
    // и показать вместо него заметку-ссылку (напр. «Для участия нужен Zoom»).
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('landing_pages', 'hide_default_nav')) {
                $table->boolean('hide_default_nav')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('landing_pages', 'header_note_text')) {
                $table->string('header_note_text')->nullable()->after('hide_default_nav');
            }
            if (! Schema::hasColumn('landing_pages', 'header_note_url')) {
                $table->string('header_note_url')->nullable()->after('header_note_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn(['hide_default_nav', 'header_note_text', 'header_note_url']);
        });
    }
};
