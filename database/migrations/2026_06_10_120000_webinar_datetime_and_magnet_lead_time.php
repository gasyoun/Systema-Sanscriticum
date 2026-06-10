<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            // Раньше webinar_date был `date` (без времени), хотя письмо уже форматирует
            // H:i. Лид-магнит выдаём «за N минут до старта» — нужно точное время.
            $table->dateTime('webinar_date')->nullable()->change();

            if (! Schema::hasColumn('landing_pages', 'magnet_lead_minutes')) {
                // За сколько минут до старта вебинара выдавать лид-магнит. NULL = дефолт 60.
                $table->unsignedSmallInteger('magnet_lead_minutes')
                    ->nullable()
                    ->default(60)
                    ->after('webinar_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            if (Schema::hasColumn('landing_pages', 'magnet_lead_minutes')) {
                $table->dropColumn('magnet_lead_minutes');
            }
            $table->date('webinar_date')->nullable()->change();
        });
    }
};
