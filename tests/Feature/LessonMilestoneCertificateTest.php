<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\MilestoneCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LessonMilestoneCertificateTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->course = Course::factory()->create(['title' => 'Кочергина']);
        $this->group = Group::create(['name' => 'Поток А', 'status' => 'active']);
        $this->course->groups()->attach($this->group->id);
    }

    /** Урок группы с датой $daysAgo дней назад. */
    private function lesson(int $daysAgo, array $attrs = []): Lesson
    {
        return Lesson::create(array_merge([
            'title' => 'Занятие -'.$daysAgo,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'lesson_date' => today()->subDays($daysAgo),
            'block_number' => 1,
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

    private function pay(User $user, string $tariff = 'full'): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 4800,
            'tariff' => $tariff,
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function lesson_count_milestone_fires_after_the_nth_lesson_and_not_before(): void
    {
        $milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Деванагари',
            'certificate_title' => 'О прохождении деванагари',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 6,
        ]);
        $student = $this->student();
        $this->pay($student);

        foreach (range(2, 6) as $d) {
            $this->lesson($d); // 5 занятий состоялось
        }

        $issuer = app(MilestoneCertificateIssuer::class);
        $this->assertCount(0, $issuer->dueTargets());

        $this->lesson(1); // 6-е занятие вчера

        $targets = $issuer->dueTargets();
        $this->assertCount(1, $targets);
        $this->assertSame(1, $targets->first()->occurrence);
        $this->assertTrue($targets->first()->group->is($this->group));

        $issued = $issuer->issueForTarget($targets->first());
        $this->assertCount(1, $issued);
        $cert = $issued->first();
        $this->assertSame('О прохождении деванагари', $cert->course_title);
        $this->assertSame($this->group->id, $cert->group_id);
        $this->assertSame(1, $cert->occurrence);
    }

    /** @test */
    public function lesson_count_is_per_group(): void
    {
        CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Деванагари',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 3,
        ]);

        // Группа А дошла до 3-го занятия, группа Б — только до 1-го.
        foreach (range(1, 3) as $d) {
            $this->lesson($d);
        }
        $groupB = Group::create(['name' => 'Поток Б', 'status' => 'active']);
        $this->course->groups()->attach($groupB->id);
        Lesson::create([
            'title' => 'Б-1',
            'course_id' => $this->course->id,
            'group_id' => $groupB->id,
            'lesson_date' => today()->subDay(),
            'block_number' => 1,
        ]);

        $inA = $this->student();
        $this->pay($inA);
        $inB = User::factory()->create();
        $inB->groups()->attach($groupB->id);
        $this->pay($inB);
        $inB->groups()->detach($this->group->id); // наблюдатель платежа добавил в обе группы — оставляем только Б

        $targets = app(MilestoneCertificateIssuer::class)->dueTargets();

        $this->assertCount(1, $targets);
        $this->assertTrue($targets->first()->group->is($this->group));

        $issued = app(MilestoneCertificateIssuer::class)->issueForTarget($targets->first());
        $this->assertCount(1, $issued);
        $this->assertTrue($issued->first()->user->is($inA));
    }

    /** @test */
    public function repeating_milestone_issues_one_document_per_window(): void
    {
        $milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Справка Бюлера',
            'certificate_title' => 'Курс Бюлера',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'repeat_every' => 3,
            'document_type' => CertificateMilestone::DOC_SPRAVKA,
        ]);
        $student = $this->student();
        $this->pay($student);

        foreach (range(1, 7) as $d) {
            $this->lesson($d); // 7 занятий → два полных окна по 3
        }

        $issuer = app(MilestoneCertificateIssuer::class);
        $issued = $issuer->issueForMilestone($milestone, force: true);

        $this->assertCount(2, $issued);
        $titles = $issued->pluck('course_title')->sort()->values()->all();
        $this->assertSame(['Курс Бюлера (занятия 1–3)', 'Курс Бюлера (занятия 4–6)'], $titles);
        $this->assertSame([1, 2], $issued->pluck('occurrence')->sort()->values()->all());
        $this->assertTrue($issued->every(fn (Certificate $c) => $c->isSpravka()));

        // Повторный прогон — ничего нового.
        $this->assertCount(0, $issuer->issueForMilestone($milestone, force: true));
    }

    /** @test */
    public function textbook_milestone_fires_when_the_textbook_lesson_passed(): void
    {
        CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Полкурса Кочергиной',
            'trigger_type' => CertificateMilestone::TRIGGER_TEXTBOOK,
            'trigger_lesson' => 20,
            'document_type' => CertificateMilestone::DOC_SPRAVKA,
        ]);
        $student = $this->student();
        $this->pay($student);

        $issuer = app(MilestoneCertificateIssuer::class);

        // Урок учебника №19 — рано.
        $this->lesson(3, ['textbook_lesson' => 19]);
        $this->assertCount(0, $issuer->dueTargets());

        // Урок №20, непубличный (запись ещё монтируется) — триггер всё равно срабатывает.
        $this->lesson(1, ['textbook_lesson' => 20, 'is_published' => false]);
        $targets = $issuer->dueTargets();
        $this->assertCount(1, $targets);

        $this->assertCount(1, $issuer->issueForTarget($targets->first()));
    }

    /** @test */
    public function lesson_window_payment_requires_its_blocks_with_fallback(): void
    {
        $milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Деванагари',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 4,
        ]);

        // Занятия окна лежат в блоках 1 и 2.
        $this->lesson(4, ['block_number' => 1]);
        $this->lesson(3, ['block_number' => 1]);
        $this->lesson(2, ['block_number' => 2]);
        $this->lesson(1, ['block_number' => 2]);

        $onlyFirst = $this->student();
        $this->pay($onlyFirst, 'block_1');

        $both = $this->student();
        $this->pay($both, 'block_1');
        $this->pay($both, 'block_2_h1');
        $this->pay($both, 'block_2_h2');

        $issuer = app(MilestoneCertificateIssuer::class);
        $issued = $issuer->issueForMilestone($milestone, force: true);

        $this->assertCount(1, $issued);
        $this->assertTrue($issued->first()->user->is($both));

        // Фолбэк «требуемых блоков нет» (manual-веха без диапазона):
        // достаточно любого реального оплаченного платежа по курсу.
        $manual = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Ручная',
            'trigger_type' => CertificateMilestone::TRIGGER_MANUAL,
        ]);
        $this->assertCount(2, $issuer->issueForMilestone($manual, force: true));
    }

    /** @test */
    public function manual_milestones_never_auto_fire_but_force_issues(): void
    {
        $milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Сандхи по Эмено',
            'trigger_type' => CertificateMilestone::TRIGGER_MANUAL,
        ]);
        $student = $this->student();
        $this->pay($student);

        $issuer = app(MilestoneCertificateIssuer::class);

        $this->assertCount(0, $issuer->dueTargets());
        $this->assertCount(0, $issuer->issueForMilestone($milestone)); // без force — никогда
        $this->assertCount(1, $issuer->issueForMilestone($milestone, force: true));
    }

    /** @test */
    public function lesson_milestones_respect_the_lookback_window(): void
    {
        MarketingSetting::create([
            'certificate_auto_issue_enabled' => true,
            'certificate_auto_issue_lookback_days' => 14,
        ]);
        $milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Старый поток',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 2,
        ]);
        $student = $this->student();
        $this->pay($student);

        // Оба занятия старше lookback-окна.
        $this->lesson(40);
        $this->lesson(30);

        $issuer = app(MilestoneCertificateIssuer::class);
        $this->assertCount(0, $issuer->dueTargets());
        // Бэкафилл-режим видит всю историю.
        $this->assertCount(1, $issuer->dueTargets(ignoreLookback: true));
        // Force тоже выдаёт.
        $this->assertCount(1, $issuer->issueForMilestone($milestone, force: true));
    }

    /** @test */
    public function spravka_snapshots_type_and_notifies_with_spravka_wording(): void
    {
        MarketingSetting::create(['certificate_auto_issue_enabled' => true]);
        CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Справка',
            'certificate_title' => 'Половина курса',
            'trigger_type' => CertificateMilestone::TRIGGER_LESSON_COUNT,
            'trigger_lesson' => 1,
            'document_type' => CertificateMilestone::DOC_SPRAVKA,
        ]);
        $student = $this->student();
        $this->pay($student);
        $this->lesson(1);

        $this->artisan('certificates:issue-milestones')->assertSuccessful();

        $cert = Certificate::firstOrFail();
        $this->assertTrue($cert->isSpravka());

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student)
            && str_contains($job->text, 'справка об образовании'));

        // Публичная верификация различает тип.
        $this->get('/verify/'.$cert->number)
            ->assertOk()
            ->assertSee('Справка подтверждена');
    }
}
