<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Рублёвая сумма скидки, применённой при покупке. Для percent-скидки —
            // эквивалент в рублях, для fixed — сама сумма. NULL/0 = без скидки.
            // Парная к discount_percent: fixed-скидка хранит только amount.
            if (! Schema::hasColumn('payments', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->nullable()->after('discount_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
        });
    }
};
