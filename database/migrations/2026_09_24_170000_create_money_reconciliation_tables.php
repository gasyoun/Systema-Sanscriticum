<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H5445 (P3, D1/D2/D4/D8/D10/D16) — ежедневная оперативная сверка: журнал
 * прогонов с контрольными суммами, очередь типизированных исключений и
 * неизменяемый след их разрешения. Денег эти таблицы не создают и не меняют —
 * только фиксируют, что сверка увидела. Контракт:
 * docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md.
 *
 * Правила неизменяемости — триггеры (как в P1 H5443), одинаковые для
 * MariaDB/MySQL и SQLite:
 *  - прогон (money_recon_runs) не редактируется и не удаляется;
 *  - у исключения замороженное доказательство (ключ, тип, источник, снимок);
 *    меняются только состояние и «последний прогон, где видели»;
 *  - событие разрешения (money_recon_exception_events) не редактируется и не
 *    удаляется; исключение не удаляется.
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

        Schema::create('money_recon_runs', function (Blueprint $t) use ($exact): void {
            $t->id();
            // Операционный день сверки (Europe/Moscow) и режим запуска.
            $t->date('business_date')->index();
            $exact($t->string('mode', 16)); // scheduled | manual
            $exact($t->string('status', 16)); // complete | incomplete
            // sha256 входа (все строки доказательств окна + срез ядра) и итогов.
            $exact($t->char('input_fingerprint', 64));
            $exact($t->char('totals_checksum', 64));
            $t->json('sources');
            $t->json('totals');
            $t->json('classification');
            $t->unsignedInteger('exceptions_opened')->default(0);
            $t->timestamp('created_at')->nullable();

            // Тот же день + тот же вход = тот же прогон: повтор ничего не пишет.
            $t->unique(['business_date', 'input_fingerprint'], 'money_recon_runs_day_input_unique');
        });

        Schema::create('money_recon_exceptions', function (Blueprint $t) use ($exact): void {
            $t->id();
            $exact($t->string('exception_key', 191))->unique();
            $exact($t->string('type', 40))->index();
            $exact($t->string('source', 40));
            $exact($t->string('source_ref', 191))->index();
            $t->json('evidence');
            $t->bigInteger('amount_kopecks')->nullable();
            $exact($t->char('currency', 3))->nullable();
            // Без FK: след сверки переживает удаление пользователя, триггер его не трогает.
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $exact($t->string('state', 16))->default('open')->index(); // open | resolved | dismissed
            $t->foreignId('first_run_id')->nullable()->constrained('money_recon_runs');
            $t->foreignId('last_seen_run_id')->nullable()->constrained('money_recon_runs');
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('money_recon_exception_events', function (Blueprint $t) use ($exact): void {
            $t->id();
            $t->foreignId('exception_id')->constrained('money_recon_exceptions');
            $exact($t->string('event', 24)); // opened | resolved | dismissed | note
            $t->foreignId('run_id')->nullable()->constrained('money_recon_runs');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->json('evidence')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['exception_id', 'id']);
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('money_recon_exception_events');
        Schema::dropIfExists('money_recon_exceptions');
        Schema::dropIfExists('money_recon_runs');
    }

    /**
     * @return array<string, array{table: string, event: string, rules: list<array{0: string, 1: string}>}>
     */
    private function triggers(): array
    {
        $changed = fn (string $col) => DB::connection()->getDriverName() === 'sqlite'
            ? "NEW.{$col} IS NOT OLD.{$col}"
            : "NOT (NEW.{$col} <=> OLD.{$col})";

        $frozen = implode(' OR ', array_map($changed, ['exception_key', 'type', 'source', 'source_ref', 'evidence', 'amount_kopecks', 'currency', 'user_id', 'first_run_id', 'created_at']));

        return [
            'recon_runs_bu' => ['table' => 'money_recon_runs', 'event' => 'UPDATE', 'rules' => [['recon: runs are immutable', '1 = 1']]],
            'recon_runs_bd' => ['table' => 'money_recon_runs', 'event' => 'DELETE', 'rules' => [['recon: runs are never deleted', '1 = 1']]],
            'recon_exceptions_bu' => ['table' => 'money_recon_exceptions', 'event' => 'UPDATE', 'rules' => [
                ['recon: exception evidence is frozen', $frozen],
                ['recon: unknown exception state', "NEW.state NOT IN ('open','resolved','dismissed')"],
                ['recon: a closed exception never reopens', "OLD.state <> 'open' AND NEW.state = 'open'"],
                ['recon: closing needs a time', "NEW.state <> 'open' AND NEW.resolved_at IS NULL"],
            ]],
            'recon_exceptions_bd' => ['table' => 'money_recon_exceptions', 'event' => 'DELETE', 'rules' => [['recon: exceptions are never deleted', '1 = 1']]],
            'recon_exception_events_bu' => ['table' => 'money_recon_exception_events', 'event' => 'UPDATE', 'rules' => [['recon: exception events are immutable', '1 = 1']]],
            'recon_exception_events_bd' => ['table' => 'money_recon_exception_events', 'event' => 'DELETE', 'rules' => [['recon: exception events are never deleted', '1 = 1']]],
        ];
    }

    private function createTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->triggers() as $name => $t) {
            if ($driver === 'sqlite') {
                $body = implode("\n", array_map(
                    fn (array $r) => 'SELECT RAISE(ABORT, '.$this->quote($r[0]).') WHERE '.$r[1].';',
                    $t['rules'],
                ));
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$t['event']} ON {$t['table']} FOR EACH ROW BEGIN\n{$body}\nEND");

                continue;
            }

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                throw new RuntimeException("H5445 recon triggers: unsupported driver {$driver}");
            }

            $body = implode("\n", array_map(
                fn (array $r) => 'IF '.$r[1].' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = '.$this->quote($r[0]).'; END IF;',
                $t['rules'],
            ));
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$t['event']} ON {$t['table']} FOR EACH ROW BEGIN\n{$body}\nEND");
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
