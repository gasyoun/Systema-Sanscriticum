<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\OnboardingDay1Mail;
use App\Mail\OnboardingDay5Mail;
use App\Mail\PurchaseConfirmationMail;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\ScheduledReminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1286: подтверждение покупки (чек-приветствие) + онбординг первой недели.
 * Чек уходит на каждую реальную оплату; онбординг (день 1 / день 5) ставится
 * через ScheduledReminder только на первую оплату конкретного курса.
 * Email-канал инертен до починки прод-SMTP (#504) — тесты офлайн.
 */
class PurchaseOnboardingSequenceTest extends TestCase
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
    public function purchase_confirmation_queued_on_successful_payment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        Mail::assertQueued(
            PurchaseConfirmationMail::class,
            fn ($mail) => $mail->hasTo($user->email) && $mail->payment->course_id === $course->id
        );
    }

    /** @test */
    public function purchase_confirmation_sent_on_every_real_payment_not_just_first(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        // Чек — атрибут транзакции: два платежа, два письма.
        Mail::assertQueued(PurchaseConfirmationMail::class, 2);
    }

    /** @test */
    public function onboarding_reminders_scheduled_on_first_course_payment_only(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]);

        $reminders = ScheduledReminder::orderBy('scheduled_for')->get();
        $this->assertCount(2, $reminders);

        $this->assertTrue($reminders[0]->scheduled_for->isSameDay(now()->addDay()));
        $this->assertTrue($reminders[1]->scheduled_for->isSameDay(now()->addDays(5)));

        foreach ($reminders as $reminder) {
            $this->assertSame($user->id, $reminder->user_id);
            $this->assertNull($reminder->created_by);
            $this->assertTrue((bool) $reminder->to_telegram);
            // Email-версии дней 1/5 — свёрстанные Mailable, ждут ESP-гейта (H1147);
            // генерическим ScheduledReminderMail их не дублируем.
            $this->assertFalse((bool) $reminder->to_email);
            $this->assertStringContainsString('Санскрит с нуля', $reminder->message);
        }

        // Второй блок того же курса: чек уйдёт, онбординг НЕ дублируется.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        $this->assertSame(2, ScheduledReminder::count());
    }

    /** @test */
    public function conditional_payment_sends_no_confirmation_and_schedules_nothing(): void
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

        Mail::assertNotQueued(PurchaseConfirmationMail::class);
        $this->assertSame(0, ScheduledReminder::count());
    }

    /** @test */
    public function deposit_does_not_send_purchase_confirmation(): void
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

        Mail::assertNotQueued(PurchaseConfirmationMail::class);
        $this->assertSame(0, ScheduledReminder::count());
    }

    /** @test */
    public function confirmation_renders_with_real_values_and_shared_access_promise(): void
    {
        $user = User::factory()->create(['name' => 'Мария']);
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);

        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $html = (new PurchaseConfirmationMail($payment))->render();

        $this->assertStringContainsString('Санскрит с нуля', $html);
        $this->assertStringContainsString('4 800 ₽', $html);
        $this->assertStringContainsString('полный курс', $html);
        // Общая строка 1 волны (docs/copy/_shared_strings.md) — дословно.
        $this->assertStringContainsString('Доступ откроется в течение пары минут.', $html);
        $this->assertStringContainsString('Если через 10 минут доступа всё еще нет', $html);
        $this->assertStringContainsString('Намасте, Мария!', $html);
    }

    /** @test */
    public function confirmation_shows_block_range_for_multi_block_payment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 3,
        ]));

        $this->assertStringContainsString('блоки 1–3', (new PurchaseConfirmationMail($payment))->render());
    }

    /** @test */
    public function zero_amount_confirmation_omits_sum_row(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $html = (new PurchaseConfirmationMail($payment))->render();

        // «0 ₽» в чеке читается как ошибка — строка суммы не выводится вовсе.
        $this->assertStringNotContainsString('Сумма', $html);
        $this->assertStringNotContainsString('0 ₽', $html);
    }

    /** @test */
    public function admin_user_gets_no_confirmation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $admin->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        Mail::assertNotQueued(PurchaseConfirmationMail::class);
        $this->assertSame(0, ScheduledReminder::count());
    }

    /**
     * @test
     *
     * Контракт голоса: без эмодзи, без штампов срочности, на очереди mailing —
     * зеркало MarathonMailablesTest для трёх новых писем.
     */
    public function all_three_mailables_hold_the_voice_contract(): void
    {
        $user = User::factory()->create(['name' => 'Мария']);
        $course = Course::factory()->create(['title' => 'Санскрит с нуля', 'chat_url' => 'https://t.me/course_chat']);

        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $mailables = [
            'confirmation' => new PurchaseConfirmationMail($payment),
            'day1' => new OnboardingDay1Mail($user, $course),
            'day5' => new OnboardingDay5Mail($user, $course),
        ];

        $forbidden = ['🎉', '🚀', '✨', '🙏', '🎁', '!!!', 'только сегодня', 'успей', 'Спешите', 'осталось мест'];

        foreach ($mailables as $name => $mailable) {
            $html = $mailable->render();

            $this->assertNotSame('', trim($html), "{$name}: empty render");
            $this->assertSame('mailing', $mailable->queue, "{$name}: not on the mailing queue");

            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString($token, $html, "{$name}: urgency/emoji token «{$token}»");
            }

            // Правило D13: новая копия без ё; единственное исключение — «всё»
            // там, где без ё читалось бы как «все» («всё еще нет»).
            $residue = str_replace('всё', '', $html);
            $this->assertStringNotContainsString('ё', $residue, "{$name}: ё вне исключения «всё»");
        }
    }
}
