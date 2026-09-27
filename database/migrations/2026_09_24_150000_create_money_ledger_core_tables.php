<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H5443 (P1, D2/D6/D7/D9/D11/D12/D16) — денежное ядро: неизменяемый журнал
 * движений, отдельные распределения и обязательства. Все суммы — целые
 * копейки рубля (BIGINT), float нигде нет.
 *
 * Инварианты живут в БД (триггеры, одинаковые правила для MariaDB/MySQL и
 * SQLite), а не только в сервисе: проведённые движения и распределения нельзя
 * ни изменить, ни удалить; возвраты не превышают исходную оплату; одно
 * доказательство нельзя зачесть дважды; сторно и корректировка ссылаются на
 * оригинал. Контракт: docs/MONEY_LEDGER_CORE_P1_CONTRACT_2026.md.
 *
 * Аддитивно: легаси-таблицы не трогаются, чтение экранов не переключается.
 * Правила заморожены здесь намеренно (не вынесены в класс приложения), чтобы
 * будущая правка кода не меняла смысл уже прогнанной миграции.
 */
return new class extends Migration
{
    private const IN = "('receipt','direct_teacher_receipt')";

    private const OUT = "('refund','payout','compensation')";

    private const MOVEMENT_TYPES = "('receipt','refund','reversal','correction','payout','compensation','direct_teacher_receipt')";

    public function up(): void
    {
        // MariaDB по умолчанию сравнивает строки без учёта регистра и хвостовых пробелов;
        // ключи и типы ядра сравниваются точно, как в SQLite и в PHP (`===`).
        $db = DB::connection();
        $bin = match (true) {
            $db->getDriverName() === 'sqlite' => null,
            method_exists($db, 'isMaria') && $db->isMaria() => 'utf8mb4_nopad_bin', // NO PAD: «a» и «a » — разные
            default => 'utf8mb4_0900_bin',
        };

        Schema::create('money_obligations', function (Blueprint $table) use ($bin) {
            $table->id();
            $table->string('obligation_key', 191)->collation($bin)->unique();
            // block | trial | deposit | debt
            $table->string('kind', 16)->collation($bin);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->unsignedInteger('block_number')->nullable();
            $table->unsignedTinyInteger('lessons_count')->nullable();
            $table->bigInteger('list_price_kopecks');
            $table->bigInteger('discount_kopecks')->default(0);
            // Цена обязательства = договорная цена − скидка (D7); депозит зачитывается ПОСЛЕ.
            $table->bigInteger('price_kopecks');
            // Пробное/блок признаются только по факту проведения (D6). Write-once.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Происхождение из легаси (без FK: легаси-удаления не блокируются до P4).
            $table->unsignedBigInteger('legacy_payment_id')->nullable()->index();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id', 'kind'], 'money_obligations_user_course_kind_idx');
        });

        Schema::create('money_movements', function (Blueprint $table) use ($bin) {
            $table->id();
            // Стабильный ключ повтора: один и тот же факт нельзя провести дважды.
            $table->string('movement_key', 191)->collation($bin)->unique();
            $table->string('type', 32)->collation($bin);
            // Со знаком: приток > 0, отток < 0; сторно = −оригинал.
            $table->bigInteger('amount_kopecks');
            // Исходная валюта/сумма (доказательство, D16); расчёт — только в копейках рубля.
            $table->char('source_currency', 3)->collation($bin)->nullable();
            $table->bigInteger('source_amount_minor')->nullable();
            // school | teacher_personal
            $table->string('received_account', 32)->collation($bin)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->restrictOnDelete();
            // Цепочка сторно/корректировок: корень — исходная строка.
            $table->foreignId('root_movement_id')->nullable()->constrained('money_movements')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('money_movements')->restrictOnDelete();
            $table->foreignId('corrects_movement_id')->nullable()->unique()->constrained('money_movements')->restrictOnDelete();
            // Возврат/компенсация ссылаются на исходную оплату (D12).
            $table->foreignId('refund_of_movement_id')->nullable()->constrained('money_movements')->restrictOnDelete();
            // Семья предела возврата: исходная оплата, её сторно/корректировки,
            // её возвраты и их сторно/корректировки. Сумма семьи >= 0.
            $table->foreignId('cap_anchor_id')->nullable()->constrained('money_movements')->restrictOnDelete();
            // Погашение обязательства перед преподавателем прямым платежом (D16).
            $table->foreignId('pairs_movement_id')->nullable()->unique()->constrained('money_movements')->restrictOnDelete();
            // Доказательство потребляется один раз (D11/D16).
            $table->string('evidence_key', 191)->collation($bin)->nullable()->unique();
            $table->unsignedBigInteger('legacy_payment_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_teacher_payout_id')->nullable()->index();
            $table->timestamp('occurred_at');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'course_id'], 'money_movements_user_course_idx');
            $table->index(['type', 'occurred_at'], 'money_movements_type_occurred_idx');
        });

        Schema::create('money_allocations', function (Blueprint $table) use ($bin) {
            $table->id();
            $table->string('allocation_key', 191)->collation($bin)->unique();
            $table->foreignId('movement_id')->constrained('money_movements')->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('money_obligations')->restrictOnDelete();
            $table->bigInteger('amount_kopecks');
            $table->foreignId('reverses_allocation_id')->nullable()->unique()->constrained('money_allocations')->restrictOnDelete();
            // Пара «снять с депозита / зачесть в блок» (D7) делит один ключ.
            $table->string('transfer_group', 191)->collation($bin)->nullable()->index();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('money_allocations');
        Schema::dropIfExists('money_movements');
        Schema::dropIfExists('money_obligations');
    }

    /**
     * @return array<string, array{table: string, event: string, rules: list<array{0: string, 1: string}>}>
     */
    private function triggers(): array
    {
        $in = self::IN;
        $out = self::OUT;
        $types = self::MOVEMENT_TYPES;
        // D6 на уровне БД: дата проведения не дальше суток вперёд (сутки — запас на
        // часовые пояса: приложение пишет московское время, SQLite считает в UTC).
        $tomorrow = DB::connection()->getDriverName() === 'sqlite' ? "datetime('now', '+1 day')" : '(NOW() + INTERVAL 1 DAY)';
        // Ключ сторно, созданного той же операцией корректировки: «<ключ корректировки>:reversal».
        $ownReversalKey = DB::connection()->getDriverName() === 'sqlite' ? "NEW.movement_key || ':reversal'" : "CONCAT(NEW.movement_key, ':reversal')";
        $ownReversalKeyV = DB::connection()->getDriverName() === 'sqlite' ? "v.movement_key || ':reversal'" : "CONCAT(v.movement_key, ':reversal')";

        // Ожидаемый якорь семьи предела возврата для новой строки движения.
        $expectedAnchor = "CASE WHEN NEW.type = 'refund' THEN NEW.refund_of_movement_id "
            ."WHEN NEW.type IN ('reversal','correction') THEN (SELECT CASE WHEN o.type IN {$in} THEN o.id "
            ."WHEN o.type = 'refund' THEN o.refund_of_movement_id ELSE NULL END FROM money_movements o WHERE o.id = NEW.root_movement_id) "
            .'ELSE NULL END';

        $immutableMovement = [['ledger: posted movements are immutable', '1 = 1']];
        $immutableAllocation = [['ledger: posted allocations are immutable', '1 = 1']];

        $obligationShape = [
            ['ledger: obligation delivered and cancelled at once', 'NEW.delivered_at IS NOT NULL AND NEW.cancelled_at IS NOT NULL'],
            ['ledger: only a lesson obligation can be delivered', "NEW.kind IN ('deposit','debt') AND NEW.delivered_at IS NOT NULL"],
            ['ledger: a lesson is delivered only after it happened', "NEW.delivered_at IS NOT NULL AND NEW.delivered_at > {$tomorrow}"],
        ];

        return [
            'money_movements_bi' => ['table' => 'money_movements', 'event' => 'INSERT', 'rules' => [
                ['ledger: unknown movement type', "NEW.type NOT IN {$types}"],
                ['ledger: amount must be non-zero', 'NEW.amount_kopecks = 0'],
                ['ledger: inflow must be positive', "NEW.type IN {$in} AND NEW.amount_kopecks < 0"],
                ['ledger: outflow must be negative', "NEW.type IN {$out} AND NEW.amount_kopecks > 0"],
                ['ledger: reversal link shape', "(NEW.type = 'reversal') <> (NEW.reverses_movement_id IS NOT NULL)"],
                ['ledger: correction link shape', "(NEW.type = 'correction') <> (NEW.corrects_movement_id IS NOT NULL)"],
                ['ledger: chain root required exactly for reversal and correction', "(NEW.type IN ('reversal','correction')) <> (NEW.root_movement_id IS NOT NULL)"],
                ['ledger: refund must name its source payment', "NEW.type = 'refund' AND NEW.refund_of_movement_id IS NULL"],
                ['ledger: only refund or compensation may name a source payment', "NEW.type NOT IN ('refund','compensation') AND NEW.refund_of_movement_id IS NOT NULL"],
                ['ledger: source payment must be a root receipt', "NEW.refund_of_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements s WHERE s.id = NEW.refund_of_movement_id AND s.type IN {$in} AND s.root_movement_id IS NULL)"],
                ['ledger: direct teacher receipt needs teacher, evidence, currency and teacher_personal account', "NEW.type = 'direct_teacher_receipt' AND (NEW.teacher_id IS NULL OR NEW.evidence_key IS NULL OR NEW.source_currency IS NULL OR COALESCE(NEW.received_account, '') <> 'teacher_personal')"],
                ['ledger: teacher_personal account only for a direct teacher receipt', "NEW.type IN ('receipt','refund','payout','compensation') AND COALESCE(NEW.received_account, '') = 'teacher_personal'"],
                ['ledger: student money names its student', "NEW.type IN ('receipt','direct_teacher_receipt','refund','compensation') AND NEW.user_id IS NULL"],
                ['ledger: a refund or compensation belongs to the student of its source payment', 'NEW.refund_of_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements s WHERE s.id = NEW.refund_of_movement_id AND s.user_id = NEW.user_id)'],
                ['ledger: reversal and correction belong to the student of their chain', 'NEW.root_movement_id IS NOT NULL AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND COALESCE(o.user_id, 0) <> COALESCE(NEW.user_id, 0))'],
                ['ledger: payout needs a teacher', "NEW.type = 'payout' AND NEW.teacher_id IS NULL"],
                ['ledger: compensation needs reason and approver', "NEW.type = 'compensation' AND (NEW.reason IS NULL OR NEW.approved_by IS NULL)"],
                ['ledger: only a payout may offset a direct teacher receipt', "NEW.pairs_movement_id IS NOT NULL AND NEW.type <> 'payout'"],
                ['ledger: offset must mirror the direct teacher receipt', "NEW.pairs_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements p WHERE p.id = NEW.pairs_movement_id AND p.type = 'direct_teacher_receipt' AND p.teacher_id = NEW.teacher_id AND p.amount_kopecks = -NEW.amount_kopecks)"],
                // D16: погашение живёт и умирает только вместе со своим прямым платежом.
                ['ledger: a direct-receipt offset is reversed only after its receipt', "NEW.type = 'reversal' AND EXISTS (SELECT 1 FROM money_movements t WHERE t.id = NEW.reverses_movement_id AND t.pairs_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = t.pairs_movement_id))"],
                ['ledger: a direct teacher receipt and its offset are reversed and re-recorded, never corrected', "NEW.type = 'correction' AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND (o.type = 'direct_teacher_receipt' OR o.pairs_movement_id IS NOT NULL))"],
                ['ledger: chain root must be a root row', 'NEW.root_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND o.root_movement_id IS NULL)'],
                ['ledger: movement already reversed', "NEW.type = 'reversal' AND EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = NEW.reverses_movement_id)"],
                ['ledger: reversal must mirror a non-reversal row of the same chain', "NEW.type = 'reversal' AND NOT EXISTS (SELECT 1 FROM money_movements t WHERE t.id = NEW.reverses_movement_id AND t.type <> 'reversal' AND t.amount_kopecks = -NEW.amount_kopecks AND COALESCE(t.root_movement_id, t.id) = NEW.root_movement_id)"],
                // Порядок вставки держит промежуточное состояние в пределе возврата:
                // приток корректируется ДО сторно, отток — ПОСЛЕ.
                ['ledger: an inflow correction must precede the reversal of the corrected row', "NEW.type = 'correction' AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND o.type IN {$in}) AND EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = NEW.corrects_movement_id)"],
                ['ledger: an outflow correction must follow the reversal of the corrected row', "NEW.type = 'correction' AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND o.type IN {$out}) AND NOT EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = NEW.corrects_movement_id)"],
                // Уже сторнированная строка недействительна: её «корректировка» — новая проводка.
                ['ledger: a reversed row is not corrected', "NEW.type = 'correction' AND EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = NEW.corrects_movement_id AND r.movement_key <> {$ownReversalKey})"],
                ['ledger: correction must stay in the chain of a non-reversal row', "NEW.type = 'correction' AND NOT EXISTS (SELECT 1 FROM money_movements t WHERE t.id = NEW.corrects_movement_id AND t.type <> 'reversal' AND COALESCE(t.root_movement_id, t.id) = NEW.root_movement_id)"],
                ['ledger: correction sign must match the original type', "NEW.type = 'correction' AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND ((o.type IN {$in} AND NEW.amount_kopecks < 0) OR (o.type IN {$out} AND NEW.amount_kopecks > 0)))"],
                ['ledger: reversal and correction keep the teacher and course of their chain', 'NEW.root_movement_id IS NOT NULL AND EXISTS (SELECT 1 FROM money_movements o WHERE o.id = NEW.root_movement_id AND (COALESCE(o.teacher_id, 0) <> COALESCE(NEW.teacher_id, 0) OR COALESCE(o.course_id, 0) <> COALESCE(NEW.course_id, 0)))'],
                ['ledger: refund-cap anchor mismatch', "COALESCE(NEW.cap_anchor_id, 0) <> COALESCE({$expectedAnchor}, 0)"],
                ['ledger: refunds exceed the source payment', 'NEW.cap_anchor_id IS NOT NULL AND COALESCE((SELECT SUM(f.amount_kopecks) FROM money_movements f WHERE f.id = NEW.cap_anchor_id OR f.cap_anchor_id = NEW.cap_anchor_id), 0) + NEW.amount_kopecks < 0'],
            ]],
            'money_movements_bu' => ['table' => 'money_movements', 'event' => 'UPDATE', 'rules' => $immutableMovement],
            'money_movements_bd' => ['table' => 'money_movements', 'event' => 'DELETE', 'rules' => $immutableMovement],

            'money_allocations_bi' => ['table' => 'money_allocations', 'event' => 'INSERT', 'rules' => [
                ['ledger: allocation amount must be non-zero', 'NEW.amount_kopecks = 0'],
                ['ledger: allocation needs a student-money movement', "NOT EXISTS (SELECT 1 FROM money_movements m JOIN money_movements r ON r.id = COALESCE(m.root_movement_id, m.id) WHERE m.id = NEW.movement_id AND r.type IN ('receipt','direct_teacher_receipt','refund'))"],
                ['ledger: allocation and obligation belong to different students', 'EXISTS (SELECT 1 FROM money_movements m, money_obligations o WHERE m.id = NEW.movement_id AND o.id = NEW.obligation_id AND COALESCE(m.user_id, 0) <> o.user_id)'],
                ['ledger: allocation reversal must mirror its target', 'NEW.reverses_allocation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_allocations t WHERE t.id = NEW.reverses_allocation_id AND t.amount_kopecks = -NEW.amount_kopecks AND t.obligation_id = NEW.obligation_id AND t.reverses_allocation_id IS NULL)'],
                // Снять распределение может только его же движение, сторно/корректировка
                // его цепочки или возврат по её корню — с обратным знаком.
                ['ledger: allocation reversal must ride the target movement, its chain or a refund of it', 'NEW.reverses_allocation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_allocations t JOIN money_movements tm ON tm.id = t.movement_id JOIN money_movements v ON v.id = NEW.movement_id '
                    ."WHERE t.id = NEW.reverses_allocation_id AND (v.id = tm.id OR ((v.amount_kopecks > 0) <> (tm.amount_kopecks > 0) AND (COALESCE(v.root_movement_id, v.id) = COALESCE(tm.root_movement_id, tm.id) OR (v.type = 'refund' AND v.refund_of_movement_id = COALESCE(tm.root_movement_id, tm.id))))))"],
                // Семья оплаты (оплата + её возвраты, сторно, корректировки) не может
                // снять с обязательства больше, чем сама в него внесла.
                ['ledger: a payment family reduces an obligation only by what it funded', 'NEW.amount_kopecks < 0 AND EXISTS (SELECT 1 FROM money_movements v WHERE v.id = NEW.movement_id AND COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a JOIN money_movements f ON f.id = a.movement_id '
                    .'WHERE a.obligation_id = NEW.obligation_id AND COALESCE(f.cap_anchor_id, f.id) = COALESCE(v.cap_anchor_id, v.id)), 0) + NEW.amount_kopecks < 0)'],
                // Сторно несёт только зеркала распределений строки, которую сторнирует.
                ['ledger: a reversal carries only the mirrors of the row it reverses', "EXISTS (SELECT 1 FROM money_movements v WHERE v.id = NEW.movement_id AND v.type = 'reversal') AND NOT EXISTS (SELECT 1 FROM money_allocations t JOIN money_movements v ON v.id = NEW.movement_id WHERE t.id = NEW.reverses_allocation_id AND t.movement_id = v.reverses_movement_id)"],
                // D6: признанная выручка проведённого занятия не уходит возвратом —
                // уменьшить её может только сторно ошибочной проводки.
                // Исключение — корректировка возврата: она заново уменьшает обязательство
                // не больше, чем сторно исправляемого возврата на него вернуло (итог операции ≥ 0).
                ['ledger: a delivered obligation is reduced only by a reversal', "NEW.amount_kopecks < 0 AND EXISTS (SELECT 1 FROM money_obligations o WHERE o.id = NEW.obligation_id AND o.delivered_at IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM money_movements v WHERE v.id = NEW.movement_id AND v.type = 'reversal') "
                    ."AND NOT EXISTS (SELECT 1 FROM money_movements v JOIN money_movements r ON r.reverses_movement_id = v.corrects_movement_id AND r.movement_key = {$ownReversalKeyV} WHERE v.id = NEW.movement_id AND v.type = 'correction' "
                    .'AND COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.movement_id = r.id AND a.obligation_id = NEW.obligation_id), 0) '
                    .'+ COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.movement_id = v.id AND a.obligation_id = NEW.obligation_id), 0) + NEW.amount_kopecks >= 0)'],
                ['ledger: cannot allocate a reversed movement', 'NEW.reverses_allocation_id IS NULL AND EXISTS (SELECT 1 FROM money_movements r WHERE r.reverses_movement_id = NEW.movement_id)'],
                ['ledger: cannot fund a cancelled obligation', 'NEW.amount_kopecks > 0 AND EXISTS (SELECT 1 FROM money_obligations o WHERE o.id = NEW.obligation_id AND o.cancelled_at IS NOT NULL)'],
                ['ledger: allocations exceed the movement', 'NOT EXISTS (SELECT 1 FROM money_movements m WHERE m.id = NEW.movement_id AND (CASE WHEN m.amount_kopecks > 0 THEN 1 ELSE -1 END) * (COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.movement_id = NEW.movement_id), 0) + NEW.amount_kopecks) BETWEEN 0 AND ABS(m.amount_kopecks))'],
                ['ledger: obligation allocation outside 0..price', 'NOT EXISTS (SELECT 1 FROM money_obligations o WHERE o.id = NEW.obligation_id AND COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.obligation_id = NEW.obligation_id), 0) + NEW.amount_kopecks BETWEEN 0 AND o.price_kopecks)'],
                // Семья оплаты не распределяет больше своего нетто (возвращённые деньги не закрывают обязательств).
                ['ledger: a payment family cannot allocate more than it holds', 'NEW.amount_kopecks > 0 AND EXISTS (SELECT 1 FROM money_movements v WHERE v.id = NEW.movement_id AND '
                    .'COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a JOIN money_movements f ON f.id = a.movement_id WHERE f.id = COALESCE(v.cap_anchor_id, v.id) OR f.cap_anchor_id = COALESCE(v.cap_anchor_id, v.id)), 0) + NEW.amount_kopecks '
                    .'> (SELECT SUM(n.amount_kopecks) FROM money_movements n WHERE n.id = COALESCE(v.cap_anchor_id, v.id) OR n.cap_anchor_id = COALESCE(v.cap_anchor_id, v.id)))'],
            ]],
            'money_allocations_bu' => ['table' => 'money_allocations', 'event' => 'UPDATE', 'rules' => $immutableAllocation],
            'money_allocations_bd' => ['table' => 'money_allocations', 'event' => 'DELETE', 'rules' => $immutableAllocation],

            'money_obligations_bi' => ['table' => 'money_obligations', 'event' => 'INSERT', 'rules' => array_merge([
                ['ledger: unknown obligation kind', "NEW.kind NOT IN ('block','trial','deposit','debt')"],
                ['ledger: price must equal list minus discount, discount within 0..list', 'NEW.list_price_kopecks < 0 OR NEW.discount_kopecks < 0 OR NEW.discount_kopecks > NEW.list_price_kopecks OR NEW.price_kopecks <> NEW.list_price_kopecks - NEW.discount_kopecks'],
                ['ledger: a block is four lessons of one course', "NEW.kind = 'block' AND (NEW.course_id IS NULL OR NEW.block_number IS NULL OR COALESCE(NEW.lessons_count, 0) <> 4)"],
                ['ledger: a trial is one lesson', "NEW.kind = 'trial' AND COALESCE(NEW.lessons_count, 0) <> 1"],
                ['ledger: deposit and debt carry no discount and no lessons', "NEW.kind IN ('deposit','debt') AND (NEW.discount_kopecks <> 0 OR NEW.lessons_count IS NOT NULL)"],
            ], $obligationShape)],
            'money_obligations_bu' => ['table' => 'money_obligations', 'event' => 'UPDATE', 'rules' => array_merge([
                ['ledger: obligation terms are immutable', 'NEW.id <> OLD.id OR NEW.obligation_key <> OLD.obligation_key OR NEW.kind <> OLD.kind OR NEW.user_id <> OLD.user_id '
                    .'OR COALESCE(NEW.course_id, 0) <> COALESCE(OLD.course_id, 0) OR COALESCE(NEW.block_number, -1) <> COALESCE(OLD.block_number, -1) '
                    .'OR COALESCE(NEW.lessons_count, -1) <> COALESCE(OLD.lessons_count, -1) OR NEW.list_price_kopecks <> OLD.list_price_kopecks '
                    .'OR NEW.discount_kopecks <> OLD.discount_kopecks OR NEW.price_kopecks <> OLD.price_kopecks '
                    .'OR COALESCE(NEW.legacy_payment_id, 0) <> COALESCE(OLD.legacy_payment_id, 0)'],
                ['ledger: delivery is write-once', 'OLD.delivered_at IS NOT NULL AND (NEW.delivered_at IS NULL OR NEW.delivered_at <> OLD.delivered_at)'],
                ['ledger: cancellation is write-once', 'OLD.cancelled_at IS NOT NULL AND (NEW.cancelled_at IS NULL OR NEW.cancelled_at <> OLD.cancelled_at)'],
            ], $obligationShape)],
            'money_obligations_bd' => ['table' => 'money_obligations', 'event' => 'DELETE', 'rules' => [['ledger: obligations are never deleted', '1 = 1']]],
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
                throw new RuntimeException("H5443 ledger triggers: unsupported driver {$driver}");
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
