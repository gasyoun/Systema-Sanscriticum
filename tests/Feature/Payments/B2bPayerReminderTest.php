<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Jobs\SendTelegramChatMessageJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Payments\B2bPayerReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * H8250 — B2B-напоминания плательщику: после даты последнего урока покрытого
 * b2b-блока N плательщик получает РОВНО одно Telegram-напоминание оплатить
 * блок N+1; без Telegram у плательщика — задача кураторам; (null,null) =
 * оплачен весь курс → тишина; флаг OFF → no-op; ученикам группы не пишем.
 */
class B2bPayerReminderTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Teacher $teacher;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'features.b2b_group_payment' => true,
            'features.b2b_payer_reminders' => true,
            'services.telegram.curators_chat_id' => '-100999',
        ]);

        $this->teacher = Teacher::create(['name' => 'Преподаватель гр.64']);
        $this->course = Course::factory()->create([
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
        ]);

        $this->group = Group::factory()->create();
        $this->course->groups()->attach($this->group->id);

        // Блок 1: четыре прошедших занятия (последнее — вчера), блок 2 — впереди.
        foreach ([-11, -8, -5, -1] as $daysAgo) {
            Lesson::factory()->create([
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'block_number' => 1,
                'lesson_date' => now()->addDays($daysAgo)->startOfDay(),
                'is_free' => false,
            ]);
        }
        foreach ([2, 5, 9, 12] as $daysAhead) {
            Lesson::factory()->create([
                'course_id' => $this->course->id,
                'group_id' => $this->group->id,
                'block_number' => 2,
                'lesson_date' => now()->addDays($daysAhead)->startOfDay(),
                'is_free' => false,
            ]);
        }

        $this->payer = User::factory()->create([
            'telegram_username' => 'adar_payer',
            'telegram_id' => 424242,
        ]);
    }

    private function payB2bGroup(array $attrs = []): Payment
    {
        $payload = array_merge([
            'user_id' => $this->payer->id,
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'tariff' => Payment::TARIFF_B2B_GROUP,
            'amount' => 30000,
            'status' => 'paid',
            'is_conditional' => false,
            'start_block' => 1,
            'end_block' => 1,
            'received_account' => Payment::RECEIVED_SCHOOL,
            'payment_method' => 'cash',
            'payer_note' => 'Оплата за группу (студия Адара)',
        ], $attrs);

        return Payment::withoutEvents(fn () => Payment::create($payload))->fresh();
    }

    public function test_reminds_payer_once_after_last_lesson_of_block(): void
    {
        $payment = $this->payB2bGroup();
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['checked']);
        Bus::assertDispatchedTimes(SendTelegramMessageJob::class, 1);
        Bus::assertDispatched(SendTelegramMessageJob::class, function (SendTelegramMessageJob $job): bool {
            return $job->userId === $this->payer->id
                && str_contains($job->text, 'Блок 1 курса')
                && str_contains($job->text, 'к блоку 2')
                && str_contains($job->text, 'счёт на юрлицо')
                && str_contains($job->text, 'наличными');
        });

        // Дедуп-маркер поставлен в момент постановки job'а.
        $this->assertNotNull($payment->fresh()->claimMeta(B2bPayerReminderService::dedupKey(2)));

        // Ученикам группы не пишем ничего — только плательщику.
        Bus::assertNotDispatched(SendTelegramChatMessageJob::class);
    }

    public function test_dedups_exactly_one_per_payer_block(): void
    {
        $this->payB2bGroup();
        Bus::fake();

        $service = app(B2bPayerReminderService::class);
        $service->run();
        $service->run();
        $service->run();

        Bus::assertDispatchedTimes(SendTelegramMessageJob::class, 1);
    }

    public function test_full_coverage_null_null_stays_silent(): void
    {
        $this->payB2bGroup(['start_block' => null, 'end_block' => null]);
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);
        Bus::assertNothingDispatched();
    }

    public function test_next_block_already_covered_stays_silent(): void
    {
        $this->payB2bGroup(['start_block' => 1, 'end_block' => 2]);
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(0, $stats['sent']);
        Bus::assertNothingDispatched();
    }

    public function test_missing_telegram_creates_curator_task(): void
    {
        $payment = $this->payB2bGroup();
        $this->payer->forceFill(['telegram_username' => null, 'telegram_id' => null])->save();
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(1, $stats['curator_tasks']);
        Bus::assertDispatchedTimes(SendTelegramChatMessageJob::class, 1);
        Bus::assertDispatched(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job): bool {
            return $job->chatId === '-100999'
                && str_contains($job->text, 'без Telegram');
        });
        Bus::assertNotDispatched(SendTelegramMessageJob::class);

        // Дедуп-маркер стоит: кураторская задача тоже ровно одна.
        $this->assertNotNull($payment->fresh()->claimMeta(B2bPayerReminderService::dedupKey(2)));
    }

    public function test_last_lesson_today_is_not_yet_due(): void
    {
        Lesson::query()
            ->where('course_id', $this->course->id)
            ->where('group_id', $this->group->id)
            ->where('block_number', 1)
            ->whereDate('lesson_date', now()->subDay()->toDateString())
            ->update(['lesson_date' => now()->startOfDay()]);
        $this->payB2bGroup();
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(0, $stats['sent']);
        Bus::assertNothingDispatched();
    }

    public function test_reminder_flag_off_is_noop(): void
    {
        config(['features.b2b_payer_reminders' => false]);
        $this->payB2bGroup();
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(0, $stats['checked']);
        Bus::assertNothingDispatched();
    }

    public function test_b2b_flag_off_is_noop(): void
    {
        config(['features.b2b_group_payment' => false]);
        $this->payB2bGroup();
        Bus::fake();

        $stats = app(B2bPayerReminderService::class)->run();

        $this->assertSame(0, $stats['checked']);
        Bus::assertNothingDispatched();
    }
}
