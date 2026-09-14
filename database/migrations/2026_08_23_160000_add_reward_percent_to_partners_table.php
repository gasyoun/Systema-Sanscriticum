<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ратифицированная MG 23-08 партнёрская схема А: процент первого платежа
 * приведённого ученика. Фикс-сумма (reward_amount_override / глобальный
 * дефолт) остаётся доступной — percent приоритетнее, когда заполнен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->decimal('reward_percent', 5, 2)
                ->nullable()
                ->after('reward_amount_override');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn('reward_percent');
        });
    }
};
