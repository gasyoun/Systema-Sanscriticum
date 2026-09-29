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
}
