<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\DunningStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Лестница эскалации напоминаний должникам (H1289): стадия выбирается по
 * дедлайну (00:00 МСК дня старта блока, пороги — config/dunning.php), у
 * каждой стадии свой текст, строка «просто проигнорируйте» — во всех четырёх.
 */
class DunningEscalationLadderTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'ladder-course']);
    }

    /** Должник «no_payment»: в группе курса, реального платежа нет. */
    private function makeDebtor(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $group = Group::create(['name' => 'Поток '.fake()->unique()->numberBetween(1, 9999)]);
        $this->course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        return $user;
    }

    private function enable(array $overrides = []): void
    {
        MarketingSetting::create(array_merge(['debt_reminders_enabled' => true], $overrides));
    }

    /** Текст единственного отправленного мессенджер-напоминания. */
    private function pushedText(): string
    {
        $text = null;
        Queue::assertPushed(SendMessengerAlerts::class, function ($job) use (&$text) {
            $text = $job->text;

            return true;
        });
        $this->assertNotNull($text);

        return $text;
    }

    /** @test */
    public function stage_one_soft_nudge_when_deadline_is_far(): void
    {
        Queue::fake();
        // Дедлайн через 6 дней — дальше порога approaching (3) → стадия 1.
        CourseBlock::factory()->for($this->course)
            ->withDates(now()->addDays(6), now()->addDays(36))->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777001']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('а оплата пока не поступила', $text);
        $this->assertStringContainsString('Оплатить нужно до', $text);
        $this->assertStringContainsString('просто проигнорируйте', $text);
    }

    /** @test */
    public function stage_two_when_deadline_is_near(): void
    {
        Queue::fake();
        // Дедлайн через 2 дня — внутри порога approaching (3) → стадия 2.
        CourseBlock::factory()->for($this->course)
            ->withDates(now()->addDays(2), now()->addDays(32))->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777002']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('стартует совсем скоро', $text);
        $this->assertStringContainsString('Оплатить нужно до', $text);
        $this->assertStringContainsString('просто проигнорируйте', $text);
    }

    /** @test */
    public function stage_three_when_overdue(): void
    {
        Queue::fake();
        // Блок стартовал 5 дней назад — просрочка меньше suspended (14) → стадия 3.
        CourseBlock::factory()->for($this->course)->current()
            ->withDates(now()->subDays(5), now()->addDays(25))->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777003']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('Материалы блока пока закрыты', $text);
        $this->assertStringContainsString('Срок оплаты истек', $text);
        $this->assertStringContainsString('просто проигнорируйте', $text);
    }

    /** @test */
    public function stage_four_when_long_overdue(): void
    {
        Queue::fake();
        Mail::fake();
        // Блок стартовал 20 дней назад — просрочка за порогом suspended (14) → стадия 4.
        CourseBlock::factory()->for($this->course)->current()
            ->withDates(now()->subDays(20), now()->addDays(10))->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777004', 'email' => 'debtor4@example.com']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('доступ к материалам блока закрыт', $text);
        $this->assertStringContainsString('Это не отчисление', $text);
        $this->assertStringContainsString('Срок оплаты истек', $text);
        $this->assertStringContainsString('просто проигнорируйте', $text);

        // Тема письма эскалирует вместе с текстом.
        Mail::assertQueued(DebtorReminderMail::class, fn (DebtorReminderMail $mail) => str_contains($mail->subjectLine, 'Доступ к материалам закрыт'));
    }

    /** @test */
    public function manager_override_wins_for_its_stage_only(): void
    {
        Queue::fake();
        CourseBlock::factory()->for($this->course)->current()
            ->withDates(now()->subDays(5), now()->addDays(25))->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777005']);
        $this->enable(['debt_reminder_stage3_text' => 'Свой текст стадии 3 для {name}.']);

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('Свой текст стадии 3', $text);
        $this->assertStringNotContainsString('Материалы блока пока закрыты', $text);
    }

    /** @test */
    public function block_without_dates_stays_on_stage_one(): void
    {
        Queue::fake();
        // Нет starts_at → дедлайна нет → эскалировать не по чему, стадия 1.
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '777006']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        $text = $this->pushedText();
        $this->assertStringContainsString('а оплата пока не поступила', $text);
    }

    /** @test */
    public function all_four_default_texts_are_distinct_and_carry_the_grace_note(): void
    {
        $texts = array_map(
            fn (DunningStage $stage) => $stage->defaultText(),
            DunningStage::cases()
        );

        $this->assertCount(4, array_unique($texts));
        foreach ($texts as $text) {
            $this->assertStringContainsString('просто проигнорируйте', $text);
        }
        // Win-back после отчисления — территория H219, в лестнице его нет.
        foreach ($texts as $text) {
            $this->assertStringNotContainsString('вернитесь', mb_strtolower($text));
        }
    }
}
