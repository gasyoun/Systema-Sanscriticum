<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6531 — HDYHAU self-report («откуда узнали», пункт 4 H6046, MG 12-10
 * «Внедрять»): необязательное поле формы заявки. Значение — канонический
 * ключ из Lead::hdyhauOptions() либо свободный текст ветки «Другое».
 * Nullable: заявка без ответа валидна, старые формы поле не шлют.
 *
 * Отличается от leads.source (H5021): source вводит куратор по итогам
 * разговора, reported_source — самоотчёт заявителя в момент заявки
 * (четвёртая нога атрибуции, зеркало reported_source журнала лида).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('reported_source', 255)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('reported_source');
        });
    }
};
