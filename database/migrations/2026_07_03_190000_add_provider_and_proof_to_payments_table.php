<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Полу-интегрированная оплата из-за рубежа (PayPal).
 *
 * provider   — платёжный источник платежа: null = касса/Точка (дефолт, обратная
 *              совместимость), 'paypal' = валютная заявка, поданная студентом и
 *              ожидающая ручной сверки. Отличает pending-заявку PayPal от
 *              pending-платежа Точки в фильтрах админки/отчётах.
 * proof_path — приватный путь (disk 'local') к загруженному студентом чеку/
 *              скриншоту оплаты. Не public: может содержать личные/платёжные данные.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('transaction_id');
            $table->string('proof_path')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['provider', 'proof_path']);
        });
    }
};
