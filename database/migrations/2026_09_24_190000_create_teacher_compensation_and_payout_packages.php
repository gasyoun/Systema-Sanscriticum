<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H5444 (P2, D10–D17) — выплаты преподавателям на версионированных условиях
 * и неизменяемых расчётных пакетах.
 *
 *  - D15 `teacher_compensation_terms`: условие с преподавателем, типом
 *        компенсации, ставкой, валютой расчёта, датой действия, подтверждением
 *        и версией. Запись неизменяема: правка ставки = новая версия.
 *  - D17 `teacher_compensation_assignments`: зарплату создаёт ТОЛЬКО отдельное
 *        датированное назначение. Роль и доступ к курсу не порождают строк —
 *        базовая строка пакета без назначения отвергается триггером.
 *  - D13 `teacher_payout_packages`: стабильный ключ + конечный автомат
 *        `draft → approved → paid → reversed`. `approved` замораживает состав
 *        и ставки, `paid` требует платёжного доказательства, назад — нельзя,
 *        второй незакрытый пакет того же окна — нельзя.
 *  - D14 все вычисления в копейках рубля; курс, валюта и производная сумма
 *        выплаты появляются последним шагом, после авансов и взаимозачётов.
 *  - D11 удержание возврата применяется один раз (unique на пару
 *        «возврат + обязательство») и только за неоказанный блок.
 *  - D16 прямое получение денег преподавателем гасится строкой пакета,
 *        связанной с движением `direct_teacher_receipt`, доказательство — раз.
 *
 * Все суммы — целые копейки (BIGINT). Приток базы > 0, удержания < 0,
 * итог = сумма строк и не может быть отрицательным.
 *
 * Аддитивно: легаси `teacher_payouts` не трогается, читатели не переключаются
 * (флаг features.money_payout_packages, по умолчанию OFF).
 */
