<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ссылка на вебинар. Заполнена → новому лиду с email уходит приветственное
    // письмо с приглашением и этой ссылкой. Пусто → письмо не отправляется.
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('landing_pages', 'webinar_url')) {
                $table->string('webinar_url', 500)->nullable()->after('webinar_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (Schema::hasColumn('landing_pages', 'webinar_url')) {
                $table->dropColumn('webinar_url');
            }
        });
    }
};
