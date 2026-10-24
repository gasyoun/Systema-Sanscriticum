<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Промокоды, скоупенные на конкретный блок курса (мини-курс «Подготовительная
 * группа»: 50% на первый блок грамматики). NULL = весь курс, как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->unsignedInteger('block_number')->nullable()->after('course_id');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table): void {
            $table->dropColumn('block_number');
        });
    }
};
