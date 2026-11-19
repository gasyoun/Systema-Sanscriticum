<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6329 — журнал размещений различает виды: kind кампании («обзорное» /
 * «разовое» / «обычное» — канон SCHEDULE_KINDS_CANON_ANONS_SITE, значения
 * фида H6313). Nullable: строки, заминченные до H6329, вида не несут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anons_placements', function (Blueprint $table): void {
            $table->string('kind', 16)->nullable()->index()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('anons_placements', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
