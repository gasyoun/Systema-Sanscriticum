<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Mail\CourseWelcomeMail;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CourseWelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
    }

    /** @test */
    public function first_course_payment_sends_thank_you_email_with_chat_link(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['chat_url' => 'https://t.me/course_chat']);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        Mail::assertQueued(
            CourseWelcomeMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->course->chat_url === 'https://t.me/course_chat'
        );
    }

    /** @test */
    public function thank_you_email_sent_only_once_per_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        // Первая оплата курса — письмо уходит.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]);

        // Вторая оплата того же курса (другой блок) — письмо НЕ дублируется.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        Mail::assertQueued(CourseWelcomeMail::class, 1);
    }

    /** @test */
    public function conditional_payment_does_not_send_thank_you_email(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => true,
        ]);

        Mail::assertNotQueued(CourseWelcomeMail::class);
    }

    /** @test */
    public function thank_you_email_sent_even_without_chat_url(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['chat_url' => null]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        Mail::assertQueued(CourseWelcomeMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    /** @test */
    public function deposit_does_not_send_course_thank_you_email(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        Mail::assertNotQueued(CourseWelcomeMail::class);
    }

    /** @test */
    public function rendered_mail_carries_the_onboarding_survey_link_when_surveys_enabled(): void
    {
        config(['surveys.enabled' => true]);

        $user = User::factory()->create();
        $course = Course::factory()->create();

        $html = (new CourseWelcomeMail($user, $course))->render();

        $this->assertStringContainsString(url('/anketa/onboarding'), $html);
    }

    /** @test */
    public function rendered_mail_omits_the_survey_link_when_surveys_disabled(): void
    {
        config(['surveys.enabled' => false]);

        $user = User::factory()->create();
        $course = Course::factory()->create();

        $html = (new CourseWelcomeMail($user, $course))->render();

        $this->assertStringNotContainsString(url('/anketa/onboarding'), $html);
    }
}
