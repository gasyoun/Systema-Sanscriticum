<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H5480 (P3) — зачисления банковской выписки Точки как источник доказательств
 * `bank_statement` для ежедневной сверки (H5445).
 *
 * Строка выписки НЕ содержит идентификатора студента (H4645: 1051/1052
 * входящих строк называют плательщиком банк), поэтому сверка идёт ТОЛЬКО на
 * уровне дневного агрегата. Денег эти таблицы не создают и не меняют — они
 * лишь фиксируют, что банк прислал.
 *
 * ПДн: назначение платежа целиком НЕ хранится — только его sha256
 * (purpose_digest) и извлечённые технические токены (QR ID, «Заказ №N»).
 *
 * Неизменяемость (как в P1 H5443 / P3 H5445): импорт и строка зачисления
 * append-only — ни UPDATE, ни DELETE, одинаково на MariaDB/MySQL и SQLite.
 * Контракт: docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md.
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

        Schema::create('bank_statement_imports', function (Blueprint $t) use ($exact): void {
            $t->id();
            // sha256 самого файла: повторная загрузка того же файла — не второй импорт.
            $exact($t->char('file_sha256', 64))->unique();
            $exact($t->string('file_name', 191));
            $exact($t->string('provider', 32))->default('tochka');
            // Период, который выписка ПОКРЫВАЕТ (что выбрал бухгалтер при выгрузке),
            // а не min/max дат строк: день без зачислений тоже покрыт.
            $t->dateTime('covers_from');
            $t->dateTime('covers_to');
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_imported')->default(0);
            $t->unsignedInteger('rows_duplicate')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);
            $t->unsignedBigInteger('credited_kopecks')->default(0);
            $t->unsignedBigInteger('imported_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['covers_from', 'covers_to']);
        });

        Schema::create('bank_statement_credits', function (Blueprint $t) use ($exact): void {
            $t->id();
            // Тот же хэш, что считает сенсор H4645: документ|дата|сумма|назначение.
            $exact($t->char('row_hash', 64))->unique();
            $t->foreignId('import_id')->constrained('bank_statement_imports');
            $t->date('booked_on')->index();
            $t->bigInteger('amount_kopecks');
            $exact($t->char('currency', 3))->default('RUB');
            // qr_settlement | card_acquiring_aggregate | transfer | other
            $exact($t->string('kind', 32))->index();
            $exact($t->string('doc_no', 64))->nullable();
            $exact($t->string('qr_id', 64))->nullable();
            $t->unsignedBigInteger('order_ref')->nullable()->index();
            $exact($t->char('purpose_digest', 64));
            $t->timestamp('created_at')->nullable();
            $t->index(['booked_on', 'kind']);
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('bank_statement_credits');
        Schema::dropIfExists('bank_statement_imports');
    }

    /**
     * @return array<string, array{table: string, event: string, message: string}>
     */
    private function triggers(): array
    {
        return [
            'bank_stmt_imports_bu' => ['table' => 'bank_statement_imports', 'event' => 'UPDATE', 'message' => 'statement: imports are immutable'],
            'bank_stmt_imports_bd' => ['table' => 'bank_statement_imports', 'event' => 'DELETE', 'message' => 'statement: imports are never deleted'],
            'bank_stmt_credits_bu' => ['table' => 'bank_statement_credits', 'event' => 'UPDATE', 'message' => 'statement: credit rows are immutable'],
            'bank_stmt_credits_bd' => ['table' => 'bank_statement_credits', 'event' => 'DELETE', 'message' => 'statement: credit rows are never deleted'],
        ];
    }

    private function createTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->triggers() as $name => $t) {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$t['event']} ON {$t['table']} FOR EACH ROW BEGIN\nSELECT RAISE(ABORT, ".$this->quote($t['message']).");\nEND");

                continue;
            }

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                throw new RuntimeException("H5480 statement triggers: unsupported driver {$driver}");
            }

            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$t['event']} ON {$t['table']} FOR EACH ROW BEGIN\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ".$this->quote($t['message']).";\nEND");
        }
    }

    private function dropTriggers(): void
    {
        foreach (array_keys($this->triggers()) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    private function quote(string $s): string
    {
        return "'".str_replace("'", "''", $s)."'";
    }
};
