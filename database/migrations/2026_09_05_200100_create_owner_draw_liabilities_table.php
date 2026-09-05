<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Обязательства перед владельцем (owner draw liability, H4188 п.3/4).
     * Руling MG 05-09-2026 (H4174-кластер): остаток фиксируется по февральской
     * ноте книги; каждая будущая запись несёт пару «выплачено / остаток»
     * вместо прозы в cell-нотах. Источник посева —
     * Uprava data/money/OWNER_DRAW_LIABILITY_REGISTRY_05-09-2026.md
     * (append-only реестр; до переноса в панель был единственным домом строк).
     *
     * Пара «выплачено/остаток»: paid пополняется ТОЛЬКО append-only записями
     * owner_draw_payments (с датой и ссылкой); remaining — хранимая проекция
     * principal − paid, обновляется обсервером записи выплаты.
     */
    public function up(): void
    {
        Schema::create('owner_draw_liabilities', function (Blueprint $table) {
            $table->id();

            // Валюта обязательства: EUR / USD / RUB (реестр 05-09).
            $table->string('currency', 3);

            // Зафиксированный остаток на дату фиксации.
            $table->decimal('principal', 12, 2);

            // Выплачено из этого обязательства (Σ append-only записей).
            $table->decimal('paid', 12, 2)->default(0);

            // Дата фиксации остатка (февральская нота = 28-02-2026).
            $table->date('fixed_at');

            // Основание: февральская нота книги / ссылка на реестр.
            $table->string('note')->nullable();

            $table->timestamps();

            $table->unique(['currency', 'fixed_at', 'principal']);
        });

        Schema::create('owner_draw_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_draw_liability_id')
                ->constrained('owner_draw_liabilities')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            $table->date('paid_at');

            // Ссылка на выплату: PayPal-подтверждение, выписка.
            $table->string('reference')->nullable();

            // Append-only: только момент записи.
            $table->timestamp('created_at')->nullable()->index();
        });

        // Посев реестра 05-09 (строки 1–3), идемпотентно.
        if (DB::table('owner_draw_liabilities')->count() === 0) {
            $registry = [
                ['currency' => 'EUR', 'principal' => 1606.76, 'fixed_at' => '2026-02-28',
                    'note' => 'Февральская нота книги: 1 242 € за февраль + 364,76 € с прошлых месяцев (OWNER_DRAW_LIABILITY_REGISTRY_05-09-2026 #1)'],
                ['currency' => 'USD', 'principal' => 38.00, 'fixed_at' => '2026-02-28',
                    'note' => 'Февральская нота книги: 38 $ (OWNER_DRAW_LIABILITY_REGISTRY_05-09-2026 #2)'],
                ['currency' => 'RUB', 'principal' => 44183.19, 'fixed_at' => '2026-02-28',
                    'note' => 'Февральская нота книги: 44 183,19 ₽ из насчитанного в валюте, выплачено в рублях = 489,24 € (OWNER_DRAW_LIABILITY_REGISTRY_05-09-2026 #3)'],
            ];
            $now = now();
            foreach ($registry as $row) {
                DB::table('owner_draw_liabilities')->insert(array_merge($row, [
                    'paid' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_draw_payments');
        Schema::dropIfExists('owner_draw_liabilities');
    }
};
