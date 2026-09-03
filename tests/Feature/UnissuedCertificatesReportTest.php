<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\UnissuedCertificates;
use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\ExamScore;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\MilestoneCertificateIssuer;
use App\Services\MilestoneIssueTarget;
use App\Services\UnissuedCertificatesReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3914: отчёт «невыданные дипломы/сертификаты» — read-only список того,
 * кому документ положен (веха созрела, оплата есть), но он ещё не выдан.
 * Ключевой контраст с автовыдачей: lookback-окно игнорируется, отчёт видит
 * и давно созревшие вехи.
 */
class UnissuedCertificatesReportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        // Блоки 1–4: 1 закончился 30 дней назад (вне lookback 14), 2 — вчера,
        // 3 и 4 ещё идут.
        foreach ([1 => -30, 2 => -1, 3 => +30, 4 => +60] as $number => $endOffset) {
            CourseBlock::factory()->create([
                'course_id' => $this->course->id,
                'number' => $number,
                'starts_at' => today()->addDays($endOffset - 20),
                'ends_at' => today()->addDays($endOffset),
            ]);
        }

        $this->group = Group::create(['name' => 'Поток 1', 'status' => 'active']);
        $this->course->groups()->attach($this->group->id);
    }

    private function milestone(array $attrs = []): CertificateMilestone
    {
        return CertificateMilestone::create(array_merge([
            'course_id' => $this->course->id,
            'title' => 'Деванагари',
            'certificate_title' => 'О прохождении деванагари',
            'template' => 'gasuns',
            'start_block' => 1,
            'end_block' => 2,
        ], $attrs));
    }

    private function student(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(
            ['telegram_id' => fake()->unique()->numerify('#########')],
            $attrs,
        ));
        $user->groups()->attach($this->group->id);

        return $user;
    }

    private function pay(User $user, string $tariff = 'full', array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 4800,
            'tariff' => $tariff,
            'status' => 'paid',
        ], $attrs));
    }

    private function tupleKeys(): array
    {
        return app(UnissuedCertificatesReport::class)
            ->tuples()
            ->map(fn (array $t): string => $t['user_id'].':'.$t['milestone_id'].':'.$t['occurrence'])
            ->all();
    }

    /** @test */
    public function lists_paid_students_of_a_matured_milestone_without_a_certificate(): void
    {
        $milestone = $this->milestone();
        $paid = $this->student();
        $this->pay($paid);

        $report = app(UnissuedCertificatesReport::class);
        $tuples = $report->tuples();

        $this->assertCount(1, $tuples);
        $this->assertSame($paid->id, $tuples[0]['user_id']);
        $this->assertSame($milestone->id, $tuples[0]['milestone_id']);
        $this->assertSame('certificate', $tuples[0]['document_type']);
        $this->assertSame('Деванагари', $tuples[0]['milestone_title']);
        // Block-вехи курс-уровневые: конкретной группы у цели нет.
        $this->assertNull($tuples[0]['group_name']);
        $this->assertNull($tuples[0]['group_id']);
        // Дата триггера block-вехи — конец end_block (вчера).
        $this->assertSame(today()->subDay()->toDateString(), $tuples[0]['trigger_date']);

        // Eloquent-запрос для Filament-таблицы отдаёт ту же строку.
        $row = $report->query()->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is($paid));
        $this->assertSame($milestone->id, $row->milestone_id);
        $this->assertSame('Санскрит с нуля', $report->courseTitles()[$this->course->id] ?? null);

        // Ничего не записано: отчёт только читает.
        $this->assertSame(0, Certificate::count());
    }

    /** @test */
    public function issued_documents_leave_the_report(): void
    {
        $milestone = $this->milestone();
        $student = $this->student();
        $this->pay($student);

        $report = app(UnissuedCertificatesReport::class);
        $this->assertCount(1, $report->tuples());

        app(MilestoneCertificateIssuer::class)->issueForMilestone($milestone);

        $this->assertTrue($report->tuples()->isEmpty());
        $this->assertNull($report->query()->first());
    }

    /** @test */
    public function a_manual_final_certificate_hides_the_final_milestone_but_not_the_intermediate_one(): void
    {
        $student = $this->student();
        $this->pay($student);

        $final = $this->milestone(['title' => 'Итог', 'start_block' => 1, 'end_block' => 4]);
        $intermediate = $this->milestone(['title' => 'Деванагари']);

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'student_name' => $student->name,
        ]);

        $keys = $this->tupleKeys();

        $this->assertNotContains($student->id.':'.$final->id.':1', $keys);
        $this->assertContains($student->id.':'.$intermediate->id.':1', $keys);
    }

    /** @test */
    public function unpaid_left_and_conditionally_paid_students_are_not_listed(): void
    {
        $this->milestone();

        $unpaid = $this->student();

        $conditional = $this->student();
        $this->pay($conditional, 'full', ['amount' => 0, 'is_conditional' => true]);

        $left = $this->student();
        $this->pay($left);
        $left->groups()->updateExistingPivot($this->group->id, ['left_at' => now()]);

        $this->assertSame([], $this->tupleKeys());
    }

    /** @test */
    public function immature_milestones_are_not_listed(): void
    {
        // Блок 3 закончится только через 30 дней.
        $this->milestone(['title' => 'Рано', 'start_block' => 3, 'end_block' => 3]);

        $student = $this->student();
        $this->pay($student);

        $this->assertSame([], $this->tupleKeys());
    }

    /** @test */
    public function the_lookback_window_is_ignored(): void
    {
        // Блок 1 закончился 30 дней назад — автовыдача его уже не трогает
        // (lookback 14 дней), а отчёт обязан показать.
        $old = $this->milestone(['title' => 'Поздно', 'start_block' => 1, 'end_block' => 1]);

        $student = $this->student();
        $this->pay($student);

        $issuer = app(MilestoneCertificateIssuer::class);
        $this->assertCount(0, $issuer->dueTargets());

        $this->assertContains($student->id.':'.$old->id.':1', $this->tupleKeys());
    }

    /** @test */
    public function a_repeating_milestone_lists_only_unissued_occurrences(): void
    {
        $milestone = $this->milestone([
            'title' => 'Справка каждые 2 занятия',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 2,
            'repeat_every' => 2,
            'start_block' => null,
            'end_block' => null,
        ]);

        $student = $this->student();
        $this->pay($student);

        foreach (range(1, 4) as $d) {
            Lesson::create([
                'title' => 'Занятие -'.$d,
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'lesson_date' => today()->subDays($d),
                'block_number' => 1,
            ]);
        }

        $report = app(UnissuedCertificatesReport::class);
        $this->assertContains($student->id.':'.$milestone->id.':1', $this->tupleKeys());
        $this->assertContains($student->id.':'.$milestone->id.':2', $this->tupleKeys());

        // Выдана только итерация 1 — она уходит из отчёта, итерация 2 остаётся.
        app(MilestoneCertificateIssuer::class)
            ->issueForTarget(new MilestoneIssueTarget($milestone, $this->group, 1));

        $keys = $this->tupleKeys();
        $this->assertNotContains($student->id.':'.$milestone->id.':1', $keys);
        $this->assertContains($student->id.':'.$milestone->id.':2', $keys);
    }

    /** @test */
    public function sanka_milestones_list_only_students_with_exam_scores(): void
    {
        $milestone = $this->milestone(['template' => 'sanka']);

        $withoutScores = $this->student();
        $this->pay($withoutScores);

        $withScores = $this->student();
        $this->pay($withScores);
        ExamScore::create([
            'user_id' => $withScores->id,
            'course_id' => $this->course->id,
            'score_clarity' => 18,
            'score_letters' => 4.5,
            'score_flow' => 5,
        ]);

        $tuples = app(UnissuedCertificatesReport::class)->tuples();

        $this->assertCount(1, $tuples);
        $this->assertSame($withScores->id, $tuples[0]['user_id']);
        $this->assertNotContains($withoutScores->id, $tuples->pluck('user_id')->all());
    }

    /** @test */
    public function the_page_renders_for_admin_and_is_gated_like_debtors(): void
    {
        $this->milestone();
        $student = $this->student();
        $this->pay($student);

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $this->assertTrue(UnissuedCertificates::canAccess());
        $this->assertTrue(UnissuedCertificates::shouldRegisterNavigation());

        $this->get('/admin/unissued-certificates')
            ->assertSuccessful()
            ->assertSee('Невыданные дипломы')
            ->assertSee($student->name);
    }

    /** @test */
    public function non_admin_roles_are_denied(): void
    {
        foreach ([Roles::MANAGER, Roles::TEACHER, Roles::ACCOUNTANT, Roles::SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            if ($role === Roles::SUPER_ADMIN) {
                $this->assertTrue(
                    UnissuedCertificates::canAccess(),
                    "роль {$role} (super_admin) обязана проходить гейт",
                );

                continue;
            }

            $this->assertFalse(
                UnissuedCertificates::canAccess(),
                "роль {$role} не должна проходить гейт",
            );
            $this->get('/admin/unissued-certificates')->assertForbidden();
        }
    }
}
