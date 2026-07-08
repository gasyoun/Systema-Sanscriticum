<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка возврата к исходному платежу (H352, модель «отдельный Расход»).
 *
 * Возврат средств ученику заводится отдельным платежом-«Расход» (как и раньше,
 * виден в ДДС), а refund_of_payment_id указывает, ЗА КАКОЙ исходный платёж этот
 * возврат. По этой связи признание выручки исходного платежа усекается по месяц
 * возврата (config revenue.reverse_unrecognized_on_refund), а отложенная выручка
 * вычитает возвращённое. Nullable: обычные платежи и системные расходы, не
 * относящиеся к возврату конкретной оплаты, оставляют поле пустым.
 *
 * nullOnDelete: если исходный платёж удалён, возврат остаётся расходной строкой
 * в ДДС, но теряет привязку (усекать больше нечего).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('refund_of_payment_id')
                ->nullable()
                ->after('status')
                ->constrained('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_payment_id');
        });
    }
};
