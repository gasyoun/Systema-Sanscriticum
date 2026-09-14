<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CabinetProbeRun;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\TextbookScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H4641: канва-факстура студенческой ветки cabinet:probe.
 *
 * student.dashboard исполняет kanva-курсор H4435 только для студента,
 * у которого есть курс с заголовком семейства канвы
 * (TextbookScale::courseFamilyPublic) + факт посещения. Без факстуры
 * фатал канвы (инцидент 10-13.09.2026: unqualified inline FQCN →
 * /dvaram 500, 43 ошибки / 9 студентов / ~3 дня) пробой не ловится.
 * ensureKanvaFixture() дозаводит недостающие связи smoke-студента
 * перед student_surfaces — идемпотентно, курс человек пинает в env.
 */
class CabinetProbeKanvaFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cabinet_probe.ping_url', '');
        config()->set('cabinet_probe.telegram_chat_id', '');
        config()->set('cabinet_probe.telegram_soft_chat_id', '');
        config()->set('cabinet_probe.check_server_guards', false); // host OS audit is prod-only (H1914)
        config()->set('cabinet_probe.check_deploy_drift', false);
        config()->set('cabinet_probe.check_payment_tls', false);
        config()->set('cabinet_probe.check_schedule_links', false);
        config()->set('cabinet_probe.check_homework_upload', false);
        config()->set('server_guards.verify_enabled', false);
        config()->set('services.telegram.bot_token', '');
        config()->set('features.cabinet_hybrid', false);
        config()->set('cabinet_probe.public_surfaces', []);
        config()->set('cabinet_probe.surfaces', []);
        config()->set('cabinet_probe.student_surfaces', [
            ['name' => 'student.dashboard', 'label' => 'student /dvaram', 'severity' => 'critical'],
        ]);
        config()->set('cabinet_probe.hybrid_surfaces', []);
        // Hermetic vs .env (local/prod boxes carry TEST_* + fixture pinning):
        // each test opts in explicitly.
        config()->set('services.test_manager.email', '');
        config()->set('services.test_manager.password', '');
        config()->set('services.test_student.email', '');
        config()->set('services.test_student.password', '');
        config()->set('cabinet_probe.kanva_fixture_course_id', 0);
        Cache::flush();
    }

    private function seedSmokeStudent(string $pass = 'stu-pass'): void
    {
        User::factory()->create([
            'email' => 'stu@example.com',
            'password' => Hash::make($pass),
            'name' => 'Smoke Student',
        ]);
        config([
            'services.test_student.email' => 'stu@example.com',
            'services.test_student.password' => $pass,
        ]);
    }

    private function seedFixtureCourse(string $title = 'Грамматика по Кочергиной (канва-факстура пробы)'): Course
    {
        $course = Course::factory()->create(['title' => $title, 'is_active' => true]);
        $group = Group::factory()->create(['name' => 'Канва-факстура гр.']);
        $course->groups()->attach($group->id);
        config()->set('cabinet_probe.kanva_fixture_course_id', $course->id);

        return $course;
    }

    /**
     * H4648: пустая конфигурация факстуры — НЕ тихий skip. Прогон громко
     * предупреждает и помечается coverage_partial (soft-флаг, не critical).
     */
    public function test_unarmed_course_id_warns_loudly_and_marks_partial_coverage(): void
    {
        $this->seedSmokeStudent();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        // Громкий warn-блок, не тихая серая строка.
        $this->assertStringContainsString('⚠️ CABINET_PROBE_KANVA_COURSE_ID ПУСТ', $out);
        $this->assertStringContainsString('НЕ покрыта', $out);
        $this->assertStringContainsString('RUNBOOK_ARM_KANVA_FIXTURE', $out);
        // Soft-флаг в истории прогона.
        $run = CabinetProbeRun::query()->latest('id')->firstOrFail();
        $this->assertFalse((bool) $run->critical, 'coverage-флаг не должен быть critical | '.$out);
        $this->assertTrue((bool) $run->coverage_partial, 'прогон без канвы — coverage_partial | '.$out);
        $student = User::query()->where('email', 'stu@example.com')->firstOrFail();
        $this->assertSame(0, $student->groups()->count(), 'no fixture course — nothing must be attached');
    }

    public function test_armed_fixture_runs_full_coverage(): void
    {
        $this->seedSmokeStudent();
        $this->seedFixtureCourse();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('⚠️', $out, 'вооружённый прогон не предупреждает | '.$out);
        $run = CabinetProbeRun::query()->latest('id')->firstOrFail();
        $this->assertFalse((bool) $run->coverage_partial, 'полный прогон — coverage_partial=false | '.$out);
    }

    public function test_missing_student_branch_marks_partial_coverage(): void
    {
        // student-ветка не настроена: канва-факстура не исполнялась вообще.
        config()->set('services.test_student.email', '');
        config()->set('services.test_student.password', '');

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('student-ветка пропущена', $out);
        $run = CabinetProbeRun::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) $run->coverage_partial, 'без student-ветки канва не покрыта | '.$out);
    }

    public function test_seeds_enrollment_and_attendance_fact_for_smoke_student(): void
    {
        $this->seedSmokeStudent();
        $course = $this->seedFixtureCourse();

        $code = Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Канва-факстура OK', $out);

        $student = User::query()->where('email', 'stu@example.com')->firstOrFail();
        $this->assertSame(1, $student->groups()->count(), $out);
        $this->assertSame(1, $student->attendances()->count(), $out);

        // Факстура реально уводит студента в ветку H4435.
        $this->assertSame('kochergina', TextbookScale::courseFamilyPublic((string) $course->title));
        $scheduleIds = Schedule::where('course_id', $course->id)->pluck('id');
        $this->assertTrue($student->attendances()->whereIn('schedule_id', $scheduleIds)->exists());
    }

    public function test_is_idempotent_across_runs(): void
    {
        $this->seedSmokeStudent();
        $this->seedFixtureCourse();

        Artisan::call('cabinet:probe');
        Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $student = User::query()->where('email', 'stu@example.com')->firstOrFail();
        $this->assertSame(1, $student->attendances()->count(), 'second run must not duplicate the fact | '.$out);
        $this->assertSame(1, $student->groups()->count(), 'second run must not duplicate membership | '.$out);
    }

    public function test_soft_fails_on_pinned_course_without_canvas_family(): void
    {
        $this->seedSmokeStudent();
        $this->seedFixtureCourse('Обычный курс без канвы');

        Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $run = CabinetProbeRun::query()->latest('id')->firstOrFail();
        $this->assertFalse((bool) $run->healthy, $out);
        $this->assertFalse((bool) $run->critical, 'soft finding must not be critical | '.$out);
        $this->assertStringContainsString('не матчит семейство канвы', $out);
    }

    public function test_soft_fails_on_missing_pinned_course(): void
    {
        $this->seedSmokeStudent();
        config()->set('cabinet_probe.kanva_fixture_course_id', 999999);

        Artisan::call('cabinet:probe');
        $out = Artisan::output();

        $run = CabinetProbeRun::query()->latest('id')->firstOrFail();
        $this->assertFalse((bool) $run->healthy, $out);
        $this->assertFalse((bool) $run->critical, 'soft finding must not be critical | '.$out);
        $this->assertStringContainsString('не найден', $out);
    }
}