return new class extends Migration
{
    private const LINE_KINDS = "('base','advance','offset','refund_adjustment','direct_receipt_offset')";

    private const TERM_TYPES = "('percent','per_lesson','per_block','fixed')";

    private const SCOPES = "('school','course')";

    private const STATES = "('draft','approved','paid','reversed')";

    public function up(): void
    {
        $bin = $this->binaryCollation();

        Schema::create('teacher_compensation_terms', function (Blueprint $table) use ($bin) {
            $table->id();
            $table->string('term_key', 191)->collation($bin)->unique();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->unsignedInteger('version');
            // percent | per_lesson | per_block | fixed
            $table->string('compensation_type', 16)->collation($bin);
            // Доля в миллионных долях (30% = 300000); только для percent.
            $table->unsignedBigInteger('rate_ppm')->nullable();
            // Ставка в копейках рубля; только для per_lesson | per_block | fixed.
            $table->bigInteger('rate_kopecks')->nullable();
            // Валюта расчёта условия — всегда RUB (D14); поле хранит договорную
            // валюту как доказательство, расчёт от неё не зависит.
            $table->char('calc_currency', 3)->collation($bin)->default('RUB');
            $table->date('effective_from');
            // Подтверждение в Systema обязательно (D15): без него условие не существует.
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            // Происхождение: перенос из чата/старого реестра сохраняется как доказательство.
            $table->string('source', 32)->collation($bin)->default('systema');
            $table->string('evidence_key', 191)->collation($bin)->nullable()->unique();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['teacher_id', 'version'], 'tct_teacher_version_unique');
        });

        Schema::create('teacher_compensation_assignments', function (Blueprint $table) use ($bin) {
            $table->id();
            $table->string('assignment_key', 191)->collation($bin)->unique();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('teacher_compensation_terms')->restrictOnDelete();
            // school | course
            $table->string('scope_kind', 16)->collation($bin);
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'scope_kind', 'course_id'], 'tca_teacher_scope_idx');
        });

        Schema::create('teacher_payout_packages', function (Blueprint $table) use ($bin) {
            $table->id();
            // Стабильный ключ расчётного окна: повтор возвращает тот же пакет (D13).
            $table->string('package_key', 191)->collation($bin)->unique();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('state', 16)->collation($bin)->default('draft');
            // Состав в копейках рубля: база > 0, удержания < 0, итог = их сумма.
            $table->bigInteger('base_kopecks')->default(0);
            $table->bigInteger('advance_kopecks')->default(0);
            $table->bigInteger('offset_kopecks')->default(0);
            $table->bigInteger('refund_adjustment_kopecks')->default(0);
            $table->bigInteger('total_kopecks')->default(0);
            // Производный валютный снимок — последний шаг (D14).
            $table->char('payout_currency', 3)->collation($bin)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_source', 64)->collation($bin)->nullable();
            $table->bigInteger('payout_amount_minor')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            // Доказательство платежа потребляется один раз (D13).
            $table->string('payment_evidence_key', 191)->collation($bin)->nullable()->unique();
            $table->foreignId('payout_movement_id')->nullable()->unique()->constrained('money_movements')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->unsignedBigInteger('legacy_teacher_payout_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'period_start', 'period_end'], 'tpp_teacher_period_idx');
        });

        Schema::create('teacher_payout_package_lines', function (Blueprint $table) use ($bin) {
            $table->id();
            $table->string('line_key', 191)->collation($bin)->unique();
            $table->foreignId('package_id')->constrained('teacher_payout_packages')->restrictOnDelete();
            // base | advance | offset | refund_adjustment | direct_receipt_offset
            $table->string('kind', 32)->collation($bin);
            $table->bigInteger('amount_kopecks');
            // База обязана назвать назначение и версию условия (D15/D17).
            $table->foreignId('assignment_id')->nullable()->constrained('teacher_compensation_assignments')->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('teacher_compensation_terms')->restrictOnDelete();
            // Удержание возврата: ссылки на исходное движение и обязательство (D11).
            $table->foreignId('refund_movement_id')->nullable()->constrained('money_movements')->restrictOnDelete();
            $table->foreignId('obligation_id')->nullable()->constrained('money_obligations')->restrictOnDelete();
            // Прямое получение денег преподавателем (D16).
            $table->foreignId('direct_receipt_movement_id')->nullable()->unique()->constrained('money_movements')->restrictOnDelete();
            $table->string('evidence_key', 191)->collation($bin)->nullable()->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['package_id', 'kind'], 'tppl_package_kind_idx');
            // D11: один возврат уменьшает зарплату по одному обязательству РОВНО один раз.
            $table->unique(['refund_movement_id', 'obligation_id'], 'tppl_refund_once_unique');
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('teacher_payout_package_lines');
        Schema::dropIfExists('teacher_payout_packages');
        Schema::dropIfExists('teacher_compensation_assignments');
        Schema::dropIfExists('teacher_compensation_terms');
    }

    private function binaryCollation(): ?string
    {
        $db = DB::connection();

        return match (true) {
            $db->getDriverName() === 'sqlite' => null,
            method_exists($db, 'isMaria') && $db->isMaria() => 'utf8mb4_nopad_bin',
            default => 'utf8mb4_0900_bin',
        };
    }

    /**
     * @return array<string, array{table: string, event: string, rules: list<array{0: string, 1: string}>}>
     */
    private function triggers(): array
    {
        $kinds = self::LINE_KINDS;
        $types = self::TERM_TYPES;
        $scopes = self::SCOPES;
        $states = self::STATES;

        $immutableTerm = [['payout: compensation terms are immutable — add a new version', '1 = 1']];
        $immutableLine = [['payout: package lines are immutable', '1 = 1']];

        // D13: вперёд и только вперёд; «то же состояние» разрешено (идемпотентная запись полей).
        $legalTransition = "(OLD.state = NEW.state) OR (OLD.state = 'draft' AND NEW.state = 'approved') "
            ."OR (OLD.state = 'approved' AND NEW.state IN ('paid','reversed')) OR (OLD.state = 'paid' AND NEW.state = 'reversed')";

        // Состав, замороженный утверждением (D13).
        $frozenComposition = 'NEW.teacher_id <> OLD.teacher_id OR NEW.period_start <> OLD.period_start OR NEW.period_end <> OLD.period_end '
            .'OR NEW.base_kopecks <> OLD.base_kopecks OR NEW.advance_kopecks <> OLD.advance_kopecks '
            .'OR NEW.offset_kopecks <> OLD.offset_kopecks OR NEW.refund_adjustment_kopecks <> OLD.refund_adjustment_kopecks '
            .'OR NEW.total_kopecks <> OLD.total_kopecks';

        // D14: валютный снимок целостен или отсутствует целиком.
        $fxShape = [
            ['payout: FX snapshot is set only on an approved package (rubles first)', "NEW.state = 'draft' AND (NEW.payout_currency IS NOT NULL OR NEW.fx_rate IS NOT NULL OR NEW.payout_amount_minor IS NOT NULL)"],
            ['payout: a non-ruble payout needs rate, date, source and derived amount', "COALESCE(NEW.payout_currency, 'RUB') <> 'RUB' AND (NEW.fx_rate IS NULL OR NEW.fx_rate <= 0 OR NEW.fx_rate_date IS NULL OR NEW.fx_source IS NULL OR NEW.payout_amount_minor IS NULL)"],
            ['payout: a ruble payout carries no exchange rate and equals the ruble total', "NEW.payout_currency = 'RUB' AND (NEW.fx_rate IS NOT NULL OR COALESCE(NEW.payout_amount_minor, NEW.total_kopecks) <> NEW.total_kopecks)"],
            ['payout: an exchange rate without a payout currency', 'NEW.payout_currency IS NULL AND (NEW.fx_rate IS NOT NULL OR NEW.payout_amount_minor IS NOT NULL)'],
        ];

        $packageShape = array_merge([
            ['payout: unknown package state', "NEW.state NOT IN {$states}"],
            ['payout: period end precedes period start', 'NEW.period_end < NEW.period_start'],
            ['payout: base must be non-negative', 'NEW.base_kopecks < 0'],
            ['payout: advances, offsets and refund adjustments are deductions (<= 0)', 'NEW.advance_kopecks > 0 OR NEW.offset_kopecks > 0 OR NEW.refund_adjustment_kopecks > 0'],
            ['payout: total must equal base plus deductions', 'NEW.total_kopecks <> NEW.base_kopecks + NEW.advance_kopecks + NEW.offset_kopecks + NEW.refund_adjustment_kopecks'],
            // D13/P0: отрицательный итог не проводится — он оформляется отдельной корректировкой.
            ['payout: a ruble total must not go negative', 'NEW.total_kopecks < 0'],
            ['payout: an approved package names its approver and time', "NEW.state IN ('approved','paid') AND (NEW.approved_by IS NULL OR NEW.approved_at IS NULL)"],
            ['payout: a paid package needs payment evidence and time', "NEW.state = 'paid' AND (NEW.payment_evidence_key IS NULL OR NEW.paid_at IS NULL OR NEW.paid_by IS NULL)"],
            ['payout: a reversed package names its reason and author', "NEW.state = 'reversed' AND (NEW.reversal_reason IS NULL OR NEW.reversed_by IS NULL OR NEW.reversed_at IS NULL)"],
            ['payout: a draft package is neither approved, paid nor reversed', "NEW.state = 'draft' AND (NEW.approved_at IS NOT NULL OR NEW.paid_at IS NOT NULL OR NEW.reversed_at IS NOT NULL OR NEW.payment_evidence_key IS NOT NULL)"],
            ['payout: only a paid package carries a payout movement', "NEW.payout_movement_id IS NOT NULL AND NEW.state NOT IN ('paid','reversed')"],
            ['payout: the payout movement must be this teacher\'s payout', "NEW.payout_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements m WHERE m.id = NEW.payout_movement_id AND m.type = 'payout' AND m.teacher_id = NEW.teacher_id)"],
        ], $fxShape);

        return [
            'tct_bi' => ['table' => 'teacher_compensation_terms', 'event' => 'INSERT', 'rules' => [
                ['payout: unknown compensation type', "NEW.compensation_type NOT IN {$types}"],
                ['payout: a percent term carries a share and no fixed rate', "NEW.compensation_type = 'percent' AND (NEW.rate_ppm IS NULL OR NEW.rate_ppm = 0 OR NEW.rate_ppm > 1000000 OR NEW.rate_kopecks IS NOT NULL)"],
                ['payout: a rate term carries kopecks and no share', "NEW.compensation_type IN ('per_lesson','per_block','fixed') AND (NEW.rate_kopecks IS NULL OR NEW.rate_kopecks <= 0 OR NEW.rate_ppm IS NOT NULL)"],
                ['payout: a term version must be positive', 'NEW.version = 0'],
                // D15: версия растёт монотонно, история не переписывается задним числом.
                ['payout: a newer term version must not predate the version it replaces', 'EXISTS (SELECT 1 FROM teacher_compensation_terms p WHERE p.teacher_id = NEW.teacher_id AND p.version < NEW.version AND p.effective_from > NEW.effective_from)'],
            ]],
            'tct_bu' => ['table' => 'teacher_compensation_terms', 'event' => 'UPDATE', 'rules' => $immutableTerm],
            'tct_bd' => ['table' => 'teacher_compensation_terms', 'event' => 'DELETE', 'rules' => $immutableTerm],

            'tca_bi' => ['table' => 'teacher_compensation_assignments', 'event' => 'INSERT', 'rules' => [
                ['payout: unknown assignment scope', "NEW.scope_kind NOT IN {$scopes}"],
                ['payout: a course assignment names its course, a school assignment does not', "(NEW.scope_kind = 'course') <> (NEW.course_id IS NOT NULL)"],
                ['payout: assignment and term belong to different teachers', 'NOT EXISTS (SELECT 1 FROM teacher_compensation_terms t WHERE t.id = NEW.term_id AND t.teacher_id = NEW.teacher_id)'],
                ['payout: an assignment cannot start before the term it applies', 'EXISTS (SELECT 1 FROM teacher_compensation_terms t WHERE t.id = NEW.term_id AND t.effective_from > NEW.effective_from)'],
                ['payout: assignment end precedes its start', 'NEW.effective_to IS NOT NULL AND NEW.effective_to < NEW.effective_from'],
                ['payout: a fresh assignment is not already revoked', 'NEW.revoked_at IS NOT NULL'],
                // Одна действующая договорённость на пару «преподаватель + область» в любой день.
                ['payout: overlapping active assignment for this teacher and scope', 'EXISTS (SELECT 1 FROM teacher_compensation_assignments a WHERE a.teacher_id = NEW.teacher_id AND a.scope_kind = NEW.scope_kind '
                    .'AND COALESCE(a.course_id, 0) = COALESCE(NEW.course_id, 0) AND a.revoked_at IS NULL '
                    .'AND a.effective_from <= COALESCE(NEW.effective_to, NEW.effective_from) AND COALESCE(a.effective_to, NEW.effective_from) >= NEW.effective_from)'],
            ]],
            'tca_bu' => ['table' => 'teacher_compensation_assignments', 'event' => 'UPDATE', 'rules' => [
                ['payout: assignment terms are immutable — revoke and issue a new one', 'NEW.assignment_key <> OLD.assignment_key OR NEW.teacher_id <> OLD.teacher_id OR NEW.term_id <> OLD.term_id '
                    .'OR NEW.scope_kind <> OLD.scope_kind OR COALESCE(NEW.course_id, 0) <> COALESCE(OLD.course_id, 0) '
                    .'OR NEW.effective_from <> OLD.effective_from OR COALESCE(NEW.effective_to, NEW.effective_from) <> COALESCE(OLD.effective_to, OLD.effective_from)'],
                ['payout: revocation is write-once', 'OLD.revoked_at IS NOT NULL AND (NEW.revoked_at IS NULL OR NEW.revoked_at <> OLD.revoked_at)'],
            ]],
            'tca_bd' => ['table' => 'teacher_compensation_assignments', 'event' => 'DELETE', 'rules' => [['payout: assignments are never deleted — revoke them', '1 = 1']]],

            'tpp_bi' => ['table' => 'teacher_payout_packages', 'event' => 'INSERT', 'rules' => array_merge([
                ['payout: a new package starts as a draft', "NEW.state <> 'draft'"],
                // D13: второй активный пакет того же расчётного окна запрещён.
                ['payout: another live package already covers this teacher and period', "EXISTS (SELECT 1 FROM teacher_payout_packages p WHERE p.teacher_id = NEW.teacher_id AND p.period_start = NEW.period_start AND p.period_end = NEW.period_end AND p.state <> 'reversed')"],
            ], $packageShape)],
            'tpp_bu' => ['table' => 'teacher_payout_packages', 'event' => 'UPDATE', 'rules' => array_merge([
                ['payout: illegal package transition', "NOT ({$legalTransition})"],
                ['payout: the package key never changes', 'NEW.package_key <> OLD.package_key'],
                // Утверждение замораживает состав, ставки и применённые блоки (D13).
                ['payout: an approved package is frozen', "OLD.state <> 'draft' AND ({$frozenComposition})"],
                ['payout: approval is write-once', 'OLD.approved_at IS NOT NULL AND (NEW.approved_at IS NULL OR NEW.approved_at <> OLD.approved_at OR NEW.approved_by <> OLD.approved_by)'],
                ['payout: payment is write-once', 'OLD.paid_at IS NOT NULL AND (NEW.paid_at IS NULL OR NEW.paid_at <> OLD.paid_at OR COALESCE(NEW.payment_evidence_key, \'\') <> COALESCE(OLD.payment_evidence_key, \'\'))'],
                ['payout: the FX snapshot of a paid package is frozen', "OLD.state = 'paid' AND (COALESCE(NEW.payout_currency, '') <> COALESCE(OLD.payout_currency, '') OR COALESCE(NEW.fx_rate, 0) <> COALESCE(OLD.fx_rate, 0) OR COALESCE(NEW.payout_amount_minor, 0) <> COALESCE(OLD.payout_amount_minor, 0))"],
                ['payout: a reversed package is final', "OLD.state = 'reversed'"],
                // Утверждать пустой пакет нечем.
                ['payout: an approved package must carry at least one line', "NEW.state = 'approved' AND OLD.state = 'draft' AND NOT EXISTS (SELECT 1 FROM teacher_payout_package_lines l WHERE l.package_id = NEW.id)"],
            ], $packageShape)],
            'tpp_bd' => ['table' => 'teacher_payout_packages', 'event' => 'DELETE', 'rules' => [['payout: packages are never deleted — reverse them', '1 = 1']]],

            'tppl_bi' => ['table' => 'teacher_payout_package_lines', 'event' => 'INSERT', 'rules' => [
                ['payout: unknown package line kind', "NEW.kind NOT IN {$kinds}"],
                ['payout: line amount must be non-zero', 'NEW.amount_kopecks = 0'],
                ['payout: a base line must be positive', "NEW.kind = 'base' AND NEW.amount_kopecks < 0"],
                ['payout: a deduction line must be negative', "NEW.kind <> 'base' AND NEW.amount_kopecks > 0"],
                // D13: состав меняется только в черновике.
                ['payout: lines are added only to a draft package', "NOT EXISTS (SELECT 1 FROM teacher_payout_packages p WHERE p.id = NEW.package_id AND p.state = 'draft')"],
                // D17: роль и доступ не создают денег — базу создаёт только датированное назначение.
                ['payout: a base line needs a dated compensation assignment and term version', "NEW.kind = 'base' AND (NEW.assignment_id IS NULL OR NEW.term_id IS NULL)"],
                ['payout: the assignment belongs to another teacher', 'NEW.assignment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM teacher_compensation_assignments a JOIN teacher_payout_packages p ON p.id = NEW.package_id WHERE a.id = NEW.assignment_id AND a.teacher_id = p.teacher_id)'],
                ['payout: the line names a term version the assignment does not use', 'NEW.assignment_id IS NOT NULL AND NEW.term_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM teacher_compensation_assignments a WHERE a.id = NEW.assignment_id AND a.term_id = NEW.term_id)'],
                // Назначение должно действовать в расчётном окне: ретроактивной зарплаты нет.
                ['payout: the assignment does not cover the package period', 'NEW.assignment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM teacher_compensation_assignments a JOIN teacher_payout_packages p ON p.id = NEW.package_id '
                    .'WHERE a.id = NEW.assignment_id AND a.effective_from <= p.period_end AND COALESCE(a.effective_to, p.period_end) >= p.period_start)'],
                // D11: удержание возврата ссылается на исходную оплату и обязательство…
                ['payout: a refund adjustment must link its refund movement and obligation', "NEW.kind = 'refund_adjustment' AND (NEW.refund_movement_id IS NULL OR NEW.obligation_id IS NULL)"],
                ['payout: only a refund adjustment links a refund movement', "NEW.kind <> 'refund_adjustment' AND NEW.refund_movement_id IS NOT NULL"],
                ['payout: the linked movement is not a refund', "NEW.refund_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements m WHERE m.id = NEW.refund_movement_id AND m.type = 'refund')"],
                // …и только за НЕОКАЗАННЫЙ блок (D10/D11): оказанное не уменьшается.
                ['payout: a delivered obligation is never deducted from salary', 'NEW.obligation_id IS NOT NULL AND EXISTS (SELECT 1 FROM money_obligations o WHERE o.id = NEW.obligation_id AND o.delivered_at IS NOT NULL)'],
                ['payout: the refund must be allocated to the obligation it deducts', 'NEW.kind = \'refund_adjustment\' AND NOT EXISTS (SELECT 1 FROM money_allocations al WHERE al.movement_id = NEW.refund_movement_id AND al.obligation_id = NEW.obligation_id)'],
                // D16: погашение прямого получения связано со своим движением и доказательством.
                ['payout: a direct-receipt offset must link its receipt and evidence', "NEW.kind = 'direct_receipt_offset' AND (NEW.direct_receipt_movement_id IS NULL OR NEW.evidence_key IS NULL)"],
                ['payout: only a direct-receipt offset links a direct receipt', "NEW.kind <> 'direct_receipt_offset' AND NEW.direct_receipt_movement_id IS NOT NULL"],
                ['payout: the linked movement is not this teacher\'s direct receipt', 'NEW.direct_receipt_movement_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_movements m JOIN teacher_payout_packages p ON p.id = NEW.package_id '
                    ."WHERE m.id = NEW.direct_receipt_movement_id AND m.type = 'direct_teacher_receipt' AND m.teacher_id = p.teacher_id)"],
                ['payout: a direct-receipt offset cannot exceed the money the teacher received', 'NEW.direct_receipt_movement_id IS NOT NULL AND EXISTS (SELECT 1 FROM money_movements m WHERE m.id = NEW.direct_receipt_movement_id AND NEW.amount_kopecks < -m.amount_kopecks)'],
            ]],
            'tppl_bu' => ['table' => 'teacher_payout_package_lines', 'event' => 'UPDATE', 'rules' => $immutableLine],
            'tppl_bd' => ['table' => 'teacher_payout_package_lines', 'event' => 'DELETE', 'rules' => $immutableLine],
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
                throw new RuntimeException("H5444 payout triggers: unsupported driver {$driver}");
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
