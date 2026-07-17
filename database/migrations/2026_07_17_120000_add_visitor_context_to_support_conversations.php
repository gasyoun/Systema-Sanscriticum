<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1196 — Jivo-паритет Pillar 1: контекст посетителя веб-чата на треде поддержки.
 *
 * Куратор в Helpdesk должен видеть, «из какого города пишет посетитель» (как в
 * админке Jivo) и с какой страницы начат чат. Всё аддитивно и nullable:
 *   • visitor_ip / entry_url / referrer — пишутся ВСЕГДА при первом сообщении
 *     (дёшево, ноль внешних вызовов);
 *   • visitor_city/region/country — резолвятся асинхронно (ResolveVisitorGeoJob →
 *     VisitorGeoResolver) и только при включённом флаге support_visitor_geo;
 *   • visitor_geo_resolved_at — маркер «попытка резолва была» (идемпотентность,
 *     город может остаться null: приватный IP, драйвер null, промах провайдера).
 * IP хранится для резолва/модерации; оператору наружу светим город/страну, не IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->string('visitor_ip', 45)->nullable()->after('guest_name');
            $table->string('visitor_city')->nullable()->after('visitor_ip');
            $table->string('visitor_region')->nullable()->after('visitor_city');
            $table->string('visitor_country')->nullable()->after('visitor_region');
            $table->timestamp('visitor_geo_resolved_at')->nullable()->after('visitor_country');
            $table->text('entry_url')->nullable()->after('visitor_geo_resolved_at');
            $table->text('referrer')->nullable()->after('entry_url');
        });
    }

    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropColumn([
                'visitor_ip',
                'visitor_city',
                'visitor_region',
                'visitor_country',
                'visitor_geo_resolved_at',
                'entry_url',
                'referrer',
            ]);
        });
    }
};
