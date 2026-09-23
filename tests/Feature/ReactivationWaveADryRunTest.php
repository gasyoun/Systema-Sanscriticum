<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ReactivationWaveADryRun;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\SuppressedEmail;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Reactivation\ReactivationWaveCohort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5288 — сухой прогон волны A: когорта (не продлившие + lapsed-пул), разбивка
 * каналов (TG-бот / email), исключения и ГАРАНТИЯ «ничего не отправлено».
 */
class ReactivationWaveADryRunTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

        $this->course = Course::factory()->create(['is_active' => true]);

        foreach ([
            [1, '2026-01-01', '2026-01-31'],
            [2, '2026-02-01', '2026-02-28'],
            [3, '2026-03-01', '2026-03-31'],
            [4, '2026-04-01', '2026-04-30'],
            [5, '2026-06-01', '2026-06-30'],
        ] as [$n, $s, $e]) {
            CourseBlock::factory()->for($this->course)
                ->withDates(Carbon::parse($s), Carbon::parse($e))
                ->create(['number' => $n]);
        }

        foreach ([4, 5] as $n) {
            Tariff::factory()->for($this->course)->block($n)->create();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(User $user, Course $course, int $start, int $end, array $extra = []): void
    {
        Payment::withoutEvents(function () use ($user, $course, $start, $end, $extra): void {
            Payment::create($extra + [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'tariff' => 'block_'.$start,
                'status' => 'paid',
                'amount' => 4800,
                'start_block' => $start,
                'end_block' => $end,
                'is_conditional' => false,
            ]);
        });
    }

    /** Курс «уже прошёл»: все блоки в прошлом → ref-блок последний, полностью оплачен. */
    private function pastCourse(): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)
            ->withDates(Carbon::parse('2025-01-01'), Carbon::parse('2025-03-31'))
            ->create(['number' => 1]);

        return $course;
    }

    /** @test */
    public function cohort_splits_segments_channels_and_counts_exclusions(): void
    {
        // 1. Не продливший (not_renewed): платил 1–3, текущий 5 не покрыт.
        $nonContinuer = User::factory()->create([
            'telegram_id' => '700101',
            'wants_messenger_announcements' => true,
            'wants_email_announcements' => true,
        ]);
        $this->paid($nonContinuer, $this->course, 1, 3);

        // 2. Lapsed: оплатил прошлый курс целиком, действующей группы нет.
        //    TG привязан, но согласия на анонсы нет → opt-out, канал email.
        $lapsed = User::factory()->create([
            'telegram_id' => '700102',
            'wants_messenger_announcements' => false,
            'wants_email_announcements' => true,
        ]);
        $this->paid($lapsed, $this->pastCourse(), 1, 1);

        // 3. Активный плательщик: прошлый курс оплачен + состоит в действующей группе.
        $active = User::factory()->create();
        $this->paid($active, $this->pastCourse(), 1, 1);
        $group = Group::create(['name' => 'Течение 1', 'status' => 'active']);
        $this->course->groups()->attach($group->id);
        $active->groups()->attach($group->id);

        // 4. Только-возврат: единственный платёж — «Расход».
        $refundOnly = User::factory()->create();
        Payment::withoutEvents(function () use ($refundOnly): void {
            Payment::create([
                'user_id' => $refundOnly->id,
                'course_id' => $this->course->id,
                'tariff' => 'Расход',
                'status' => 'paid',
                'amount' => 4800,
                'is_conditional' => false,
            ]);
        });

        // 5. Исключён везде: платил прошлый курс, course_user.status = «Исключен».
        $expelled = User::factory()->create();
        $this->paid($expelled, $this->pastCourse(), 1, 1);
        $expelled->courses()->attach($this->pastCourse()->id, ['status' => 'Исключен']);

        // 6. Lapsed с подавленным email → канала нет вовсе.
        $suppressed = User::factory()->create();
        $this->paid($suppressed, $this->pastCourse(), 1, 1);
        SuppressedEmail::suppress($suppressed->email, 'hard_bounce');

        $built = app(ReactivationWaveCohort::class)->build();
        $counts = $built['counts'];

        $this->assertSame(1, $counts['non_continuer']);
        $this->assertSame(2, $counts['lapsed']);
        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['excluded_active_payers']);
        $this->assertSame(1, $counts['excluded_refund_only']);
        $this->assertSame(1, $counts['excluded_expelled_or_left']);

        // Каналы: не продливший — TG-бот; lapsed №1 — opt-out TG → email;
        // suppressed — email недоступен → none.
        $this->assertSame(1, $counts['channel_tg_bot']);
        $this->assertSame(1, $counts['channel_email']);
        $this->assertSame(1, $counts['channel_none']);
        $this->assertSame(1, $counts['opt_out_messenger']);
        $this->assertSame(1, $counts['opt_out_or_suppressed_email']);

        $rowNc = $built['rows']->firstWhere('user_id', $nonContinuer->id);
        $this->assertSame('non_continuer', $rowNc['segment']);
        $this->assertSame('tg_bot', $rowNc['channel']);
        $rowLapsed = $built['rows']->firstWhere('user_id', $lapsed->id);
        $this->assertSame('lapsed', $rowLapsed['segment']);
        $this->assertSame('email', $rowLapsed['channel']);

        foreach ([$active->id, $refundOnly->id, $expelled->id] as $excludedId) {
            $this->assertNull($built['rows']->firstWhere('user_id', $excludedId), "user {$excludedId} не должен попасть в когорту");
        }
    }

    /** @test */
    public function command_writes_report_and_sendlist_and_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();

        $nonContinuer = User::factory()->create([
            'telegram_id' => '700201',
            'wants_messenger_announcements' => true,
        ]);
        $this->paid($nonContinuer, $this->course, 1, 3);

        $dir = storage_path('app/reactivation');
        @mkdir($dir, 0775, true);

        $this->artisan(ReactivationWaveADryRun::class)->assertSuccessful();

        $report = glob($dir.'/wave-a-dry-run-*.md');
        $this->assertNotEmpty($report, 'dry-run отчёт должен быть записан');
        $body = file_get_contents($report[0]);
        $this->assertStringContainsString('Outbound messages sent by this dry run: 0', $body);
        $this->assertStringContainsString('| Telegram bot |', $body);
        $this->assertStringNotContainsString($nonContinuer->email, $body, 'PII не должно быть в отчёте');

        $list = glob($dir.'/wave-a-sendlist-*.csv');
        $this->assertNotEmpty($list, 'send-list CSV должен быть записан');
        $this->assertStringContainsString((string) $nonContinuer->id, file_get_contents($list[0]));

        // Гарантия «отправки нет»: ни писем, ни очередей, ни попыток win-back.
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, \App\Models\DebtWinBackAttempt::count());

        foreach (array_merge($report, $list) as $f) {
            @unlink($f);
        }
    }
}
