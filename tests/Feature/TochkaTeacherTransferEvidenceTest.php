<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\TeacherPayoutIdentity;
use App\Models\TeacherTransferMatch;
use App\Models\TochkaOutgoingTransfer;
use App\Services\Payments\TeacherPaymentIdentity;
use App\Services\Payments\TochkaApiCreditImporter;
use App\Services\Payments\TochkaTeacherTransferImporter;
use App\Services\Payroll\PayrollReadinessService;
use App\Services\Payroll\TeacherTransferEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TochkaTeacherTransferEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.money_tochka_teacher_transfers' => true, 'app.key' => 'base64:test-payroll-evidence-key']);
    }

    public function test_import_is_idempotent_exactly_matched_and_never_creates_money_rows(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Толчельников Иван Евгеньевич']);
        $this->identity($teacher, 'inn', '352525483087');
        $beforePayments = Payment::query()->count();
        $beforePayouts = TeacherPayout::query()->count();
        $importer = app(TochkaTeacherTransferImporter::class);

        $dry = $importer->importPayload($this->payload(), false);
        $this->assertSame('dry_run', $dry['outcome']);
        $this->assertSame(1, $dry['matched']);
        $this->assertSame(0, TochkaOutgoingTransfer::query()->count());

        $first = $importer->importPayload($this->payload(), true);
        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, TeacherTransferMatch::query()->where('teacher_id', $teacher->id)->count());
        $second = $importer->importPayload($this->payload(), true);
        $this->assertSame(1, $second['duplicate']);
        $this->assertSame(1, TochkaOutgoingTransfer::query()->count());
        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertSame($beforePayouts, TeacherPayout::query()->count());
    }

    public function test_same_transaction_id_with_changed_amount_fails_closed(): void
    {
        $importer = app(TochkaTeacherTransferImporter::class);
        $importer->importPayload($this->payload(), true);
        $changed = $this->payload();
        data_set($changed, 'statements.0.data.Data.Statement.0.Transaction.0.Amount.amount', 18521);

        $result = $importer->importPayload($changed, true);
        $this->assertSame('incomplete', $result['outcome']);
        $this->assertSame(1, $result['conflict']);
        $this->assertSame(1852000, TochkaOutgoingTransfer::query()->firstOrFail()->amount_kopecks);
    }

    public function test_same_api_payload_imports_booked_student_credits_with_full_coverage(): void
    {
        $payload = $this->payload();
        $payload['statements'][0]['data']['Data']['Statement'][0]['Transaction'][] = [
            'transactionId' => 'credit-1',
            'creditDebitIndicator' => 'Credit',
            'status' => 'Booked',
            'documentNumber' => 'C1',
            'documentProcessDate' => '2026-09-15',
            'description' => 'Оплата Заказ №1667',
            'Amount' => ['amount' => 6000, 'currency' => 'RUB'],
        ];
        $importer = app(TochkaApiCreditImporter::class);
        $dry = $importer->importPayload($payload, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-28'));
        $this->assertSame('dry_run', $dry['outcome']);
        $this->assertSame(0, BankStatementCredit::query()->count());

        $result = $importer->importPayload($payload, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-28'), true);
        $this->assertSame('imported', $result['outcome']);
        $this->assertSame(600000, BankStatementCredit::query()->firstOrFail()->amount_kopecks);
        $this->assertSame('2026-08-01', BankStatementImport::query()->firstOrFail()->covers_from->toDateString());
        $this->assertSame('2026-09-28', BankStatementImport::query()->firstOrFail()->covers_to->toDateString());
    }

    public function test_bank_fact_wins_over_stale_lms_and_remains_unallocated(): void
    {
        $teacher = Teacher::factory()->create();
        $this->identity($teacher, 'inn', '352525483087');
        TeacherPayout::query()->create(['teacher_id' => $teacher->id, 'type' => 'regular', 'amount' => 4553, 'paid_at' => '2026-07-28']);
        app(TochkaTeacherTransferImporter::class)->importPayload($this->payload(), true);

        $actual = app(TeacherTransferEvidenceService::class)->lastForTeacher($teacher, Carbon::parse('2026-09-28'));
        $this->assertSame('2026-08-27', $actual['date']);
        $this->assertSame(32, $actual['days_since']);
        $this->assertSame(18520.0, $actual['amount_rub']);
        $this->assertSame('unallocated', $actual['allocation_state']);
        $this->assertSame('124', $actual['document_no']);
    }

    public function test_evidence_rows_are_append_only(): void
    {
        app(TochkaTeacherTransferImporter::class)->importPayload($this->payload(), true);
        $this->expectException(QueryException::class);
        DB::table('tochka_outgoing_transfers')->update(['amount_kopecks' => 1]);
    }

    public function test_gasuns_is_visible_and_held_when_compensation_policy_is_invalid(): void
    {
        $gasuns = Teacher::factory()->create(['name' => 'Гасунс Марцис Юрьевич']);
        Teacher::factory()->count(22)->create();
        Course::factory()->create([
            'teacher_id' => $gasuns->id,
            'salary_type' => 'percent',
            'salary_value' => 1000,
        ]);

        $report = app(PayrollReadinessService::class)->build(Carbon::parse('2026-09-28'));
        $row = collect($report['teachers'])->firstWhere('teacher_id', $gasuns->id);

        $this->assertNotNull($row);
        $this->assertSame('held', $row['disposition']);
        $this->assertContains('compensation_policy_invalid:course_'.Course::query()->where('teacher_id', $gasuns->id)->value('id'), $row['holds']);
    }

    private function identity(Teacher $teacher, string $type, string $value): void
    {
        TeacherPayoutIdentity::query()->create([
            'teacher_id' => $teacher->id,
            'provider' => 'tochka',
            'identity_type' => $type,
            'identity_hmac' => TeacherPaymentIdentity::digest($value),
            'display_tail' => substr($value, -4),
            'confirmed_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return ['statements' => [[
            'statement_id' => 'stmt-1',
            'data' => ['Data' => ['Statement' => [[
                'accountId' => '40802810020000877617/044525104',
                'statementId' => 'stmt-1',
                'Transaction' => [[
                    'transactionId' => 'cbs-tb;2456702600;1',
                    'paymentId' => 'payment-2026-08-27_5535482025',
                    'creditDebitIndicator' => 'Debit',
                    'status' => 'Booked',
                    'documentNumber' => '124',
                    'documentProcessDate' => '2026-08-27',
                    'description' => 'Synthetic contract payment',
                    'Amount' => ['amount' => 18520, 'currency' => 'RUB'],
                    'CreditorParty' => ['inn' => '352525483087', 'name' => 'Synthetic Teacher'],
                    'CreditorAccount' => ['identification' => '40817810000040153662'],
                ]],
            ]]]],
        ]]];
    }
}
