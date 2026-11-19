<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherPayrollReadiness;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payroll\PayrollReadinessService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PayrollReadinessAccessAndCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exportPath = storage_path('framework/testing/payroll-readiness-export.json');
        File::delete($this->exportPath);
        config()->set('features.teacher_payroll_readiness', true);
        config()->set('payroll_readiness.expected_teacher_count', 23);
        config()->set('payroll_readiness.evidence_manifest_path', storage_path('framework/testing/missing-payroll-evidence.json'));
        config()->set('payroll_readiness.evidence_hash_ledger_path', storage_path('framework/testing/payroll-evidence-hash-ledger.json'));
        File::delete((string) config('payroll_readiness.evidence_hash_ledger_path'));
        config()->set('services.tochka.token', '');
        Teacher::factory()->count(23)->create();
    }

    protected function tearDown(): void
    {
        File::delete($this->exportPath);
        parent::tearDown();
    }

    public function test_only_accounting_roles_can_open_the_private_payroll_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->assertTrue(TeacherPayrollReadiness::canAccess());
        $this->get('/admin/teacher-payroll-readiness')
            ->assertSuccessful()
            ->assertSee('Готовность выплат преподавателям')
            ->assertSee('Перепись 23 / 23');

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->assertFalse(TeacherPayrollReadiness::canAccess());
        $this->get('/admin/teacher-payroll-readiness')->assertForbidden();
    }

    public function test_private_export_and_transfer_time_fingerprint_check_are_read_only(): void
    {
        $before = [Payment::query()->count(), TeacherPayout::query()->count()];
        $cutoff = Carbon::parse('2026-10-01T08:30:00+03:00');
        $fingerprint = app(PayrollReadinessService::class)->build($cutoff)['fingerprint'];

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $this->exportPath,
            '--expect-fingerprint' => $fingerprint,
        ])->expectsOutputToContain('Disposition')
            ->expectsOutputToContain('never')
            ->assertSuccessful();

        $export = json_decode(File::get($this->exportPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($fingerprint, $export['fingerprint']);
        $this->assertCount(23, $export['teachers']);
        $this->assertSame($before, [Payment::query()->count(), TeacherPayout::query()->count()]);

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--expect-fingerprint' => str_repeat('0', 64),
        ])->assertFailed();
    }

    public function test_the_command_refuses_to_run_when_the_readiness_feature_flag_is_off(): void
    {
        config()->set('features.teacher_payroll_readiness', false);

        $this->artisan('payroll:readiness')
            ->expectsOutputToContain('teacher_payroll_readiness is OFF')
            ->assertFailed();
    }

    public function test_a_rerun_for_an_already_exported_cutoff_refuses_to_overwrite_the_incumbent_artifact(): void
    {
        $cutoff = Carbon::parse('2026-10-01T08:30:00+03:00');
        $fingerprint = app(PayrollReadinessService::class)->build($cutoff)['fingerprint'];

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $this->exportPath,
        ])->assertSuccessful();

        $incumbent = File::get($this->exportPath);

        // Two reruns, each asserting one half of the refusal message: the
        // message names the incumbent fingerprint and the live one, and the
        // incumbent artifact is byte-identical after both refusals.
        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $this->exportPath,
        ])->expectsOutputToContain('APPROVED EXPORT EXISTS')
            ->assertFailed();
        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $this->exportPath,
        ])->expectsOutputToContain($fingerprint)
            ->assertFailed();

        $this->assertSame($incumbent, File::get($this->exportPath));
        $this->assertSame($fingerprint, json_decode($incumbent, true, 512, JSON_THROW_ON_ERROR)['fingerprint']);
    }

    public function test_expect_fingerprint_reads_the_artifact_and_fails_when_it_was_replaced(): void
    {
        $cutoff = Carbon::parse('2026-10-02T09:15:00+03:00');
        $fingerprint = app(PayrollReadinessService::class)->build($cutoff)['fingerprint'];
        $artifact = storage_path('app/private/payroll/readiness-'.$cutoff->format('Ymd-His').'.json');
        File::delete($artifact);
        $this->beforeApplicationDestroyed(fn () => File::delete($artifact));

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => 'auto',
        ])->assertSuccessful();

        // Transfer-time recheck with the genuine artifact in place: passes.
        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--expect-fingerprint' => $fingerprint,
        ])->assertSuccessful();

        // The artifact is replaced in place by foreign means; the recheck
        // must fail on the artifact itself, not only on live drift.
        File::put($artifact, json_encode(['fingerprint' => str_repeat('e', 64)], JSON_THROW_ON_ERROR));
        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--expect-fingerprint' => $fingerprint,
        ])->expectsOutputToContain('REPLACED ARTIFACT')
            ->assertFailed();
    }

    public function test_an_export_to_a_relative_public_path_is_refused(): void
    {
        $cutoff = Carbon::parse('2026-10-01T08:30:00+03:00');

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => 'public/payroll.json',
        ])->expectsOutputToContain('public directory')
            ->assertFailed();

        $this->assertFileDoesNotExist(public_path('payroll.json'));
    }

    public function test_two_command_runs_over_unchanged_data_yield_the_same_fingerprint(): void
    {
        $cutoff = Carbon::parse('2026-10-01T08:30:00+03:00');
        $first = storage_path('framework/testing/payroll-readiness-export-1.json');
        $second = storage_path('framework/testing/payroll-readiness-export-2.json');
        File::delete([$first, $second]);
        $this->beforeApplicationDestroyed(fn () => File::delete([$first, $second]));

        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $first,
        ])->assertSuccessful();
        $this->artisan('payroll:readiness', [
            '--on' => $cutoff->toIso8601String(),
            '--export' => $second,
        ])->assertSuccessful();

        $a = json_decode(File::get($first), true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode(File::get($second), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertSame($a['teachers'], $b['teachers']);
        $this->assertCount(23, $b['teachers']);
    }
}
