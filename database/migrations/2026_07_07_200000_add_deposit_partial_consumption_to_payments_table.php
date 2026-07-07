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
            // На строке депозита/пробного: сколько его стоимости уже зачтено в
            // реальные покупки. Депозит гасится (deposit_consumed_at) только когда
            // consumed_amount доходит до amount — остаток переживает покупку
            // дешевле депозита (money-core, H071 #10).
            $table->decimal('consumed_amount', 10, 2)
                ->nullable()
                ->after('deposit_consumed_at');

            // На строке реальной покупки: сколько предоплаты (депозит/пробное)
            // было вычтено из цены ЭТОГО заказа при создании. null = платёж создан
            // до этой колонки (легаси) — при оплате гасим депозиты целиком, как
            // раньше. Даёт зачёту при докупке (upgradeCreditForUser) видеть полную
            // стоимость половины: net-кэш + депозитная часть (money-core, H071 #9).
            $table->decimal('deposit_credit_applied', 10, 2)
                ->nullable()
                ->after('consumed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['consumed_amount', 'deposit_credit_applied']);
        });
    }
};
