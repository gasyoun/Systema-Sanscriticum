<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GC-C1 (H1641) — стадии воронки СДЕЛОК. Отдельная таблица, а не переиспользование
 * lead_stages: у lead_stages первичный ключ-строка `key` (leads.status join'ится по
 * строке), а спека GC-C1 (§5) задаёт deals.stage_id как ЧИСЛОВОЙ id. Формы
 * несовместимы как нарисованы — см. развилку F3 в
 * docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §7, решённую в пользу отдельной
 * таблицы.
 *
 * Сделка ≠ лид: у одного человека может быть несколько сделок (второй курс,
 * апгрейд тарифа) — именно это Lead выразить не может. lead_stages и
 * LeadKanbanBoard (H451) остаются нетронутыми.
 */
return new class extends Migration
{
    /**
     * Стартовая воронка продаж. Финальные стадии помечены is_won/is_lost —
     * зеркало lead_stages.is_final, но разнесённое на «выиграна»/«проиграна»,
     * потому что мост от оплаты (PaymentDealBridgeObserver) должен уметь найти
     * именно выигрышную стадию.
     */
    private const SEED = [
        ['key' => 'new', 'name' => 'Новая', 'position' => 1, 'is_won' => false, 'is_lost' => false],
        ['key' => 'in_progress', 'name' => 'В работе', 'position' => 2, 'is_won' => false, 'is_lost' => false],
        ['key' => 'negotiation', 'name' => 'Переговоры', 'position' => 3, 'is_won' => false, 'is_lost' => false],
        ['key' => 'won', 'name' => 'Выиграна', 'position' => 4, 'is_won' => true, 'is_lost' => false],
        ['key' => 'lost', 'name' => 'Проиграна', 'position' => 5, 'is_won' => false, 'is_lost' => true],
    ];

    public function up(): void
    {
        Schema::create('deal_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            // Ровно одна стадия должна нести is_won — её ищет мост от оплаты.
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('deal_stages')->insert(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            self::SEED,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stages');
    }
};
