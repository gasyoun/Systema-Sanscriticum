<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\StudentWelcomeMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регрессия (money-core, H071 #15): fireOnPaid перезапускался при ЛЮБОМ переходе
 * статуса в paid без проверки предыдущего значения. При единственной оплате
 * sendWelcomeEmailIfNeeded (count===1) заново генерировал студенту пароль и слал
 * письмо — round-trip paid→canceled→paid молча ломал самостоятельно заданный
 * пароль. Теперь пароль генерируется ровно раз (маркер welcome_email_sent_at).
 */
class WelcomePasswordRegenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function payment(User $user): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function first_paid_generates_password_and_marks_welcome_sent(): void
    {
        $user = User::factory()->create(['welcome_email_sent_at' => null]);
        $payment = $this->payment($user);

        $payment->update(['status' => 'paid']);

        $this->assertNotNull($user->fresh()->welcome_email_sent_at);
        Mail::assertQueued(StudentWelcomeMail::class, 1);
    }

    /** @test */
    public function status_roundtrip_does_not_regenerate_student_password(): void
    {
        $user = User::factory()->create(['welcome_email_sent_at' => null]);
        $payment = $this->payment($user);

        // Первая оплата — генерирует пароль, ставит маркер, шлёт письмо.
        $payment->update(['status' => 'paid']);

        // Студент сам задаёт свой пароль (обходим двойное хеширование каста).
        $chosen = Hash::make('my-own-password');
        $user->forceFill(['password' => $chosen])->saveQuietly();

        // Диспут: отмена, затем возврат в paid (вебхук/админка).
        $payment->update(['status' => 'canceled']);
        $payment->update(['status' => 'paid']);

        // Пароль студента НЕ перегенерирован; второе письмо не ушло.
        $this->assertSame($chosen, $user->fresh()->password);
        Mail::assertQueued(StudentWelcomeMail::class, 1);
    }
}
