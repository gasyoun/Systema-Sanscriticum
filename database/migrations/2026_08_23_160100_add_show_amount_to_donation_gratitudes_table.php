<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ратифицированное MG 23-08 уточнение реестра благодарностей: сумму показываем,
 * только если конкретный человек сам попросил («имена; сумма — по просьбе»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_gratitudes', function (Blueprint $table): void {
            $table->boolean('show_amount')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('donation_gratitudes', function (Blueprint $table): void {
            $table->dropColumn('show_amount');
        });
    }
};
