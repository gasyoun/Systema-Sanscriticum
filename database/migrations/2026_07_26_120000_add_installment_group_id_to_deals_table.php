<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1659 — группа рассрочки на сделке.
 *
 * Ruling 26-07-2026 по развилке F9: несколько платежей считаются ОДНОЙ продажей
 * тогда и только тогда, когда их обещания оплаты делят непустой
 * installment_group_id (эвристика «этот человек + этот курс» отменена, она
 * прятала настоящие повторные покупки).
 *
 * Колонка — материализация уже разрешённой группы, чтобы второй взнос находил
 * свою продажу ОДНИМ индексированным запросом по deals, а не перепрохождением
 * цепочки payments → payment_promises на каждом платеже. Заодно группа видна в
 * отчётности: «эта выигранная сделка закрыта рассрочкой такого-то плана».
 *
 * Аддитивно и nullable: обычная разовая покупка группы не имеет, весь
 * существующий ряд сделок остаётся валидным. FK нет намеренно —
 * installment_group_id это uuid-МЕТКА на payment_promises, а не первичный ключ
 * какой-либо таблицы (сама payment_promises хранит её тем же uuid + index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->uuid('installment_group_id')
                ->nullable()
                ->after('source_payment_id');

            $table->index('installment_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['installment_group_id']);
            $table->dropColumn('installment_group_id');
        });
    }
};
