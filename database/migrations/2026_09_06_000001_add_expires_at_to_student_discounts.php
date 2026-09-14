<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3916: срок действия персональной скидки.
 *
 * Скидка лояльности подписки «в записи» (5% первого года, 112 покупателей
 * из CRM) живёт год с момента запуска — StudentDiscount раньше был вечным.
 * NULL = бессрочная (все существующие строки) — поведение не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_discounts', function (Blueprint $table): void {
            $table->date('expires_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('student_discounts', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
