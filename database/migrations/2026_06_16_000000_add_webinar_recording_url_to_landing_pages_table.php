<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ссылка на запись вебинара. Заполнена админом → планировщик
    // (webinar:deliver-recordings) разошлёт лидам лендинга письмо со ссылкой.
    // Пусто → письмо «вот запись» не отправляется.
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('landing_pages', 'webinar_recording_url')) {
                $table->string('webinar_recording_url', 500)->nullable()->after('webinar_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (Schema::hasColumn('landing_pages', 'webinar_recording_url')) {
                $table->dropColumn('webinar_recording_url');
            }
        });
    }
};
