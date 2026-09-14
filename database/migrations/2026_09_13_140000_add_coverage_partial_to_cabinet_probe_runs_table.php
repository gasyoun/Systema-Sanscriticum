<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4648: soft-флаг «прогон неполного покрытия» в истории cabinet:probe.
 *
 * Канва-факстура не вооружена (CABINET_PROBE_KANVA_COURSE_ID пуст) или
 * student-ветка пропущена → прогон здоров, но фатал класса инцидента
 * 10-13.09 (/dvaram 500) этой пробой НЕ ловится. Флаг не критичен
 * (канал не выгорает), но виден в истории тем, кто смотрит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinet_probe_runs', function (Blueprint $table): void {
            $table->boolean('coverage_partial')->default(false)->after('critical');
        });
    }

    public function down(): void
    {
        Schema::table('cabinet_probe_runs', function (Blueprint $table): void {
            $table->dropColumn('coverage_partial');
        });
    }
};
