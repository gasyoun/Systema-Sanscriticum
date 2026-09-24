<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5442 (P0, D4/D13): стабильные ключи повтора на уровне данных.
 *
 *  - payments.claim_replay_key — одна PayPal-заявка на один перевод
 *    (txn, иначе ученик+тариф+дата+валюта+сумма). Повтор — нарушение
 *    unique-индекса, а не второй paid-платёж.
 *  - teacher_payouts.settlement_key — одна поблочная выплата на один
 *    расчётный пакет (препод+курс+блок+группа). Двойной submit — отказ.
 *
 * Обе колонки nullable: исторические строки остаются NULL (не переписываются),
 * ключ пишется только при включённом features.payment_fix_wave1. Аддитивно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'claim_replay_key')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('claim_replay_key', 64)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('teacher_payouts', 'settlement_key')) {
            Schema::table('teacher_payouts', function (Blueprint $table): void {
                $table->string('settlement_key', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'claim_replay_key')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique(['claim_replay_key']);
                $table->dropColumn('claim_replay_key');
            });
        }

        if (Schema::hasColumn('teacher_payouts', 'settlement_key')) {
            Schema::table('teacher_payouts', function (Blueprint $table): void {
                $table->dropUnique(['settlement_key']);
                $table->dropColumn('settlement_key');
            });
        }
    }
};
