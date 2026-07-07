<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Тикет A1: атрибуция при регистрации/оплате — UTM/реферер захватываются на
    // первом визите и пишутся в профиль при регистрации; год рождения —
    // необязательное поле онбординга (сейчас известен у ~7 % студентов).
    // Все колонки nullable — аддитивная миграция, не блокирует существующие строки.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'utm_source')) {
                $table->string('utm_source')->nullable()->index()->after('referred_by');
            }
            if (! Schema::hasColumn('users', 'utm_medium')) {
                $table->string('utm_medium')->nullable()->after('utm_source');
            }
            if (! Schema::hasColumn('users', 'utm_campaign')) {
                $table->string('utm_campaign')->nullable()->after('utm_medium');
            }
            if (! Schema::hasColumn('users', 'utm_content')) {
                $table->string('utm_content')->nullable()->after('utm_campaign');
            }
            if (! Schema::hasColumn('users', 'utm_term')) {
                $table->string('utm_term')->nullable()->after('utm_content');
            }
            if (! Schema::hasColumn('users', 'click_id')) {
                $table->string('click_id')->nullable()->after('utm_term');
            }
            if (! Schema::hasColumn('users', 'referrer')) {
                $table->string('referrer', 2048)->nullable()->after('click_id');
            }
            if (! Schema::hasColumn('users', 'lead_id')) {
                $table->unsignedBigInteger('lead_id')->nullable()->index()->after('referrer');
            }
            if (! Schema::hasColumn('users', 'birth_year')) {
                $table->unsignedSmallInteger('birth_year')->nullable()->after('lead_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
                'utm_term', 'click_id', 'referrer', 'lead_id', 'birth_year',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
