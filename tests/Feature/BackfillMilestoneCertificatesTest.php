<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackfillMilestoneCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private CertificateMilestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->course = Course::factory()->create();
        // Блок закончился 60 дней назад — старше lookback, ежедневная команда молчит.
        CourseBlock::factory()->create([
            'course_id' => $this->course->id,
            'number' => 1,
            'starts_at' => today()->subDays(90),
            'ends_at' => today()->subDays(60),
        ]);
        $this->group = Group::create(['name' => 'Старый поток', 'status' => 'archived']);
        $this->course->groups()->attach($this->group->id);

        $this->milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Блок 1',
            'start_block' => 1,
            'end_block' => 1,
        ]);
    }

    private function paidStudent(): User
    {
        $user = User::factory()->create(['telegram_id' => fake()->unique()->numerify('#########')]);
        $user->groups()->attach($this->group->id);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        return $user;
    }

    /** @test */
    public function daily_command_skips_targets_older_than_lookback_but_backfill_sees_them(): void
    {
        MarketingSetting::create(['certificate_auto_issue_enabled' => true]);
        $this->paidStudent();

        $this->artisan('certificates:issue-milestones')->assertSuccessful();
        $this->assertSame(0, Certificate::count());

        $this->artisan('certificates:backfill-milestones')
            ->expectsConfirmation('Выдать перечисленные документы?', 'yes')
            ->assertSuccessful();
        $this->assertSame(1, Certificate::count());
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        $this->paidStudent();

        $this->artisan('certificates:backfill-milestones --dry-run')->assertSuccessful();

        $this->assertSame(0, Certificate::count());
        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function without_notify_flag_certificates_are_stamped_and_nightly_run_stays_silent(): void
    {
        MarketingSetting::create(['certificate_auto_issue_enabled' => true]);
        $this->paidStudent();

        $this->artisan('certificates:backfill-milestones')
            ->expectsConfirmation('Выдать перечисленные документы?', 'yes')
            ->assertSuccessful();

        $cert = Certificate::firstOrFail();
        $this->assertNotNull($cert->notified_at);
        Queue::assertNotPushed(SendMessengerAlerts::class);

        // Ночная команда не должна доотправить бэкафилл-документы.
        // (Не assertNothingQueued: welcome-письма платёжного контура — не наши.)
        $this->artisan('certificates:issue-milestones')->assertSuccessful();
        Queue::assertNotPushed(SendMessengerAlerts::class);
        Mail::assertNotQueued(CertificateIssuedMail::class);
    }

    /** @test */
    public function notify_flag_sends_notifications(): void
    {
        $student = $this->paidStudent();

        $this->artisan('certificates:backfill-milestones --notify')
            ->expectsConfirmation('Выдать перечисленные документы?', 'yes')
            ->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
        $this->assertNotNull(Certificate::firstOrFail()->notified_at);
    }

    /** @test */
    public function students_who_left_are_excluded_and_course_filter_works(): void
    {
        $left = $this->paidStudent();
        $left->groups()->updateExistingPivot($this->group->id, ['left_at' => now()]);

        // Чужой курс не попадает под --course нашего.
        $other = Course::factory()->create();
        CourseBlock::factory()->create([
            'course_id' => $other->id,
            'number' => 1,
            'starts_at' => today()->subDays(90),
            'ends_at' => today()->subDays(60),
        ]);
        $otherGroup = Group::create(['name' => 'Чужой', 'status' => 'archived']);
        $other->groups()->attach($otherGroup->id);
        CertificateMilestone::create([
            'course_id' => $other->id,
            'title' => 'Чужая веха',
            'start_block' => 1,
            'end_block' => 1,
        ]);
        $stranger = User::factory()->create();
        $stranger->groups()->attach($otherGroup->id);
        Payment::create([
            'user_id' => $stranger->id,
            'course_id' => $other->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $this->artisan('certificates:backfill-milestones --course='.$this->course->id)
            ->assertSuccessful();

        // Ушедший — единственный кандидат нашего курса → выдавать некому,
        // подтверждение даже не спрашивается; чужой курс отфильтрован.
        $this->assertSame(0, Certificate::count());
    }
}
