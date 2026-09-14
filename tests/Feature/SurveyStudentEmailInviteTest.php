<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SurveyStudentInviteMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class SurveyStudentEmailInviteTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'student-purchase-2026-09';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'surveys.enabled' => true,
            'app.url' => 'https://samskrte.ru',
            'mail.from.address' => 'school@samskrte.ru',
            'mail.default' => 'smtp',
        ]);
    }

    /** @test */
    public function unknown_slug_and_disabled_surveys_fail_fast(): void
    {
        config(['surveys.enabled' => false]);
        $this->artisan('surveys:send-student-email-invites')->assertExitCode(1);

        config(['surveys.enabled' => true]);
        $this->artisan('surveys:send-student-email-invites', ['--slug' => 'no-such-wave'])
            ->assertExitCode(1);

        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function report_only_run_sends_nothing_and_writes_no_rows(): void
    {
        Mail::fake();
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function exclusion_classes_leave_only_really_eligible_email_students(): void
    {
        Mail::fake();

        $eligible = $this->emailStudent();

        $this->emailStudent(['role' => 'teacher']);
        $this->emailStudent(['role' => 'admin']);
        $this->emailStudent(['wants_messenger_announcements' => false]);
        $this->emailStudent(['email' => '']);
        $this->telegramStudent();

        $responder = $this->emailStudent();
        SurveyResponse::create(['survey_slug' => 'post3m', 'answers' => [], 'user_id' => $responder->id]);

        $this->emailStudent(['cabinet_invite_sent_at' => now()]);

        $supportInvited = $this->emailStudent();
        DB::table('telegram_support_contacts')->insert([
            'telegram_user_id' => 900001,
            'linked_user_id' => $supportInvited->id,
            'link_invited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitSurveyed = $this->emailStudent();
        $course = Course::factory()->create(['exit_survey_triggered_at' => now()]);
        DB::table('course_user')->insert([
            'course_id' => $course->id,
            'user_id' => $exitSurveyed->id,
            'status' => 'Учится',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alreadyInvited = $this->emailStudent();
        SurveyInvitation::create([
            'survey_slug' => 'post3m',
            'user_id' => $alreadyInvited->id,
            'channel' => 'email',
            'status' => SurveyInvitation::STATUS_SENT,
        ]);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertSent(SurveyStudentInviteMail::class, 1);
        $sent = SurveyInvitation::where('survey_slug', self::SLUG)
            ->where('channel', 'email')
            ->where('status', SurveyInvitation::STATUS_SENT)
            ->get();
        $this->assertSame([$eligible->id], $sent->pluck('user_id')->all());
    }

    /** @test */
    public function recent_paid_payment_without_group_or_course_is_eligible(): void
    {
        Mail::fake();

        $payer = $this->bareUser();
        Payment::create(['user_id' => $payer->id, 'amount' => 6000, 'status' => 'paid', 'created_at' => now()->subMonths(2)]);

        // Контроль: старый платёж (7 месяцев) не делает пользователя «текущим».
        $stale = $this->bareUser();
        Payment::create(['user_id' => $stale->id, 'amount' => 6000, 'status' => 'paid', 'created_at' => now()->subMonths(7)]);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $sent = SurveyInvitation::where('channel', 'email')->where('status', SurveyInvitation::STATUS_SENT)->get();
        $this->assertSame([$payer->id], $sent->pluck('user_id')->all());
    }

    /** @test */
    public function send_journals_channel_email_and_rerun_does_not_duplicate(): void
    {
        Mail::fake();

        $this->emailStudent();
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertSent(SurveyStudentInviteMail::class, 2);
        $rows = SurveyInvitation::where('channel', 'email')->get();
        $this->assertSame(2, $rows->count());
        $this->assertSame([SurveyInvitation::STATUS_SENT, SurveyInvitation::STATUS_SENT], $rows->pluck('status')->all());
        $this->assertNull($rows->first()->telegram_chat_id);
        $this->assertNotNull($rows->first()->sent_at);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertSent(SurveyStudentInviteMail::class, 2);
        $this->assertSame(2, SurveyInvitation::count());
    }

    /** @test */
    public function invalid_email_marks_failed_without_sending_and_suppresses_future_waves(): void
    {
        Mail::fake();
        $this->emailStudent(['email' => 'not-an-email']);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertNothingSent();
        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_FAILED, $row->status);
        $this->assertStringStartsWith('invalid-email', (string) $row->error);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(1, SurveyInvitation::count());
        $row->refresh();
        $this->assertSame(SurveyInvitation::STATUS_FAILED, $row->status);
    }

    /** @test */
    public function smtp_5xx_marks_hard_bounce_and_address_is_suppressed_from_future_waves(): void
    {
        $transport = $this->fakeFailingSmtp('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown in virtual mailbox table"');
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $this->assertSame(1, $transport->attempts);
        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_FAILED, $row->status);
        $this->assertStringStartsWith('hard-bounce: smtp 550', (string) $row->error);
        $this->assertNull($row->sent_at);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $this->assertSame(1, $transport->attempts, 'Жёсткий отскок не должен ретраиться в будущих волнах');
        $this->assertSame(1, SurveyInvitation::count());
    }

    /** @test */
    public function smtp_4xx_marks_unknown_and_blocks_blind_retry(): void
    {
        $transport = $this->fakeFailingSmtp('Expected response code "250" but got code "451", with message "451 4.7.1 Try again later"');
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $this->assertSame(1, $transport->attempts);
        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_UNKNOWN, $row->status);
        $this->assertStringContainsString('smtp 4xx', (string) $row->error);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $this->assertSame(1, $transport->attempts, '4xx не должен ретраиться вслепую');
        $row->refresh();
        $this->assertSame(SurveyInvitation::STATUS_UNKNOWN, $row->status);
    }

    /** @test */
    public function connection_failure_without_reply_code_marks_unknown(): void
    {
        $transport = $this->fakeFailingSmtp('Connection could not be established with host mail.samskrte.ru: connection timed out');
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        $this->assertSame(1, $transport->attempts);
        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_UNKNOWN, $row->status);
        $this->assertStringContainsString('transport:', (string) $row->error);
    }

    /** @test */
    public function invitation_carries_survey_link_and_promises_no_reward(): void
    {
        Mail::fake();
        // Имя без &<>'" : Blade экранирует их в HTML, и str_contains($html, $name)
        // случайно падал на Faker-именах вида O'Kon / O'Keefe (флейк CI 09-09).
        $user = $this->emailStudent(['name' => 'Анна Примерная']);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);
        $console = trim(Artisan::output());

        $sent = collect(Mail::sent(SurveyStudentInviteMail::class));
        $this->assertCount(1, $sent, 'приглашение не ушло; вывод команды: '.$console);

        $mail = $sent[0];
        $subject = (string) $mail->envelope()->subject;
        $html = $mail->render();

        $this->assertSame('https://samskrte.ru/anketa/'.self::SLUG, $mail->url);
        $this->assertStringContainsString('15–20 минут', $subject);
        $this->assertStringContainsString($mail->url, $html);
        $this->assertStringContainsString('15–20 минут', $html);
        $this->assertStringContainsString($user->name, $html);
        $this->assertStringNotContainsString('наград', $html);
        $this->assertStringNotContainsString('приз', $html);
    }

    /** @test */
    public function missing_sender_identity_aborts_before_any_send(): void
    {
        config(['mail.from.address' => '']);
        $this->emailStudent();

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(1);

        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function telegram_invited_user_cannot_receive_email_row_for_same_wave(): void
    {
        Mail::fake();

        $tgInvited = $this->emailStudent();
        SurveyInvitation::create([
            'survey_slug' => self::SLUG,
            'user_id' => $tgInvited->id,
            'telegram_chat_id' => 519000009,
            'channel' => 'telegram',
            'status' => SurveyInvitation::STATUS_SENT,
        ]);

        $this->artisan('surveys:send-student-email-invites', ['--send' => true])->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(1, SurveyInvitation::count());
        $this->assertSame('telegram', SurveyInvitation::sole()->channel);
    }

    /**
     * Подменяем транспорт мейлера на падающий с заданным текстом ошибки
     * (ничего не покидает процесс); счётчик попыток возвращает анонимный объект.
     */
    private function fakeFailingSmtp(string $exceptionMessage): object
    {
        $counter = new class
        {
            public int $attempts = 0;
        };

        Mail::extend('survey-fail', function () use ($exceptionMessage, $counter) {
            $counter->attempts++;

            return new class($exceptionMessage) extends AbstractTransport
            {
                public function __construct(private readonly string $exceptionMessage)
                {
                    parent::__construct();
                }

                protected function doSend(SentMessage $message): void
                {
                    throw new TransportException($this->exceptionMessage);
                }

                public function __toString(): string
                {
                    return 'survey-fail://test';
                }
            };
        });
        config([
            'mail.default' => 'survey-fail',
            'mail.mailers.survey-fail' => ['transport' => 'survey-fail'],
        ]);

        return $counter;
    }

    /** Ученик без telegram_id (email-аудитория) с живой группой. */
    private function emailStudent(array $attrs = []): User
    {
        $user = $this->bareUser($attrs);

        $group = Group::factory()->create(['status' => 'active']);
        $group->users()->attach($user->id);

        return $user->refresh();
    }

    /** Ученик С telegram_id — остаётся ботовому каналу, email-командой не берётся. */
    private function telegramStudent(): User
    {
        $user = $this->emailStudent();
        $user->forceFill(['telegram_id' => 519000000 + $user->id])->save();

        return $user->refresh();
    }

    private function bareUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'wants_messenger_announcements' => true,
            'role' => null,
            'is_admin' => false,
        ], $attrs));
    }
}
