<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GC-B3: провайдер-нейтральные алиасы meeting_* поверх zoom_*-колонок.
 *
 * Абстракция WebinarProvider не должна протекать именем вендора в схему.
 * На переходный период zoom_* остаются читаемыми и ЗАПИСЫВАЕМЫМИ (весь код
 * пишет по-прежнему в zoom_*); meeting_* заполняются копией на миграции и
 * перейдут в запись вместе с реальной сменой провайдера (Q4, руление R1 — BBB).
 * До тех пор источник истины — zoom_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->string('meeting_provider_id')->nullable()->after('zoom_meeting_id');
            $table->text('meeting_join_url')->nullable()->after('zoom_join_url');
            $table->text('meeting_start_url')->nullable()->after('zoom_start_url');
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('meeting_provider_id')->nullable()->after('zoom_meeting_id');
            $table->text('meeting_link')->nullable()->after('zoom_link');
        });

        DB::table('schedules')->update([
            'meeting_provider_id' => DB::raw('zoom_meeting_id'),
            'meeting_join_url' => DB::raw('zoom_join_url'),
            'meeting_start_url' => DB::raw('zoom_start_url'),
        ]);
        DB::table('courses')->update([
            'meeting_provider_id' => DB::raw('zoom_meeting_id'),
            'meeting_link' => DB::raw('zoom_link'),
        ]);
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn(['meeting_provider_id', 'meeting_join_url', 'meeting_start_url']);
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn(['meeting_provider_id', 'meeting_link']);
        });
    }
};
