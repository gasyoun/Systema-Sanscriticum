<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SendPaidNeverLoginReinvite;
use App\Mail\Reinvite48hMail;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * H5022 — повторное приглашение через 48 ч после оплаты без входа в кабинет.
 * Пины: окно 48 ч, идемпотентность (ровно одно сообщение), Telegram → email,
 * kill switch, штамп cabinet_invite_sent_at, magic-ссылка, отчет --report.
 */
class SendPaidNeverLoginReinviteTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 11:00:00');
        Http::fake();
        Mail::fake();
        config(['features.reinvite_48h' => true, 'app.url' => 'https://samskrte.test']);
        $this->course = Course::factory()->create();
    }

    private function paidNeverLogin(array $attrs = [], ?Carbon $paidAt = null): User
    {
        $user = User::factory()->create(array_merge([
            'login_count' => 0,
            'last_login_at' => null,
            'is_admin' => false,
            'cabinet_invite_sent_at' => null,
        ], $attrs));

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
            'transaction_id' => 'h5022_'.$user->id,
            'is_conditional' => false,
            'first_paid_at' => $paidAt ?? now()->subHours(50),
        ]));

        return $user;
    }

    private function reinviteEvents(int $userId): int
    {
        return DB::table('activity_events')
            ->where('user_id', $userId)
            ->where('event_type', ActivityEvent::REINVITE_48H_SENT)
            ->count();
    }

    public function test_dry_run_lists_cohort_without_sending_or_marking(): void
    {
        $user = $this->paidNeverLogin(['email' => 'fresh@example.com', 'telegram_id' => null]);

        $this->artisan('students:reinvite-48h')
            ->expectsOutputToContain('еще не приглашались: 1')
            ->assertSuccessful();

        Mail::assertNothingQueued();
        Http::assertNothingSent();
        $this->assertSame(0, $this->reinviteEvents($user->id));
        $this->assertNull($user->fresh()->cabinet_invite_sent_at);
    }

    public function test_send_emails_magic_link_and_marks_once(): void
    {
        $user = $this->paidNeverLogin(['email' => 'fresh@example.com', 'telegram_id' => null]);

        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertQueued(Reinvite48hMail::class, function (Reinvite48hMail $mail) use ($user): bool {
            return $mail->hasTo('fresh@example.com')
                && $mail->user->is($user)
                && str_contains($mail->loginUrl, '/login-link/');
        });
        $this->assertSame(1, $this->reinviteEvents($user->id));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
        $this->assertSame(1, MagicLinkToken::where('user_id', $user->id)->count());

        // Второй прогон — идемпотентен: ровно ОДНО приглашение на пользователя.
        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertQueuedCount(1);
        $this->assertSame(1, $this->reinviteEvents($user->id));
    }

    public function test_telegram_wins_over_email_when_chat_id_present(): void
    {
        $user = $this->paidNeverLogin(['email' => 'tg@example.com', 'telegram_id' => '111222333']);

        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) ($request['text'] ?? ''), '/login-link/')
            && str_contains((string) ($request['text'] ?? ''), 'записи занятий'));

        $event = DB::table('activity_events')->where('user_id', $user->id)->first();
        $this->assertSame('telegram', json_decode((string) $event->event_data, true)['channel']);
    }

    public function test_payment_younger_than_48h_is_not_eligible_yet(): void
    {
        $this->paidNeverLogin(['email' => 'young@example.com'], now()->subHours(30));

        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_old_payment_outside_lookback_is_left_to_weekly_drip(): void
    {
        $this->paidNeverLogin(['email' => 'old@example.com'], now()->subDays(45));

        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_logged_in_users_and_recent_invitees_are_excluded(): void
    {
        $this->paidNeverLogin(['email' => 'active@example.com', 'login_count' => 2, 'last_login_at' => now()->subDay()]);
        $this->paidNeverLogin(['email' => 'recent@example.com', 'cabinet_invite_sent_at' => now()->subDays(3)]);

        $this->artisan('students:reinvite-48h', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_kill_switch_stops_everything(): void
    {
        config(['features.reinvite_48h' => false]);
        $this->paidNeverLogin(['email' => 'fresh@example.com']);

        $this->artisan('students:reinvite-48h', ['--send' => true])
            ->expectsOutputToContain('выключен')
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_report_measures_login_within_7_days_against_baseline(): void
    {
        $winner = $this->paidNeverLogin(['email' => 'w@example.com']);
        $loser = $this->paidNeverLogin(['email' => 'l@example.com']);

        $sentAt = now()->subDays(10);
        foreach ([$winner, $loser] as $u) {
            DB::table('activity_events')->insert([
                'user_id' => $u->id,
                'event_type' => ActivityEvent::REINVITE_48H_SENT,
                'event_data' => json_encode(['channel' => 'email']),
                'created_at' => $sentAt,
            ]);
        }
        $winner->forceFill(['last_login_at' => $sentAt->copy()->addDays(2), 'login_count' => 1])->save();

        $this->artisan('students:reinvite-48h', ['--report' => true])
            ->expectsOutputToContain('вошли в течение 7 дн.: 1 (50.0%)')
            ->expectsOutputToContain('Baseline')
            ->assertSuccessful();

        $this->assertSame(10.4, SendPaidNeverLoginReinvite::BASELINE_LOGIN_RATE);
    }
}
