<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TriggerExitSurveyOnCourseCompletion;
use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\ExitSurveyAutoTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3915 — Exit-опрос на авто-триггер «завершение курса». Fence: система сама
 * студентам НЕ пишет — только готовые черновики куратору; флаг default OFF.
 */
class ExitSurveyAutoTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.telegram.curators_chat_id' => '-100555',
            'features.exit_survey_auto_trigger' => true,
        ]);
        $this->course = Course::factory()->create(['title' => 'Грамматика с нуля']);
    }

    /** Реальный заказ (не deposit/conditional) со статусом без оплаты. */
    private function ask(User $user, string $status, string $createdAt, array $extra = []): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => $status,
            'is_conditional' => false,
            'created_at' => $createdAt,
        ], $extra)));
    }

    /** @test */
    public function flag_off_keeps_everything_silent(): void
    {
        config(['features.exit_survey_auto_trigger' => false]);
        $asker = User::factory()->create(['name' => 'Неплательщица']);
        $this->ask($asker, 'pending', now()->subDays(30)->toDateTimeString());

        $this->course->update(['is_completed' => true]);

        Queue::assertNothingPushed();
        $this->assertNull($this->course->fresh()->exit_survey_triggered_at);
    }

    /** @test */
    public function completion_notifies_curator_with_personal_drafts(): void
    {
        $asker = User::factory()->create(['name' => 'Неплательщица', 'telegram_username' => 'nepna', 'email' => 'n@mail.ru']);
        $payer = User::factory()->create(['name' => 'Оплатившая']);
        $fresh = User::factory()->create(['name' => 'Свежая']);

        $this->ask($asker, 'pending', now()->subDays(30)->toDateTimeString());
        $this->ask($payer, 'pending', now()->subDays(30)->toDateTimeString());
        $this->ask($payer, 'paid', now()->subDays(20)->toDateTimeString());
        $this->ask($fresh, 'pending', now()->subDays(3)->toDateTimeString());

        $this->course->update(['is_completed' => true]);

        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job): bool {
            $this->assertSame('-100555', $job->chatId);

            return str_contains($job->text, 'Курс завершён')
                && str_contains($job->text, 'Грамматика с нуля')
                && str_contains($job->text, 'Неплательщица')
                && str_contains($job->text, '@nepna')
                && str_contains($job->text, 'Здравствуйте, Неплательщица!')
                && str_contains($job->text, url('/anketa/'.ExitSurveyAutoTrigger::SURVEY_SLUG))
                && str_contains($job->text, 'не рассылкой')
                && ! str_contains($job->text, 'Оплатившая')
                && ! str_contains($job->text, 'Свежая');
        });

        $this->assertNotNull($this->course->fresh()->exit_survey_triggered_at);
    }

    /** @test */
    public function only_the_false_to_true_transition_triggers_and_stamp_dedupes(): void
    {
        $asker = User::factory()->create(['name' => 'Неплательщица']);
        $this->ask($asker, 'cancelled', now()->subDays(40)->toDateTimeString());

        $this->course->update(['is_completed' => true]);
        // Повторное сохранение без перехода — тишина.
        $this->course->update(['title' => 'Грамматика с нуля (поток 2)']);
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);

        // Прогон по штампу молчит, force — присылает снова.
        $trigger = app(ExitSurveyAutoTrigger::class);
        $this->assertSame(0, $trigger->run($this->course->fresh())->count());
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        $this->assertSame(1, $trigger->run($this->course->fresh(), force: true)->count());
        Queue::assertPushed(SendTelegramChatMessageJob::class, 2);
    }

    /** @test */
    public function deposits_and_conditional_access_are_not_price_asks(): void
    {
        $depositor = User::factory()->create(['name' => 'Бронь']);
        $conditional = User::factory()->create(['name' => 'Условная']);
        $this->ask($depositor, 'pending', now()->subDays(30)->toDateTimeString(), ['tariff' => 'deposit']);
        $this->ask($conditional, 'pending', now()->subDays(30)->toDateTimeString(), ['is_conditional' => true]);

        $this->course->update(['is_completed' => true]);

        Queue::assertNothingPushed();
        // Когорта пуста, но курс разобран — штамп стоит.
        $this->assertNotNull($this->course->fresh()->exit_survey_triggered_at);
    }

    /** @test */
    public function command_backfills_completed_unstamped_courses(): void
    {
        // Кейсы: флаг включили ПОСЛЕ того, как курсы уже завершили.
        config(['features.exit_survey_auto_trigger' => false]);

        $asker = User::factory()->create(['name' => 'Неплательщица']);
        $this->ask($asker, 'pending', now()->subDays(30)->toDateTimeString());
        $this->course->update(['is_completed' => true]); // тихо: флаг ещё OFF

        $lateAsk = User::factory()->create(['name' => 'Вторая']);
        $freshCompleted = Course::factory()->create(['title' => 'Джйотиш', 'is_completed' => true]);
        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $lateAsk->id,
            'course_id' => $freshCompleted->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
            'created_at' => now()->subDays(30)->toDateTimeString(),
        ]));
        $stamped = Course::factory()->create(['is_completed' => true, 'exit_survey_triggered_at' => now()]);

        config(['features.exit_survey_auto_trigger' => true]);

        $this->artisan(TriggerExitSurveyOnCourseCompletion::class)
            ->expectsOutputToContain('Курсов разобрано: 2')
            ->assertSuccessful();

        Queue::assertPushed(SendTelegramChatMessageJob::class, 2);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job) => str_contains($job->text, $this->course->title));
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job) => str_contains($job->text, 'Джйотиш'));
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job) => ! str_contains($job->text, $stamped->title));
        $this->assertNotNull($this->course->fresh()->exit_survey_triggered_at);
        $this->assertNotNull($freshCompleted->fresh()->exit_survey_triggered_at);

        // Повторный прогон без --force — нечего разбирать.
        Queue::fake();
        $this->artisan(TriggerExitSurveyOnCourseCompletion::class)
            ->expectsOutputToContain('разбирать нечего')
            ->assertSuccessful();
        Queue::assertNothingPushed();
    }

    /** @test */
    public function command_refuses_to_run_when_flag_is_off(): void
    {
        config(['features.exit_survey_auto_trigger' => false]);

        $this->artisan(TriggerExitSurveyOnCourseCompletion::class)->assertFailed();
    }
}
