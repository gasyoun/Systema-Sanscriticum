<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H5480 (P3b): выписка ЗАЧИСЛЕНИЙ банка как источник доказательств сверки.
 * Парсеры H4200 читают только расходы, поэтому у H5445 источник
 * `bank_statement` был жёстко missing и день никогда не становился complete.
 *
 * Две таблицы, обе append-only:
 *  - money_bank_statements — факт импорта файла (sha256 файла уникален, так
 *    повторный импорт того же файла ничего не пишет) и ПЕРИОД, который файл
 *    покрывает: день считается покрытым только целиком (D1 «источник либо
 *    есть, либо громко missing», никогда ноль);
 *  - money_bank_statement_credits — строки зачислений, идемпотентные по
 *    row_hash (дата+сумма+№ документа+назначение).
 *
 * Персональных данных не добавляем: H4645 измерил, что в выписке счёта Точки
 * нет идентификатора ученика (1051 из 1052 поступлений — от самого банка), а
 * назначение платежа хранится ТОЛЬКО для машинных строк банка (QR-расчёты и
 * дневные агрегаты эквайринга); у прочих строк остаётся лишь purpose_digest.
 *
 * Имена триггеров НЕ начинаются с `money_`: счётчик триггеров ядра P1
 * (LedgerCoreTest::ledgerTriggerCount) считает по маске `money_%`, и общий
 * префикс сделал бы его цифру зависимой от чужой миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        $bin = match (true) {
            $db->getDriverName() === 'sqlite' => null,
            method_exists($db, 'isMaria') && $db->isMaria() => 'utf8mb4_nopad_bin',
            default => 'utf8mb4_bin',
        };
        $exact = fn ($column) => $bin ? $column->collation($bin) : $column;

        Schema::create('money_bank_statements', function (Blueprint $t) use ($exact): void {
            $t->id();
            $exact($t->char('file_hash', 64))->unique();
            $t->string('file_name', 191);
            // Период, который файл покрывает. explicit — из --from/--to
            // (период выгрузки), derived — по датам самих строк (нижняя оценка).
            $t->date('covers_from')->index();
            $t->date('covers_to')->index();
            $exact($t->string('period_source', 16)); // explicit | derived
            $t->unsignedInteger('rows_imported')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);
            $t->bigInteger('credit_kopecks')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('money_bank_statement_credits', function (Blueprint $t) use ($exact): void {
            $t->id();
            $t->foreignId('statement_id')->constrained('money_bank_statements');
            $exact($t->char('row_hash', 64))->unique();
            $t->date('value_date')->index();
            $t->bigInteger('amount_kopecks');
            $exact($t->char('currency', 3));
            // qr_settlement | card_acquiring_aggregate | transfer | other
            $exact($t->string('kind', 32))->index();
            $exact($t->string('doc_no', 64))->nullable();
            // Заказ №N из назначения (сильная привязка, в выписке Точки редка).
            $t->unsignedBigInteger('order_ref')->nullable()->index();
            $exact($t->string('qr_id', 64))->nullable();
            // Назначение — только у машинных строк банка (см. шапку файла).
            $t->text('purpose')->nullable();
            $exact($t->char('purpose_digest', 64));
            $t->timestamp('created_at')->nullable();
        });

        if ($db->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bank_statement_credits_no_update
                BEFORE UPDATE ON money_bank_statement_credits
                BEGIN SELECT RAISE(ABORT, 'money_bank_statement_credits is append-only'); END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bank_statement_credits_no_delete
                BEFORE DELETE ON money_bank_statement_credits
                BEGIN SELECT RAISE(ABORT, 'money_bank_statement_credits is append-only'); END;
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_statement_credits_no_update
            BEFORE UPDATE ON money_bank_statement_credits
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'money_bank_statement_credits is append-only';
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_statement_credits_no_delete
            BEFORE DELETE ON money_bank_statement_credits
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'money_bank_statement_credits is append-only';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_credits_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_credits_no_delete');
        Schema::dropIfExists('money_bank_statement_credits');
        Schema::dropIfExists('money_bank_statements');
    }
};
