<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DozhimDripLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NOBORING dozhim Wave 1b (H2059, IMPLEMENTATION H-B п.5): dozhim:drip шлёт
 * линейный шаг day0/day3/day7 через существующий Messaging, идемпотентно,
 * гейт dozhim_drip (независим от dozhim_queue).
 */
class DozhimDripTest extends TestCase
{
    use RefreshDatabase;

    private function seedDealAgedHours(int $hours, array $userAttrs = []): Deal
    {
        $stage = DealStage::first() ?? DealStage::factory()->create();
        $course = Course::factory()->create();
        $user = User::factory()->create(array_merge(['email' => 'buyer@example.com', 'telegram_id' => null, 'vk_id' => null], $userAttrs));

        $deal = Deal::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'stage_id' => $stage->id,
            'closed_at' => null,
        ]);

        $deal->forceFill(['created_at' => now()->subHours($hours)])->save();

        return $deal;
    }

    private function seedDozhimTemplates(): void
    {
        MessageTemplate::create([
            'title' => 'day0', 'body' => 'Привет {name}, курс {course}: {pay_link}',
            'category' => MessageTemplate::CATEGORY_DOZHIM, 'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY0, 'is_active' => true,
        ]);
        MessageTemplate::create([
            'title' => 'day3', 'body' => 'День 3, {name}',
            'category' => MessageTemplate::CATEGORY_DOZHIM, 'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY3, 'is_active' => true,
        ]);
        MessageTemplate::create([
            'title' => 'day7', 'body' => 'День 7, {name}',
            'category' => MessageTemplate::CATEGORY_DOZHIM, 'dozhim_step' => MessageTemplate::DOZHIM_STEP_DAY7, 'is_active' => true,
        ]);
    }

    /** @test */
    public function sends_nothing_when_flag_off(): void
    {
        Mail::fake();
        Queue::fake();
        config(['features.dozhim_drip' => false, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        $this->seedDealAgedHours(30);

        $this->artisan('dozhim:drip')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, DozhimDripLog::query()->count());
    }

    /** @test */
    public function sends_day0_by_email_for_a_freshly_aged_deal(): void
    {
        Mail::fake();
        Queue::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        $deal = $this->seedDealAgedHours(30);

        $this->artisan('dozhim:drip')->assertSuccessful();

        Mail::assertQueued(DebtorReminderMail::class, fn (DebtorReminderMail $mail) => $mail->hasTo($deal->user->email));
        $this->assertSame(1, DozhimDripLog::query()->where('deal_id', $deal->id)->where('step', MessageTemplate::DOZHIM_STEP_DAY0)->count());
    }

    /** @test */
    public function does_not_resend_day0_on_second_run(): void
    {
        Mail::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        $this->seedDealAgedHours(30);

        $this->artisan('dozhim:drip')->assertSuccessful();
        $this->artisan('dozhim:drip')->assertSuccessful();

        $this->assertSame(1, DozhimDripLog::query()->count());
        Mail::assertQueued(DebtorReminderMail::class, 1);
    }

    /** @test */
    public function never_skips_a_step_even_when_the_deal_is_already_old_enough_for_a_later_one(): void
    {
        Mail::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        // 24h base + 72h day3 offset + margin — old enough for day3, but day0 was never sent.
        $deal = $this->seedDealAgedHours(100);

        $this->artisan('dozhim:drip')->assertSuccessful();

        // Linear order: day0 first (never sent), not the more-due day3.
        $this->assertSame(1, DozhimDripLog::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(
            MessageTemplate::DOZHIM_STEP_DAY0,
            DozhimDripLog::query()->where('deal_id', $deal->id)->value('step')
        );

        // Next run advances to day3 — still one step per run.
        $this->artisan('dozhim:drip')->assertSuccessful();
        $this->assertSame(2, DozhimDripLog::query()->where('deal_id', $deal->id)->count());
        $this->assertTrue(
            DozhimDripLog::query()->where('deal_id', $deal->id)->where('step', MessageTemplate::DOZHIM_STEP_DAY3)->exists()
        );
    }

    /** @test */
    public function young_open_deal_gets_no_message(): void
    {
        Mail::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        $this->seedDealAgedHours(2);

        $this->artisan('dozhim:drip')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, DozhimDripLog::query()->count());
    }

    /** @test */
    public function messenger_channel_used_when_telegram_present(): void
    {
        Mail::fake();
        Queue::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();
        $this->seedDealAgedHours(30, ['telegram_id' => '999']);

        $this->artisan('dozhim:drip')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function deal_without_a_resolvable_user_is_skipped_silently(): void
    {
        Mail::fake();
        config(['features.dozhim_drip' => true, 'dozhim.unpaid_deal_hours' => 24]);
        $this->seedDozhimTemplates();

        $stage = DealStage::first() ?? DealStage::factory()->create();
        $deal = Deal::factory()->create(['user_id' => null, 'stage_id' => $stage->id, 'closed_at' => null]);
        $deal->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('dozhim:drip')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, DozhimDripLog::query()->count());
    }
}
