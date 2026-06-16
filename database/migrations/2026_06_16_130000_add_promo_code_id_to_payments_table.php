<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Промокод, применённый к платежу. Нужен для аудита («каким кодом оплатили»)
    // и для правила «один человек — один раз»: код израсходован, когда у юзера
    // есть ОПЛАЧЕННЫЙ платёж с этим promo_code_id.
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'promo_code_id')) {
                $table->foreignId('promo_code_id')
                    ->nullable()
                    ->after('course_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'promo_code_id')) {
                $table->dropConstrainedForeignId('promo_code_id');
            }
        });
    }
};
