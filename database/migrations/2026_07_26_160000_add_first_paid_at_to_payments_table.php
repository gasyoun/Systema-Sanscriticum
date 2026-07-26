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
            // Момент первого входа в оплаченный статус (create-as-paid или
            // pending->paid), проставляется один раз и никогда не переписывается —
            // резервная опора resurrection-guard'а (H1359 guard b), не зависящая
            // от payment_audits (который существует только с 08-06-2026 и не
            // видит withoutEvents create-as-paid пути). NULL = ещё не забэкфилен
            // и/или платёж никогда не был оплачен.
            $table->timestamp('first_paid_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('first_paid_at');
        });
    }
};
