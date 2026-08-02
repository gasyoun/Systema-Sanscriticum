<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\ExamScore;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\MilestoneCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MilestoneCertificateIssueTest extends TestCase
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
        // Блоки 1–4: первые два уже закончились (вчера), остальные идут.
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

    private function pay(User $user, string $tariff, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 4800,
            'tariff' => $tariff,
            'status' => 'paid',
        ], $attrs));
    }

    private function enableAutoIssue(array $attrs = []): void
    {
        MarketingSetting::create(array_merge(['certificate_auto_issue_enabled' => true], $attrs));
    }

    /** @test */
    public function issues_certificate_with_milestone_snapshots_and_notifies(): void
    {
        $this->enableAutoIssue();
        $milestone = $this->milestone();
        $student = $this->student();
        $this->pay($student, 'full');

        $this->artisan('certificates:issue-milestones')->assertSuccessful();

        $cert = Certificate::where('user_id', $student->id)->first();
        $this->assertNotNull($cert);
        $this->assertSame($milestone->id, $cert->certificate_milestone_id);
        $this->assertSame('О прохождении деванагари', $cert->course_title);
        $this->assertSame('gasuns', $cert->template);
        $this->assertSame($student->name, $cert->student_name);
        $this->assertNotNull($cert->number);
        $this->assertNotNull($cert->notified_at);

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student)
            && str_contains($job->text, 'О прохождении деванагари'));
        Mail::assertQueued(CertificateIssuedMail::class, fn ($mail) => $mail->certificate->is($cert));
    }

    /** @test */
    public function a_second_run_issues_and_notifies_nothing_new(): void
    {
        $this->enableAutoIssue();
        $this->milestone();
        $student = $this->student();
        $this->pay($student, 'full');

        $this->artisan('certificates:issue-milestones')->assertSuccessful();
        $this->artisan('certificates:issue-milestones')->assertSuccessful();

        $this->assertSame(1, Certificate::where('user_id', $student->id)->count());
        Queue::assertPushed(SendMessengerAlerts::class, 1);
        Mail::assertQueued(CertificateIssuedMail::class, 1);
    }

    /** @test */
    public function unpaid_or_partially_paid_students_do_not_receive_a_certificate(): void
    {
        $milestone = $this->milestone();
        $issuer = app(MilestoneCertificateIssuer::class);

        $noPayment = $this->student();

        $onlyFirstBlock = $this->student();
        $this->pay($onlyFirstBlock, 'block_1');

        $halfOfSecond = $this->student();
        $this->pay($halfOfSecond, 'block_1');
        $this->pay($halfOfSecond, 'block_2_h1');

        $issued = $issuer->issueForMilestone($milestone);

        $this->assertCount(0, $issued);
        $this->assertSame(0, Certificate::count());
        $this->assertFalse($issuer->hasPaidMilestone($milestone, $noPayment));
        $this->assertFalse($issuer->hasPaidMilestone($milestone, $onlyFirstBlock));
        $this->assertFalse($issuer->hasPaidMilestone($milestone, $halfOfSecond));
    }

    /** @test */
    public function full_block_coverage_via_blocks_or_both_halves_qualifies(): void
    {
        $milestone = $this->milestone();
        $issuer = app(MilestoneCertificateIssuer::class);

        $byBlocks = $this->student();
        $this->pay($byBlocks, 'block_1');
        $this->pay($byBlocks, 'block_2');

        $byHalves = $this->student();
        $this->pay($byHalves, 'block_1');
        $this->pay($byHalves, 'block_2_h1');
        $this->pay($byHalves, 'block_2_h2');

        $issued = $issuer->issueForMilestone($milestone);

        $this->assertCount(2, $issued);
        $this->assertTrue(Certificate::where('user_id', $byBlocks->id)->exists());
        $this->assertTrue(Certificate::where('user_id', $byHalves->id)->exists());
    }

    /** @test */
    public function conditional_payments_do_not_qualify(): void
    {
        $milestone = $this->milestone();
        $student = $this->student();
        $this->pay($student, 'full', ['amount' => 0, 'is_conditional' => true]);

        $issued = app(MilestoneCertificateIssuer::class)->issueForMilestone($milestone);

        $this->assertCount(0, $issued);
    }

    /** @test */
    public function sanka_milestones_issue_only_to_students_with_exam_scores(): void
    {
        $this->enableAutoIssue();
        $milestone = $this->milestone(['template' => 'sanka']);
        $issuer = app(MilestoneCertificateIssuer::class);

        $withoutScores = $this->student();
        $this->pay($withoutScores, 'full');

        $withScores = $this->student();
        $this->pay($withScores, 'full');
        ExamScore::create([
            'user_id' => $withScores->id,
            'course_id' => $this->course->id,
            'score_clarity' => 18,
            'score_letters' => 4.5,
            'score_flow' => 5,
        ]);

        $issued = $issuer->issueForMilestone($milestone, force: true);

        // Без баллов сертификат не рисуется; с баллами — выдаётся со снапшотом.
        $this->assertCount(1, $issued);
        $cert = $issued->first();
        $this->assertTrue($cert->user->is($withScores));
        $this->assertSame(18.0, $cert->score_clarity);
        $this->assertSame(4.5, $cert->score_letters);
        $this->assertSame(5.0, $cert->score_flow);
        $this->assertFalse(Certificate::where('user_id', $withoutScores->id)->exists());

        // Баллы появились позже → следующий прогон выдаёт.
        ExamScore::create([
            'user_id' => $withoutScores->id,
            'course_id' => $this->course->id,
            'score_clarity' => 15,
        ]);
        $this->assertCount(1, $issuer->issueForMilestone($milestone, force: true));

        // Обновление баллов ПОСЛЕ выдачи не трогает замороженный снапшот.
        ExamScore::where('user_id', $withScores->id)
            ->update(['score_clarity' => 20]);
        $this->assertCount(0, $issuer->issueForMilestone($milestone, force: true));
        $this->assertSame(18.0, $cert->fresh()->score_clarity);
    }

    /** @test */
    public function milestones_outside_the_date_window_wait_but_force_overrides(): void
    {
        $this->enableAutoIssue(['certificate_auto_issue_lookback_days' => 14]);
        $issuer = app(MilestoneCertificateIssuer::class);
        $student = $this->student();
        $this->pay($student, 'full');

        // Блок 3 закончится только через 30 дней — веха ещё не созрела.
        $notYet = $this->milestone(['title' => 'Рано', 'start_block' => 3, 'end_block' => 3]);
        // Блок 1 закончился 30 дней назад — старше lookback-окна (14).
        $tooOld = $this->milestone(['title' => 'Поздно', 'start_block' => 1, 'end_block' => 1]);

        $due = $issuer->dueMilestones();
        $this->assertFalse($due->contains('id', $notYet->id));
        $this->assertFalse($due->contains('id', $tooOld->id));

        // Force (ручной action) игнорирует окно дат, но не оплату.
        $this->assertCount(1, $issuer->issueForMilestone($tooOld, force: true));
    }

    /** @test */
    public function the_command_is_a_noop_when_the_gate_is_off(): void
    {
        MarketingSetting::create(['certificate_auto_issue_enabled' => false]);
        $this->milestone();
        $student = $this->student();
        $this->pay($student, 'full');

        $this->artisan('certificates:issue-milestones')->assertSuccessful();

        $this->assertSame(0, Certificate::count());
        // Не assertNothingPushed: PaymentObserver пушит свои job'ы (sheet-синк).
        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function students_who_left_and_forming_groups_are_excluded(): void
    {
        $milestone = $this->milestone();
        $issuer = app(MilestoneCertificateIssuer::class);

        $left = $this->student();
        $this->pay($left, 'full');
        $left->groups()->updateExistingPivot($this->group->id, ['left_at' => now()]);

        $formingGroup = Group::create(['name' => 'Набор', 'status' => 'forming']);
        $this->course->groups()->attach($formingGroup->id);
        $inForming = User::factory()->create();
        $inForming->groups()->attach($formingGroup->id);
        // Платёж без групп active/archived: наблюдатель платежа добавит в группы
        // курса, поэтому отвязываем обратно от активной группы.
        $this->pay($inForming, 'full');
        $inForming->groups()->detach($this->group->id);

        $this->assertCount(0, $issuer->issueForMilestone($milestone));
    }

    /** @test */
    public function a_manual_final_certificate_blocks_the_final_milestone_but_not_intermediate_ones(): void
    {
        $issuer = app(MilestoneCertificateIssuer::class);
        $student = $this->student();
        $this->pay($student, 'full');

        // Ручной итоговый сертификат (веха NULL) — как из «Выдать группе».
        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'student_name' => $student->name,
        ]);

        $final = $this->milestone(['title' => 'Итог', 'start_block' => 1, 'end_block' => 4]);
        $intermediate = $this->milestone(['title' => 'Деванагари']);

        $this->assertCount(0, $issuer->issueForMilestone($final, force: true));
        $this->assertCount(1, $issuer->issueForMilestone($intermediate, force: true));
    }

    /** @test */
    public function manual_certificates_are_not_notified_by_the_command(): void
    {
        $this->enableAutoIssue();
        $student = $this->student();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'student_name' => $student->name,
        ]);

        $this->artisan('certificates:issue-milestones')->assertSuccessful();

        Queue::assertNotPushed(SendMessengerAlerts::class);
        Mail::assertNothingQueued();
    }

    /** @test */
    public function dry_run_reports_without_writing_or_notifying(): void
    {
        $this->milestone();
        $student = $this->student();
        $this->pay($student, 'full');

        $this->artisan('certificates:issue-milestones --dry-run')->assertSuccessful();

        $this->assertSame(0, Certificate::count());
        // Не assertNothingQueued: welcome-письма платёжного контура — не наши.
        Queue::assertNotPushed(SendMessengerAlerts::class);
        Mail::assertNotQueued(CertificateIssuedMail::class);
    }
}
