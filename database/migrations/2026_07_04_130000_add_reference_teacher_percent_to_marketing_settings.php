<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Опорный процент преподавателя для сравнения «фикс vs процент» в калькуляторе
 * выплаты по блоку. Подставляется по умолчанию, редактируется на месте.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->decimal('reference_teacher_percent', 5, 2)->default(30)->after('deposit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->dropColumn('reference_teacher_percent');
        });
    }
};
